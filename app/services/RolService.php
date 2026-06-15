<?php

declare(strict_types=1);

class RolService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public function listar(): array
    {
        return $this->db
            ->query('SELECT id, nombre, activo, creado_el FROM rol ORDER BY nombre')
            ->fetchAll();
    }
}
