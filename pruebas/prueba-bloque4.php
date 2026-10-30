<?php
/**
 * Prueba automática del Bloque 4: APIs externas (EmailJS, OpenWeatherMap, Cloudinary, PayPal, QR).
 *   docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque4.php
 * La aprobación del comprador en PayPal ocurre en la ventana de PayPal (navegador), así que aquí
 * se prueba crear la orden y que no se cobre una orden sin aprobar; el flujo completo se prueba a mano.
 */
declare(strict_types=1);

$BASE = getenv('GATEWAY') ?: 'http://gateway';
$ok = 0;
$fallos = 0;

function http(string $metodo, string $ruta, mixed $cuerpo = null, ?string $token = null): array
{
    global $BASE;
    $c = curl_init(str_starts_with($ruta, 'http') ? $ruta : $BASE . $ruta);
    $h = [];
    if ($token) {
        $h[] = "Authorization: Bearer $token";
    }
    if (is_array($cuerpo) && !isset($cuerpo['imagen'])) {
        $h[] = 'Content-Type: application/json';
        $cuerpo = json_encode($cuerpo);
    }
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo); // arreglo con CURLFile = multipart
    }
    $r = curl_exec($c);
    return ['codigo' => (int) curl_getinfo($c, CURLINFO_HTTP_CODE), 'datos' => json_decode((string) $r, true),
            'tipo' => curl_getinfo($c, CURLINFO_CONTENT_TYPE)];
}

function comprobar(string $descripcion, bool $condicion, mixed $detalle = null): void
{
    global $ok, $fallos;
    $condicion ? $ok++ : $fallos++;
    echo ($condicion ? "  [OK]    " : "  [FALLO] ") . $descripcion . "\n";
    if (!$condicion && $detalle !== null) {
        echo '          ' . json_encode($detalle, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

$tokenAdmin = http('POST', '/api/auth/login', ['email' => 'admin@mitienda.com', 'password' => 'Admin2026!'])['datos']['token'];
// Comprador de prueba en example.com (dominio reservado para pruebas): EmailJS acepta el envío, nadie lo recibe
$correo = 'comprador' . time() . '@example.com';
http('POST', '/api/auth/register', ['nombre' => 'Comprador Prueba', 'email' => $correo, 'password' => 'Prueba2026!']);
$token = http('POST', '/api/auth/login', ['email' => $correo, 'password' => 'Prueba2026!'])['datos']['token'];
$direccion = ['direccion' => '4a. Calle 5-20, Zona 1', 'ciudad' => 'Santa Cruz del Quiché', 'telefono' => '5555-0000'];

echo "\n== OpenWeatherMap (clima del dashboard) ==\n";
$r = http('GET', '/api/pedidos/clima', null, $tokenAdmin);
comprobar('Admin consulta el clima -> 200 con temperatura', $r['codigo'] === 200 && is_numeric($r['datos']['temperatura'] ?? null), $r);
if ($r['codigo'] === 200) {
    echo "          {$r['datos']['ciudad']}: {$r['datos']['temperatura']} °C, {$r['datos']['descripcion']}\n";
}
$r = http('GET', '/api/pedidos/clima', null, $token);
comprobar('Cliente consulta el clima -> 403', $r['codigo'] === 403, $r);

echo "\n== Cloudinary (imágenes) ==\n";
$productos = http('GET', '/api/catalogo/productos?por_pagina=50')['datos']['productos'];
$enCloudinary = array_filter($productos, fn($p) => str_starts_with((string) $p['imagen_url'], 'https://res.cloudinary.com/'));
comprobar('Las imágenes del catálogo se sirven desde Cloudinary (' . count($enCloudinary) . '/' . count($productos) . ')',
    count($productos) >= 20 && count($enCloudinary) === count($productos));
$r = http('GET', $productos[0]['imagen_url']);
comprobar('La imagen del CDN responde 200 (' . $r['tipo'] . ')', $r['codigo'] === 200 && str_starts_with((string) $r['tipo'], 'image/'), $r['codigo']);

// PNG de 1x1 píxel para probar la subida
$png = tempnam(sys_get_temp_dir(), 'png');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$r = http('POST', '/api/catalogo/imagenes', ['imagen' => new CURLFile($png, 'image/png', 'prueba.png')], $tokenAdmin);
comprobar('Admin sube una imagen -> 201 con URL de Cloudinary', $r['codigo'] === 201 && str_starts_with($r['datos']['imagen_url'] ?? '', 'https://res.cloudinary.com/'), $r);
$r = http('POST', '/api/catalogo/imagenes', ['imagen' => new CURLFile($png, 'image/png', 'prueba.png')], $token);
comprobar('Cliente sube una imagen -> 403', $r['codigo'] === 403, $r);
$txt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($txt, 'esto no es una imagen');
$r = http('POST', '/api/catalogo/imagenes', ['imagen' => new CURLFile($txt, 'image/png', 'falsa.png')], $tokenAdmin);
comprobar('Archivo que no es imagen (aunque diga .png) -> 422', $r['codigo'] === 422, $r);

echo "\n== EmailJS (correo de confirmación) y QR ==\n";
$r = http('POST', '/api/pedidos', ['items' => [['id_producto' => 20, 'cantidad' => 1]], 'metodo_pago' => 'stripe',
    'payment_method_id' => 'pm_card_visa', 'direccion' => $direccion], $token);
comprobar('Compra con Stripe -> 201 pagado', $r['codigo'] === 201 && ($r['datos']['pedido']['estado'] ?? '') === 'pagado', $r);
comprobar('Correo enviado con EmailJS (correo_enviado = true)', ($r['datos']['correo_enviado'] ?? false) === true, $r['datos'] ?? null);
$qr = $r['datos']['pedido']['qr_url'] ?? '';
$rq = http('GET', $qr);
comprobar('QR de api.qrserver.com responde una imagen', $rq['codigo'] === 200 && str_starts_with((string) $rq['tipo'], 'image/'), $rq['codigo']);

echo "\n== PayPal Sandbox ==\n";
$r = http('GET', '/api/pagos/config');
comprobar('GET /api/pagos/config publica el Client ID de PayPal (no el secreto)',
    strlen($r['datos']['paypal_client_id'] ?? '') > 20 && !isset($r['datos']['paypal_client_secret']), array_keys($r['datos'] ?? []));
$r = http('POST', '/api/pedidos', ['items' => [['id_producto' => 18, 'cantidad' => 1]], 'metodo_pago' => 'paypal',
    'direccion' => $direccion], $token);
comprobar('Pedido con PayPal -> 202 con id de orden', $r['codigo'] === 202 && preg_match('/^[A-Z0-9]{10,25}$/', $r['datos']['paypal_orden_id'] ?? ''), $r);
comprobar('El pedido queda "pendiente" hasta que el comprador apruebe', ($r['datos']['pedido']['estado'] ?? '') === 'pendiente', $r['datos'] ?? null);
$idPedido = $r['datos']['pedido']['id'] ?? 0;
$orden = $r['datos']['paypal_orden_id'] ?? '';
$r = http('POST', '/api/pagos/paypal', ['id_pedido' => $idPedido, 'orden_id' => $orden], $token);
comprobar('Capturar sin aprobación del comprador -> 422 (no se cobra)', $r['codigo'] === 422 && str_contains($r['datos']['error'] ?? '', 'ORDER_NOT_APPROVED'), $r);
$r = http('POST', '/api/pagos/paypal', ['id_pedido' => $idPedido, 'orden_id' => 'ABCDEFGHIJ1234567'], $token);
comprobar('Orden que no corresponde al pedido -> 404', $r['codigo'] === 404, $r);
$otro = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'Test2026!'])['datos']['token'];
$r = http('POST', '/api/pagos/paypal', ['id_pedido' => $idPedido], $otro);
comprobar('Otro cliente intenta pagar el pedido -> 403', $r['codigo'] === 403, $r);

echo "\nResultado: $ok correctas, $fallos con fallo\n";
exit($fallos > 0 ? 1 : 0);
