<?php
/**
 * auth-service (puerto 8081) — registro, login, logout y emisión/verificación de JWT.
 * Base de datos: auth_db (tabla usuarios). Redis: lista de tokens revocados.
 */
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/jwt.php';

// Duración del token según el enunciado: 2 h cliente, 8 h administrador
const DURACION_TOKEN = ['cliente' => 2 * 3600, 'admin' => 8 * 3600];

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = rutaActual();

if ($metodo === 'GET' && in_array($ruta, ['/health', '/api/auth/health'], true)) {
    conexion()->query('SELECT 1');
    responder(200, ['servicio' => 'auth-service', 'estado' => 'ok']);
}

// ---------- POST /api/auth/register ----------
if ($metodo === 'POST' && $ruta === '/api/auth/register') {
    $datos    = cuerpoJson();
    $nombre   = trim((string) ($datos['nombre'] ?? ''));
    $email    = strtolower(trim((string) ($datos['email'] ?? '')));
    $password = (string) ($datos['password'] ?? '');

    $errores = [];
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
        $errores['nombre'] = 'Debe tener entre 2 y 100 caracteres';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'Correo no válido';
    }
    if (strlen($password) < 8) {
        $errores['password'] = 'Debe tener al menos 8 caracteres';
    }
    if ($errores) {
        error(422, 'Datos no válidos', ['campos' => $errores]);
    }

    $db = conexion();
    $existe = $db->prepare('SELECT id FROM usuarios WHERE email = ?');
    $existe->execute([$email]);
    if ($existe->fetch()) {
        error(409, 'El correo ya está registrado');
    }

    // bcrypt: la contraseña nunca se guarda en texto plano
    $hash = password_hash($password, PASSWORD_BCRYPT);
    // El registro público siempre crea clientes; los admin se crean en la BD
    $db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, "cliente")')
       ->execute([$nombre, $email, $hash]);

    responder(201, ['usuario' => [
        'id' => (int) $db->lastInsertId(), 'nombre' => $nombre, 'email' => $email, 'rol' => 'cliente',
    ]]);
}

// ---------- POST /api/auth/login ----------
if ($metodo === 'POST' && $ruta === '/api/auth/login') {
    $datos    = cuerpoJson();
    $email    = strtolower(trim((string) ($datos['email'] ?? '')));
    $password = (string) ($datos['password'] ?? '');
    if ($email === '' || $password === '') {
        error(400, 'Correo y contraseña son obligatorios');
    }

    $consulta = conexion()->prepare('SELECT id, nombre, email, password_hash, rol FROM usuarios WHERE email = ?');
    $consulta->execute([$email]);
    $usuario = $consulta->fetch();

    // Mismo mensaje si no existe el correo o si la contraseña falla (no revela cuál)
    if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
        error(401, 'Credenciales incorrectas');
    }

    $duracion = DURACION_TOKEN[$usuario['rol']];
    $token = jwt_crear([
        'sub'    => (int) $usuario['id'],
        'nombre' => $usuario['nombre'],
        'email'  => $usuario['email'],
        'rol'    => $usuario['rol'],
        'iat'    => time(),
        'exp'    => time() + $duracion,
        'jti'    => bin2hex(random_bytes(8)), // id único del token (para revocarlo con logout)
    ], getenv('JWT_SECRET'));

    responder(200, [
        'token'      => $token,
        'expira_en'  => $duracion,
        'usuario'    => [
            'id' => (int) $usuario['id'], 'nombre' => $usuario['nombre'],
            'email' => $usuario['email'], 'rol' => $usuario['rol'],
        ],
    ]);
}

/**
 * ¿El token fue revocado con logout? Se guarda en Redis la clave "revocado:<jti>".
 * Si Redis no responde se registra el fallo y se acepta el token (el sistema sigue operando).
 */
function tokenRevocado(array $payload): bool
{
    $redis = redis();
    if ($redis === null) {
        error_log('No se pudo consultar la lista de tokens revocados (Redis no disponible)');
        return false;
    }
    try {
        return (bool) $redis->exists('revocado:' . $payload['jti']);
    } catch (RedisException $e) {
        error_log('Error consultando tokens revocados: ' . $e->getMessage());
        return false;
    }
}

// ---------- POST /api/auth/logout — revoca el token actual ----------
if ($metodo === 'POST' && $ruta === '/api/auth/logout') {
    $token = tokenBearer();
    $payload = $token !== null ? jwt_verificar($token, getenv('JWT_SECRET')) : null;
    if ($payload === null) {
        error(401, 'Token inválido o expirado');
    }
    $redis = redis();
    if ($redis === null) {
        error(503, 'No se pudo cerrar la sesión: Redis no disponible');
    }
    // La clave vive solo hasta que el token habría expirado; después Redis la borra sola
    $redis->setex('revocado:' . $payload['jti'], max(1, $payload['exp'] - time()), '1');
    responder(204);
}

// ---------- POST /api/auth/verify (interno: lo usan los demás servicios) ----------
if ($metodo === 'POST' && $ruta === '/api/auth/verify') {
    $token = tokenBearer() ?? (string) (cuerpoJson()['token'] ?? '');
    $payload = $token !== '' ? jwt_verificar($token, getenv('JWT_SECRET')) : null;
    if ($payload === null) {
        error(401, 'Token inválido o expirado', ['valido' => false]);
    }
    if (tokenRevocado($payload)) {
        error(401, 'Token revocado (la sesión fue cerrada)', ['valido' => false]);
    }
    responder(200, [
        'valido'  => true,
        'usuario' => [
            'id' => $payload['sub'], 'nombre' => $payload['nombre'],
            'email' => $payload['email'], 'rol' => $payload['rol'],
        ],
        'expira' => $payload['exp'],
    ]);
}

error(404, 'Ruta no encontrada');
