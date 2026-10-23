<?php

declare(strict_types=1);

// Convierte a WEBP las imágenes de promociones subidas antes de que se guardaran en ese formato.
// Uso: php scripts/convertir_imagenes_webp.php [--simular]

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/services/PromocionService.php';

$simular = in_array('--simular', $argv, true);

if (!function_exists('imagewebp')) {
    fwrite(STDERR, "La extensión GD con soporte WEBP no está habilitada (extension=gd en php.ini).\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$archivos = $pdo->query(
    "SELECT id, archivo, mime FROM promocion_archivo WHERE tipo = 'imagen' AND mime != 'image/webp' ORDER BY id"
)->fetchAll();

$update = $pdo->prepare("UPDATE promocion_archivo SET archivo = ?, mime = 'image/webp' WHERE id = ?");

$convertidos = 0;
$errores     = 0;
$bytesAntes  = 0;
$bytesDespues = 0;

foreach ($archivos as $archivo) {
    $origen = PROMOCIONES_PATH . '/' . basename((string) $archivo['archivo']);
    $nombre = pathinfo((string) $archivo['archivo'], PATHINFO_FILENAME) . '.webp';
    $destino = PROMOCIONES_PATH . '/' . $nombre;

    if (!is_file($origen)) {
        echo "- #{$archivo['id']} {$archivo['archivo']}: no existe en disco, se omite.\n";
        $errores++;
        continue;
    }

    if ($simular) {
        echo "- #{$archivo['id']} {$archivo['archivo']} -> {$nombre}\n";
        continue;
    }

    try {
        if (!PromocionService::convertirAWebp($origen, (string) $archivo['mime'], $destino)) {
            throw new RuntimeException('no se pudo convertir la imagen');
        }
        $update->execute([$nombre, $archivo['id']]);
    } catch (Throwable $e) {
        @unlink($destino);
        echo "- #{$archivo['id']} {$archivo['archivo']}: error, {$e->getMessage()}.\n";
        $errores++;
        continue;
    }

    // El original se borra recién cuando la base ya apunta al WEBP
    $bytesAntes   += filesize($origen);
    $bytesDespues += filesize($destino);
    unlink($origen);

    echo "- #{$archivo['id']} {$archivo['archivo']} -> {$nombre}\n";
    $convertidos++;
}

if ($simular) {
    echo "Simulación: " . count($archivos) . " imágenes para convertir. No se modificó nada.\n";
    exit(0);
}

$mb = fn (int $bytes): string => number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';

echo "Conversión completada.\n";
echo "- Imágenes convertidas: {$convertidos}\n";
echo "- Tamaño: {$mb($bytesAntes)} -> {$mb($bytesDespues)}\n";
echo "- Con errores u omitidas: {$errores}\n";

exit($errores > 0 ? 1 : 0);
