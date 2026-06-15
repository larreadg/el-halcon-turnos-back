<?php

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Carga simple de .env (sin dependencias externas)
$envFile = __DIR__ . '/../.env';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'fallback-insecure-secret');
define('JWT_EXPIRY', (int) ($_ENV['JWT_EXPIRY'] ?? 86400));
define('DB_PATH',    __DIR__ . '/../' . ($_ENV['DB_NAME'] ?? 'el_halcon.db'));
