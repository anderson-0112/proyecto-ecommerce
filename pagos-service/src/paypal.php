<?php
/**
 * Cliente mínimo de la API REST de PayPal (Orders v2) en modo SANDBOX.
 * Flujo de dos pasos:
 *   1) crear la orden (servidor)  -> el comprador la aprueba en la ventana de PayPal (navegador)
 *   2) capturar la orden (servidor) -> recién aquí se cobra
 * Autenticación OAuth 2.0: Client ID + Secret -> access token temporal.
 */
declare(strict_types=1);

/** Pide un access token a PayPal (client_credentials). null si no se pudo. */
function paypalToken(): ?string
{
    $curl = curl_init(getenv('PAYPAL_API_URL') . '/v1/oauth2/token');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => getenv('PAYPAL_CLIENT_ID') . ':' . getenv('PAYPAL_CLIENT_SECRET'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $datos = json_decode((string) curl_exec($curl), true);
    curl_close($curl);
    return $datos['access_token'] ?? null;
}

/**
 * Petición JSON a PayPal. Devuelve ['codigo' => int, 'datos' => array]; codigo 0 = sin respuesta.
 * $idPeticion (PayPal-Request-Id) hace la operación idempotente: si se repite, PayPal no la duplica.
 */
function paypal(string $metodo, string $ruta, ?array $cuerpo, string $idPeticion): array
{
    $token = paypalToken();
    if ($token === null) {
        return ['codigo' => 0, 'datos' => []];
    }
    $curl = curl_init(getenv('PAYPAL_API_URL') . $ruta);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'PayPal-Request-Id: ' . $idPeticion,
        ],
        CURLOPT_POSTFIELDS     => $cuerpo === null ? '{}' : json_encode($cuerpo),
    ]);
    $respuesta = curl_exec($curl);
    $codigo = $respuesta === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return ['codigo' => $codigo, 'datos' => $respuesta ? (json_decode($respuesta, true) ?? []) : []];
}

/** Paso 1: crea la orden de PayPal por el total del pedido (USD). */
function paypalCrearOrden(array $pedido): array
{
    return paypal('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'pedido-' . $pedido['id'],
            'custom_id'    => (string) $pedido['id'],
            'description'  => "NodoTec - Pedido #{$pedido['id']}",
            'amount'       => ['currency_code' => 'USD', 'value' => number_format($pedido['total'], 2, '.', '')],
        ]],
    ], 'crear-' . $pedido['id'] . '-' . bin2hex(random_bytes(6)));
}

/** Paso 2: captura (cobra) una orden ya aprobada por el comprador. */
function paypalCapturar(string $ordenId): array
{
    return paypal('POST', "/v2/checkout/orders/{$ordenId}/capture", null, 'capturar-' . $ordenId);
}
