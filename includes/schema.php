<?php
/**
 * Migrações leves do banco para instalações que já existiam antes da Fase 2.
 * O resultado é memorizado em cache/schema-*.ok (apague o arquivo para forçar nova verificação).
 */
require_once __DIR__ . '/../config/database.php';

function ensureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $flag = CACHE_DIR . '/schema-v3-' . md5(DB_HOST . '|' . DB_PORT . '|' . DB_NAME) . '.ok';
    if (is_file($flag)) { $done = true; return; }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ip_address` VARCHAR(45) NOT NULL,
            `email` VARCHAR(150) NOT NULL DEFAULT '',
            `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_login_ip` (`ip_address`, `attempted_at`),
            INDEX `idx_login_email` (`email`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $column = $pdo->query("SHOW COLUMNS FROM `sections` LIKE 'publish_at'")->fetch();
        if (!$column) {
            $pdo->exec("ALTER TABLE `sections`
                ADD COLUMN `publish_at` DATETIME NULL DEFAULT NULL AFTER `draft_data`,
                ADD INDEX `idx_sections_publish_at` (`publish_at`)");
        }

        $styleColumn = $pdo->query("SHOW COLUMNS FROM `sections` LIKE 'style_data'")->fetch();
        if (!$styleColumn) {
            $pdo->exec("ALTER TABLE `sections` ADD COLUMN `style_data` LONGTEXT NULL AFTER `publish_at`");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_reset_token` (`token_hash`),
            INDEX `idx_reset_user` (`user_id`, `created_at`),
            INDEX `idx_reset_ip` (`ip_address`, `created_at`),
            CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $done = true;
        if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0755, true); }
        @file_put_contents($flag, date('c'));
    } catch (Throwable $e) {
        error_log('Erro ao atualizar o esquema do banco: ' . $e->getMessage());
    }
}
