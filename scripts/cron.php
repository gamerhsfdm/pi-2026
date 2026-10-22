<?php
/**
 * Rotina periódica (publicações agendadas + backup diário). Somente terminal.
 * Cron (a cada minuto):  * * * * * /usr/bin/php /caminho/do/projeto/scripts/cron.php >/dev/null 2>&1
 * Windows (Agendador de Tarefas): C:\xampp\php\php.exe C:\xampp\htdocs\ProjetoIntegrador-2026\scripts\cron.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/../includes/scheduler.php';

$pdo = getPDO();
if (!$pdo) { fwrite(STDERR, "Sem conexão com o banco.\n"); exit(1); }

$r = runScheduledJobs($pdo);
echo date('c') . " publicadas={$r['published']}";
if ($r['backup'] !== null) {
    echo $r['backup']['ok'] ? " backup={$r['backup']['file']}" : " backup=ERRO({$r['backup']['error']})";
}
echo "\n";
exit(($r['backup']['ok'] ?? true) ? 0 : 1);
