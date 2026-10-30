<?php
/**
 * Migración única: sube a Cloudinary las fotos de los productos que aún apuntan a otra URL
 * (Wikimedia Commons) y actualiza imagen_url en catalogo_db (Master; el Slave lo replica).
 * Uso:  docker compose exec catalogo-service php /var/www/html/src/migrar-imagenes-cloudinary.php
 * Imprime "id|url" de cada producto para actualizar también db/init-master.sql.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/cloudinary.php';

$db = conexion();
$productos = $db->query("SELECT id, imagen_url FROM productos
    WHERE imagen_url IS NOT NULL AND imagen_url NOT LIKE 'https://res.cloudinary.com/%' ORDER BY id")->fetchAll();
$actualizar = $db->prepare('UPDATE productos SET imagen_url = ? WHERE id = ?');

foreach ($productos as $p) {
    // Se descarga la foto (Wikimedia pide identificar al cliente con User-Agent)
    $curl = curl_init($p['imagen_url']);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'NodoTec-proyecto-academico/1.0 (UMG)']);
    $bytes = curl_exec($curl);
    $codigo = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($codigo !== 200 || !$bytes) {
        fwrite(STDERR, "Producto {$p['id']}: no se pudo descargar (HTTP {$codigo})\n");
        continue;
    }
    $temporal = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($temporal, $bytes);
    $url = cloudinarySubir($temporal, "producto-{$p['id']}");
    unlink($temporal);
    if ($url === null) {
        fwrite(STDERR, "Producto {$p['id']}: Cloudinary rechazó la imagen\n");
        continue;
    }
    $actualizar->execute([$url, $p['id']]);
    echo "{$p['id']}|{$url}\n";
    sleep(2); // ritmo amable con Wikimedia
}
redis()?->incr('catalogo:version'); // invalida la caché del catálogo
