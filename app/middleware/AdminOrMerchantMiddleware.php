<?php

declare(strict_types=1);

class AdminOrMerchantMiddleware
{
    public function before(array $_params): bool
    {
        $rol = Flight::get('user_rol');

        if ($rol !== 'ADMIN' && $rol !== 'MERCHANT') {
            ApiResponse::error('No autorizado', 403)->send();
        }

        return true;
    }
}
