<?php

declare(strict_types=1);

class TurnoController
{
    public function atender(): void
    {
        $merchantId = (int) Flight::get('user_id');
        $service    = new TurnoService();
        $resultado  = $service->atender($merchantId);

        if (!$resultado['ok']) {
            if ($resultado['error'] === 'turno_activo') {
                ApiResponse::error('Ya tenés un turno en atención. Finalizalo antes de pedir el siguiente.', 409)->send();
                return;
            }

            ApiResponse::error('No hay configuración de turnos para hoy', 404)->send();
            return;
        }

        ApiResponse::success('Turno asignado', $resultado['turno'], 201)->send();
    }

    public function activo(): void
    {
        $merchantId = (int) Flight::get('user_id');
        $service    = new TurnoService();
        $turno      = $service->obtenerActivo($merchantId);

        ApiResponse::success('Turno activo obtenido', $turno)->send();
    }

    public function pantalla(): void
    {
        $service = new TurnoService();
        ApiResponse::success('Pantalla obtenida', $service->pantalla())->send();
    }

    public function ultimosLlamados(): void
    {
        $service = new TurnoService();
        ApiResponse::success('Últimos llamados obtenidos', $service->ultimosLlamados())->send();
    }

    public function finalizarTodos(): void
    {
        $adminId  = (int) Flight::get('user_id');
        $service  = new TurnoService();
        $cantidad = $service->finalizarTodos($adminId);

        ApiResponse::success('Turnos finalizados', ['finalizados' => $cantidad])->send();
    }

    public function llamarNuevamente(string $id): void
    {
        $merchantId = (int) Flight::get('user_id');
        $service    = new TurnoService();
        $resultado  = $service->marcarLlamarNuevamente((int) $id, $merchantId);

        if (!$resultado['ok']) {
            ApiResponse::error('Turno no encontrado o no está en atención', 404)->send();
            return;
        }

        ApiResponse::success('Turno marcado para re-anunciar')->send();
    }

    public function reabrir(string $id): void
    {
        $merchantId = (int) Flight::get('user_id');
        $service    = new TurnoService();
        $resultado  = $service->reabrir((int) $id, $merchantId);

        if (!$resultado['ok']) {
            if ($resultado['error'] === 'turno_activo') {
                ApiResponse::error('Ya tenés un turno en atención. Finalizalo antes de reabrir otro.', 409)->send();
                return;
            }

            ApiResponse::error('Turno no encontrado o no está finalizado', 404)->send();
            return;
        }

        ApiResponse::success('Turno reabierto', $resultado['turno'])->send();
    }

    public function finalizar(string $id): void
    {
        $merchantId = (int) Flight::get('user_id');
        $service    = new TurnoService();
        $resultado  = $service->finalizar((int) $id, $merchantId);

        ApiResponse::success('Turno finalizado', $resultado['turno'] ?? null)->send();
    }
}
