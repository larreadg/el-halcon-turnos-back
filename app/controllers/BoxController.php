<?php

declare(strict_types=1);

class BoxController
{
    public function listar(): void
    {
        $service = new BoxService();
        ApiResponse::success('Boxes obtenidos', $service->listar())->send();
    }

    public function obtener(string $id): void
    {
        $service = new BoxService();
        $box     = $service->obtener((int) $id);

        if (!$box) {
            ApiResponse::error('Box no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Box obtenido', $box)->send();
    }

    public function crear(): void
    {
        $body   = $this->body();
        $puesto = trim((string) ($body['puesto'] ?? ''));

        if ($puesto === '') {
            ApiResponse::error('El puesto es requerido', 400)->send();
            return;
        }

        $creadoPor = (int) Flight::get('user_id');
        $service   = new BoxService();
        $box       = $service->crear($puesto, $creadoPor);

        ApiResponse::success('Box creado', $box, 201)->send();
    }

    public function actualizar(string $id): void
    {
        $body  = $this->body();
        $datos = [];

        if (isset($body['puesto'])) {
            $puesto = trim((string) $body['puesto']);
            $datos['puesto'] = $puesto === '' ? null : $puesto;
        }

        if (!$datos) {
            ApiResponse::error('No hay datos para actualizar', 400)->send();
            return;
        }

        $service       = new BoxService();
        $modificadoPor = (int) Flight::get('user_id');
        $actualizado   = $service->actualizar((int) $id, $datos, $modificadoPor);

        if (!$actualizado) {
            ApiResponse::error('Box no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Box actualizado')->send();
    }

    public function eliminar(string $id): void
    {
        $service   = new BoxService();
        $eliminado = $service->eliminar((int) $id);

        if (!$eliminado) {
            ApiResponse::error('Box no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Box eliminado')->send();
    }

    public function resetearClave(string $id): void
    {
        $service       = new BoxService();
        $modificadoPor = (int) Flight::get('user_id');
        $clave         = $service->resetearClave((int) $id, $modificadoPor);

        if ($clave === null) {
            ApiResponse::error('Box no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Contraseña reseteada', ['clave' => $clave])->send();
    }

    private function body(): array
    {
        $data = json_decode(Flight::request()->body, true);
        return is_array($data) ? $data : [];
    }
}
