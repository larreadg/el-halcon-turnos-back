<?php

declare(strict_types=1);

class PromocionController
{
    private const DURACION_MAXIMA = 300;
    private const MAXIMO_ARCHIVOS = 20;

    public function listar(): void
    {
        $service = new PromocionService();
        ApiResponse::success('Promociones obtenidas', $service->listar())->send();
    }

    public function pantalla(): void
    {
        $service = new PromocionService();
        ApiResponse::success('Promociones obtenidas', $service->listarActivas())->send();
    }

    public function crear(): void
    {
        $archivos = $this->archivosSubidos();
        if ($archivos instanceof ApiResponse) {
            $archivos->send();
            return;
        }

        $datos = $this->validar($_POST, true);
        if (is_string($datos)) {
            ApiResponse::error($datos, 400)->send();
            return;
        }

        $creadoPor = (int) Flight::get('user_id');
        $service   = new PromocionService();
        $promocion = $service->crear($datos, $archivos, $creadoPor);

        ApiResponse::success('Promoción creada', $promocion, 201)->send();
    }

    public function agregarArchivos(string $id): void
    {
        $archivos = $this->archivosSubidos();
        if ($archivos instanceof ApiResponse) {
            $archivos->send();
            return;
        }

        $modificadoPor = (int) Flight::get('user_id');
        $service       = new PromocionService();
        $creados       = $service->agregarArchivos((int) $id, $archivos, $modificadoPor);

        if ($creados === null) {
            ApiResponse::error('Promoción no encontrada', 404)->send();
            return;
        }

        ApiResponse::success('Archivos agregados', $creados, 201)->send();
    }

    public function eliminarArchivo(string $id, string $archivoId): void
    {
        $modificadoPor = (int) Flight::get('user_id');
        $service       = new PromocionService();
        $resultado     = $service->eliminarArchivo((int) $id, (int) $archivoId, $modificadoPor);

        if ($resultado === 'no_encontrado') {
            ApiResponse::error('Archivo no encontrado', 404)->send();
            return;
        }

        if ($resultado === 'ultimo') {
            ApiResponse::error('La promoción debe tener al menos un archivo', 409)->send();
            return;
        }

        ApiResponse::success('Archivo eliminado')->send();
    }

    public function ordenarArchivos(string $id): void
    {
        $ids = $this->body()['ids'] ?? null;

        if (!is_array($ids) || !$ids || array_filter($ids, fn ($v) => !is_numeric($v))) {
            ApiResponse::error('ids debe ser una lista con los archivos en el nuevo orden', 400)->send();
            return;
        }

        $modificadoPor = (int) Flight::get('user_id');
        $service       = new PromocionService();
        $ordenado      = $service->ordenarArchivos((int) $id, array_map('intval', $ids), $modificadoPor);

        if (!$ordenado) {
            ApiResponse::error('La lista de archivos no coincide con los de la promoción', 400)->send();
            return;
        }

        ApiResponse::success('Archivos ordenados')->send();
    }

    public function actualizar(string $id): void
    {
        $service = new PromocionService();
        $actual  = $service->obtener((int) $id);

        if (!$actual) {
            ApiResponse::error('Promoción no encontrada', 404)->send();
            return;
        }

        $datos = $this->validar($this->body(), false, $actual);
        if (is_string($datos)) {
            ApiResponse::error($datos, 400)->send();
            return;
        }

        if (!$datos) {
            ApiResponse::error('No hay datos para actualizar', 400)->send();
            return;
        }

        $modificadoPor = (int) Flight::get('user_id');
        $actualizado   = $service->actualizar((int) $id, $datos, $modificadoPor);

        if (!$actualizado) {
            ApiResponse::error('Promoción no encontrada', 404)->send();
            return;
        }

        ApiResponse::success('Promoción actualizada')->send();
    }

    public function eliminar(string $id): void
    {
        $service   = new PromocionService();
        $eliminado = $service->eliminar((int) $id);

        if (!$eliminado) {
            ApiResponse::error('Promoción no encontrada', 404)->send();
            return;
        }

        ApiResponse::success('Promoción eliminada')->send();
    }

    /**
     * Sirve un archivo de promoción (público, para <img>/<video>).
     * Soporta Range para que los navegadores puedan reproducir videos correctamente.
     */
    public function archivo(string $archivoId): void
    {
        $service = new PromocionService();
        $archivo = $service->archivo((int) $archivoId);

        if (!$archivo) {
            ApiResponse::error('Archivo no encontrado', 404)->send();
            return;
        }

        $tamano = filesize($archivo['ruta']);
        $inicio = 0;
        $fin    = $tamano - 1;

        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $inicio = max(0, $tamano - (int) $m[2]);
            } else {
                $inicio = (int) $m[1];
                $fin    = $m[2] !== '' ? min((int) $m[2], $tamano - 1) : $fin;
            }

            if ($inicio > $fin || $inicio >= $tamano) {
                http_response_code(416);
                header("Content-Range: bytes */$tamano");
                exit();
            }

            http_response_code(206);
            header("Content-Range: bytes $inicio-$fin/$tamano");
        } else {
            http_response_code(200);
        }

        header('Content-Type: ' . $archivo['mime']);
        header('Content-Length: ' . ($fin - $inicio + 1));
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=604800, immutable');

        // Descartar el buffer de salida de Flight para enviar el archivo en streaming
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        set_time_limit(0);

        $handle = fopen($archivo['ruta'], 'rb');
        fseek($handle, $inicio);

        $restante = $fin - $inicio + 1;
        while ($restante > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $restante));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
            $restante -= strlen($chunk);
        }

        fclose($handle);
        exit();
    }

    /**
     * Valida los archivos del campo multipart "archivos[]".
     * Devuelve la lista de archivos ({tmp, mime}) o la respuesta de error a enviar.
     *
     * @return array<int, array{tmp: string, mime: string}>|ApiResponse
     */
    private function archivosSubidos(): array|ApiResponse
    {
        $campo = $_FILES['archivos'] ?? null;

        if (!$campo) {
            // Si el body supera post_max_size, PHP descarta $_POST y $_FILES por completo
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST)) {
                return ApiResponse::error('Los archivos superan el tamaño máximo permitido por el servidor', 413);
            }

            return ApiResponse::error('Seleccioná al menos un archivo', 400);
        }

        // Normalizar archivos[] (arrays paralelos) a una lista de archivos
        $cantidad = is_array($campo['name']) ? count($campo['name']) : 1;
        $lista    = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $lista[] = [
                'nombre' => (string) (is_array($campo['name']) ? $campo['name'][$i] : $campo['name']),
                'tmp'    => (string) (is_array($campo['tmp_name']) ? $campo['tmp_name'][$i] : $campo['tmp_name']),
                'error'  => (int) (is_array($campo['error']) ? $campo['error'][$i] : $campo['error']),
                'size'   => (int) (is_array($campo['size']) ? $campo['size'][$i] : $campo['size']),
            ];
        }

        if (count($lista) > self::MAXIMO_ARCHIVOS) {
            return ApiResponse::error('No se pueden subir más de ' . self::MAXIMO_ARCHIVOS . ' archivos a la vez', 400);
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $maxMb    = intdiv(PROMOCIONES_MAX_BYTES, 1024 * 1024);
        $archivos = [];

        foreach ($lista as $archivo) {
            $nombre = $archivo['nombre'];

            if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return ApiResponse::error("\"$nombre\" supera el tamaño máximo permitido por el servidor", 413);
            }

            if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp'])) {
                return ApiResponse::error("No se pudo subir \"$nombre\"", 400);
            }

            if ($archivo['size'] > PROMOCIONES_MAX_BYTES) {
                return ApiResponse::error("\"$nombre\" no puede superar los {$maxMb} MB", 413);
            }

            $mime = (string) $finfo->file($archivo['tmp']);

            if (PromocionService::tipoPorMime($mime) === null) {
                return ApiResponse::error("\"$nombre\" tiene un formato no soportado. Usá JPG, PNG, WEBP, GIF, MP4 o WEBM", 400);
            }

            // Las imágenes deben tener exactamente el tamaño del área del carrusel
            if (PromocionService::tipoPorMime($mime) === 'imagen') {
                $dimensiones = @getimagesize($archivo['tmp']);
                [$ancho, $alto] = $dimensiones ?: [0, 0];

                if ($ancho !== PROMOCIONES_ANCHO || $alto !== PROMOCIONES_ALTO) {
                    $requerido = PROMOCIONES_ANCHO . ' × ' . PROMOCIONES_ALTO;
                    return ApiResponse::error("\"$nombre\" mide {$ancho} × {$alto} px; debe medir exactamente {$requerido} px", 400);
                }
            }

            $archivos[] = ['tmp' => $archivo['tmp'], 'mime' => $mime];
        }

        return $archivos;
    }

    /**
     * Normaliza los campos editables. Devuelve el array de datos o un mensaje de error.
     * $actual es la promoción guardada, para validar el rango de fechas cuando solo llega una.
     */
    private function validar(array $body, bool $creando, ?array $actual = null): array|string
    {
        $datos = [];

        if ($creando || array_key_exists('titulo', $body)) {
            $titulo = trim((string) ($body['titulo'] ?? ''));
            if ($titulo === '') {
                return 'El título es requerido';
            }
            if (mb_strlen($titulo) > 120) {
                return 'El título no puede superar los 120 caracteres';
            }
            $datos['titulo'] = $titulo;
        }

        if ($creando || array_key_exists('duracion_segundos', $body)) {
            $duracion = $body['duracion_segundos'] ?? 10;
            if (!is_numeric($duracion) || (int) $duracion < 1 || (int) $duracion > self::DURACION_MAXIMA) {
                return 'La duración debe ser un número entre 1 y ' . self::DURACION_MAXIMA . ' segundos';
            }
            $datos['duracion_segundos'] = (int) $duracion;
        }

        if ($creando || array_key_exists('orden', $body)) {
            $orden = $body['orden'] ?? 0;
            if (!is_numeric($orden) || (int) $orden < 0) {
                return 'El orden debe ser un número mayor o igual a 0';
            }
            $datos['orden'] = (int) $orden;
        }

        if ($creando || array_key_exists('activo', $body)) {
            $activo = $body['activo'] ?? true;
            $datos['activo'] = filter_var($activo, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        foreach (['fecha_desde' => 'desde', 'fecha_hasta' => 'hasta'] as $campo => $nombre) {
            if ($creando || array_key_exists($campo, $body)) {
                $fecha = trim((string) ($body[$campo] ?? ''));
                if ($fecha !== '' && !$this->esFechaValida($fecha)) {
                    return "La fecha $nombre debe tener el formato AAAA-MM-DD";
                }
                $datos[$campo] = $fecha === '' ? null : $fecha;
            }
        }

        $desde = array_key_exists('fecha_desde', $datos) ? $datos['fecha_desde'] : ($actual['fecha_desde'] ?? null);
        $hasta = array_key_exists('fecha_hasta', $datos) ? $datos['fecha_hasta'] : ($actual['fecha_hasta'] ?? null);
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            return 'La fecha hasta no puede ser anterior a la fecha desde';
        }

        if ($creando || array_key_exists('dias_semana', $body)) {
            // Llega como lista (JSON) o como "1,3,5" (multipart); vacío = todos los días
            $dias = $body['dias_semana'] ?? [];
            if (is_string($dias)) {
                $dias = $dias === '' ? [] : explode(',', $dias);
            }
            if (!is_array($dias)) {
                return 'dias_semana debe ser una lista de días (1 = lunes ... 7 = domingo)';
            }

            $normalizados = [];
            foreach ($dias as $dia) {
                $dia = trim((string) $dia);
                if (!ctype_digit($dia) || (int) $dia < 1 || (int) $dia > 7) {
                    return 'dias_semana debe ser una lista de días (1 = lunes ... 7 = domingo)';
                }
                $normalizados[(int) $dia] = (int) $dia;
            }
            ksort($normalizados);

            // Sin días o con los 7 se publica todos los días
            $datos['dias_semana'] = !$normalizados || count($normalizados) === 7
                ? null
                : implode(',', $normalizados);
        }

        return $datos;
    }

    private function esFechaValida(string $fecha): bool
    {
        $parseada = DateTime::createFromFormat('!Y-m-d', $fecha);
        return $parseada !== false && $parseada->format('Y-m-d') === $fecha;
    }

    private function body(): array
    {
        $data = json_decode(Flight::request()->body, true);
        return is_array($data) ? $data : [];
    }
}
