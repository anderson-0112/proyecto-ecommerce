<?php
/**
 * Subida de imágenes a Cloudinary con su API REST (subida firmada).
 * La firma = SHA-1 de los parámetros ordenados + API_SECRET: prueba a Cloudinary
 * que la petición viene de nosotros sin enviar el secreto. El secreto nunca sale del servidor.
 */
declare(strict_types=1);

/**
 * Sube un archivo local a Cloudinary. Devuelve la URL pública (CDN) optimizada,
 * o null si Cloudinary no respondió o rechazó la imagen.
 */
function cloudinarySubir(string $rutaArchivo, ?string $publicId = null): ?string
{
    $parametros = ['folder' => getenv('CLOUDINARY_CARPETA') ?: 'nodotec/productos', 'timestamp' => time()];
    if ($publicId !== null) {
        $parametros['public_id'] = $publicId;
        $parametros['overwrite'] = 'true';
    }
    ksort($parametros); // la firma exige los parámetros en orden alfabético
    $cadena = urldecode(http_build_query($parametros));
    $parametros['signature'] = sha1($cadena . getenv('CLOUDINARY_API_SECRET'));
    $parametros['api_key'] = getenv('CLOUDINARY_API_KEY');
    $parametros['file'] = new CURLFile($rutaArchivo);

    $curl = curl_init('https://api.cloudinary.com/v1_1/' . getenv('CLOUDINARY_CLOUD_NAME') . '/image/upload');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $parametros, // multipart/form-data
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $respuesta = json_decode((string) curl_exec($curl), true);
    $codigo = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($codigo !== 200 || empty($respuesta['secure_url'])) {
        error_log("Cloudinary rechazó la subida (HTTP {$codigo}): " . json_encode($respuesta['error'] ?? $respuesta));
        return null;
    }
    // f_auto,q_auto: Cloudinary entrega el formato y la calidad óptimos; w_600: ancho máximo
    return str_replace('/image/upload/', '/image/upload/f_auto,q_auto,w_600/', $respuesta['secure_url']);
}
