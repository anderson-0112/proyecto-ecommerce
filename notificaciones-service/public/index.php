<?php
/**
 * notificaciones-service (puerto 8085) — correos de confirmación.
 * No tiene base de datos: recibe avisos internos de pagos-service.
 * Bloque 1: valida el JWT, registra el aviso en el log y responde.
 * Bloque 4: envío real del correo con la API REST de EmailJS.
 */
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = rutaActual();

if ($metodo === 'GET' && in_array($ruta, ['/health', '/api/notificaciones/health'], true)) {
    responder(200, ['servicio' => 'notificaciones-service', 'estado' => 'ok']);
}

// ---------- POST /api/notificaciones/interno/confirmacion-pedido (lo llama pagos-service) ----------
if ($metodo === 'POST' && $ruta === '/api/notificaciones/interno/confirmacion-pedido') {
    usuarioAutenticado(); // JWT reenviado por pagos-service, verificado en auth-service
    $datos = cuerpoJson();
    $email = (string) ($datos['email'] ?? '');
    $idPedido = filter_var($datos['id_pedido'] ?? null, FILTER_VALIDATE_INT);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$idPedido) {
        error(422, 'Se requiere email e id_pedido válidos');
    }

    // Queda registrado en el log del contenedor: docker compose logs notificaciones-service
    error_log(sprintf('[NOTIFICACIÓN] Pedido #%d pagado — confirmación para %s (total US$ %.2f)',
        $idPedido, $email, (float) ($datos['total'] ?? 0)));

    responder(200, [
        'registrado' => true,
        'enviado'    => false,
        'mensaje'    => 'Aviso registrado; el envío real por EmailJS se activa en el Bloque 4',
    ]);
}

error(404, 'Ruta no encontrada');
