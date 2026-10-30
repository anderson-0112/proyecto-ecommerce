<?php
/**
 * notificaciones-service (puerto 8085) — correos de confirmación con EmailJS.
 * No tiene base de datos: recibe avisos internos de pagos-service.
 * El correo se envía con la API REST de EmailJS desde el servidor (D13): la
 * Private Key viaja como "accessToken" y nunca llega al navegador.
 */
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';

/**
 * Envía la plantilla de EmailJS. Devuelve [enviado (bool), mensaje].
 * Timeout corto (2.5 s): pagos-service espera como máximo 3 s a este servicio (D15).
 */
function enviarConEmailJS(array $parametrosPlantilla): array
{
    $curl = curl_init('https://api.emailjs.com/api/v1.0/email/send');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT_MS     => 2500,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'service_id'      => getenv('EMAILJS_SERVICE_ID'),
            'template_id'     => getenv('EMAILJS_TEMPLATE_ID'),
            'user_id'         => getenv('EMAILJS_PUBLIC_KEY'),
            'accessToken'     => getenv('EMAILJS_PRIVATE_KEY'),
            'template_params' => $parametrosPlantilla,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $respuesta = curl_exec($curl);
    $codigo = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $errorRed = curl_error($curl);
    curl_close($curl);

    if ($respuesta === false) {
        error_log("EmailJS no respondió: {$errorRed}");
        return [false, 'El proveedor de correo (EmailJS) no respondió'];
    }
    if ($codigo !== 200) { // EmailJS responde "OK" con 200; en error devuelve un texto explicativo
        error_log("EmailJS rechazó el envío (HTTP {$codigo}): {$respuesta}");
        return [false, "EmailJS rechazó el envío (HTTP {$codigo})"];
    }
    return [true, 'Correo de confirmación enviado'];
}

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

    $enlace = rtrim((string) getenv('APP_URL'), '/') . '/seguimiento.html?pedido=' . $idPedido;
    // Variables de la plantilla creada en EmailJS
    [$enviado, $mensaje] = enviarConEmailJS([
        'to_email'           => $email,
        'cliente_nombre'     => (string) ($datos['nombre'] ?? 'cliente'),
        'pedido_id'          => $idPedido,
        'total'              => sprintf('US$ %.2f', (float) ($datos['total'] ?? 0)),
        'enlace_seguimiento' => $enlace,
    ]);
    error_log(sprintf('[NOTIFICACIÓN] Pedido #%d -> %s: %s', $idPedido, $email, $mensaje));

    responder(200, ['registrado' => true, 'enviado' => $enviado, 'mensaje' => $mensaje]);
}

error(404, 'Ruta no encontrada');
