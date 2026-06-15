<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$usuario    = 'admin';
$nuevaClave = '123456';

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$hash = password_hash($nuevaClave, PASSWORD_BCRYPT);

$stmt = $pdo->prepare('UPDATE usuario SET clave_hash = ? WHERE usuario = ?');
$stmt->execute([$hash, $usuario]);

if ($stmt->rowCount() === 0) {
    fwrite(STDERR, "No se encontró el usuario '{$usuario}'.\n");
    exit(1);
}

echo "Clave del usuario '{$usuario}' actualizada correctamente.\n";
