-- Migração segura e idempotente para um banco Projeto Tunísia já existente.
-- NÃO contém DROP, TRUNCATE, DELETE nem INSERT de conteúdo.
-- Faça um backup pelo painel antes de executar no phpMyAdmin.

DELIMITER $$

DROP PROCEDURE IF EXISTS `cms_align_schema`$$
CREATE PROCEDURE `cms_align_schema`()
BEGIN
  DECLARE n INT DEFAULT 0;

  SELECT COUNT(*) INTO n FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND column_name = 'publish_at';
  IF n = 0 THEN ALTER TABLE `sections` ADD COLUMN `publish_at` DATETIME NULL DEFAULT NULL AFTER `draft_data`; END IF;

  SELECT COUNT(*) INTO n FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND column_name = 'style_data';
  IF n = 0 THEN ALTER TABLE `sections` ADD COLUMN `style_data` LONGTEXT NULL AFTER `publish_at`; END IF;

  SELECT COUNT(*) INTO n FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND column_name = 'deleted_at';
  IF n = 0 THEN ALTER TABLE `sections` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `style_data`; END IF;

  SELECT COUNT(*) INTO n FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND column_name = 'locked_by';
  IF n = 0 THEN ALTER TABLE `sections` ADD COLUMN `locked_by` INT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`; END IF;

  SELECT COUNT(*) INTO n FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND column_name = 'locked_at';
  IF n = 0 THEN ALTER TABLE `sections` ADD COLUMN `locked_at` DATETIME NULL DEFAULT NULL AFTER `locked_by`; END IF;

  SELECT COUNT(*) INTO n FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND index_name = 'idx_sections_publish_at';
  IF n = 0 THEN ALTER TABLE `sections` ADD INDEX `idx_sections_publish_at` (`publish_at`); END IF;

  SELECT COUNT(*) INTO n FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'sections' AND index_name = 'idx_sections_deleted_at';
  IF n = 0 THEN ALTER TABLE `sections` ADD INDEX `idx_sections_deleted_at` (`deleted_at`); END IF;

  CREATE TABLE IF NOT EXISTS `support_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `message` TEXT NOT NULL,
    `attachment` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_support_messages_ticket` (`ticket_id`, `created_at`),
    CONSTRAINT `fk_support_message_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_support_message_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$

CALL `cms_align_schema`()$$
DROP PROCEDURE `cms_align_schema`$$
DELIMITER ;

-- Validação após executar a migração.
SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('sections','section_items','media','support_messages')
ORDER BY table_name, ordinal_position;

SELECT identifier, status, is_visible, deleted_at, image_path
FROM sections
ORDER BY display_order, id;

SELECT 'sections' AS source, COUNT(*) AS records FROM sections
UNION ALL SELECT 'section_items', COUNT(*) FROM section_items
UNION ALL SELECT 'media', COUNT(*) FROM media;
