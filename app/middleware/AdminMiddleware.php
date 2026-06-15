<?php

declare(strict_types=1);

class AdminMiddleware
{
    public function before(array $_params): bool
    {
        if (Flight::get('user_rol') !== 'ADMIN') {
            ApiResponse::error('No autorizado', 403)->send();
        }

        return true;
    }
}
