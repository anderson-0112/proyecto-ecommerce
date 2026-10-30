<?php
/**
 * pagos-service (puerto 8084) — cobro con Stripe (modo prueba) y estado de pagos.
 * Base de datos: pagos_db (tabla pagos).
 *
 * Llamadas a otros servicios (reenviando el JWT del usuario):
 *   auth-service           -> verificar el token
 *   pedidos-service        -> consultar el pedido (monto y dueño) y marcarlo "pagado"
 *   notificaciones-service -> correo de confirmación (si falla, la compra NO se detiene)
 */
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/stripe.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = rutaActual();

/** Pide el pedido a pedidos-service. Corta con el error correspondiente si no se puede. */
function obtenerPedido(int $idPedido, string $jwt): array
{
    $r = llamarServicio('GET', getenv('PEDIDOS_URL') . "/api/pedidos/{$idPedido}", null, $jwt);
    if ($r['codigo'] === 0 || $r['codigo'] >= 500) {
        error(503, 'pedidos-service no disponible');
    }
    if ($r['codigo'] !== 200) {
        responder($r['codigo'], $r['datos']); // 401, 403 (no es el dueño) o 404
    }
    return $r['datos']['pedido'];
}

/**
 * Pago aprobado: avisa a pedidos-service (estado "pagado") y a notificaciones-service.
 * La notificación tiene un timeout corto (3 s): si falla, se registra y se continúa (D15).
 */
function confirmarPago(array $pedido, string $referencia, string $jwt): array
{
    $marcado = llamarServicio('PUT', getenv('PEDIDOS_URL') . "/api/pedidos/interno/{$pedido['id']}/pagado",
        ['referencia' => $referencia], $jwt);
    if ($marcado['codigo'] !== 200) {
        error_log("ATENCIÓN: pago {$referencia} aprobado pero pedidos-service no marcó el pedido {$pedido['id']} (HTTP {$marcado['codigo']})");
    }

    $correo = llamarServicio('POST', getenv('NOTIFICACIONES_URL') . '/api/notificaciones/interno/confirmacion-pedido', [
        'id_pedido' => $pedido['id'],
        'nombre'    => $pedido['cliente']['nombre'],
        'email'     => $pedido['cliente']['email'],
        'total'     => $pedido['total'],
        'detalle'   => $pedido['detalle'],
    ], $jwt, 3);
    $correoEnviado = $correo['codigo'] >= 200 && $correo['codigo'] < 300 && !empty($correo['datos']['enviado']);
    if ($correo['codigo'] === 0) {
        error_log("notificaciones-service no respondió; pedido {$pedido['id']} pagado sin correo");
    }

    return [
        'pedido_estado'   => $marcado['datos']['pedido']['estado'] ?? 'desconocido',
        'correo_enviado'  => $correoEnviado,
        'correo_mensaje'  => $correoEnviado ? 'Correo de confirmación enviado'
            : ($correo['codigo'] === 0 ? 'No se pudo enviar el correo: servicio de notificaciones no disponible'
                                       : ($correo['datos']['mensaje'] ?? 'No se envió el correo')),
    ];
}

function formatearPago(array $p): array
{
    return [
        'id' => (int) $p['id'], 'id_pedido' => (int) $p['id_pedido'], 'monto' => (float) $p['monto'],
        'metodo' => $p['metodo'], 'referencia_stripe' => $p['referencia_stripe'],
        'referencia_paypal' => $p['referencia_paypal'], 'estado' => $p['estado'], 'fecha_pago' => $p['fecha_pago'],
    ];
}

// =============================================================
// Rutas
// =============================================================

if ($metodo === 'GET' && in_array($ruta, ['/health', '/api/pagos/health'], true)) {
    conexion()->query('SELECT 1');
    responder(200, ['servicio' => 'pagos-service', 'estado' => 'ok']);
}

// ---------- GET /api/pagos/config — clave PÚBLICA de Stripe para Stripe.js ----------
if ($metodo === 'GET' && $ruta === '/api/pagos/config') {
    responder(200, ['stripe_public_key' => getenv('STRIPE_PUBLIC_KEY')]);
}

// ---------- POST /api/pagos/stripe ----------
// Body: { id_pedido, payment_method_id }  -> cobro nuevo (o reintento tras rechazo, D2)
//       { id_pedido, payment_intent_id }  -> confirmar tras 3D Secure resuelto en el navegador
if ($metodo === 'POST' && $ruta === '/api/pagos/stripe') {
    $usuario = usuarioAutenticado();
    $datos = cuerpoJson();
    $idPedido = filter_var($datos['id_pedido'] ?? null, FILTER_VALIDATE_INT);
    $paymentMethodId = (string) ($datos['payment_method_id'] ?? '');
    $paymentIntentId = (string) ($datos['payment_intent_id'] ?? '');
    if (!$idPedido || (!preg_match('/^pm_\w+$/', $paymentMethodId) && !preg_match('/^pi_\w+$/', $paymentIntentId))) {
        error(422, 'Se requiere id_pedido y payment_method_id (o payment_intent_id)');
    }

    // El monto se toma del pedido guardado, nunca del cliente. También valida que sea su pedido.
    $pedido = obtenerPedido($idPedido, $usuario['token']);
    if ($pedido['estado'] !== 'pendiente') {
        error(409, 'El pedido ya fue pagado', ['pedido_estado' => $pedido['estado']]);
    }

    $db = conexion();
    if ($paymentIntentId !== '') {
        // Consultar un cobro existente (después de 3D Secure)
        $r = stripe('GET', "/payment_intents/{$paymentIntentId}");
        if (($r['datos']['metadata']['id_pedido'] ?? null) !== (string) $idPedido) {
            error(422, 'El pago no corresponde a este pedido');
        }
    } else {
        $r = stripeCobrar($idPedido, $pedido['fecha'], $pedido['total'], $paymentMethodId);
    }

    if ($r['codigo'] === 0 || $r['codigo'] >= 500) {
        error(503, 'Stripe no está disponible; el pedido sigue pendiente');
    }
    $intent = $r['datos'];
    $esErrorDeTarjeta = ($intent['error']['type'] ?? '') === 'card_error';
    if ($r['codigo'] !== 200 && !$esErrorDeTarjeta) {
        error_log('Error de Stripe: ' . json_encode($intent['error'] ?? $intent));
        error(500, 'Error al comunicarse con Stripe');
    }

    // Una tarjeta rechazada viene como error; el id del PaymentIntent viene dentro del error
    $intentId = $intent['id'] ?? ($intent['error']['payment_intent']['id'] ?? null);
    $estadoStripe = $intent['status'] ?? 'requires_payment_method';
    $estadoPago = match ($estadoStripe) {
        'succeeded'               => 'aprobado',
        'requires_action',
        'processing'              => 'pendiente',
        default                   => 'rechazado',
    };

    // Se registra el intento (aprobado o rechazado) con la referencia de Stripe
    $existente = $db->prepare('SELECT id FROM pagos WHERE referencia_stripe = ?');
    $existente->execute([$intentId]);
    $idPago = $existente->fetchColumn();
    if ($idPago) {
        $db->prepare('UPDATE pagos SET estado = ?, fecha_pago = NOW() WHERE id = ?')->execute([$estadoPago, $idPago]);
    } else {
        $db->prepare('INSERT INTO pagos (id_pedido, monto, metodo, referencia_stripe, estado) VALUES (?, ?, "stripe", ?, ?)')
           ->execute([$idPedido, $pedido['total'], $intentId, $estadoPago]);
        $idPago = $db->lastInsertId();
    }
    $consulta = $db->prepare('SELECT * FROM pagos WHERE id = ?');
    $consulta->execute([$idPago]);
    $pago = formatearPago($consulta->fetch());

    if ($estadoPago === 'rechazado') {
        // D2: el pedido queda "pendiente"; el cliente puede reintentar con otra tarjeta
        error(422, 'Pago rechazado: ' . ($intent['error']['message'] ?? 'la tarjeta no fue aceptada'),
              ['pago' => $pago, 'codigo_rechazo' => $intent['error']['decline_code'] ?? $intent['error']['code'] ?? null]);
    }
    if ($estadoPago === 'pendiente') {
        // 3D Secure: el navegador completa la autenticación con este client_secret
        responder(202, ['pago' => $pago, 'requiere_accion' => true, 'client_secret' => $intent['client_secret'] ?? null]);
    }

    responder(200, ['pago' => $pago] + confirmarPago($pedido, $intentId, $usuario['token']));
}

// ---------- GET /api/pagos/{id}/estado ----------
if ($metodo === 'GET' && preg_match('#^/api/pagos/(\d+)/estado$#', $ruta, $m)) {
    $usuario = usuarioAutenticado();
    $consulta = conexion()->prepare('SELECT * FROM pagos WHERE id = ?');
    $consulta->execute([(int) $m[1]]);
    $pago = $consulta->fetch();
    if (!$pago) {
        error(404, 'Pago no encontrado');
    }
    // D16: se comprueba con pedidos-service que el usuario sea dueño del pedido (o admin)
    obtenerPedido((int) $pago['id_pedido'], $usuario['token']);
    responder(200, ['pago' => formatearPago($pago)]);
}

error(404, 'Ruta no encontrada');
