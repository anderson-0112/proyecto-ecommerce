<?php
/**
 * Prueba automática del Bloque 1 (flujo de compra a través del API Gateway).
 * Se ejecuta en un contenedor dentro de la red de Docker:
 *   docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque1.php
 *
 * Para el cobro usa los PaymentMethods de prueba de Stripe:
 *   pm_card_visa            = tarjeta 4242 4242 4242 4242 (aprobada)
 *   pm_card_chargeDeclined  = tarjeta 4000 0000 0000 0002 (rechazada)
 * (La tarjeta escrita a mano se prueba desde el navegador en /prueba-compra.html.)
 */
declare(strict_types=1);

$BASE = getenv('GATEWAY') ?: 'http://gateway';
$ok = 0;
$fallos = 0;

function http(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
{
    global $BASE;
    $c = curl_init($BASE . $ruta);
    $h = ['Content-Type: application/json'];
    if ($token) {
        $h[] = "Authorization: Bearer $token";
    }
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    }
    $r = curl_exec($c);
    return ['codigo' => (int) curl_getinfo($c, CURLINFO_HTTP_CODE), 'datos' => json_decode((string) $r, true)];
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

$direccion = ['direccion' => '4a. Calle 5-20, Zona 1', 'ciudad' => 'Santa Cruz del Quiché', 'telefono' => '5555-0000'];

echo "\n== Catálogo (sin registro) ==\n";
$r = http('GET', '/api/catalogo/productos?por_pagina=50');
comprobar('GET /api/catalogo/productos -> 200 con 20+ productos', $r['codigo'] === 200 && $r['datos']['total'] >= 20, $r);
$r = http('GET', '/api/catalogo/productos?categoria=1&precio_max=750');
comprobar('Filtros categoria + precio_max', $r['codigo'] === 200 && count(array_filter($r['datos']['productos'], fn($p) => $p['categoria_id'] !== 1 || $p['precio'] > 750)) === 0, $r);
$r = http('GET', '/api/catalogo/destacados');
comprobar('GET /api/catalogo/destacados -> 200', $r['codigo'] === 200 && count($r['datos']['productos']) > 0, $r);
$r = http('GET', '/api/catalogo/productos/999999');
comprobar('Producto inexistente -> 404', $r['codigo'] === 404, $r);

echo "\n== Autenticación ==\n";
$correo = 'prueba' . time() . '@test.com';
$r = http('POST', '/api/auth/register', ['nombre' => 'Usuario Prueba', 'email' => $correo, 'password' => 'Prueba2026!']);
comprobar('Registro -> 201', $r['codigo'] === 201, $r);
$r = http('POST', '/api/auth/register', ['nombre' => 'Usuario Prueba', 'email' => $correo, 'password' => 'Prueba2026!']);
comprobar('Correo repetido -> 409', $r['codigo'] === 409, $r);
$r = http('POST', '/api/auth/register', ['nombre' => 'X', 'email' => 'no-es-correo', 'password' => '123']);
comprobar('Datos no válidos -> 422', $r['codigo'] === 422, $r);
$r = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'mala']);
comprobar('Contraseña incorrecta -> 401', $r['codigo'] === 401, $r);
$r = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'Test2026!']);
comprobar('Login cliente -> 200 + JWT (2 h)', $r['codigo'] === 200 && $r['datos']['expira_en'] === 7200, $r);
$tokenCliente = $r['datos']['token'] ?? '';
$r = http('POST', '/api/auth/login', ['email' => 'admin@mitienda.com', 'password' => 'Admin2026!']);
comprobar('Login admin -> 200 + JWT (8 h)', $r['codigo'] === 200 && $r['datos']['expira_en'] === 28800, $r);
$tokenAdmin = $r['datos']['token'] ?? '';
$r = http('POST', '/api/auth/login', ['email' => $correo, 'password' => 'Prueba2026!']);
$tokenOtro = $r['datos']['token'] ?? '';
$r = http('POST', '/api/auth/verify', null, $tokenCliente);
comprobar('/api/auth/verify bloqueado desde el Gateway -> 403', $r['codigo'] === 403, $r);

// Token alterado: se cambia el rol en el payload sin poder recalcular la firma
[$h, $p, $f] = explode('.', $tokenCliente);
$payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
$payload['rol'] = 'admin';
$falso = $h . '.' . rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.' . $f;
$r = http('POST', '/api/catalogo/productos', ['nombre' => 'Hack', 'precio' => 1, 'stock' => 1, 'categoria_id' => 1], $falso);
comprobar('Token con rol alterado -> 401', $r['codigo'] === 401, $r);

echo "\n== Permisos ==\n";
$r = http('POST', '/api/pedidos', ['items' => [['id_producto' => 1, 'cantidad' => 1]]]);
comprobar('Pedido sin token -> 401', $r['codigo'] === 401, $r);
$r = http('POST', '/api/catalogo/productos', ['nombre' => 'X', 'precio' => 1, 'stock' => 1, 'categoria_id' => 1], $tokenCliente);
comprobar('Cliente intenta crear producto -> 403', $r['codigo'] === 403, $r);
$r = http('PUT', '/api/pedidos/interno/1/pagado', [], $tokenCliente);
comprobar('Ruta interna "marcar pagado" bloqueada desde el Gateway -> 403', $r['codigo'] === 403, $r);

echo "\n== CRUD de catálogo (admin) ==\n";
$r = http('POST', '/api/catalogo/productos', ['nombre' => 'Producto temporal de prueba', 'precio' => 10.5, 'stock' => 3, 'categoria_id' => 7], $tokenAdmin);
comprobar('Admin crea producto -> 201', $r['codigo'] === 201, $r);
$idTemporal = $r['datos']['producto']['id'] ?? 0;
$r = http('PUT', "/api/catalogo/productos/$idTemporal", ['precio' => 12], $tokenAdmin);
comprobar('Admin actualiza precio -> 200', $r['codigo'] === 200 && $r['datos']['producto']['precio'] == 12, $r);
$r = http('DELETE', "/api/catalogo/productos/$idTemporal", null, $tokenAdmin);
comprobar('Admin elimina (baja lógica) -> 204', $r['codigo'] === 204, $r);
$r = http('GET', "/api/catalogo/productos/$idTemporal");
comprobar('Producto dado de baja ya no se muestra -> 404', $r['codigo'] === 404, $r);

echo "\n== Compra ==\n";
$r = http('POST', '/api/pedidos', ['items' => [['id_producto' => 6, 'cantidad' => 999]], 'payment_method_id' => 'pm_card_visa', 'direccion' => $direccion], $tokenCliente);
comprobar('Cantidad mayor al stock -> 409 (D3)', $r['codigo'] === 409, $r);

$stockAntes = http('GET', '/api/catalogo/productos/12')['datos']['producto']['stock'];
$r = http('POST', '/api/pedidos', [
    'items' => [['id_producto' => 12, 'cantidad' => 2], ['id_producto' => 20, 'cantidad' => 1]],
    'metodo_pago' => 'stripe', 'payment_method_id' => 'pm_card_visa', 'direccion' => $direccion,
], $tokenCliente);
comprobar('Compra con tarjeta 4242 (pm_card_visa) -> 201', $r['codigo'] === 201, $r);
$pedido = $r['datos']['pedido'] ?? [];
comprobar('Pedido en estado "pagado"', ($pedido['estado'] ?? '') === 'pagado', $r);
comprobar('Total calculado con precios del catálogo (2 x 99 + 80 = 278)', ($pedido['total'] ?? 0) == 278, $pedido);
comprobar('Referencia de Stripe guardada (pi_...)', str_starts_with($r['datos']['pago']['referencia_stripe'] ?? '', 'pi_'), $r['datos']['pago'] ?? null);
comprobar('QR de seguimiento generado', str_contains($pedido['qr_url'] ?? '', 'api.qrserver.com'), $pedido);
comprobar('La respuesta informa del correo (correo_mensaje)', isset($r['datos']['correo_mensaje']), $r['datos']);
$stockDespues = http('GET', '/api/catalogo/productos/12')['datos']['producto']['stock'];
comprobar("Stock descontado (D20): $stockAntes -> $stockDespues", $stockDespues === $stockAntes - 2);
$idPedido = $pedido['id'] ?? 0;
$idPago = $r['datos']['pago']['id'] ?? 0;

$r = http('GET', "/api/pedidos/$idPedido", null, $tokenCliente);
comprobar('Dueño consulta su pedido -> 200', $r['codigo'] === 200, $r);
$r = http('GET', "/api/pedidos/$idPedido", null, $tokenOtro);
comprobar('Otro cliente consulta el pedido -> 403 (D16)', $r['codigo'] === 403, $r);
$r = http('GET', "/api/pagos/$idPago/estado", null, $tokenCliente);
comprobar('GET /api/pagos/{id}/estado -> aprobado', $r['codigo'] === 200 && $r['datos']['pago']['estado'] === 'aprobado', $r);
$r = http('GET', "/api/pagos/$idPago/estado", null, $tokenOtro);
comprobar('Estado de pago ajeno -> 403', $r['codigo'] === 403, $r);
$r = http('POST', '/api/pagos/stripe', ['id_pedido' => $idPedido, 'payment_method_id' => 'pm_card_visa'], $tokenCliente);
comprobar('Pagar dos veces el mismo pedido -> 409', $r['codigo'] === 409, $r);

echo "\n== Pago rechazado y reintento (D2) ==\n";
$r = http('POST', '/api/pedidos', ['items' => [['id_producto' => 18, 'cantidad' => 1]], 'payment_method_id' => 'pm_card_chargeDeclined', 'direccion' => $direccion], $tokenCliente);
comprobar('Tarjeta rechazada -> 422', $r['codigo'] === 422, $r);
comprobar('El pedido queda "pendiente"', ($r['datos']['pedido']['estado'] ?? '') === 'pendiente', $r['datos'] ?? null);
$idRechazado = $r['datos']['pedido']['id'] ?? 0;
$r = http('POST', '/api/pagos/stripe', ['id_pedido' => $idRechazado, 'payment_method_id' => 'pm_card_visa'], $tokenCliente);
comprobar('Reintento con 4242 -> 200 y pedido "pagado"', $r['codigo'] === 200 && $r['datos']['pedido_estado'] === 'pagado', $r);

echo "\n== Estados del pedido (admin) ==\n";
$r = http('PUT', "/api/pedidos/$idPedido/estado", ['estado' => 'preparando'], $tokenCliente);
comprobar('Cliente cambia estado -> 403', $r['codigo'] === 403, $r);
$r = http('PUT', "/api/pedidos/$idPedido/estado", ['estado' => 'entregado'], $tokenAdmin);
comprobar('Saltar de "pagado" a "entregado" -> 409', $r['codigo'] === 409, $r);
foreach (['preparando', 'enviado', 'entregado'] as $estado) {
    $r = http('PUT', "/api/pedidos/$idPedido/estado", ['estado' => $estado], $tokenAdmin);
    comprobar("Admin avanza a \"$estado\" -> 200", $r['codigo'] === 200 && $r['datos']['pedido']['estado'] === $estado, $r);
}
$r = http('GET', '/api/pedidos', null, $tokenAdmin);
comprobar('Admin ve el historial de todos los pedidos', $r['codigo'] === 200 && count($r['datos']['pedidos']) >= 2, $r);

echo "\nResultado: $ok correctas, $fallos con fallo\n";
exit($fallos > 0 ? 1 : 0);
