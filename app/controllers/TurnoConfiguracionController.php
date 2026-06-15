<?php

declare(strict_types=1);

class TurnoConfiguracionController
{
    public function obtener(): void
    {
        $fecha = trim((string) (Flight::request()->query['fecha'] ?? FechaHelper::hoy()));

        if (!$this->fechaValida($fecha)) {
            ApiResponse::error('Fecha inválida, formato esperado YYYY-MM-DD', 400)->send();
            return;
        }

        $service = new TurnoConfiguracionService();
        $config  = $service->obtenerActiva($fecha);

        if (!$config) {
            ApiResponse::error('No hay configuración de turnos para la fecha indicada', 404)->send();
            return;
        }

        ApiResponse::success('Configuración obtenida', $config)->send();
    }

    public function crear(): void
    {
        $body          = $this->body();
        $fecha         = trim((string) ($body['fecha'] ?? FechaHelper::hoy()));
        $numeroInicial = $body['numero_inicial'] ?? null;

        if (!$this->fechaValida($fecha)) {
            ApiResponse::error('Fecha inválida, formato esperado YYYY-MM-DD', 400)->send();
            return;
        }

        if (!is_numeric($numeroInicial) || (int) $numeroInicial < 1) {
            ApiResponse::error('numero_inicial es requerido y debe ser un entero mayor a 0', 400)->send();
            return;
        }

        $creadoPor = (int) Flight::get('user_id');
        $service   = new TurnoConfiguracionService();
        $config    = $service->crear($fecha, (int) $numeroInicial, $creadoPor);

        ApiResponse::success('Configuración guardada', $config, 201)->send();
    }

    private function fechaValida(string $fecha): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio);
    }

    private function body(): array
    {
        $data = json_decode(Flight::request()->body, true);
        return is_array($data) ? $data : [];
    }
}
