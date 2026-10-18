<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');

// Migraciones de promociones (se aplican solas si todavía no están)
$columnasPromocion = fn (): array => array_column($pdo->query('PRAGMA table_info(promocion)')->fetchAll(), 'name');

if (!$columnasPromocion()) {
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/001_promociones.sql'));
}

if (in_array('archivo', $columnasPromocion(), true)) {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // Volver a verificar dentro de la transacción por si otro request ya migró
        if (in_array('archivo', $columnasPromocion(), true)) {
            $pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/002_promocion_archivos.sql'));
        }
        $pdo->exec('COMMIT');
    } catch (\Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

Flight::set('db', $pdo);
