<?php

declare(strict_types=1);

class BoxService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public function listar(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, usuario, puesto, activo, creado_el, modificado_el
             FROM usuario
             WHERE rol_id = ? AND usuario LIKE 'b%'
             ORDER BY usuario"
        );
        $stmt->execute([$this->rolMerchantId()]);

        return $stmt->fetchAll();
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, usuario, puesto, activo, creado_el, modificado_el
             FROM usuario
             WHERE id = ? AND rol_id = ? AND usuario LIKE 'b%'"
        );
        $stmt->execute([$id, $this->rolMerchantId()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function crear(?string $puesto, int $creadoPor): array
    {
        $usuario  = $this->generarUsuario();
        $documento = $this->generarUuid();
        $clave    = $this->generarClave();
        $hash     = password_hash($clave, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            'INSERT INTO usuario (usuario, clave_hash, nombres, apellidos, documento, puesto, rol_id, creado_por, creado_el)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$usuario, $hash, '', '', $documento, $puesto, $this->rolMerchantId(), $creadoPor, FechaHelper::ahora()]);

        return [
            'id'      => (int) $this->db->lastInsertId(),
            'usuario' => $usuario,
            'clave'   => $clave,
            'puesto'  => $puesto,
        ];
    }

    public function actualizar(int $id, array $datos, int $modificadoPor): bool
    {
        $campos = [];
        $params = [];

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
            "UPDATE usuario SET " . implode(', ', $campos) . " WHERE id = ? AND rol_id = ? AND usuario LIKE 'b%'"
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function resetearClave(int $id, int $modificadoPor): ?string
    {
        $clave = $this->generarClave();
        $hash  = password_hash($clave, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            "UPDATE usuario SET clave_hash = ?, modificado_por = ?, modificado_el = ?
             WHERE id = ? AND rol_id = ? AND usuario LIKE 'b%'"
        );
        $stmt->execute([$hash, $modificadoPor, FechaHelper::ahora(), $id, $this->rolMerchantId()]);

        return $stmt->rowCount() > 0 ? $clave : null;
    }

    public function eliminar(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM usuario WHERE id = ? AND rol_id = ? AND usuario LIKE 'b%'"
        );
        $stmt->execute([$id, $this->rolMerchantId()]);

        return $stmt->rowCount() > 0;
    }

    private function generarUsuario(): string
    {
        $stmt = $this->db->prepare(
            "SELECT usuario FROM usuario WHERE usuario LIKE 'b%'"
        );
        $stmt->execute();
        $existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $max = 0;
        foreach ($existentes as $u) {
            if (preg_match('/^b(\d+)$/', (string) $u, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'b' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function generarUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
