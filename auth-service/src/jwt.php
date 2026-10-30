<?php
/**
 * JWT (JSON Web Token) firmado con HMAC-SHA256 (HS256), implementado a mano
 * para poder explicar cada paso:
 *   token = base64url(encabezado) . "." . base64url(payload) . "." . base64url(firma)
 *   firma = HMAC_SHA256(encabezado.payload, JWT_SECRET)
 * Solo auth-service conoce JWT_SECRET; los demás servicios verifican el token
 * llamando a POST /api/auth/verify.
 */
declare(strict_types=1);

function base64url_codificar(string $datos): string
{
    return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
}

function base64url_decodificar(string $datos): string|false
{
    return base64_decode(strtr($datos, '-_', '+/'), true);
}

/** Crea un token firmado con los datos ($payload) indicados. */
function jwt_crear(array $payload, string $secreto): string
{
    $encabezado = base64url_codificar(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $cuerpo     = base64url_codificar(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $firma      = base64url_codificar(hash_hmac('sha256', "$encabezado.$cuerpo", $secreto, true));
    return "$encabezado.$cuerpo.$firma";
}

/**
 * Verifica firma y expiración. Devuelve el payload si el token es válido,
 * o null si fue alterado, está mal formado o ya expiró.
 */
function jwt_verificar(string $token, string $secreto): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return null;
    }
    [$encabezado, $cuerpo, $firma] = $partes;

    // Se recalcula la firma; si alguien cambió el payload (p. ej. el rol), no coincide
    $firmaEsperada = base64url_codificar(hash_hmac('sha256', "$encabezado.$cuerpo", $secreto, true));
    if (!hash_equals($firmaEsperada, $firma)) { // comparación en tiempo constante
        return null;
    }

    $cab = json_decode((string) base64url_decodificar($encabezado), true);
    if (($cab['alg'] ?? '') !== 'HS256') { // se rechaza "alg: none" u otros algoritmos
        return null;
    }

    $payload = json_decode((string) base64url_decodificar($cuerpo), true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}
