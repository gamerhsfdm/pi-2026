<?php
/**
 * Backup do banco (dump SQL em PHP puro, sem depender de mysqldump) + pasta de uploads.
 * Os arquivos são gravados em BACKUP_DIR (padrão: <projeto>/backups, protegido por .htaccess).
 * Em produção prefira uma pasta FORA da raiz pública: BACKUP_DIR=/home/usuario/backups-tunisia
 */
require_once __DIR__ . '/../config/database.php';

/** Tabelas cujos dados NÃO entram no backup (sensíveis/temporárias); a estrutura é mantida. */
const BACKUP_SKIP_DATA = ['login_attempts', 'password_resets'];

function backupDir(): string {
    $custom = trim((string)envValue('BACKUP_DIR', ''));
    return rtrim($custom !== '' ? $custom : (__DIR__ . '/../backups'), '/\\');
}

function ensureBackupDir(): bool {
    $dir = backupDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    if (!is_writable($dir)) return false;
    // Defesa em profundidade caso a pasta esteja dentro da raiz pública.
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return true;
}

function dumpDatabaseToFile(PDO $pdo, string $path): void {
    $fh = @fopen($path, 'wb');
    if (!$fh) throw new RuntimeException('Não foi possível criar o arquivo temporário do dump.');

    $q = fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);

    try {
        fwrite($fh, "-- Backup do banco " . DB_NAME . " em " . date('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $tableRow) {
            $table = (string)$tableRow[0];
            $create = $pdo->query('SHOW CREATE TABLE ' . $q($table))->fetch(PDO::FETCH_NUM);
            fwrite($fh, "-- ----------------------------\n-- Tabela {$table}\n-- ----------------------------\n");
            fwrite($fh, 'DROP TABLE IF EXISTS ' . $q($table) . ";\n" . $create[1] . ";\n\n");

            if (in_array($table, BACKUP_SKIP_DATA, true)) continue;

            // Leitura sem buffer: tabelas grandes (page_views) não estouram a memória.
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $rows = $pdo->query('SELECT * FROM ' . $q($table));
            $batch = [];
            $columns = null;
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                if ($columns === null) {
                    $columns = implode(',', array_map($q, array_keys($row)));
                }
                $values = [];
                foreach ($row as $value) {
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                }
                $batch[] = '(' . implode(',', $values) . ')';
                if (count($batch) >= 100) {
                    fwrite($fh, 'INSERT INTO ' . $q($table) . " ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $rows->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            if ($batch) {
                fwrite($fh, 'INSERT INTO ' . $q($table) . " ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        fclose($fh);
    }
}

/**
 * Cria um backup (.zip com database.sql + uploads/media; ou .sql.gz se a extensão zip não existir).
 * @return array{ok: bool, file?: string, size?: int, error?: string, note?: string}
 */
function createBackup(PDO $pdo, ?bool $withUploads = null): array {
    $withUploads = $withUploads ?? (envValue('BACKUP_INCLUDE_UPLOADS', '1') !== '0');
    if (!ensureBackupDir()) {
        return ['ok' => false, 'error' => 'Não foi possível criar/gravar a pasta de backups (' . backupDir() . ').'];
    }

    $suffix = date('Ymd-His') . '-' . bin2hex(random_bytes(4)); // sufixo aleatório: nome não adivinhável
    $tmpSql = backupDir() . '/.dump-' . $suffix . '.sql';
    $note = '';
    $target = '';

    try {
        dumpDatabaseToFile($pdo, $tmpSql);

        if (class_exists('ZipArchive')) {
            $target = backupDir() . '/backup-' . $suffix . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo .zip.');
            }
            $zip->addFile($tmpSql, 'database.sql');
            if ($withUploads && is_dir(UPLOADS_DIR)) {
                foreach (glob(UPLOADS_DIR . '/*') ?: [] as $file) {
                    if (!is_file($file) || basename($file) === '.htaccess') continue;
                    $entry = 'uploads/media/' . basename($file);
                    $zip->addFile($file, $entry);
                    // Imagens e vídeos já são comprimidos: apenas armazena (mais rápido).
                    if (preg_match('/\.(png|jpe?g|webp|gif|mp4|pdf)$/i', $file) && method_exists($zip, 'setCompressionName')) {
                        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                    }
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('Falha ao finalizar o arquivo .zip.');
            }
        } else {
            $target = backupDir() . '/backup-' . $suffix . '.sql.gz';
            $gz = gzencode((string)file_get_contents($tmpSql), 6);
            if ($gz === false || file_put_contents($target, $gz) === false) {
                throw new RuntimeException('Falha ao compactar o dump do banco.');
            }
            $note = 'Extensão zip indisponível: este backup contém apenas o banco de dados (sem uploads).';
        }

        if (!is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('O arquivo de backup foi criado vazio.');
        }
    } catch (Throwable $e) {
        error_log('Erro ao criar backup: ' . $e->getMessage());
        if ($target !== '' && is_file($target)) @unlink($target);
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        if (is_file($tmpSql)) @unlink($tmpSql);
    }

    $size = (int)filesize($target);
    try {
        $save = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'publishing')");
        $save->execute(['last_backup_at', date('Y-m-d H:i:s')]);
        $save->execute(['last_backup_file', basename($target)]);
        $save->execute(['last_backup_size', (string)$size]);
    } catch (Throwable $e) {
        error_log('Backup criado, mas não foi possível registrar os dados: ' . $e->getMessage());
    }

    pruneBackups(max(1, (int)envValue('BACKUP_KEEP', '7')));
    return ['ok' => true, 'file' => basename($target), 'size' => $size, 'note' => $note];
}

/** Lista os backups existentes (mais novos primeiro). */
function listBackups(int $limit = 10): array {
    $files = array_merge(glob(backupDir() . '/backup-*.zip') ?: [], glob(backupDir() . '/backup-*.sql.gz') ?: []);
    rsort($files, SORT_STRING); // o nome começa com a data: ordem alfabética = ordem cronológica
    $list = [];
    foreach (array_slice($files, 0, $limit) as $file) {
        $list[] = ['file' => basename($file), 'size' => (int)filesize($file), 'time' => (int)filemtime($file)];
    }
    return $list;
}

function isValidBackupName(string $name): bool {
    return (bool)preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{8}\.(zip|sql\.gz)$/', $name);
}

/** Mantém apenas os N backups mais recentes. */
function pruneBackups(int $keep): void {
    $files = array_merge(glob(backupDir() . '/backup-*.zip') ?: [], glob(backupDir() . '/backup-*.sql.gz') ?: []);
    rsort($files, SORT_STRING);
    foreach (array_slice($files, $keep) as $old) {
        if (isValidBackupName(basename($old))) @unlink($old);
    }
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    return $bytes . ' B';
}
