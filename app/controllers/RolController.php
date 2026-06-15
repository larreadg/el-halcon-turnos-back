<?php

declare(strict_types=1);

class RolController
{
    public function listar(): void
    {
        $service = new RolService();
        ApiResponse::success('Roles obtenidos', $service->listar())->send();
    }
}
