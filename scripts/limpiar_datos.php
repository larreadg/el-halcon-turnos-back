<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$nuevaClaveAdmin = '4dmin$$ElHalcon';

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->beginTransaction();

try {
    // Limpiar turnos y configuraciones de turno
    $pdo->exec('DELETE FROM turno');
    $pdo->exec('DELETE FROM turno_configuracion');
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('turno', 'turno_configuracion')");

    // Quitar referencias de auditoría del admin antes de borrar el resto de usuarios
    $pdo->exec("UPDATE usuario SET creado_por = NULL, modificado_por = NULL WHERE usuario = 'admin'");

    // Limpiar usuarios, conservando solo el admin
    $stmt = $pdo->prepare("DELETE FROM usuario WHERE usuario != 'admin'");
    $stmt->execute();
    $usuariosEliminados = $stmt->rowCount();

    // Actualizar la clave del admin
    $hash = password_hash($nuevaClaveAdmin, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE usuario SET clave_hash = ?, modificado_el = CURRENT_TIMESTAMP WHERE usuario = 'admin'");
    $stmt->execute([$hash]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException("No se encontró el usuario 'admin'.");
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error al limpiar los datos: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Limpieza completada.\n";
echo "- Turnos y configuraciones de turno eliminados.\n";
echo "- Usuarios eliminados (excepto admin): {$usuariosEliminados}\n";
echo "- Clave del usuario 'admin' actualizada.\n";
