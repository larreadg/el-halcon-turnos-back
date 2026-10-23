<?php

declare(strict_types=1);

// Aplica las migraciones pendientes de database/migrations/ y compara la base con database/schema.sql.
// Antes de migrar deja un respaldo de la base junto al archivo original.
// Uso por consola: php scripts/migrar.php [--simular]
// Uso por web (hosting sin consola): migrar.php[?simular=1]

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/helpers/MigracionHelper.php';

$esCli = PHP_SAPI === 'cli';

if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function fallar(string $mensaje): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $mensaje . "\n");
    } else {
        http_response_code(500);
        echo $mensaje . "\n";
    }
    exit(1);
}

$simular = $esCli ? in_array('--simular', $_SERVER['argv'] ?? [], true) : isset($_GET['simular']);

// new PDO crearía una base vacía si el archivo no existe
if (!is_file(DB_PATH)) {
    fallar('No existe la base ' . DB_PATH . ' (revisar DB_NAME en .env).');
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');

echo 'Base: ' . realpath(DB_PATH) . "\n";

if (!MigracionHelper::columnas($pdo, 'usuario')) {
    fallar('La base no tiene el esquema inicial (falta la tabla usuario). Crearla con database/schema.sql.');
}

// 1. Migraciones
$pendientes = MigracionHelper::pendientes($pdo);

if (!$pendientes) {
    echo "No hay migraciones pendientes.\n";
} elseif ($simular) {
    echo "Migraciones pendientes (simulación, no se aplica nada):\n";
    foreach ($pendientes as $migracion) {
        echo "  - {$migracion}\n";
    }
} else {
    $respaldo = DB_PATH . '.respaldo-' . date('Ymd-His');
    $stmt = $pdo->prepare('VACUUM INTO ?');
    $stmt->execute([$respaldo]);
    echo 'Respaldo: ' . realpath($respaldo) . "\n";

    foreach (MigracionHelper::aplicarPendientes($pdo) as $migracion) {
        echo "  Aplicada {$migracion}\n";
    }
}

// 2. Comparar contra schema.sql (cargado en una base en memoria)
$referencia = new PDO('sqlite::memory:');
$referencia->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$referencia->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));

$tablas = $referencia
    ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

$diferencias = [];
foreach ($tablas as $tabla) {
    $columnas = MigracionHelper::columnas($pdo, $tabla);

    if (!$columnas) {
        $diferencias[] = "falta la tabla {$tabla}";
        continue;
    }

    foreach (array_diff(MigracionHelper::columnas($referencia, $tabla), $columnas) as $columna) {
        $diferencias[] = "falta la columna {$tabla}.{$columna}";
    }
}

if ($simular && $pendientes) {
    echo "Comparación con schema.sql omitida: primero hay que aplicar las migraciones.\n";
} elseif ($diferencias) {
    echo "Diferencias con database/schema.sql que no cubre ninguna migración:\n";
    foreach ($diferencias as $diferencia) {
        echo "  - {$diferencia}\n";
    }
} else {
    echo "La base coincide con database/schema.sql.\n";
}

// 3. Requisitos de promociones fuera de la base
if (!function_exists('imagewebp')) {
    echo "Aviso: la extensión GD con soporte WEBP no está habilitada; no se podrán subir imágenes de promociones.\n";
}

if (is_dir(PROMOCIONES_PATH) && !is_writable(PROMOCIONES_PATH)) {
    echo 'Aviso: sin permiso de escritura en ' . realpath(PROMOCIONES_PATH) . "\n";
}

exit($diferencias && !($simular && $pendientes) ? 1 : 0);
