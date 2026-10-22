<?php
/**
 * Backup manual/agendado (banco + uploads). Somente terminal.
 *   php scripts/backup.php              -> respeita a opção "backup automático" do painel
 *   php scripts/backup.php --force      -> executa sempre
 *   php scripts/backup.php --db-only    -> não inclui a pasta uploads
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/../includes/scheduler.php';

$pdo = getPDO();
if (!$pdo) { fwrite(STDERR, "Sem conexão com o banco.\n"); exit(1); }

$force = in_array('--force', $argv ?? [], true);
if (!$force && getSiteSetting($pdo, 'auto_backup', '0') !== '1') {
    echo "Backup automático desativado no painel (use --force para executar mesmo assim).\n";
    exit(0);
}
$res = createBackup($pdo, in_array('--db-only', $argv ?? [], true) ? false : null);
if (!$res['ok']) { fwrite(STDERR, "ERRO: {$res['error']}\n"); exit(1); }
echo "Backup criado: {$res['file']} (" . formatBytes($res['size']) . ")\n" . ($res['note'] ? $res['note'] . "\n" : '');
