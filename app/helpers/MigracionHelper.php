<?php

declare(strict_types=1);

/**
 * Migraciones de database/migrations/ para bases creadas antes de cada cambio de esquema.
 * Cada migración se detecta por el estado de las tablas, así que es seguro correrlas siempre.
 */
class MigracionHelper
{
    private const DIRECTORIO = __DIR__ . '/../../database/migrations/';

    private const MIGRACIONES = [
        '001_promociones.sql',
        '002_promocion_archivos.sql',
        '003_promocion_publicacion.sql',
        '004_turno_llamar_nuevamente.sql',
    ];

    /**
     * Migraciones que faltan aplicar, en orden.
     *
     * @return string[]
     */
    public static function pendientes(PDO $pdo): array
    {
        return array_values(array_filter(
            self::MIGRACIONES,
            fn (string $migracion): bool => self::estaPendiente($pdo, $migracion)
        ));
    }

    /**
     * Aplica las migraciones pendientes, cada una en su propia transacción.
     *
     * @return string[] migraciones aplicadas
     */
    public static function aplicarPendientes(PDO $pdo): array
    {
        $aplicadas = [];

        foreach (self::pendientes($pdo) as $migracion) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // Volver a verificar dentro de la transacción por si otro request ya migró
                if (self::estaPendiente($pdo, $migracion)) {
                    $pdo->exec(file_get_contents(self::DIRECTORIO . $migracion));
                    $aplicadas[] = $migracion;
                }
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }

        return $aplicadas;
    }

    // Si promocion no existe, 001 la crea con el esquema viejo y hacen falta también 002 y 003
    private static function estaPendiente(PDO $pdo, string $migracion): bool
    {
        $promocion = self::columnas($pdo, 'promocion');

        return match ($migracion) {
            '001_promociones.sql'             => !$promocion,
            '002_promocion_archivos.sql'      => !$promocion || in_array('archivo', $promocion, true),
            '003_promocion_publicacion.sql'   => !$promocion || !in_array('dias_semana', $promocion, true),
            '004_turno_llamar_nuevamente.sql' => self::faltaColumna($pdo, 'turno', 'llamar_nuevamente'),
        };
    }

    // Solo si la tabla existe: sin la tabla no hay columna que agregar
    private static function faltaColumna(PDO $pdo, string $tabla, string $columna): bool
    {
        $columnas = self::columnas($pdo, $tabla);

        return $columnas && !in_array($columna, $columnas, true);
    }

    /**
     * @return string[]
     */
    public static function columnas(PDO $pdo, string $tabla): array
    {
        // PRAGMA no acepta parámetros bindeados; pragma_table_info(?) requiere SQLite 3.16+,
        // que algunos hostings no tienen. El nombre se escapa como identificador.
        $identificador = '"' . str_replace('"', '""', $tabla) . '"';

        return array_column($pdo->query("PRAGMA table_info({$identificador})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
}
