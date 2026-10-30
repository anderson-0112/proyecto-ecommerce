<?php
/**
 * catalogo-service (puerto 8082) — productos, categorías y stock.
 * Base de datos: catalogo_db.
 *  - Lecturas públicas desde DB_READ_HOST (réplica Slave) con caché en Redis.
 *  - Escrituras (admin) y control de stock desde DB_HOST (Master).
 */
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = rutaActual();

const CAMPOS_PRODUCTO = 'p.id, p.nombre, p.descripcion, p.precio, p.stock, p.imagen_url, p.destacado,
                         p.activo, p.categoria_id, c.nombre AS categoria';

/** Convierte los tipos de una fila de productos (MariaDB devuelve DECIMAL como texto). */
function formatearProducto(array $p): array
{
    $p['id']           = (int) $p['id'];
    $p['precio']       = (float) $p['precio'];
    $p['stock']        = (int) $p['stock'];
    $p['destacado']    = (bool) $p['destacado'];
    $p['activo']       = (bool) $p['activo'];
    $p['categoria_id'] = $p['categoria_id'] !== null ? (int) $p['categoria_id'] : null;
    return $p;
}

/** Valida los campos de un producto. $parcial = true para PUT (solo los campos enviados). */
function validarProducto(array $d, bool $parcial): array
{
    $limpio = [];
    $errores = [];
    $reglas = [
        'nombre'       => fn($v) => is_string($v) && mb_strlen(trim($v)) >= 2 && mb_strlen($v) <= 150,
        'descripcion'  => fn($v) => is_string($v) && mb_strlen($v) <= 2000,
        'precio'       => fn($v) => is_numeric($v) && $v > 0 && $v < 1000000,
        'stock'        => fn($v) => filter_var($v, FILTER_VALIDATE_INT) !== false && $v >= 0,
        'imagen_url'   => fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) && str_starts_with($v, 'http'),
        'destacado'    => fn($v) => is_bool($v) || in_array($v, [0, 1], true),
        'categoria_id' => fn($v) => filter_var($v, FILTER_VALIDATE_INT) !== false && $v > 0,
    ];
    foreach ($reglas as $campo => $esValido) {
        if (!array_key_exists($campo, $d)) {
            if (!$parcial && in_array($campo, ['nombre', 'precio', 'stock', 'categoria_id'], true)) {
                $errores[$campo] = 'Campo obligatorio';
            }
            continue;
        }
        if (!$esValido($d[$campo])) {
            $errores[$campo] = 'Valor no válido';
            continue;
        }
        $limpio[$campo] = is_string($d[$campo]) ? trim($d[$campo]) : $d[$campo];
    }
    if ($errores) {
        error(422, 'Datos del producto no válidos', ['campos' => $errores]);
    }
    if (isset($limpio['destacado'])) {
        $limpio['destacado'] = (int) (bool) $limpio['destacado'];
    }
    if (isset($limpio['categoria_id'])) {
        $c = conexion()->prepare('SELECT id FROM categorias WHERE id = ?');
        $c->execute([$limpio['categoria_id']]);
        if (!$c->fetch()) {
            error(422, 'La categoría no existe', ['campos' => ['categoria_id' => 'No existe']]);
        }
    }
    return $limpio;
}

/** Valida la lista de ítems que envía pedidos-service: [{id_producto, cantidad}]. */
function leerItems(): array
{
    $items = cuerpoJson()['items'] ?? null;
    if (!is_array($items) || $items === []) {
        error(422, 'Se requiere una lista de items');
    }
    $porProducto = [];
    foreach ($items as $item) {
        $id = filter_var($item['id_producto'] ?? null, FILTER_VALIDATE_INT);
        $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || !$cantidad || $cantidad < 1) {
            error(422, 'Cada item necesita id_producto y cantidad (entero mayor que 0)');
        }
        $porProducto[$id] = ($porProducto[$id] ?? 0) + $cantidad; // agrupa repetidos
    }
    return $porProducto;
}

// ---------------- Caché del catálogo en Redis ----------------
// Las lecturas públicas se guardan en Redis durante CACHE_TTL segundos.
// Para invalidar todo de una vez se sube "catalogo:version": las claves nuevas llevan
// la versión en el nombre, así que las viejas dejan de usarse y expiran solas.
const CACHE_TTL = 30;

function claveCache(string $nombre): ?string
{
    try {
        $version = redis()?->get('catalogo:version') ?: '0';
        return redis() ? "catalogo:v{$version}:{$nombre}" : null;
    } catch (RedisException $e) {
        return null;
    }
}

/**
 * Responde desde Redis si la consulta ya está en caché (X-Cache: HIT);
 * si no, ejecuta $consulta contra la BD (Slave), la guarda y responde (X-Cache: MISS).
 */
function responderConCache(string $nombre, callable $consulta): never
{
    $clave = claveCache($nombre);
    try {
        $guardado = $clave ? redis()->get($clave) : false;
    } catch (RedisException $e) {
        $guardado = false;
    }
    if ($guardado) {
        header('X-Cache: HIT');
        responder(200, json_decode($guardado, true));
    }
    $datos = $consulta();
    if ($clave) {
        try {
            redis()->setex($clave, CACHE_TTL, json_encode($datos, JSON_UNESCAPED_UNICODE));
        } catch (RedisException $e) {
            error_log('No se pudo guardar en caché: ' . $e->getMessage());
        }
    }
    header('X-Cache: ' . ($clave ? 'MISS' : 'SIN-REDIS'));
    responder(200, $datos);
}

/** Invalida toda la caché del catálogo (tras crear, editar, borrar o descontar stock). */
function invalidarCache(): void
{
    try {
        redis()?->incr('catalogo:version');
    } catch (RedisException $e) {
        error_log('No se pudo invalidar la caché: ' . $e->getMessage());
    }
}

// =============================================================
// Rutas
// =============================================================

if ($metodo === 'GET' && in_array($ruta, ['/health', '/api/catalogo/health'], true)) {
    $redisActivo = false;
    try {
        $redisActivo = redis()?->ping() !== null;
    } catch (RedisException $e) {
    }
    responder(200, ['servicio' => 'catalogo-service', 'estado' => 'ok',
                    'lecturas_desde' => servidorLectura(), 'redis' => $redisActivo]);
}

// ---------- GET /api/catalogo/productos?categoria=&precio_max=&pagina=&por_pagina= ----------
if ($metodo === 'GET' && $ruta === '/api/catalogo/productos') {
    $condiciones = ['p.activo = 1'];
    $parametros = [];
    if (($_GET['categoria'] ?? '') !== '') {
        $categoria = filter_var($_GET['categoria'], FILTER_VALIDATE_INT);
        if ($categoria === false) {
            error(400, 'El parámetro categoria debe ser el id numérico de la categoría');
        }
        $condiciones[] = 'p.categoria_id = ?';
        $parametros[] = $categoria;
    }
    if (($_GET['precio_max'] ?? '') !== '') {
        if (!is_numeric($_GET['precio_max'])) {
            error(400, 'El parámetro precio_max debe ser numérico');
        }
        $condiciones[] = 'p.precio <= ?';
        $parametros[] = (float) $_GET['precio_max'];
    }
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = min(50, max(1, (int) ($_GET['por_pagina'] ?? 12)));
    $donde     = implode(' AND ', $condiciones);

    $claveConsulta = 'productos:' . md5(json_encode([$parametros, $donde, $pagina, $porPagina]));
    responderConCache($claveConsulta, function () use ($donde, $parametros, $pagina, $porPagina) {
        $db = conexion('DB_READ_HOST'); // lectura desde el Slave
        $total = $db->prepare("SELECT COUNT(*) FROM productos p WHERE $donde");
        $total->execute($parametros);
        $total = (int) $total->fetchColumn();

        $offset = ($pagina - 1) * $porPagina;
        $consulta = $db->prepare("SELECT " . CAMPOS_PRODUCTO . " FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE $donde ORDER BY p.id LIMIT $porPagina OFFSET $offset");
        $consulta->execute($parametros);

        return [
            'productos'     => array_map('formatearProducto', $consulta->fetchAll()),
            'pagina'        => $pagina,
            'por_pagina'    => $porPagina,
            'total'         => $total,
            'total_paginas' => (int) ceil($total / $porPagina),
        ];
    });
}

// ---------- GET /api/catalogo/productos/{id} ----------
if ($metodo === 'GET' && preg_match('#^/api/catalogo/productos/(\d+)$#', $ruta, $m)) {
    $id = (int) $m[1];
    responderConCache("producto:{$id}", function () use ($id) {
        $consulta = conexion('DB_READ_HOST')->prepare("SELECT " . CAMPOS_PRODUCTO . " FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ? AND p.activo = 1");
        $consulta->execute([$id]);
        $producto = $consulta->fetch();
        if (!$producto) {
            error(404, 'Producto no encontrado'); // el 404 no se guarda en caché
        }
        return ['producto' => formatearProducto($producto)];
    });
}

// ---------- GET /api/catalogo/destacados ----------
if ($metodo === 'GET' && $ruta === '/api/catalogo/destacados') {
    responderConCache('destacados', function () {
        $consulta = conexion('DB_READ_HOST')->query("SELECT " . CAMPOS_PRODUCTO . " FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE p.activo = 1 AND p.destacado = 1 ORDER BY p.id");
        return ['productos' => array_map('formatearProducto', $consulta->fetchAll())];
    });
}

// ---------- GET /api/catalogo/categorias ----------
if ($metodo === 'GET' && $ruta === '/api/catalogo/categorias') {
    responderConCache('categorias', function () {
        $categorias = conexion('DB_READ_HOST')->query('SELECT id, nombre, descripcion FROM categorias ORDER BY nombre')->fetchAll();
        return ['categorias' => array_map(fn($c) => ['id' => (int) $c['id']] + $c, $categorias)];
    });
}

// ---------- POST /api/catalogo/productos (solo admin) ----------
if ($metodo === 'POST' && $ruta === '/api/catalogo/productos') {
    requiereAdmin(usuarioAutenticado()); // JWT verificado por auth-service + rol admin
    $p = validarProducto(cuerpoJson(), false);
    $db = conexion(); // escritura -> Master
    $db->prepare('INSERT INTO productos (nombre, descripcion, precio, stock, imagen_url, destacado, categoria_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$p['nombre'], $p['descripcion'] ?? '', $p['precio'], $p['stock'],
                  $p['imagen_url'] ?? null, $p['destacado'] ?? 0, $p['categoria_id']]);
    $id = (int) $db->lastInsertId();
    invalidarCache();

    $nuevo = $db->prepare("SELECT " . CAMPOS_PRODUCTO . " FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ?");
    $nuevo->execute([$id]);
    responder(201, ['producto' => formatearProducto($nuevo->fetch())]);
}

// ---------- PUT /api/catalogo/productos/{id} (solo admin) ----------
if ($metodo === 'PUT' && preg_match('#^/api/catalogo/productos/(\d+)$#', $ruta, $m)) {
    requiereAdmin(usuarioAutenticado());
    $id = (int) $m[1];
    $cambios = validarProducto(cuerpoJson(), true);
    if (!$cambios) {
        error(422, 'No se envió ningún campo para actualizar');
    }
    $db = conexion();
    $existe = $db->prepare('SELECT id FROM productos WHERE id = ? AND activo = 1');
    $existe->execute([$id]);
    if (!$existe->fetch()) {
        error(404, 'Producto no encontrado');
    }
    // Las columnas vienen de la lista blanca de validarProducto(), nunca del usuario
    $asignaciones = implode(', ', array_map(fn($campo) => "$campo = ?", array_keys($cambios)));
    $db->prepare("UPDATE productos SET $asignaciones WHERE id = ?")
       ->execute([...array_values($cambios), $id]);
    invalidarCache();

    $actual = $db->prepare("SELECT " . CAMPOS_PRODUCTO . " FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ?");
    $actual->execute([$id]);
    responder(200, ['producto' => formatearProducto($actual->fetch())]);
}

// ---------- DELETE /api/catalogo/productos/{id} (solo admin) ----------
// Borrado lógico (activo = 0): el producto deja de mostrarse pero los pedidos antiguos lo conservan
if ($metodo === 'DELETE' && preg_match('#^/api/catalogo/productos/(\d+)$#', $ruta, $m)) {
    requiereAdmin(usuarioAutenticado());
    $baja = conexion()->prepare('UPDATE productos SET activo = 0 WHERE id = ? AND activo = 1');
    $baja->execute([(int) $m[1]]);
    if ($baja->rowCount() === 0) {
        error(404, 'Producto no encontrado');
    }
    invalidarCache();
    responder(204);
}

// ---------- POST /api/catalogo/interno/verificar-stock (lo llama pedidos-service) ----------
// Devuelve nombre y precio OFICIALES de cada producto: el precio nunca se toma del cliente.
if ($metodo === 'POST' && $ruta === '/api/catalogo/interno/verificar-stock') {
    usuarioAutenticado(); // el JWT del cliente llega reenviado por pedidos-service
    $items = leerItems();
    $marcadores = implode(',', array_fill(0, count($items), '?'));
    // Se lee del Master: el stock debe estar actualizado al momento de comprar
    $consulta = conexion()->prepare("SELECT id, nombre, precio, stock FROM productos WHERE activo = 1 AND id IN ($marcadores)");
    $consulta->execute(array_keys($items));
    $productos = array_column($consulta->fetchAll(), null, 'id');

    $resultado = [];
    $sinStock = [];
    foreach ($items as $id => $cantidad) {
        if (!isset($productos[$id])) {
            error(404, "El producto {$id} no existe o no está disponible");
        }
        $p = $productos[$id];
        if ((int) $p['stock'] < $cantidad) {
            $sinStock[] = ['id_producto' => $id, 'nombre' => $p['nombre'], 'solicitado' => $cantidad, 'disponible' => (int) $p['stock']];
        }
        $resultado[] = ['id_producto' => $id, 'nombre' => $p['nombre'], 'precio' => (float) $p['precio'], 'cantidad' => $cantidad];
    }
    if ($sinStock) {
        error(409, 'Stock insuficiente', ['sin_stock' => $sinStock]);
    }
    responder(200, ['disponible' => true, 'productos' => $resultado]);
}

// ---------- POST /api/catalogo/interno/descontar-stock (lo llama pedidos-service al pagarse) ----------
if ($metodo === 'POST' && $ruta === '/api/catalogo/interno/descontar-stock') {
    usuarioAutenticado();
    $items = leerItems();
    $db = conexion();
    $db->beginTransaction();
    // "stock >= ?" en el mismo UPDATE evita que el stock quede negativo si dos compras coinciden
    $descontar = $db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?');
    foreach ($items as $id => $cantidad) {
        $descontar->execute([$cantidad, $id, $cantidad]);
        if ($descontar->rowCount() === 0) {
            $db->rollBack(); // todo o nada
            error(409, "Stock insuficiente para el producto {$id}");
        }
    }
    $db->commit();
    invalidarCache();
    responder(200, ['descontado' => true]);
}

error(404, 'Ruta no encontrada');
