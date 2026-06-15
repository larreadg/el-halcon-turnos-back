<?php

declare(strict_types=1);

class MerchantController
{
    public function listar(): void
    {
        $service = new MerchantService();
        ApiResponse::success('Merchants obtenidos', $service->listar())->send();
    }

    public function obtener(string $id): void
    {
        $service  = new MerchantService();
        $merchant = $service->obtener((int) $id);

        if (!$merchant) {
            ApiResponse::error('Merchant no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Merchant obtenido', $merchant)->send();
    }

    public function crear(): void
    {
        $body      = $this->body();
        $nombres   = trim((string) ($body['nombres'] ?? ''));
        $apellidos = trim((string) ($body['apellidos'] ?? ''));
        $documento = trim((string) ($body['documento'] ?? ''));
        $puesto    = trim((string) ($body['puesto'] ?? ''));

        if ($nombres === '' || $apellidos === '' || $documento === '') {
            ApiResponse::error('Nombres, apellidos y documento son requeridos', 400)->send();
            return;
        }

        $service = new MerchantService();

        if ($service->existeDocumento($documento)) {
            ApiResponse::error('El documento ya existe', 409)->send();
            return;
        }

        $creadoPor = (int) Flight::get('user_id');
        $merchant  = $service->crear($nombres, $apellidos, $documento, $puesto === '' ? null : $puesto, $creadoPor);

        ApiResponse::success('Merchant creado', $merchant, 201)->send();
    }

    public function actualizar(string $id): void
    {
        $body  = $this->body();
        $datos = [];

        if (isset($body['nombres'])) {
            $nombres = trim((string) $body['nombres']);
            if ($nombres === '') {
                ApiResponse::error('Los nombres no pueden estar vacíos', 400)->send();
                return;
            }
            $datos['nombres'] = $nombres;
        }

        if (isset($body['apellidos'])) {
            $apellidos = trim((string) $body['apellidos']);
            if ($apellidos === '') {
                ApiResponse::error('Los apellidos no pueden estar vacíos', 400)->send();
                return;
            }
            $datos['apellidos'] = $apellidos;
        }

        if (isset($body['documento'])) {
            $documento = trim((string) $body['documento']);
            if ($documento === '') {
                ApiResponse::error('El documento no puede estar vacío', 400)->send();
                return;
            }
            $datos['documento'] = $documento;
        }

        if (isset($body['puesto'])) {
            $puesto = trim((string) $body['puesto']);
            $datos['puesto'] = $puesto === '' ? null : $puesto;
        }

        if (!$datos) {
            ApiResponse::error('No hay datos para actualizar', 400)->send();
            return;
        }

        $service = new MerchantService();

        if (isset($datos['documento']) && $service->existeDocumento($datos['documento'], (int) $id)) {
            ApiResponse::error('El documento ya existe', 409)->send();
            return;
        }

        $modificadoPor = (int) Flight::get('user_id');
        $actualizado   = $service->actualizar((int) $id, $datos, $modificadoPor);

        if (!$actualizado) {
            ApiResponse::error('Merchant no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Merchant actualizado')->send();
    }

    public function eliminar(string $id): void
    {
        $service   = new MerchantService();
        $eliminado = $service->eliminar((int) $id);

        if (!$eliminado) {
            ApiResponse::error('Merchant no encontrado', 404)->send();
            return;
        }

        ApiResponse::success('Merchant eliminado')->send();
    }

    public function resetearClave(string $id): void
    {
        $service       = new MerchantService();
        $modificadoPor = (int) Flight::get('user_id');
        $clave         = $service->resetearClave((int) $id, $modificadoPor);

        if ($clave === null) {
            ApiResponse::error('Merchant no encontrado', 404)->send();
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
