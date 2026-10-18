<?php

declare(strict_types=1);

// ──────────────────────────────────────────
// Rutas públicas (sin autenticación)
// ──────────────────────────────────────────
Flight::route('GET /api/auth/captcha',  [AuthController::class, 'captcha']);
Flight::route('POST /api/auth/login',   [AuthController::class, 'login']);

// Turnos - pantalla pública
Flight::route('GET /api/turnos/pantalla', [TurnoController::class, 'pantalla']);

// Promociones - pantalla pública
Flight::route('GET /api/promociones/pantalla',     [PromocionController::class, 'pantalla']);
Flight::route('GET /api/promociones/archivos/@id', [PromocionController::class, 'archivo']);

// ──────────────────────────────────────────
// Rutas protegidas (requieren JWT + rol ADMIN)
// ──────────────────────────────────────────
Flight::group('/api', function () {

    // Auth
    Flight::route('GET /auth/me', [AuthController::class, 'me']);

    // Roles
    Flight::route('GET /roles', [RolController::class, 'listar']);

    // Merchants
    Flight::route('GET /merchants',                      [MerchantController::class, 'listar']);
    Flight::route('POST /merchants',                     [MerchantController::class, 'crear']);
    Flight::route('GET /merchants/@id',                  [MerchantController::class, 'obtener']);
    Flight::route('PUT /merchants/@id',                  [MerchantController::class, 'actualizar']);
    Flight::route('DELETE /merchants/@id',               [MerchantController::class, 'eliminar']);
    Flight::route('POST /merchants/@id/resetear-clave',  [MerchantController::class, 'resetearClave']);

    // Boxes
    Flight::route('GET /boxes',                      [BoxController::class, 'listar']);
    Flight::route('POST /boxes',                     [BoxController::class, 'crear']);
    Flight::route('GET /boxes/@id',                  [BoxController::class, 'obtener']);
    Flight::route('PUT /boxes/@id',                  [BoxController::class, 'actualizar']);
    Flight::route('DELETE /boxes/@id',               [BoxController::class, 'eliminar']);
    Flight::route('POST /boxes/@id/resetear-clave',  [BoxController::class, 'resetearClave']);

    // Turnos - configuración diaria
    Flight::route('GET /turnos/configuracion',  [TurnoConfiguracionController::class, 'obtener']);
    Flight::route('POST /turnos/configuracion', [TurnoConfiguracionController::class, 'crear']);

    // Turnos - acciones de admin
    Flight::route('POST /turnos/finalizar-todos', [TurnoController::class, 'finalizarTodos']);

    // Promociones
    Flight::route('GET /promociones',                            [PromocionController::class, 'listar']);
    Flight::route('POST /promociones',                           [PromocionController::class, 'crear']);
    Flight::route('PUT /promociones/@id',                        [PromocionController::class, 'actualizar']);
    Flight::route('DELETE /promociones/@id',                     [PromocionController::class, 'eliminar']);
    Flight::route('POST /promociones/@id/archivos',              [PromocionController::class, 'agregarArchivos']);
    Flight::route('PUT /promociones/@id/archivos/orden',         [PromocionController::class, 'ordenarArchivos']);
    Flight::route('DELETE /promociones/@id/archivos/@archivoId', [PromocionController::class, 'eliminarArchivo']);

}, [new AuthMiddleware(), new AdminMiddleware()]);

// ──────────────────────────────────────────
// Rutas protegidas (requieren JWT + rol MERCHANT)
// ──────────────────────────────────────────
Flight::group('/api', function () {

    // Turnos - atención
    Flight::route('GET /turnos/activo',                      [TurnoController::class, 'activo']);
    Flight::route('POST /turnos/atender',                    [TurnoController::class, 'atender']);
    Flight::route('POST /turnos/@id/finalizar',              [TurnoController::class, 'finalizar']);
    Flight::route('POST /turnos/@id/llamar-nuevamente',      [TurnoController::class, 'llamarNuevamente']);
    Flight::route('POST /turnos/@id/reabrir',                [TurnoController::class, 'reabrir']);

}, [new AuthMiddleware(), new MerchantMiddleware()]);

// ──────────────────────────────────────────
// Rutas protegidas (requieren JWT + rol ADMIN o MERCHANT)
// ──────────────────────────────────────────
Flight::group('/api', function () {

    Flight::route('GET /turnos/ultimos-llamados', [TurnoController::class, 'ultimosLlamados']);

}, [new AuthMiddleware(), new AdminOrMerchantMiddleware()]);
