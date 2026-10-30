<?php
/**
 * Utilidades del servicio: respuestas JSON, lectura de la petición,
 * conexión a la BD y llamadas HTTP a otros microservicios.
 * Cada microservicio lleva su propia copia (son independientes).
 */
declare(strict_types=1);

/** Envía una respuesta JSON con el código HTTP indicado y termina. */
function responder(int $codigo, mixed $datos = null): never
{
    http_response_code($codigo);
    if ($codigo !== 204) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function error(int $codigo, string $mensaje, array $extra = []): never
{
    responder($codigo, ['error' => $mensaje] + $extra);
}

/** Lee el cuerpo JSON de la petición (400 si no es JSON válido). */
function cuerpoJson(): array
{
    $crudo = file_get_contents('php://input');
    if ($crudo === '' || $crudo === false) {
        return [];
    }
    $datos = json_decode($crudo, true);
    if (!is_array($datos)) {
        error(400, 'El cuerpo de la petición no es JSON válido');
    }
    return $datos;
}

/** Extrae el token del encabezado "Authorization: Bearer <token>". */
function tokenBearer(): ?string
{
    $encabezado = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($encabezado === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nombre => $valor) {
            if (strcasecmp($nombre, 'Authorization') === 0) {
                $encabezado = $valor;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $encabezado, $m) ? $m[1] : null;
}

/**
 * Conexión PDO a MariaDB. $variableHost permite elegir el servidor
 * (DB_HOST = Master para escrituras; DB_READ_HOST = réplica de lectura).
 */
function conexion(string $variableHost = 'DB_HOST'): PDO
{
    static $conexiones = [];
    $host = getenv($variableHost) ?: getenv('DB_HOST');
    if (!isset($conexiones[$host])) {
        $conexiones[$host] = new PDO(
            "mysql:host={$host};dbname=" . getenv('DB_NAME') . ';charset=utf8mb4',
            getenv('DB_USER'),
            getenv('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // consultas preparadas reales
                PDO::ATTR_TIMEOUT            => 3,
            ]
        );
    }
    return $conexiones[$host];
}

/**
 * Llamada HTTP a otro microservicio por la red interna de Docker.
 * Reenvía el JWT del usuario para que el servicio destino lo verifique.
 * Devuelve ['codigo' => int, 'datos' => array|null]; codigo 0 = el servicio no respondió.
 */
function llamarServicio(string $metodo, string $url, ?array $cuerpo = null, ?string $jwt = null, int $timeout = 5): array
{
    $encabezados = ['Accept: application/json'];
    if ($jwt !== null) {
        $encabezados[] = 'Authorization: Bearer ' . $jwt;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($cuerpo !== null) {
        $encabezados[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $encabezados);
    $respuesta = curl_exec($curl);
    $codigo = $respuesta === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($respuesta === false) {
        error_log("Fallo llamando a {$metodo} {$url}: " . curl_error($curl));
    }
    curl_close($curl);
    return ['codigo' => $codigo, 'datos' => $respuesta ? json_decode($respuesta, true) : null];
}

/**
 * Verifica el JWT de la petición preguntando a auth-service (POST /api/auth/verify).
 * Devuelve el usuario (id, nombre, email, rol) o corta con 401 / 503.
 */
function usuarioAutenticado(): array
{
    $jwt = tokenBearer();
    if ($jwt === null) {
        error(401, 'Falta el token de autenticación');
    }
    $r = llamarServicio('POST', getenv('AUTH_URL') . '/api/auth/verify', null, $jwt, 3);
    if ($r['codigo'] === 0 || $r['codigo'] >= 500) {
        error(503, 'No se pudo verificar el token: auth-service no disponible');
    }
    if ($r['codigo'] !== 200 || empty($r['datos']['valido'])) {
        error(401, 'Token inválido o expirado');
    }
    $usuario = $r['datos']['usuario'];
    $usuario['token'] = $jwt; // se reenvía en las llamadas a otros servicios
    return $usuario;
}

function requiereAdmin(array $usuario): void
{
    if ($usuario['rol'] !== 'admin') {
        error(403, 'Acción permitida solo para administradores');
    }
}

/** Ruta de la petición sin barra final, p. ej. "/api/pedidos/5". */
function rutaActual(): string
{
    $ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    return rtrim($ruta, '/') ?: '/';
}

// Cualquier excepción no controlada se registra en el log y responde 500 sin exponer detalles
set_exception_handler(function (Throwable $e): void {
    error_log('Error no controlado: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof PDOException && in_array($e->getCode(), [2002, 2006, 1045], false)) {
        error(503, 'Base de datos no disponible');
    }
    error(500, 'Error interno del servidor');
});
