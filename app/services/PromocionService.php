<?php

declare(strict_types=1);

class PromocionService
{
    private const MIMES = [
        'image/jpeg' => ['imagen', 'jpg'],
        'image/png'  => ['imagen', 'png'],
        'image/webp' => ['imagen', 'webp'],
        'image/gif'  => ['imagen', 'gif'],
        'video/mp4'  => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
    ];

    private const CAMPOS = 'id, titulo, duracion_segundos, orden, activo, fecha_desde, fecha_hasta, dias_semana, creado_el, modificado_el';

    private PDO $db;

    public function __construct()
    {
        $this->db = Flight::get('db');
    }

    public static function tipoPorMime(string $mime): ?string
    {
        return self::MIMES[$mime][0] ?? null;
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
            $nombre = bin2hex(random_bytes(16)) . '.' . $extension;

            if (!move_uploaded_file($archivo['tmp'], $this->ruta($nombre))) {
                throw new RuntimeException('No se pudo guardar el archivo de la promoción');
            }
            $guardados[] = $nombre;

            $orden++;
            $insert->execute([$promocionId, $tipo, $nombre, $archivo['mime'], $orden, FechaHelper::ahora()]);

            $creados[] = [
                'id'    => (int) $this->db->lastInsertId(),
                'tipo'  => $tipo,
                'mime'  => $archivo['mime'],
                'orden' => $orden,
            ];
        }

        return $creados;
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
