<?php
/** Download de backups: somente administradores logados e apenas arquivos gerados pelo sistema. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/backup.php';
requireAuth(true);

$name = (string)($_GET['file'] ?? '');
if (!isValidBackupName($name)) {
    http_response_code(400);
    exit('Arquivo inválido.');
}
$path = backupDir() . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('Backup não encontrado.');
}
logAudit('baixou backup', $name);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
