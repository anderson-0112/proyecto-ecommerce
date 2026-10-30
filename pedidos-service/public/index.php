<?php
/**
 * pedidos-service (puerto 8083) — creación de pedidos, estados e historial.
 * Base de datos: pedidos_db (clientes, direcciones, pedidos, detalle_pedidos).
 *
 * Llamadas a otros servicios (todas reenvían el JWT del usuario):
 *   auth-service     -> verificar el token
 *   catalogo-service -> verificar stock y precios / descontar stock al pagarse
 *   pagos-service    -> cobrar el pedido (Stripe)
 */
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';

// Flujo de estados (D1): "pagado" lo pone el sistema; el admin avanza uno a uno
const SIGUIENTE_ESTADO = ['pagado' => 'preparando', 'preparando' => 'enviado', 'enviado' => 'entregado'];

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = rutaActual();

/** Carga un pedido con su detalle, cliente y dirección. null si no existe. */
function cargarPedido(int $id): ?array
{
    $db = conexion();
    $consulta = $db->prepare('SELECT p.id, p.id_cliente, p.total, p.estado, p.fecha,
            c.id_usuario, c.nombre AS cliente_nombre, c.email AS cliente_email, c.telefono AS cliente_telefono
        FROM pedidos p JOIN clientes c ON c.id = p.id_cliente WHERE p.id = ?');
    $consulta->execute([$id]);
    $p = $consulta->fetch();
    if (!$p) {
        return null;
    }

    $detalle = $db->prepare('SELECT id_producto, nombre_producto, cantidad, precio_unitario, subtotal
        FROM detalle_pedidos WHERE id_pedido = ? ORDER BY id');
    $detalle->execute([$id]);

    // Última dirección registrada del cliente (direcciones se relaciona con clientes, D5)
    $direccion = $db->prepare('SELECT direccion, ciudad, telefono FROM direcciones WHERE id_cliente = ? ORDER BY id DESC LIMIT 1');
    $direccion->execute([$p['id_cliente']]);

    $pedido = [
        'id'         => (int) $p['id'],
        'estado'     => $p['estado'],
        'total'      => (float) $p['total'],
        'fecha'      => $p['fecha'],
        'id_usuario' => (int) $p['id_usuario'],
        'cliente'    => [
            'id' => (int) $p['id_cliente'], 'nombre' => $p['cliente_nombre'],
            'email' => $p['cliente_email'], 'telefono' => $p['cliente_telefono'],
        ],
        'direccion'  => $direccion->fetch() ?: null,
        'detalle'    => array_map(fn($d) => [
            'id_producto'     => (int) $d['id_producto'],
            'nombre_producto' => $d['nombre_producto'],
            'cantidad'        => (int) $d['cantidad'],
            'precio_unitario' => (float) $d['precio_unitario'],
            'subtotal'        => (float) $d['subtotal'],
        ], $detalle->fetchAll()),
    ];

    // QR de seguimiento (API externa api.qrserver.com) una vez pagado (D11)
    if ($pedido['estado'] !== 'pendiente') {
        $enlace = rtrim((string) getenv('APP_URL'), '/') . '/seguimiento.html?pedido=' . $pedido['id'];
        $pedido['qr_url'] = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($enlace);
    }
    return $pedido;
}

/** D16: solo el dueño del pedido o un admin pueden verlo. */
function verificarAcceso(array $pedido, array $usuario): void
{
    if ($usuario['rol'] !== 'admin' && $pedido['id_usuario'] !== (int) $usuario['id']) {
        error(403, 'No tiene permiso para ver este pedido');
    }
}

/** Busca el cliente asociado al usuario de auth (D6) o lo crea. Devuelve su id. */
function obtenerCliente(array $usuario, ?string $telefono): int
{
    $db = conexion();
    $consulta = $db->prepare('SELECT id FROM clientes WHERE id_usuario = ?');
    $consulta->execute([$usuario['id']]);
    $id = $consulta->fetchColumn();
    if ($id) {
        if ($telefono) {
            $db->prepare('UPDATE clientes SET telefono = ? WHERE id = ?')->execute([$telefono, $id]);
        }
        return (int) $id;
    }
    $db->prepare('INSERT INTO clientes (id_usuario, nombre, email, telefono) VALUES (?, ?, ?, ?)')
       ->execute([$usuario['id'], $usuario['nombre'], $usuario['email'], $telefono]);
    return (int) $db->lastInsertId();
}

// =============================================================
// Rutas
// =============================================================

if ($metodo === 'GET' && in_array($ruta, ['/health', '/api/pedidos/health'], true)) {
    conexion()->query('SELECT 1');
    responder(200, ['servicio' => 'pedidos-service', 'estado' => 'ok']);
}

// ---------- POST /api/pedidos — crear pedido y cobrarlo ----------
if ($metodo === 'POST' && $ruta === '/api/pedidos') {
    $usuario = usuarioAutenticado();
    $datos = cuerpoJson();

    $metodoPago = $datos['metodo_pago'] ?? 'stripe';
    $paymentMethodId = (string) ($datos['payment_method_id'] ?? '');
    $dir = $datos['direccion'] ?? [];
    $errores = [];
    if (!in_array($metodoPago, ['stripe', 'paypal'], true)) {
        $errores['metodo_pago'] = 'Método de pago no válido (stripe o paypal)';
    }
    // La tarjeta se tokeniza en el navegador con Stripe.js: aquí solo llega el id "pm_..."
    if ($metodoPago === 'stripe' && !preg_match('/^pm_\w+$/', $paymentMethodId)) {
        $errores['payment_method_id'] = 'Se requiere el payment_method_id generado por Stripe.js';
    }
    if (mb_strlen(trim((string) ($dir['direccion'] ?? ''))) < 5) {
        $errores['direccion.direccion'] = 'Dirección de entrega obligatoria';
    }
    if (mb_strlen(trim((string) ($dir['ciudad'] ?? ''))) < 2) {
        $errores['direccion.ciudad'] = 'Ciudad obligatoria';
    }
    if ($errores) {
        error(422, 'Datos del pedido no válidos', ['campos' => $errores]);
    }

    // 1) Llamada interna a catalogo-service: verifica stock y obtiene precios oficiales
    $stock = llamarServicio('POST', getenv('CATALOGO_URL') . '/api/catalogo/interno/verificar-stock',
        ['items' => $datos['items'] ?? null], $usuario['token']);
    if ($stock['codigo'] === 0 || $stock['codigo'] >= 500) {
        error(503, 'El catálogo no está disponible; intente de nuevo');
    }
    if ($stock['codigo'] !== 200) {
        // 409 sin stock (D3), 404 producto inexistente, 422 items mal formados
        responder($stock['codigo'], $stock['datos']);
    }
    $productos = $stock['datos']['productos'];

    // 2) Guardar cliente, dirección, pedido "pendiente" y su detalle en una transacción
    $db = conexion();
    $db->beginTransaction();
    $telefono = trim((string) ($dir['telefono'] ?? '')) ?: null;
    $idCliente = obtenerCliente($usuario, $telefono);
    $db->prepare('INSERT INTO direcciones (id_cliente, direccion, ciudad, telefono) VALUES (?, ?, ?, ?)')
       ->execute([$idCliente, trim($dir['direccion']), trim($dir['ciudad']), $telefono]);

    $total = 0.0;
    foreach ($productos as $p) {
        $total += round($p['precio'] * $p['cantidad'], 2);
    }
    $db->prepare('INSERT INTO pedidos (id_cliente, total, estado) VALUES (?, ?, "pendiente")')
       ->execute([$idCliente, $total]);
    $idPedido = (int) $db->lastInsertId();

    $insertarDetalle = $db->prepare('INSERT INTO detalle_pedidos
        (id_pedido, id_producto, nombre_producto, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($productos as $p) {
        $insertarDetalle->execute([$idPedido, $p['id_producto'], $p['nombre'], $p['cantidad'],
                                   $p['precio'], round($p['precio'] * $p['cantidad'], 2)]);
    }
    // Se confirma ANTES de llamar a pagos: pagos-service consultará este pedido
    $db->commit();

    // 3) Llamada interna a pagos-service para cobrar (D-PAGO opción B).
    //    Stripe cobra en esta misma llamada; PayPal crea la orden (202) y el cobro se
    //    completa cuando el comprador la aprueba (el navegador llama a POST /api/pagos/paypal).
    $pago = $metodoPago === 'stripe'
        ? llamarServicio('POST', getenv('PAGOS_URL') . '/api/pagos/stripe',
            ['id_pedido' => $idPedido, 'payment_method_id' => $paymentMethodId], $usuario['token'], 40)
        : llamarServicio('POST', getenv('PAGOS_URL') . '/api/pagos/paypal',
            ['id_pedido' => $idPedido], $usuario['token'], 40);

    $pedido = cargarPedido($idPedido);
    if ($pago['codigo'] === 0 || $pago['codigo'] >= 500) {
        // pagos-service caído o error con Stripe: el pedido queda "pendiente" y se puede reintentar (D2)
        error(503, 'No se pudo procesar el pago en este momento. Su pedido quedó pendiente de pago.',
              ['pedido' => $pedido]);
    }
    // Se combina el pedido con la respuesta de pagos-service (pago, correo_enviado, correo_mensaje...)
    if ($pago['codigo'] !== 200) {
        // 422 pago rechazado (D2), 202 requiere 3D Secure (client_secret) o aprobación en PayPal
        responder($pago['codigo'], ['pedido' => $pedido] + $pago['datos']);
    }
    responder(201, ['pedido' => $pedido] + $pago['datos']);
}

// ---------- GET /api/pedidos/clima (solo admin) — clima del día para las entregas ----------
// API externa OpenWeatherMap. La clave queda en el servidor; la respuesta se guarda
// 10 minutos en un archivo temporal para no gastar el límite gratuito (1,000 consultas/día).
if ($metodo === 'GET' && $ruta === '/api/pedidos/clima') {
    requiereAdmin(usuarioAutenticado());
    $cache = sys_get_temp_dir() . '/clima.json';
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        responder(200, json_decode((string) file_get_contents($cache), true) + ['cache' => true]);
    }
    $url = 'https://api.openweathermap.org/data/2.5/weather?' . http_build_query([
        'lat' => getenv('CLIMA_LAT'), 'lon' => getenv('CLIMA_LON'),
        'units' => 'metric', 'lang' => 'es', 'appid' => getenv('OPENWEATHER_API_KEY'),
    ]);
    $r = llamarServicio('GET', $url, null, null, 5);
    if ($r['codigo'] !== 200) {
        error_log("OpenWeatherMap respondió HTTP {$r['codigo']}");
        error(503, 'El servicio de clima no está disponible');
    }
    $d = $r['datos'];
    $clima = [
        'ciudad'      => getenv('CLIMA_CIUDAD') ?: $d['name'],
        'temperatura' => round($d['main']['temp'], 1),
        'sensacion'   => round($d['main']['feels_like'], 1),
        'humedad'     => $d['main']['humidity'],
        'viento_kmh'  => round($d['wind']['speed'] * 3.6, 1),
        'descripcion' => $d['weather'][0]['description'],
        'icono_url'   => "https://openweathermap.org/img/wn/{$d['weather'][0]['icon']}@2x.png",
        'lluvia'      => isset($d['rain']),
        'hora'        => date('c', $d['dt']),
    ];
    file_put_contents($cache, json_encode($clima, JSON_UNESCAPED_UNICODE));
    responder(200, $clima + ['cache' => false]);
}

// ---------- GET /api/pedidos — historial (cliente: los suyos; admin: todos) ----------
if ($metodo === 'GET' && $ruta === '/api/pedidos') {
    $usuario = usuarioAutenticado();
    $sql = 'SELECT p.id, p.total, p.estado, p.fecha, c.nombre AS cliente, c.email
            FROM pedidos p JOIN clientes c ON c.id = p.id_cliente';
    $parametros = [];
    if ($usuario['rol'] !== 'admin') {
        $sql .= ' WHERE c.id_usuario = ?';
        $parametros[] = $usuario['id'];
    }
    if (($_GET['estado'] ?? '') !== '') {
        $sql .= $parametros ? ' AND' : ' WHERE';
        $sql .= ' p.estado = ?';
        $parametros[] = $_GET['estado'];
    }
    if (($_GET['desde'] ?? '') !== '') { // fecha AAAA-MM-DD (reportes del dashboard)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'])) {
            error(400, 'El parámetro desde debe tener formato AAAA-MM-DD');
        }
        $sql .= $parametros ? ' AND' : ' WHERE';
        $sql .= ' p.fecha >= ?';
        $parametros[] = $_GET['desde'];
    }
    // D9: el listado del admin alimenta los reportes/dashboard y se lee del Slave;
    // el cliente lee del Master para ver su compra recién hecha sin retraso de réplica.
    $esAdmin = $usuario['rol'] === 'admin';
    $db = $esAdmin ? conexion('DB_READ_HOST') : conexion();
    $consulta = $db->prepare($sql . ' ORDER BY p.id DESC LIMIT ' . ($esAdmin ? 2000 : 200));
    $consulta->execute($parametros);
    $pedidos = array_map(fn($p) => ['total' => (float) $p['total'], 'id' => (int) $p['id']] + $p, $consulta->fetchAll());
    responder(200, ['pedidos' => $pedidos]);
}

// ---------- GET /api/pedidos/{id} ----------
if ($metodo === 'GET' && preg_match('#^/api/pedidos/(\d+)$#', $ruta, $m)) {
    $usuario = usuarioAutenticado();
    $pedido = cargarPedido((int) $m[1]);
    if (!$pedido) {
        error(404, 'Pedido no encontrado');
    }
    verificarAcceso($pedido, $usuario);
    responder(200, ['pedido' => $pedido]);
}

// ---------- PUT /api/pedidos/{id}/estado (solo admin) ----------
if ($metodo === 'PUT' && preg_match('#^/api/pedidos/(\d+)/estado$#', $ruta, $m)) {
    requiereAdmin(usuarioAutenticado());
    $nuevo = (string) (cuerpoJson()['estado'] ?? '');
    if (!in_array($nuevo, ['pendiente', 'pagado', 'preparando', 'enviado', 'entregado'], true)) {
        error(422, 'Estado no válido (pendiente, preparando, enviado, entregado)');
    }
    $pedido = cargarPedido((int) $m[1]);
    if (!$pedido) {
        error(404, 'Pedido no encontrado');
    }
    $permitido = SIGUIENTE_ESTADO[$pedido['estado']] ?? null;
    if ($nuevo !== $permitido) {
        $msg = $pedido['estado'] === 'pendiente'
            ? 'El pedido aún no está pagado; el estado "pagado" lo asigna el sistema al confirmarse el pago'
            : "Transición no permitida: de \"{$pedido['estado']}\" solo se puede pasar a \"" . ($permitido ?? 'ninguno') . '"';
        error(409, $msg);
    }
    conexion()->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute([$nuevo, $pedido['id']]);
    responder(200, ['pedido' => cargarPedido($pedido['id'])]);
}

// ---------- PUT /api/pedidos/interno/{id}/pagado (lo llama pagos-service) ----------
// El Gateway bloquea /interno/: un cliente no puede marcar su pedido como pagado.
if ($metodo === 'PUT' && preg_match('#^/api/pedidos/interno/(\d+)/pagado$#', $ruta, $m)) {
    $usuario = usuarioAutenticado();
    $pedido = cargarPedido((int) $m[1]);
    if (!$pedido) {
        error(404, 'Pedido no encontrado');
    }
    if ($pedido['estado'] !== 'pendiente') {
        responder(200, ['pedido' => $pedido]); // idempotente: ya estaba pagado
    }

    // Cambio de estado condicionado: solo pasa a "pagado" si sigue "pendiente"
    $cambio = conexion()->prepare('UPDATE pedidos SET estado = "pagado" WHERE id = ? AND estado = "pendiente"');
    $cambio->execute([$pedido['id']]);

    // D20: al confirmarse el pago se descuenta el stock en catalogo-service
    $items = array_map(fn($d) => ['id_producto' => $d['id_producto'], 'cantidad' => $d['cantidad']], $pedido['detalle']);
    $descuento = llamarServicio('POST', getenv('CATALOGO_URL') . '/api/catalogo/interno/descontar-stock',
        ['items' => $items], $usuario['token']);
    $stockDescontado = $descuento['codigo'] === 200;
    if (!$stockDescontado) {
        // Riesgo documentado: el cobro ya se hizo; se registra para revisión manual del admin
        error_log("ATENCIÓN: pedido {$pedido['id']} pagado pero no se pudo descontar stock (HTTP {$descuento['codigo']})");
    }
    responder(200, ['pedido' => cargarPedido($pedido['id']), 'stock_descontado' => $stockDescontado]);
}

error(404, 'Ruta no encontrada');
