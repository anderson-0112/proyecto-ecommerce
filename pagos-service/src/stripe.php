<?php
/**
 * Cliente mínimo de la API REST de Stripe (https://api.stripe.com/v1) con cURL.
 * Solo se usa la clave secreta de PRUEBA (sk_test_...), guardada en el .env.
 * El servidor nunca recibe números de tarjeta: el navegador los convierte en un
 * PaymentMethod ("pm_...") con Stripe.js y aquí solo se usa ese identificador.
 */
declare(strict_types=1);

/**
 * Hace una petición a Stripe. Devuelve ['codigo' => int, 'datos' => array].
 * codigo 0 = Stripe no respondió (sin conexión / timeout).
 */
function stripe(string $metodo, string $ruta, array $parametros = [], ?string $claveIdempotencia = null): array
{
    $encabezados = ['Authorization: Bearer ' . getenv('STRIPE_SECRET_KEY')];
    if ($claveIdempotencia !== null) {
        // Si la misma petición se repite (reintento de red), Stripe no cobra dos veces
        $encabezados[] = 'Idempotency-Key: ' . $claveIdempotencia;
    }
    $curl = curl_init('https://api.stripe.com/v1' . $ruta);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $encabezados,
    ]);
    if ($parametros) {
        // Stripe recibe parámetros en formato formulario (a=1&b[c]=2), no JSON
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($parametros));
    }
    $respuesta = curl_exec($curl);
    $codigo = $respuesta === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($respuesta === false) {
        error_log('Stripe no respondió: ' . curl_error($curl));
    }
    curl_close($curl);
    return ['codigo' => $codigo, 'datos' => $respuesta ? (json_decode($respuesta, true) ?? []) : []];
}

/**
 * Crea y confirma en un solo paso un PaymentIntent (cobro) en dólares.
 * $fechaPedido forma parte de la clave de idempotencia: si la BD se reinicia, los ids
 * de pedido vuelven a empezar y Stripe (que recuerda las claves 24 h) las confundiría.
 */
function stripeCobrar(int $idPedido, string $fechaPedido, float $monto, string $paymentMethodId): array
{
    return stripe('POST', '/payment_intents', [
        'amount'         => (int) round($monto * 100), // Stripe trabaja en centavos
        'currency'       => 'usd',
        'payment_method' => $paymentMethodId,
        'confirm'        => 'true',
        'description'    => "Pedido #{$idPedido}",
        'metadata'       => ['id_pedido' => $idPedido],
        // Solo pagos que no redirigen a otra página (tarjeta); 3D Secure se resuelve en el navegador
        'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
    ], "pedido-{$idPedido}-" . preg_replace('/\D/', '', $fechaPedido) . "-{$paymentMethodId}");
}
