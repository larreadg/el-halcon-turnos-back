<?php

declare(strict_types=1);

class TurnoService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    /**
     * Asigna el siguiente número de turno al merchant.
     *
     * Devuelve:
     *  - ['ok' => true,  'turno' => [...]]
     *  - ['ok' => false, 'error' => 'turno_activo' | 'sin_configuracion']
     */
    public function atender(int $merchantId): array
    {
        $this->db->exec('BEGIN EXCLUSIVE');
        try {
            $resultado = $this->asignarSiguiente($merchantId);
            $this->db->exec('COMMIT');
            return $resultado;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    private function asignarSiguiente(int $merchantId): array
    {
        if ($this->obtenerActivo($merchantId) !== null) {
            return ['ok' => false, 'error' => 'turno_activo'];
        }

        $fecha  = FechaHelper::hoy();
        $config = $this->configuracionActiva($fecha);

        if (!$config) {
            return ['ok' => false, 'error' => 'sin_configuracion'];
        }

        $numero = $this->siguienteNumero((int) $config['id'], (int) $config['numero_inicial']);
        $ahora  = FechaHelper::ahora();

        $stmt = $this->db->prepare(
            'INSERT INTO turno (configuracion_id, fecha, numero, estado, merchant_id, llamado_el, creado_por, creado_el)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$config['id'], $fecha, $numero, 'llamado', $merchantId, $ahora, $merchantId, $ahora]);

        return ['ok' => true, 'turno' => $this->obtener((int) $this->db->lastInsertId())];
    }

    /**
     * Reabre un turno finalizado: lo vuelve a 'llamado', reasigna el merchant
     * y lo marca para que la pantalla lo anuncie.
     *
     * Devuelve:
     *  - ['ok' => true,  'turno' => [...]]
     *  - ['ok' => false, 'error' => 'no_encontrado' | 'turno_activo']
     */
    public function reabrir(int $turnoId, int $merchantId): array
    {
        if ($this->obtenerActivo($merchantId) !== null) {
            return ['ok' => false, 'error' => 'turno_activo'];
        }

        $ahora = FechaHelper::ahora();
        $fecha = FechaHelper::hoy();

        $stmt = $this->db->prepare(
            "UPDATE turno
             SET estado = 'llamado', merchant_id = ?, llamado_el = ?,
                 modificado_por = ?, modificado_el = ?
             WHERE id = ? AND fecha = ? AND estado = 'finalizado'"
        );
        $stmt->execute([$merchantId, $ahora, $merchantId, $ahora, $turnoId, $fecha]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'no_encontrado'];
        }

        return ['ok' => true, 'turno' => $this->obtener($turnoId)];
    }

    public function ultimosLlamados(): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.numero, t.finalizado_el, u.nombres, u.apellidos, u.puesto,
                    CAST(SUBSTR(u.usuario, 2) AS INTEGER) AS box_numero
             FROM turno t
             JOIN usuario u ON u.id = t.merchant_id
             WHERE t.fecha = ? AND t.estado = 'finalizado'
             ORDER BY t.finalizado_el DESC
             LIMIT 10"
        );
        $stmt->execute([FechaHelper::hoy()]);

        return $stmt->fetchAll();
    }

    /**
     * Finaliza todos los turnos 'llamado' de la configuración activa de hoy (acción de admin).
     * Devuelve la cantidad de turnos finalizados.
     */
    public function finalizarTodos(int $adminId): int
    {
        $ahora = FechaHelper::ahora();
        $fecha = FechaHelper::hoy();

        $stmt = $this->db->prepare(
            "UPDATE turno SET estado = 'finalizado', finalizado_el = ?, modificado_por = ?, modificado_el = ?
             WHERE estado = 'llamado' AND fecha = ?"
        );
        $stmt->execute([$ahora, $adminId, $ahora, $fecha]);

        return $stmt->rowCount();
    }

    /**
     * Finaliza el turno activo del merchant.
     *
     * Devuelve:
     *  - ['ok' => true,  'turno' => [...]]
     *  - ['ok' => false, 'error' => 'no_encontrado']
     */
    public function finalizar(int $turnoId, int $merchantId): array
    {
        $ahora = FechaHelper::ahora();

        $stmt = $this->db->prepare(
            "UPDATE turno SET estado = 'finalizado', finalizado_el = ?, modificado_por = ?, modificado_el = ?
             WHERE id = ? AND merchant_id = ? AND estado = 'llamado'"
        );
        $stmt->execute([$ahora, $merchantId, $ahora, $turnoId, $merchantId]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'no_encontrado'];
        }

        return ['ok' => true, 'turno' => $this->obtener($turnoId)];
    }

    /**
     * Datos para la pantalla pública: turnos en atención, últimos finalizados,
     * y los que deben volver a sonar (consume y limpia llamar_nuevamente).
     */
    public function pantalla(): array
    {
        $fecha = FechaHelper::hoy();

        $llamando = $this->db->prepare(
            "SELECT t.id, t.numero, t.llamado_el, u.nombres, u.apellidos, u.puesto,
                    CAST(SUBSTR(u.usuario, 2) AS INTEGER) AS box_numero
             FROM turno t
             JOIN usuario u ON u.id = t.merchant_id
             WHERE t.fecha = ? AND t.estado = 'llamado'
             ORDER BY t.llamado_el DESC"
        );
        $llamando->execute([$fecha]);

        $ultimos = $this->db->prepare(
            "SELECT t.id, t.numero, t.finalizado_el, u.nombres, u.apellidos, u.puesto
             FROM turno t
             JOIN usuario u ON u.id = t.merchant_id
             WHERE t.fecha = ? AND t.estado = 'finalizado'
             ORDER BY t.finalizado_el DESC
             LIMIT 5"
        );
        $ultimos->execute([$fecha]);

        // Turnos marcados para re-anunciar (consume y limpia)
        $nuevamente = $this->db->prepare(
            "SELECT t.id, t.numero, t.llamado_el, u.nombres, u.apellidos, u.puesto,
                    CAST(SUBSTR(u.usuario, 2) AS INTEGER) AS box_numero
             FROM turno t
             JOIN usuario u ON u.id = t.merchant_id
             WHERE t.fecha = ? AND t.llamar_nuevamente = 1"
        );
        $nuevamente->execute([$fecha]);
        $llamarNuevamente = $nuevamente->fetchAll();

        if (count($llamarNuevamente) > 0) {
            $this->db->prepare(
                "UPDATE turno SET llamar_nuevamente = 0 WHERE fecha = ? AND llamar_nuevamente = 1"
            )->execute([$fecha]);
        }

        return [
            'llamando'            => $llamando->fetchAll(),
            'ultimos_finalizados' => $ultimos->fetchAll(),
            'llamarNuevamente'    => $llamarNuevamente,
        ];
    }

    /**
     * Marca un turno para que la pantalla lo re-anuncie.
     * Solo aplica si el turno está en estado 'llamado' y pertenece al merchant.
     *
     * Devuelve:
     *  - ['ok' => true]
     *  - ['ok' => false, 'error' => 'no_encontrado']
     */
    public function marcarLlamarNuevamente(int $turnoId, int $merchantId): array
    {
        $stmt = $this->db->prepare(
            "UPDATE turno SET llamar_nuevamente = 1, modificado_por = ?, modificado_el = ?
             WHERE id = ? AND merchant_id = ? AND estado = 'llamado'"
        );
        $stmt->execute([$merchantId, FechaHelper::ahora(), $turnoId, $merchantId]);

        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'no_encontrado'];
        }

        return ['ok' => true];
    }

    /**
     * Turno con estado='llamado' del merchant, si existe.
     */
    public function obtenerActivo(int $merchantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.numero, t.estado, t.llamado_el, t.finalizado_el,
                    u.id AS merchant_id, u.nombres, u.apellidos, u.puesto
             FROM turno t
             JOIN usuario u ON u.id = t.merchant_id
             WHERE t.merchant_id = ? AND t.estado = 'llamado'"
        );
        $stmt->execute([$merchantId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function configuracionActiva(string $fecha): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero_inicial FROM turno_configuracion WHERE fecha = ? AND activo = 1'
        );
        $stmt->execute([$fecha]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function siguienteNumero(int $configuracionId, int $numeroInicial): int
    {
        $stmt = $this->db->prepare('SELECT MAX(numero) FROM turno WHERE configuracion_id = ?');
        $stmt->execute([$configuracionId]);
        $max = $stmt->fetchColumn();

        return $max !== null ? ((int) $max) + 1 : $numeroInicial;
    }

    private function obtener(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.numero, t.estado, t.llamado_el, t.finalizado_el,
                    u.id AS merchant_id, u.nombres, u.apellidos, u.puesto
             FROM turno t
             JOIN usuario u ON u.id = t.merchant_id
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch();
    }
}
