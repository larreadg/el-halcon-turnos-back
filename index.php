<?php

declare(strict_types=1);

require_once __DIR__ . '/flight/autoload.php';
require_once __DIR__ . '/config/config.php';

// Autoloader para clases de la aplicación
spl_autoload_register(function (string $class): void {
    $dirs = [
        __DIR__ . '/core/',
        __DIR__ . '/app/helpers/',
        __DIR__ . '/app/middleware/',
        __DIR__ . '/app/services/',
        __DIR__ . '/app/controllers/',
    ];

    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// CORS
Flight::before('start', function () {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
});

// Manejo de errores
Flight::map('error', function (Throwable $e): void {
    error_log('[API] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    ApiResponse::error('Error interno del servidor', 500)->send();
});

Flight::map('notFound', function (): void {
    ApiResponse::error('Ruta no encontrada', 404)->send();
});

// Iniciar base de datos
require_once __DIR__ . '/config/database.php';

// Cargar rutas
require_once __DIR__ . '/routes/api.php';

Flight::start();
