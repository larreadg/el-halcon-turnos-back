<?php

declare(strict_types=1);

class TurnoConfiguracionService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public function obtenerActiva(string $fecha): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT tc.id, tc.fecha, tc.numero_inicial, tc.activo, tc.creado_el, tc.modificado_el,
                    (SELECT MAX(t.numero) FROM turno t WHERE t.configuracion_id = tc.id) AS numero_actual
             FROM turno_configuracion tc
             WHERE tc.fecha = ? AND tc.activo = 1'
        );
        $stmt->execute([$fecha]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function crear(string $fecha, int $numeroInicial, int $creadoPor): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE turno_configuracion SET activo = 0, modificado_por = ?, modificado_el = ?
                 WHERE fecha = ? AND activo = 1'
            );
            $stmt->execute([$creadoPor, FechaHelper::ahora(), $fecha]);

            $stmt = $this->db->prepare(
                'INSERT INTO turno_configuracion (fecha, numero_inicial, creado_por, creado_el)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$fecha, $numeroInicial, $creadoPor, FechaHelper::ahora()]);

            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'id'             => $id,
            'fecha'          => $fecha,
            'numero_inicial' => $numeroInicial,
            'activo'         => 1,
        ];
    }
}
