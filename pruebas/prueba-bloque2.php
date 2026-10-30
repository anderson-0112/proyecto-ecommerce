<?php
/**
 * Prueba automática del Bloque 2: replicación Master-Slave y Redis.
 *   docker run --rm --network ecommerce -v "${PWD}/pruebas:/pruebas" php:8.3-apache php /pruebas/prueba-bloque2.php
 * (Las pruebas de caída del Slave y de Redis se hacen deteniendo contenedores: ver docs/guia-defensa.md)
 */
declare(strict_types=1);

$BASE = getenv('GATEWAY') ?: 'http://gateway';
$ok = 0;
$fallos = 0;

/** Igual que en el Bloque 1, pero también devuelve el encabezado X-Cache. */
function http(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
{
    global $BASE;
    $c = curl_init($BASE . $ruta);
    $h = ['Content-Type: application/json'];
    if ($token) {
        $h[] = "Authorization: Bearer $token";
    }
    $cache = null;
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$cache) {
            if (stripos($linea, 'X-Cache:') === 0) {
                $cache = trim(substr($linea, 8));
            }
            return strlen($linea);
        }]);
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    }
    $r = curl_exec($c);
    return ['codigo' => (int) curl_getinfo($c, CURLINFO_HTTP_CODE), 'datos' => json_decode((string) $r, true), 'cache' => $cache];
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

/** Repite la lectura hasta que se cumpla la condición (la réplica tarda milisegundos). */
function esperar(callable $condicion, float $maxSegundos = 5): bool
{
    $limite = microtime(true) + $maxSegundos;
    do {
        if ($condicion()) {
            return true;
        }
        usleep(200000);
    } while (microtime(true) < $limite);
    return false;
}

$tokenAdmin = http('POST', '/api/auth/login', ['email' => 'admin@mitienda.com', 'password' => 'Admin2026!'])['datos']['token'];

echo "\n== Lecturas desde el Slave ==\n";
$r = http('GET', '/api/catalogo/health');
comprobar('catalogo-service lee del Slave (server_id=2)', str_starts_with($r['datos']['lecturas_desde'] ?? '', 'Slave'), $r);
comprobar('catalogo-service conectado a Redis', ($r['datos']['redis'] ?? false) === true, $r);

echo "\n== Replicación: escribir en Master, leer en Slave ==\n";
$r = http('POST', '/api/catalogo/productos', ['nombre' => 'Producto replicado de prueba', 'precio' => 15, 'stock' => 4, 'categoria_id' => 7], $tokenAdmin);
comprobar('Admin crea producto (INSERT en el Master) -> 201', $r['codigo'] === 201, $r);
$id = $r['datos']['producto']['id'] ?? 0;
$t0 = microtime(true);
$visible = esperar(fn() => http('GET', "/api/catalogo/productos/$id")['codigo'] === 200);
comprobar(sprintf('El producto se lee desde el Slave (%.2f s)', microtime(true) - $t0), $visible);

echo "\n== Caché de catálogo en Redis ==\n";
$ruta = "/api/catalogo/productos/$id";
http('GET', $ruta); // asegura que quede en caché
$r = http('GET', $ruta);
comprobar('Segunda lectura sale de la caché (X-Cache: HIT)', $r['cache'] === 'HIT', $r['cache']);
$r = http('PUT', $ruta, ['precio' => 19.99], $tokenAdmin);
comprobar('Admin cambia el precio -> 200', $r['codigo'] === 200, $r);
$r = http('GET', $ruta);
comprobar('Tras el cambio la caché se invalida (X-Cache: MISS)', $r['cache'] === 'MISS', $r['cache']);
$actualizado = esperar(fn() => (http('GET', $ruta)['datos']['producto']['precio'] ?? 0) == 19.99);
comprobar('El precio nuevo se ve en la tienda', $actualizado);
http('DELETE', $ruta, null, $tokenAdmin);

echo "\n== Logout y tokens revocados ==\n";
$r = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'Test2026!']);
$token = $r['datos']['token'];
$r = http('GET', '/api/pedidos', null, $token);
comprobar('Con el token activo, GET /api/pedidos -> 200', $r['codigo'] === 200, $r);
$r = http('POST', '/api/auth/logout', null, $token);
comprobar('POST /api/auth/logout -> 204', $r['codigo'] === 204, $r);
$r = http('GET', '/api/pedidos', null, $token);
comprobar('El mismo token ya no sirve (revocado en Redis) -> 401', $r['codigo'] === 401, $r);
$r = http('POST', '/api/auth/logout', null, null);
comprobar('Logout sin token -> 401', $r['codigo'] === 401, $r);
$r = http('POST', '/api/auth/login', ['email' => 'cliente@test.com', 'password' => 'Test2026!']);
$r = http('GET', '/api/pedidos', null, $r['datos']['token']);
comprobar('Un login nuevo genera un token válido -> 200', $r['codigo'] === 200, $r);

echo "\n== Reportes del admin desde el Slave ==\n";
$r = http('GET', '/api/pedidos?desde=2026-01-01', null, $tokenAdmin);
comprobar('Admin lista pedidos con filtro de fecha -> 200', $r['codigo'] === 200 && is_array($r['datos']['pedidos'] ?? null), $r);
$r = http('GET', '/api/pedidos?desde=ayer', null, $tokenAdmin);
comprobar('Fecha con formato incorrecto -> 400', $r['codigo'] === 400, $r);

echo "\nResultado: $ok correctas, $fallos con fallo\n";
exit($fallos > 0 ? 1 : 0);
