<?php
/**
 * Pruebas de tolerancia a fallos (criterio 10 de la rúbrica).
 * Las ejecuta pruebas/ejecutar-pruebas.sh, que antes detiene el contenedor indicado:
 *   php prueba-tolerancia.php <escenario>
 * Escenarios: notificaciones | pagos | catalogo | slave | redis
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
    $cache = null;
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 60, CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cache) {
            if (stripos($l, 'X-Cache:') === 0) {
                $cache = trim(substr($l, 8));
            }
            return strlen($l);
        }]);
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    }
    $inicio = microtime(true);
    $r = curl_exec($c);
    return ['codigo' => (int) curl_getinfo($c, CURLINFO_HTTP_CODE), 'datos' => json_decode((string) $r, true),
            'cache' => $cache, 'segundos' => round(microtime(true) - $inicio, 2)];
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

/** Comprador nuevo en example.com (dominio de pruebas) con su token. */
function comprador(): string
{
    $correo = 'tolerancia' . time() . random_int(100, 999) . '@example.com';
    http('POST', '/api/auth/register', ['nombre' => 'Prueba Tolerancia', 'email' => $correo, 'password' => 'Prueba2026!']);
    return http('POST', '/api/auth/login', ['email' => $correo, 'password' => 'Prueba2026!'])['datos']['token'] ?? '';
}

function comprar(string $token): array
{
    return http('POST', '/api/pedidos', ['items' => [['id_producto' => 20, 'cantidad' => 1]], 'metodo_pago' => 'stripe',
        'payment_method_id' => 'pm_card_visa',
        'direccion' => ['direccion' => '4a. Calle 5-20, Zona 1', 'ciudad' => 'Santa Cruz del Quiché']], $token);
}

$escenario = $argv[1] ?? '';
echo "\n== Tolerancia: {$escenario} detenido ==\n";

switch ($escenario) {
    case 'notificaciones':
        $r = http('GET', '/api/catalogo/productos?por_pagina=1');
        comprobar('El catálogo sigue respondiendo -> 200', $r['codigo'] === 200, $r);
        $r = comprar(comprador());
        comprobar("La compra termina igual -> 201 pagado ({$r['segundos']} s)", $r['codigo'] === 201 && ($r['datos']['pedido']['estado'] ?? '') === 'pagado', $r);
        comprobar('La respuesta avisa que el correo no se envió', ($r['datos']['correo_enviado'] ?? true) === false
            && str_contains($r['datos']['correo_mensaje'] ?? '', 'no disponible'), $r['datos'] ?? null);
        break;

    case 'pagos':
        $r = http('GET', '/api/catalogo/productos?por_pagina=1');
        comprobar('El catálogo sigue respondiendo -> 200', $r['codigo'] === 200, $r);
        $r = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'Test2026!']);
        comprobar('El login sigue funcionando -> 200', $r['codigo'] === 200, $r['codigo']);
        $r = comprar(comprador());
        comprobar('Comprar -> 503 con mensaje claro', $r['codigo'] === 503, $r);
        comprobar('El pedido queda guardado como "pendiente" (se puede reintentar, D2)', ($r['datos']['pedido']['estado'] ?? '') === 'pendiente', $r['datos'] ?? null);
        break;

    case 'catalogo':
        $r = http('GET', '/api/catalogo/productos');
        comprobar('El Gateway responde 503 en JSON (no se cae)', $r['codigo'] === 503 && isset($r['datos']['error']), $r);
        $r = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'Test2026!']);
        comprobar('Login sigue funcionando -> 200', $r['codigo'] === 200, $r['codigo']);
        $r = http('GET', '/api/pedidos', null, $r['datos']['token'] ?? null);
        comprobar('El historial de pedidos sigue funcionando -> 200', $r['codigo'] === 200, $r['codigo']);
        $r = comprar(comprador());
        comprobar('Comprar -> 503 (no se puede verificar stock) sin crear el pedido', $r['codigo'] === 503 && !isset($r['datos']['pedido']), $r);
        break;

    case 'slave':
        $r = http('GET', '/api/catalogo/health');
        comprobar('catalogo-service lee del Master', str_starts_with($r['datos']['lecturas_desde'] ?? '', 'Master'), $r);
        $r = http('GET', '/api/catalogo/productos?pagina=2&por_pagina=3');
        comprobar("El catálogo sigue respondiendo -> 200 ({$r['segundos']} s)", $r['codigo'] === 200, $r);
        $r = comprar(comprador());
        comprobar('La compra termina -> 201 pagado', $r['codigo'] === 201 && ($r['datos']['pedido']['estado'] ?? '') === 'pagado', $r);
        break;

    case 'redis':
        $r = http('GET', '/api/catalogo/productos?pagina=3&por_pagina=4');
        comprobar("El catálogo responde sin caché -> 200, X-Cache: {$r['cache']}", $r['codigo'] === 200 && $r['cache'] === 'SIN-REDIS', $r);
        $token = comprador();
        comprobar('El login sigue funcionando', $token !== '');
        $r = comprar($token);
        comprobar('La compra termina -> 201 pagado', $r['codigo'] === 201 && ($r['datos']['pedido']['estado'] ?? '') === 'pagado', $r);
        $r = http('POST', '/api/auth/logout', null, $token);
        comprobar('Logout -> 503 (no se puede revocar sin Redis)', $r['codigo'] === 503, $r);
        break;

    default:
        fwrite(STDERR, "Escenario desconocido\n");
        exit(2);
}

echo "\nResultado: $ok correctas, $fallos con fallo\n";
exit($fallos > 0 ? 1 : 0);
