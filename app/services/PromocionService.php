<?php

declare(strict_types=1);

class PromocionService
{
    private const MIMES = [
        'image/jpeg' => ['imagen', 'jpg'],
        'image/png'  => ['imagen', 'png'],
        'image/webp' => ['imagen', 'webp'],
        'video/mp4'  => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
    ];

    /** Calidades a probar en orden hasta que la imagen entre en el máximo permitido. */
    private const CALIDADES_WEBP = [85, 75, 65, 50, 35];

    private const CAMPOS ='id, titulo, duracion_segundos, orden, activo, fecha_desde, fecha_hasta, dias_semana, creado_el, modificado_el';

    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public static function tipoPorMime(string $mime): ?string
    {
        return self::MIMES[$mime][0] ?? null;
    }

    /**
     * Detecta el MIME real de un archivo por su firma (primeros bytes), solo para los formatos
     * soportados. No usa finfo porque la extensión fileinfo no está disponible en todos los hostings.
     * Devuelve null si el contenido no corresponde a ningún formato soportado.
     */
    public static function detectarMime(string $ruta): ?string
    {
        $cabecera = (string) @file_get_contents($ruta, false, null, 0, 64);

        if (str_starts_with($cabecera, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($cabecera, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        if (str_starts_with($cabecera, 'RIFF') && substr($cabecera, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // EBML: WEBM y Matroska comparten cabecera, se distinguen por el DocType
        if (str_starts_with($cabecera, "\x1A\x45\xDF\xA3")) {
            return preg_match('/\x42\x82.{1,8}webm/s', $cabecera) ? 'video/webm' : null;
        }

        // MP4: caja "ftyp" con la marca principal y las compatibles (de a 4 bytes)
        if (substr($cabecera, 4, 4) === 'ftyp') {
            $tamanio = unpack('N', substr($cabecera, 0, 4))[1];
            $caja    = substr($cabecera, 8, max(0, min($tamanio, strlen($cabecera)) - 8));
            $marcas  = array_map('rtrim', str_split($caja, 4));

            // QuickTime (.mov), 3GP, audio (M4A/M4B) y fotos HEIC/AVIF también usan ftyp
            if (str_starts_with($marcas[0], '3g') || in_array($marcas[0], ['qt', 'M4A', 'M4B', 'M4P', 'heic', 'heix', 'mif1', 'msf1', 'avif'], true)) {
                return null;
            }

            $marcasMp4 = ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V', 'mmp4', 'dash', 'MSNV'];

            return array_intersect($marcas, $marcasMp4) ? 'video/mp4' : null;
        }

        return null;
    }

    public function listar(): array
    {
        $promociones = $this->db->query(
            'SELECT ' . self::CAMPOS . ' FROM promocion ORDER BY orden, id'
        )->fetchAll();

        return $this->conArchivos($promociones);
    }

    /**
     * Promociones activas con al menos un archivo que se publican hoy, para la pantalla pública.
     */
    public function listarActivas(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, titulo, duracion_segundos, orden
             FROM promocion
             WHERE activo = 1
               AND (fecha_desde IS NULL OR fecha_desde <= :hoy)
               AND (fecha_hasta IS NULL OR fecha_hasta >= :hoy)
               AND (dias_semana IS NULL OR instr(',' || dias_semana || ',', :dia) > 0)
             ORDER BY orden, id"
        );
        $stmt->execute([
            'hoy' => FechaHelper::hoy(),
            'dia' => ',' . date('N') . ',',
        ]);
        $promociones = $stmt->fetchAll();

        return array_values(array_filter(
            $this->conArchivos($promociones),
            fn (array $p): bool => count($p['archivos']) > 0
        ));
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM promocion WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->conArchivos([$row])[0] : null;
    }

    /**
     * Ruta absoluta y mime de un archivo de promoción, si existe en disco.
     */
    public function archivo(int $archivoId): ?array
    {
        $stmt = $this->db->prepare('SELECT archivo, mime FROM promocion_archivo WHERE id = ?');
        $stmt->execute([$archivoId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $ruta = $this->ruta((string) $row['archivo']);

        return is_file($ruta) ? ['ruta' => $ruta, 'mime' => $row['mime']] : null;
    }

    /**
     * Crea la promoción con sus archivos.
     *
     * @param array<int, array{tmp: string, mime: string}> $archivos
     */
    public function crear(array $datos, array $archivos, int $creadoPor): array
    {
        $this->db->beginTransaction();
        $guardados = [];

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO promocion (titulo, duracion_segundos, orden, activo, fecha_desde, fecha_hasta, dias_semana, creado_por, creado_el)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $datos['titulo'],
                $datos['duracion_segundos'],
                $datos['orden'],
                $datos['activo'],
                $datos['fecha_desde'],
                $datos['fecha_hasta'],
                $datos['dias_semana'],
                $creadoPor,
                FechaHelper::ahora(),
            ]);

            $id = (int) $this->db->lastInsertId();
            $this->insertarArchivos($id, $archivos, $guardados);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->borrarDelDisco($guardados);
            throw $e;
        }

        return $this->obtener($id);
    }

    /**
     * Agrega archivos al final de una promoción existente.
     * Devuelve los archivos creados (en el mismo orden recibido) o null si la promoción no existe.
     *
     * @param array<int, array{tmp: string, mime: string}> $archivos
     */
    public function agregarArchivos(int $promocionId, array $archivos, int $modificadoPor): ?array
    {
        if (!$this->existe($promocionId)) {
            return null;
        }

        $this->db->beginTransaction();
        $guardados = [];

        try {
            $creados = $this->insertarArchivos($promocionId, $archivos, $guardados);
            $this->marcarModificada($promocionId, $modificadoPor);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->borrarDelDisco($guardados);
            throw $e;
        }

        return $creados;
    }

    /**
     * Elimina un archivo de la promoción.
     * Devuelve 'ok', 'no_encontrado' o 'ultimo' (una promoción no puede quedar sin archivos).
     */
    public function eliminarArchivo(int $promocionId, int $archivoId, int $modificadoPor): string
    {
        $stmt = $this->db->prepare('SELECT archivo FROM promocion_archivo WHERE id = ? AND promocion_id = ?');
        $stmt->execute([$archivoId, $promocionId]);
        $archivo = $stmt->fetchColumn();

        if ($archivo === false) {
            return 'no_encontrado';
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM promocion_archivo WHERE promocion_id = ?');
        $stmt->execute([$promocionId]);

        if ((int) $stmt->fetchColumn() <= 1) {
            return 'ultimo';
        }

        $this->db->prepare('DELETE FROM promocion_archivo WHERE id = ?')->execute([$archivoId]);
        $this->marcarModificada($promocionId, $modificadoPor);
        $this->borrarDelDisco([(string) $archivo]);

        return 'ok';
    }

    /**
     * Reordena los archivos de la promoción. $ids debe contener exactamente los archivos actuales.
     *
     * @param int[] $ids
     */
    public function ordenarArchivos(int $promocionId, array $ids, int $modificadoPor): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM promocion_archivo WHERE promocion_id = ?');
        $stmt->execute([$promocionId]);
        $actuales = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $recibidos = $ids;
        sort($actuales);
        sort($recibidos);

        if (!$actuales || $actuales !== $recibidos) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare('UPDATE promocion_archivo SET orden = ? WHERE id = ?');
            foreach (array_values($ids) as $orden => $id) {
                $update->execute([$orden, $id]);
            }
            $this->marcarModificada($promocionId, $modificadoPor);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return true;
    }

    public function actualizar(int $id, array $datos, int $modificadoPor): bool
    {
        $campos = [];
        $params = [];

        foreach (['titulo', 'duracion_segundos', 'orden', 'activo', 'fecha_desde', 'fecha_hasta', 'dias_semana'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $campos[] = "$campo = ?";
                $params[] = $datos[$campo];
            }
        }

        if (!$campos) {
            return false;
        }

        $campos[] = 'modificado_por = ?';
        $params[] = $modificadoPor;
        $campos[] = 'modificado_el = ?';
        $params[] = FechaHelper::ahora();

        $params[] = $id;

        $stmt = $this->db->prepare('UPDATE promocion SET ' . implode(', ', $campos) . ' WHERE id = ?');
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Activa o desactiva varias promociones. Devuelve cuántas existían.
     *
     * @param int[] $ids
     */
    public function cambiarEstado(array $ids, int $activo, int $modificadoPor): int
    {
        $ids          = array_values(array_unique($ids));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            "UPDATE promocion SET activo = ?, modificado_por = ?, modificado_el = ?
             WHERE id IN ($placeholders)"
        );
        $stmt->execute([$activo, $modificadoPor, FechaHelper::ahora(), ...$ids]);

        return $stmt->rowCount();
    }

    public function eliminar(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT archivo FROM promocion_archivo WHERE promocion_id = ?');
        $stmt->execute([$id]);
        $archivos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // promocion_archivo se elimina en cascada
        $stmt = $this->db->prepare('DELETE FROM promocion WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->borrarDelDisco($archivos);

        return true;
    }

    /**
     * Mueve los archivos subidos a storage/promociones y los registra a continuación de los existentes.
     * Los nombres guardados en disco se agregan a $guardados para poder limpiarlos si algo falla.
     */
    private function insertarArchivos(int $promocionId, array $archivos, array &$guardados): array
    {
        if (!is_dir(PROMOCIONES_PATH)) {
            mkdir(PROMOCIONES_PATH, 0775, true);
        }

        $stmt = $this->db->prepare('SELECT COALESCE(MAX(orden), -1) FROM promocion_archivo WHERE promocion_id = ?');
        $stmt->execute([$promocionId]);
        $orden = (int) $stmt->fetchColumn();

        $insert = $this->db->prepare(
            'INSERT INTO promocion_archivo (promocion_id, tipo, archivo, mime, orden, creado_el)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $creados = [];
        foreach ($archivos as $archivo) {
            [$tipo, $extension] = self::MIMES[$archivo['mime']];
            $mime = $archivo['mime'];

            // Las imágenes se guardan siempre como WEBP; los videos se guardan tal cual
            if ($tipo === 'imagen') {
                $extension = 'webp';
                $mime      = 'image/webp';
            }

            $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
            $ruta   = $this->ruta($nombre);

            // Un WEBP que ya entra en el máximo se guarda sin volver a comprimirlo
            $comprimir = $tipo === 'imagen'
                && ($archivo['mime'] !== 'image/webp' || filesize($archivo['tmp']) > PROMOCIONES_MAX_BYTES_IMAGEN);

            $guardado = $comprimir
                ? self::convertirAWebp($archivo['tmp'], $archivo['mime'], $ruta)
                : move_uploaded_file($archivo['tmp'], $ruta);

            if (!$guardado) {
                @unlink($ruta);
                throw new RuntimeException('No se pudo guardar el archivo de la promoción');
            }
            $guardados[] = $nombre;

            $orden++;
            $insert->execute([$promocionId, $tipo, $nombre, $mime, $orden, FechaHelper::ahora()]);

            $creados[] = [
                'id'    => (int) $this->db->lastInsertId(),
                'tipo'  => $tipo,
                'mime'  => $mime,
                'orden' => $orden,
            ];
        }

        return $creados;
    }

    /**
     * Convierte una imagen JPG/PNG/WEBP a WEBP con GD, conservando la transparencia.
     * Si el resultado supera PROMOCIONES_MAX_BYTES_IMAGEN, baja la calidad hasta que entre.
     */
    public static function convertirAWebp(string $origen, string $mime, string $destino): bool
    {
        if (!function_exists('imagewebp')) {
            throw new RuntimeException('La extensión GD con soporte WEBP no está habilitada en el servidor');
        }

        $imagen = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($origen),
            'image/png'  => @imagecreatefrompng($origen),
            'image/webp' => @imagecreatefromwebp($origen),
            default      => false,
        };

        if ($imagen === false) {
            return false;
        }

        imagepalettetotruecolor($imagen);
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        $ok = false;
        foreach (self::CALIDADES_WEBP as $calidad) {
            if (!imagewebp($imagen, $destino, $calidad)) {
                break;
            }

            clearstatcache(true, $destino);
            if (filesize($destino) <= PROMOCIONES_MAX_BYTES_IMAGEN) {
                $ok = true;
                break;
            }
        }
        imagedestroy($imagen);

        return $ok;
    }

    /**
     * Agrega a cada promoción su lista de archivos ordenada.
     * Si se seleccionó dias_semana, lo devuelve como lista de días ISO (vacía = todos los días).
     */
    private function conArchivos(array $promociones): array
    {
        if (!$promociones) {
            return [];
        }

        $ids          = array_column($promociones, 'id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            "SELECT id, promocion_id, tipo, mime, orden
             FROM promocion_archivo
             WHERE promocion_id IN ($placeholders)
             ORDER BY orden, id"
        );
        $stmt->execute($ids);

        $porPromocion = [];
        foreach ($stmt->fetchAll() as $archivo) {
            $promocionId = (int) $archivo['promocion_id'];
            unset($archivo['promocion_id']);
            $porPromocion[$promocionId][] = $archivo;
        }

        foreach ($promociones as &$promocion) {
            if (array_key_exists('dias_semana', $promocion)) {
                $promocion['dias_semana'] = $promocion['dias_semana'] === null
                    ? []
                    : array_map('intval', explode(',', (string) $promocion['dias_semana']));
            }
            $promocion['archivos'] = $porPromocion[(int) $promocion['id']] ?? [];
        }

        return $promociones;
    }

    private function existe(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM promocion WHERE id = ?');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    private function marcarModificada(int $id, int $modificadoPor): void
    {
        $this->db->prepare('UPDATE promocion SET modificado_por = ?, modificado_el = ? WHERE id = ?')
            ->execute([$modificadoPor, FechaHelper::ahora(), $id]);
    }

    private function ruta(string $nombre): string
    {
        return PROMOCIONES_PATH . '/' . basename($nombre);
    }

    private function borrarDelDisco(array $nombres): void
    {
        foreach ($nombres as $nombre) {
            @unlink($this->ruta((string) $nombre));
        }
    }
}
