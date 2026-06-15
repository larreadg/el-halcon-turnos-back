<?php

declare(strict_types=1);

class MerchantService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public function listar(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, usuario, nombres, apellidos, documento, puesto, activo, creado_el, modificado_el
             FROM usuario
             WHERE rol_id = ?
             ORDER BY usuario'
        );
        $stmt->execute([$this->rolMerchantId()]);

        return $stmt->fetchAll();
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, usuario, nombres, apellidos, documento, puesto, activo, creado_el, modificado_el
             FROM usuario
             WHERE id = ? AND rol_id = ?'
        );
        $stmt->execute([$id, $this->rolMerchantId()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function existeUsuario(string $usuario): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM usuario WHERE usuario = ?');
        $stmt->execute([$usuario]);

        return (bool) $stmt->fetchColumn();
    }

    public function existeDocumento(string $documento, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare('SELECT 1 FROM usuario WHERE documento = ? AND id != ?');
            $stmt->execute([$documento, $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM usuario WHERE documento = ?');
            $stmt->execute([$documento]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function crear(string $nombres, string $apellidos, string $documento, ?string $puesto, int $creadoPor): array
    {
        $usuario = $this->generarUsuario($documento);
        $clave   = $this->generarClave();
        $hash    = password_hash($clave, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            'INSERT INTO usuario (usuario, clave_hash, nombres, apellidos, documento, puesto, rol_id, creado_por, creado_el)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$usuario, $hash, $nombres, $apellidos, $documento, $puesto, $this->rolMerchantId(), $creadoPor, FechaHelper::ahora()]);

        return [
            'id'       => (int) $this->db->lastInsertId(),
            'usuario'  => $usuario,
            'clave'    => $clave,
            'puesto'   => $puesto,
        ];
    }

    public function actualizar(int $id, array $datos, int $modificadoPor): bool
    {
        $campos = [];
        $params = [];

        if (isset($datos['nombres'])) {
            $campos[] = 'nombres = ?';
            $params[] = $datos['nombres'];
        }

        if (isset($datos['apellidos'])) {
            $campos[] = 'apellidos = ?';
            $params[] = $datos['apellidos'];
        }

        if (isset($datos['documento'])) {
            $campos[] = 'documento = ?';
            $params[] = $datos['documento'];
        }

        if (array_key_exists('puesto', $datos)) {
            $campos[] = 'puesto = ?';
            $params[] = $datos['puesto'];
        }

        if (!$campos) {
            return false;
        }

        $campos[] = 'modificado_por = ?';
        $params[] = $modificadoPor;
        $campos[] = 'modificado_el = ?';
        $params[] = FechaHelper::ahora();

        $params[] = $id;
        $params[] = $this->rolMerchantId();

        $stmt = $this->db->prepare(
            'UPDATE usuario SET ' . implode(', ', $campos) . ' WHERE id = ? AND rol_id = ?'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function resetearClave(int $id, int $modificadoPor): ?string
    {
        $clave = $this->generarClave();
        $hash  = password_hash($clave, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            'UPDATE usuario SET clave_hash = ?, modificado_por = ?, modificado_el = ?
             WHERE id = ? AND rol_id = ?'
        );
        $stmt->execute([$hash, $modificadoPor, FechaHelper::ahora(), $id, $this->rolMerchantId()]);

        return $stmt->rowCount() > 0 ? $clave : null;
    }

    public function eliminar(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM usuario WHERE id = ? AND rol_id = ?');
        $stmt->execute([$id, $this->rolMerchantId()]);

        return $stmt->rowCount() > 0;
    }

    private function generarUsuario(string $documento): string
    {
        $base    = 'u' . $documento;
        $usuario = $base;
        $sufijo  = 1;

        while ($this->existeUsuario($usuario)) {
            $usuario = $base . $sufijo;
            $sufijo++;
        }

        return $usuario;
    }

    private function generarClave(): string
    {
        return substr(bin2hex(random_bytes(5)), 0, 8);
    }

    private function rolMerchantId(): int
    {
        return (int) $this->db->query("SELECT id FROM rol WHERE nombre = 'MERCHANT'")->fetchColumn();
    }
}
