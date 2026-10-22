-- =======================================================
-- Banco de Dados: projeto_tunisia
-- Sistema de Gerenciamento de Conteúdo (CMS) - Projeto Tunísia
-- Compatível com phpMyAdmin, MySQL 5.7+ / MariaDB 10.3+
-- =======================================================

-- Execute este arquivo APÓS selecionar, no phpMyAdmin, o mesmo banco definido em DB_NAME no .env.
-- Não use CREATE DATABASE/USE aqui: isso poderia importar a estrutura no banco errado.

-- --------------------------------------------------------
-- Tabela: users (Usuários e Controle de Acesso)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'editor') NOT NULL DEFAULT 'editor',
  `avatar` VARCHAR(255) NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Não crie usuários pelo arquivo de estrutura. Use admin/create_admin.php para criar
-- o primeiro administrador sem sobrescrever uma conta existente.

-- --------------------------------------------------------
-- Tabela: site_settings (Configurações Gerais e de Publicação)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: media (Biblioteca de Mídia)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `filepath` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(50) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `alt_text` VARCHAR(255) NULL,
  `user_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_media_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: sections (Seções do Site Tunísia)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sections` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(60) NOT NULL UNIQUE,
  `title` VARCHAR(150) NOT NULL,
  `subtitle` VARCHAR(255) NULL,
  `badge` VARCHAR(100) NULL,
  `description` TEXT NULL,
  `primary_btn_text` VARCHAR(80) NULL,
  `primary_btn_link` VARCHAR(255) NULL,
  `secondary_btn_text` VARCHAR(80) NULL,
  `secondary_btn_link` VARCHAR(255) NULL,
  `image_path` VARCHAR(255) NULL,
  `video_path` VARCHAR(255) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('published', 'draft') NOT NULL DEFAULT 'published',
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `custom_data` LONGTEXT NULL,
  `draft_data` LONGTEXT NULL,
  `publish_at` DATETIME NULL DEFAULT NULL,
  `style_data` LONGTEXT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `locked_by` INT UNSIGNED NULL DEFAULT NULL,
  `locked_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ,INDEX `idx_sections_deleted_at` (`deleted_at`)
  ,INDEX `idx_sections_publish_at` (`publish_at`)
  ,CONSTRAINT `fk_sections_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: section_items (Itens de cada seção: cards, timeline, etc.)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `section_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT UNSIGNED NOT NULL,
  `item_type` VARCHAR(50) NOT NULL DEFAULT 'card',
  `title` VARCHAR(150) NOT NULL,
  `subtitle` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `icon` VARCHAR(100) NULL,
  `image_path` VARCHAR(255) NULL,
  `badge` VARCHAR(100) NULL,
  `link_url` VARCHAR(255) NULL,
  `meta_data` LONGTEXT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('published', 'draft') NOT NULL DEFAULT 'published',
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_items_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: versions (Histórico e Versionamento de Conteúdos)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT UNSIGNED NOT NULL,
  `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `title` VARCHAR(150) NOT NULL,
  `data_payload` LONGTEXT NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `user_name` VARCHAR(100) NOT NULL DEFAULT 'Admin',
  `action_type` ENUM('published', 'saved_draft', 'restored') NOT NULL DEFAULT 'saved_draft',
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_versions_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_versions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: audit_logs (Auditoria e Logs de Atividades)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: login_attempts (limite de tentativas de login por IP/e-mail)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `email` VARCHAR(150) NOT NULL DEFAULT '',
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_login_ip` (`ip_address`, `attempted_at`),
  INDEX `idx_login_email` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: password_resets (recuperação de senha por e-mail; só o hash do token é guardado)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: page_views (Registro de Visualizações Reais)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `page_views` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_views_date` (`created_at`),
  INDEX `idx_views_ip` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: notifications (Notificações do CMS)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(50) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `section_id` INT UNSIGNED NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notif_read` (`is_read`),
  INDEX `idx_notif_date` (`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabela: support_tickets (Central de Suporte e Chamados)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `subject` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `attachment` VARCHAR(255) NULL,
  `status` ENUM('open', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tickets_user` (`user_id`),
  CONSTRAINT `fk_ticket_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mensagens trocadas em cada chamado (consultada por admin/support.php).
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

/*
  Dados de exemplo para instalações novas foram desabilitados intencionalmente.
  Este arquivo é seguro para importar em um banco já existente: não altera títulos,
  imagens, usuários, configurações ou itens. Crie o primeiro administrador por
  admin/create_admin.php e cadastre conteúdo pelo painel.

-- --------------------------------------------------------
-- Inserções Iniciais: Configurações Gerais
-- --------------------------------------------------------
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('site_name', 'Projeto Tunísia', 'general'),
('site_title', 'Projeto Tunísia - Cultura, Inovação e Conhecimento', 'seo'),
('site_description', 'Uma jornada completa pelos aspectos culturais, econômicos, históricos e tecnológicos de um dos países mais fascinantes do Mediterrâneo.', 'general'),
('primary_language', 'pt-BR', 'general'),
('timezone', 'America/Sao_Paulo', 'general'),
('admin_email', 'contato@projetotunisia.com.br', 'general'),
('require_review', '1', 'publishing'),
('auto_backup', '1', 'publishing'),
('enable_scheduling', '1', 'publishing'),
('initial_status', 'draft', 'publishing'),
('history_retention', '30', 'publishing'),
('notify_on_publish', '1', 'publishing')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- --------------------------------------------------------
-- Inserções Iniciais: Seções do Site Real
-- --------------------------------------------------------
INSERT INTO `sections` (`id`, `identifier`, `title`, `subtitle`, `badge`, `description`, `primary_btn_text`, `primary_btn_link`, `secondary_btn_text`, `secondary_btn_link`, `image_path`, `video_path`, `display_order`, `status`, `is_visible`) VALUES
(1, 'hero', 'Tunísia: Cultura, Inovação e Conhecimento', 'Uma jornada completa pelos aspectos culturais, econômicos, históricos e tecnológicos de um dos países mais fascinantes do Mediterrâneo.', 'Projeto Integrador 2026', 'Uma jornada completa pelos aspectos culturais, econômicos, históricos e tecnológicos de um dos países mais fascinantes do Mediterrâneo.', 'Explorar Projeto →', '#sobre', 'Saiba Mais', '#mapa', 'assets/img/banner.png', 'assets/img/videotunisia.mp4', 1, 'published', 1),
(2, 'sobre', 'Projeto Integrador', 'SOBRE O PROJETO', 'SOBRE O PROJETO', 'Um trabalho multidisciplinar que conecta conhecimentos de diferentes áreas do saber, desenvolvendo competências essenciais para a formação acadêmica e pessoal dos alunos.', NULL, NULL, NULL, NULL, NULL, NULL, 2, 'published', 1),
(3, 'mapa', 'Mapa Interativo', 'EXPLORE A TUNÍSIA', 'EXPLORE A TUNÍSIA', 'Descubra as principais cidades tunisianas, suas curiosidades e atrações turísticas. Clique nos marcadores para explorar cada região.', NULL, NULL, NULL, NULL, 'assets/img/mapa.png', NULL, 3, 'published', 1),
(4, 'cultura', 'Celebrando a Tunísia', 'SEMANA CULTURAL', 'SEMANA CULTURAL', 'Uma semana dedicada a vivenciar e compartilhar a rica cultura tunisiana através de experiências imersivas e apresentações envolventes.', NULL, NULL, NULL, NULL, NULL, NULL, 4, 'published', 1),
(5, 'empreendedorismo', 'Empreendedorismo com Sabor Tunisiano', 'FEIRA DO EMPREENDEDORISMO', 'FEIRA DO EMPREENDEDORISMO', 'Transformando tradição em oportunidade através do Bambalouni, um doce típico tunisiano que combina cultura, gastronomia e potencial de mercado.', NULL, NULL, NULL, NULL, 'assets/img/bambalouni.png', NULL, 5, 'published', 1),
(6, 'conhecimento', 'Descobertas e Pesquisas', 'FEIRA DO CONHECIMENTO', 'FEIRA DO CONHECIMENTO', 'Resultados das pesquisas desenvolvidas sobre a Tunísia, reunindo dados geográficos, econômicos, tecnológicos, educacionais e ambientais.', NULL, NULL, NULL, NULL, NULL, NULL, 6, 'published', 1),
(7, 'cronograma', 'Cronograma do Projeto', 'DESENVOLVIMENTO DO PI', 'DESENVOLVIMENTO DO PI', 'Acompanhe as principais etapas do desenvolvimento do Projeto Integrador, desde a escolha do país até a apresentação final.', NULL, NULL, NULL, NULL, NULL, NULL, 7, 'published', 1),
(8, 'rodape', 'Rodapé e Contatos', 'Informações Institucionais', 'RODAPÉ', 'Projeto Integrador dedicado ao estudo da cultura, história, economia, turismo e inovação da Tunísia.', NULL, NULL, NULL, NULL, 'assets/img/logo.png', NULL, 8, 'published', 1)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- --------------------------------------------------------
-- Inserções Iniciais: Itens de Seção
-- --------------------------------------------------------
-- Itens da seção 'sobre' (Pilares)
INSERT INTO `section_items` (`section_id`, `item_type`, `title`, `description`, `icon`, `display_order`) VALUES
(2, 'card', 'Pesquisa', 'Investigação profunda sobre aspectos culturais, históricos e sociais da Tunísia.', '📖', 1),
(2, 'card', 'Cultura', 'Exploração da rica herança cultural, gastronomia e tradições tunisianas.', '💡', 2),
(2, 'card', 'Tecnologia', 'Análise do desenvolvimento tecnológico e inovação no país mediterrâneo.', '💻', 3),
(2, 'card', 'Empreendedorismo', 'Desenvolvimento de ideias de negócios inspiradas na economia tunisiana.', '📈', 4);

-- Itens da seção 'cultura' (Cards Culturais)
INSERT INTO `section_items` (`section_id`, `item_type`, `title`, `subtitle`, `description`, `icon`, `image_path`, `display_order`) VALUES
(4, 'cultural_card', 'Gastronomia', 'Cuscuz, tajine, brik e doces tradicionais', 'Degustação de pratos típicos tunisianos', '🍴', 'assets/img/img01.png', 1),
(4, 'cultural_card', 'Música', 'Ritmos mediterrâneos e instrumentos árabes', 'Apresentação de músicas e danças tradicionais', '🎵', 'assets/img/img02.png', 2),
(4, 'cultural_card', 'História', 'De Cartago aos dias atuais', 'Exposição sobre a rica história tunisiana', '📖', 'assets/img/img03.png', 3);

-- Itens da seção 'cronograma' (Timeline)
INSERT INTO `section_items` (`section_id`, `item_type`, `title`, `subtitle`, `description`, `display_order`) VALUES
(7, 'timeline', 'Escolha do País', 'Março 2026', 'Definição da Tunísia como tema principal do projeto.', 1),
(7, 'timeline', 'Pesquisa Inicial', 'Março - Abril', 'Levantamento de dados históricos, culturais e econômicos.', 2),
(7, 'timeline', 'Coleta de Informações', 'Abril - Maio', 'Pesquisa aprofundada em diversas áreas do conhecimento.', 3),
(7, 'timeline', 'Produção dos Materiais', 'Maio - Junho', 'Criação de banners, apresentações, maquetes e conteúdos digitais.', 4),
(7, 'timeline', 'Preparação das Apresentações', 'Junho 2026', 'Organização dos eventos e ensaios finais.', 5),
(7, 'timeline', 'Evento Final', 'Junho 2026', 'Semana Cultural, Feira do Conhecimento e Feira de Empreendedorismo.', 6);

-- --------------------------------------------------------
-- Inserções Iniciais: Mídias
-- --------------------------------------------------------
INSERT INTO `media` (`id`, `filename`, `original_name`, `filepath`, `file_type`, `mime_type`, `file_size`, `alt_text`) VALUES
(1, 'banner.png', 'banner.png', 'assets/img/banner.png', 'image', 'image/png', 1766114, 'Banner Principal da Tunísia'),
(2, 'logo.png', 'logo.png', 'assets/img/logo.png', 'image', 'image/png', 1127466, 'Logo do Projeto Tunísia'),
(3, 'mapa.png', 'mapa.png', 'assets/img/mapa.png', 'image', 'image/png', 172684, 'Mapa da Tunísia'),
(4, 'bambalouni.png', 'bambalouni.png', 'assets/img/bambalouni.png', 'image', 'image/png', 311341, 'Bambalouni doce tradicional'),
(5, 'img01.png', 'img01.png', 'assets/img/img01.png', 'image', 'image/png', 116377, 'Gastronomia da Tunísia'),
(6, 'img02.png', 'img02.png', 'assets/img/img02.png', 'image', 'image/png', 107725, 'Música tradicional'),
(7, 'img03.png', 'img03.png', 'assets/img/img03.png', 'image', 'image/png', 183271, 'História da Tunísia')
ON DUPLICATE KEY UPDATE `filename`=VALUES(`filename`);

-- Histórico e auditoria começam vazios; serão preenchidos por ações reais no painel.
*/

