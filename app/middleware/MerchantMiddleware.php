<?php

declare(strict_types=1);

class MerchantMiddleware
{
    public function before(array $_params): bool
    {
        if (Flight::get('user_rol') !== 'MERCHANT') {
            ApiResponse::error('No autorizado', 403)->send();
        }

        return true;
    }
}
