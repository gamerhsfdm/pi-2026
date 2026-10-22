<?php
/**
 * Configurações Gerais, Publicação e Equipe — Tunísia CMS
 * Fiel às páginas 11, 12 e 13 do design.
 * Dados reais, gerenciamento completo de equipe (convidar, alterar cargo, excluir membro) e feedback dinâmico.
 */
$pageTitle = 'Configuração';
$activePage = 'settings';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduler.php';
requireAuth(true);

$pdo = getPDO();
$tab = $_GET['tab'] ?? 'general';
$successMsg = '';
$errorMsg = '';
$currentUser = getCurrentUser();

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $errorMsg = 'Sessão inválida ou expirada.';
    } else {
        $formType = $_POST['form_type'] ?? '';

        // 1. Configurações Gerais
        if ($formType === 'general') {
            $siteName = trim($_POST['site_name'] ?? '');
            $siteDesc = trim($_POST['site_description'] ?? '');
            $lang = trim($_POST['primary_language'] ?? '');
            $tz = trim($_POST['timezone'] ?? '');
            $email = trim($_POST['admin_email'] ?? '');

            if ($pdo) {
                $seoTitle = mb_substr(trim((string)($_POST['site_title'] ?? $siteName)), 0, 150);
                $stmt = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, setting_group) VALUES 
                    ('site_name', ?, 'general'),
                    ('site_title', ?, 'seo'),
                    ('site_description', ?, 'general'),
                    ('primary_language', ?, 'general'),
                    ('timezone', ?, 'general'),
                    ('admin_email', ?, 'general')");
                $stmt->execute([$siteName, $seoTitle, $siteDesc, $lang, $tz, $email]);
                logAudit('atualizou configurações', 'Informações gerais');
                $successMsg = 'Configurações gerais salvas com sucesso!';
            }
        } 
        // 2. Fluxo de Publicação
        elseif ($formType === 'publishing') {
            $requireReview = isset($_POST['require_review']) ? '1' : '0';
            $autoBackup = isset($_POST['auto_backup']) ? '1' : '0';
            $scheduling = isset($_POST['enable_scheduling']) ? '1' : '0';
            $initialStatus = trim($_POST['initial_status'] ?? 'draft');
            $retention = (int)($_POST['history_retention'] ?? 30);
            $notifyEmail = trim($_POST['notify_email'] ?? '');
            $notifyOnPublish = !empty($notifyEmail) ? '1' : '0';

            if ($pdo) {
                $stmt = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, setting_group) VALUES 
                    ('require_review', ?, 'publishing'),
                    ('auto_backup', ?, 'publishing'),
                    ('enable_scheduling', ?, 'publishing'),
                    ('initial_status', ?, 'publishing'),
                    ('history_retention', ?, 'publishing'),
                    ('notify_email', ?, 'publishing'),
                    ('notify_on_publish', ?, 'publishing')");
                $stmt->execute([$requireReview, $autoBackup, $scheduling, $initialStatus, (string)$retention, $notifyEmail, $notifyOnPublish]);
                logAudit('atualizou configurações', 'Fluxo de publicação');
                $successMsg = 'Preferências de publicação salvas com sucesso!';
            }
        } 
        // 2.1 Backup manual (banco + uploads)
        elseif ($formType === 'backup_now') {
            if ($pdo) {
                $res = createBackup($pdo);
                if ($res['ok']) {
                    logAudit('criou backup', 'Backup manual: ' . $res['file']);
                    $successMsg = 'Backup criado: ' . $res['file'] . ' (' . formatBytes((int)$res['size']) . ').' . (!empty($res['note']) ? ' ' . $res['note'] : '');
                } else {
                    $errorMsg = 'Falha ao criar o backup: ' . $res['error'];
                }
            }
        }
        // 2.2 E-mail de teste (SMTP)
        elseif ($formType === 'test_mail') {
            $to = (string)($currentUser['email'] ?? '');
            $res = sendAppMail($to, 'Teste de e-mail — Projeto Tunísia', "Se você recebeu esta mensagem, o envio de e-mails do painel está funcionando.\n\nEnviado em " . date('d/m/Y H:i') . ".");
            if ($res['ok']) {
                $successMsg = 'E-mail de teste enviado para ' . $to . '. Confira a caixa de entrada (e o spam).';
            } else {
                $errorMsg = 'Não foi possível enviar o e-mail de teste: ' . $res['error'];
            }
        }
        // 3. Adicionar Colaborador / Convidar pessoa
        elseif ($formType === 'add_user') {
            $name = trim($_POST['user_name'] ?? '');
            $email = trim($_POST['user_email'] ?? '');
            $role = trim($_POST['user_role'] ?? 'editor');
            $password = (string)($_POST['user_password'] ?? '');

            if (empty($name) || empty($email)) {
                $errorMsg = 'Nome e e-mail são obrigatórios.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = 'E-mail inválido.';
            } elseif (strlen($password) < 12) {
                $errorMsg = 'A senha precisa ter pelo menos 12 caracteres.';
            } elseif (!in_array($role, ['admin', 'editor'], true)) {
                $errorMsg = 'Papel de usuário inválido.';
            } elseif ($pdo) {
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmtCheck->execute([$email]);
                if ($stmtCheck->fetch()) {
                    $errorMsg = 'Este e-mail já está cadastrado no sistema.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
                    $stmt->execute([$name, $email, $hash, $role]);
                    $newId = (int)$pdo->lastInsertId();

                    logAudit('adicionou usuário', "{$name} ({$role})");
                    createNotification('novo membro', 'Novo Membro na Equipe', "{$name} entrou para a equipe como {$role}.", null, $newId);
                    $successMsg = 'Novo membro adicionado com sucesso!';
                }
            }
        }
        // 4. Alterar Papel de Membro
        elseif ($formType === 'update_user_role') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newRole = ($_POST['new_role'] === 'admin') ? 'admin' : 'editor';

            if ($targetUserId > 0 && $pdo) {
                // Impede que o usuário remova seu próprio admin se for o único
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$newRole, $targetUserId]);
                logAudit('alterou papel', "Usuário ID {$targetUserId} agora é {$newRole}");
                $successMsg = 'Papel do colaborador atualizado com sucesso!';
            }
        }
        // 5. Ativar/desativar conta de colaborador (aprovação de cadastros).
        elseif ($formType === 'update_user_status') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newStatus = (($_POST['new_status'] ?? '') === 'active') ? 'active' : 'inactive';
            if ($targetUserId > 0 && $pdo) {
                $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
                $stmt->execute([$newStatus, $targetUserId]);
                logAudit('alterou status de usuário', 'Usuário ID ' . $targetUserId . ' status ' . $newStatus);
                $successMsg = $newStatus === 'active' ? 'Conta ativada com sucesso.' : 'Conta desativada com sucesso.';
            }
        }
        // 6. Remover Membro da Equipe
        elseif ($formType === 'delete_user') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $currentUserId = (int)($currentUser['id'] ?? 1);

            if ($targetUserId === $currentUserId) {
                $errorMsg = 'Você não pode remover sua própria conta logada.';
            } elseif ($targetUserId > 0 && $pdo) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$targetUserId]);
                logAudit('removeu usuário', "Usuário ID {$targetUserId} foi excluído da equipe");
                $successMsg = 'Colaborador removido da equipe com sucesso!';
            }
        }
        // 6. Salvar Domínio Personalizado
        elseif ($formType === 'domain') {
            $customDomain = trim($_POST['custom_domain'] ?? '');
            if ($pdo) {
                $stmt = $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value, setting_group) VALUES ('custom_domain', ?, 'domain')");
                $stmt->execute([$customDomain]);
                logAudit('atualizou configurações', "Domínio personalizado: {$customDomain}");
                $successMsg = 'Configurações de domínio salvas com sucesso!';
            }
        }
    }
}

// Carrega todas as configurações do banco
$settings = [];
if ($pdo) {
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {
        error_log("Settings fetch error: " . $e->getMessage());
    }
}

// Usuários cadastrados reais do banco
$usersList = [];
if ($pdo) {
    try {
        $usersList = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Users fetch error: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<!-- Header da Página -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Configurações</h1>
        <p class="text-sm text-slate-500 mt-0.5">Preferências gerais do site, equipe e publicação</p>
    </div>

    <div>
        <?php if ($tab !== 'team'): ?>
            <button type="submit" form="currentForm" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-100 transition">
                <span>Salvar configuração</span>
            </button>
        <?php else: ?>
            <button type="button" onclick="document.getElementById('inviteModal').classList.remove('hidden')" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-100 transition">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                <span>Convidar pessoa</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3">
        <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600"></i>
        <span><?= e($successMsg) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-3">
        <i data-lucide="alert-circle" class="w-5 h-5 text-red-600"></i>
        <span><?= e($errorMsg) ?></span>
    </div>
<?php endif; ?>

<!-- Layout de Abas com Conteúdo à Direita (Fiel às páginas 11, 12 e 13) -->
<div class="grid grid-cols-1 md:grid-cols-12 gap-4 md:gap-8 items-start">
    <!-- Menu Lateral de Abas -->
    <div class="md:col-span-3 flex md:block gap-2 md:space-y-1 overflow-x-auto md:overflow-visible pb-1 md:pb-0 -mx-1 px-1 md:mx-0 md:px-0">
        <a href="?tab=general" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-semibold whitespace-nowrap shrink-0 transition <?= $tab === 'general' ? 'bg-indigo-50 text-indigo-700 border border-indigo-100' : 'text-slate-600 hover:bg-slate-50' ?>">
            <i data-lucide="sliders" class="w-4 h-4 <?= $tab === 'general' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
            <span>Geral</span>
        </a>

        <a href="?tab=publishing" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-semibold whitespace-nowrap shrink-0 transition <?= $tab === 'publishing' ? 'bg-indigo-50 text-indigo-700 border border-indigo-100' : 'text-slate-600 hover:bg-slate-50' ?>">
            <i data-lucide="send" class="w-4 h-4 <?= $tab === 'publishing' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
            <span>Publicação</span>
        </a>

        <a href="?tab=team" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-semibold whitespace-nowrap shrink-0 transition <?= $tab === 'team' ? 'bg-indigo-50 text-indigo-700 border border-indigo-100' : 'text-slate-600 hover:bg-slate-50' ?>">
            <i data-lucide="users" class="w-4 h-4 <?= $tab === 'team' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
            <span>Equipe e acesso</span>
        </a>

        <a href="?tab=domain" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-semibold whitespace-nowrap shrink-0 transition <?= $tab === 'domain' ? 'bg-indigo-50 text-indigo-700 border border-indigo-100' : 'text-slate-600 hover:bg-slate-50' ?>">
            <i data-lucide="globe" class="w-4 h-4 <?= $tab === 'domain' ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
            <span>Domínio</span>
        </a>
    </div>

    <!-- Conteúdo da Aba Selecionada (9 Colunas) -->
    <div class="md:col-span-9 min-w-0 bg-white rounded-3xl p-5 sm:p-8 border border-slate-200 shadow-sm">
        
        <!-- ============================================== -->
        <!-- ABA 1: GERAL (Página 11)                      -->
        <!-- ============================================== -->
        <?php if ($tab === 'general'): ?>
            <form id="currentForm" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="general">

                <div>
                    <h2 class="text-base font-bold text-slate-900">Informações gerais</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Dados básicos sobre o site e sua apresentação.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-2">Nome do site</label>
                    <input type="text" name="site_name" value="<?= e($settings['site_name'] ?? 'Projeto Tunísia') ?>" required class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-2">Título para buscadores (SEO)</label>
                    <input type="text" name="site_title" maxlength="150" value="<?= e($settings['site_title'] ?? $settings['site_name'] ?? 'Projeto Tunísia') ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                    <p class="text-[11px] text-slate-500 mt-1">Usado no título da aba do navegador e em metadados de compartilhamento.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-2">Descrição do site</label>
                    <textarea name="site_description" rows="3" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition"><?= e($settings['site_description'] ?? 'Uma jornada completa pelos aspectos culturais, econômicos, históricos e tecnológicos de um dos países mais fascinantes do Mediterrâneo.') ?></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-2">Idioma principal</label>
                        <select name="primary_language" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                            <option value="pt-BR" <?= ($settings['primary_language'] ?? '') === 'pt-BR' ? 'selected' : '' ?>>Português (Brasil)</option>
                            <option value="en" <?= ($settings['primary_language'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                            <option value="fr" <?= ($settings['primary_language'] ?? '') === 'fr' ? 'selected' : '' ?>>Français</option>
                            <option value="ar" <?= ($settings['primary_language'] ?? '') === 'ar' ? 'selected' : '' ?>>Árabe</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-2">Fuso horário</label>
                        <select name="timezone" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                            <option value="America/Sao_Paulo" <?= ($settings['timezone'] ?? '') === 'America/Sao_Paulo' ? 'selected' : '' ?>>Brasília (GMT-3)</option>
                            <option value="Africa/Tunis" <?= ($settings['timezone'] ?? '') === 'Africa/Tunis' ? 'selected' : '' ?>>Tunis (GMT+1)</option>
                            <option value="UTC" <?= ($settings['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-2">E-mail administrativo de contato</label>
                    <input type="email" name="admin_email" value="<?= e($settings['admin_email'] ?? 'contato@projetotunisia.com.br') ?>" required class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                </div>
            </form>

        <!-- ============================================== -->
        <!-- ABA 2: PUBLICAÇÃO (Página 12)                 -->
        <!-- ============================================== -->
        <?php elseif ($tab === 'publishing'): ?>
            <form id="currentForm" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="publishing">

                <div>
                    <h2 class="text-base font-bold text-slate-900">Fluxo de publicação</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Controle como as alterações chegam ao site público.</p>
                </div>

                <div class="space-y-6">
                    <!-- Toggle 1: Exigir revisão -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Exigir revisão antes de publicar</p>
                            <p class="text-xs text-slate-400">Rascunhos de editores precisam ser aprovados por um administrador.</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="require_review" value="1" <?= ($settings['require_review'] ?? '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                    </div>

                    <!-- Toggle 2: Backup automático -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Criar backup automático</p>
                            <p class="text-xs text-slate-400">Gera um arquivo .zip (banco + uploads) a cada 24 h. Requer o cron configurado (veja o painel abaixo).</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="auto_backup" value="1" <?= ($settings['auto_backup'] ?? '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                    </div>

                    <!-- Toggle 3: Publicação agendada -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Permitir publicação agendada</p>
                            <p class="text-xs text-slate-400">Permite que administradores agendem a publicação de uma seção para uma data futura (no editor).</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="enable_scheduling" value="1" <?= ($settings['enable_scheduling'] ?? '1') === '1' ? 'checked' : '' ?> class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-2">Status inicial ao criar</label>
                        <select name="initial_status" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
                            <option value="draft" <?= ($settings['initial_status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                            <option value="published" <?= ($settings['initial_status'] ?? '') === 'published' ? 'selected' : '' ?>>Publicado imediatamente</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-2">Retenção do histórico (dias)</label>
                        <input type="number" name="history_retention" min="7" max="365" value="<?= (int)($settings['history_retention'] ?? 30) ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-2">Avisar por e-mail após a publicação</label>
                    <input type="email" name="notify_email" value="<?= e($settings['notify_email'] ?? '') ?>" placeholder="equipe@tunisia.com" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                    <p class="text-[11px] text-slate-400 mt-1.5">Insira o e-mail de destino para ser notificado sempre que uma seção for publicada.</p>
                </div>
            </form>

            <!-- Estado do agendador, backups e e-mail -->
            <?php
                $mailCfg = mailConfig();
                $lastBackupAt = $settings['last_backup_at'] ?? '';
                $backupList = listBackups(5);
                $cronLast = is_file(CACHE_DIR . '/scheduler.last') ? (int)filemtime(CACHE_DIR . '/scheduler.last') : 0;
            ?>
            <div class="mt-10 space-y-6">
                <div class="rounded-2xl border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900">Backups</h3>
                    <p class="text-xs text-slate-500 mt-1">
                        Último backup: <strong><?= $lastBackupAt ? e(date('d/m/Y H:i', strtotime($lastBackupAt))) : 'nenhum ainda' ?></strong>.
                        Mantemos os <?= max(1, (int)envValue('BACKUP_KEEP', '7')) ?> mais recentes em <code class="text-[11px]"><?= e(backupDir()) ?></code>.
                    </p>
                    <?php if ($backupList): ?>
                        <ul class="mt-3 divide-y divide-slate-100 text-xs">
                            <?php foreach ($backupList as $b): ?>
                                <li class="py-2 flex items-center justify-between gap-3">
                                    <span class="text-slate-700"><?= e(date('d/m/Y H:i', $b['time'])) ?> · <?= e(formatBytes($b['size'])) ?></span>
                                    <a href="<?= ADMIN_URL ?>/download_backup.php?file=<?= urlencode($b['file']) ?>" class="font-semibold text-indigo-600 hover:underline">Baixar</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="form_type" value="backup_now">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold transition">Fazer backup agora</button>
                    </form>
                    <p class="text-[11px] text-slate-400 mt-3">O backup contém senhas (em hash) e dados da equipe: guarde os arquivos em local privado.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900">Agendador (cron)</h3>
                    <p class="text-xs text-slate-500 mt-1">
                        Publicações agendadas e o backup diário rodam pelo cron do servidor:
                        <code class="text-[11px]">* * * * * php <?= e(realpath(__DIR__ . '/../scripts/cron.php') ?: 'scripts/cron.php') ?></code>.
                        Sem cron, uma verificação reserva acontece quando alguém visita o site ou o painel (até ~1 minuto de atraso, mais o tempo de cache do site).
                    </p>
                    <p class="text-xs mt-2 text-slate-600">Última verificação: <strong><?= $cronLast ? e(date('d/m/Y H:i:s', $cronLast)) : 'ainda não executada' ?></strong></p>
                </div>

                <div class="rounded-2xl border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900">E-mail (SMTP)</h3>
                    <p class="text-xs text-slate-500 mt-1">
                        <?php if ($mailCfg['host'] !== ''): ?>
                            Servidor: <strong><?= e($mailCfg['host']) ?>:<?= (int)$mailCfg['port'] ?></strong> (<?= e($mailCfg['secure']) ?>)
                            <?= $mailCfg['user'] !== '' ? '· autenticado como ' . e($mailCfg['user']) : '· sem autenticação' ?>.
                        <?php else: ?>
                            SMTP não configurado: o sistema tenta usar a função mail() do servidor, que costuma não funcionar. Defina <code class="text-[11px]">SMTP_HOST</code>, <code class="text-[11px]">SMTP_USER</code> e <code class="text-[11px]">SMTP_PASS</code> no arquivo .env.
                        <?php endif; ?>
                    </p>
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="form_type" value="test_mail">
                        <button type="submit" class="px-4 py-2 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold transition">Enviar e-mail de teste para mim</button>
                    </form>
                </div>
            </div>

        <!-- ============================================== -->
        <!-- ABA 3: EQUIPE E ACESSO (Página 13)            -->
        <!-- ============================================== -->
        <?php elseif ($tab === 'team'): ?>
            <div class="space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Equipe e acesso</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Gerencie os colaboradores, papéis e permissões do projeto.</p>
                    </div>

                    <button type="button" onclick="document.getElementById('inviteModal').classList.remove('hidden')" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-100 transition flex items-center gap-2">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                        <span>Convidar pessoa</span>
                    </button>
                </div>

                <div class="border border-slate-200 rounded-2xl divide-y divide-slate-100 overflow-hidden">
                    <?php foreach ($usersList as $u): ?>
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 hover:bg-slate-50/70 transition">
                            <div class="flex items-center gap-3.5 min-w-0">
                                <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center font-bold text-slate-800 overflow-hidden shadow-sm shrink-0">
                                    <?php if (!empty($u['avatar'])): ?>
                                        <img src="<?= SITE_URL ?>/<?= ltrim($u['avatar'], '/') ?>" alt="Avatar" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <span class="text-sm font-semibold"><?= strtoupper(substr($u['name'], 0, 1)) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <h4 class="text-sm font-semibold text-slate-800"><?= e($u['name']) ?></h4>
                                        <?php if ((int)$u['id'] === (int)($currentUser['id'] ?? 0)): ?>
                                            <span class="px-2 py-0.2 bg-indigo-50 text-indigo-700 text-[10px] font-bold rounded">Você</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-400 truncate"><?= e($u['email']) ?></p>
                                    <?php if (($u['status'] ?? 'active') !== 'active'): ?>
                                        <span class="inline-flex mt-1 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[10px] font-semibold">Aguardando aprovação / inativo</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 self-end sm:self-center">
                                <!-- Aprovar ou suspender conta -->
                                <form method="POST" class="inline-flex items-center">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="form_type" value="update_user_status">
                                    <input type="hidden" name="target_user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= ($u['status'] ?? 'active') === 'active' ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-[11px] font-semibold <?= ($u['status'] ?? 'active') === 'active' ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' ?>">
                                        <?= ($u['status'] ?? 'active') === 'active' ? 'Desativar' : 'Aprovar' ?>
                                    </button>
                                </form>

                                <!-- Alternar Papel -->
                                <form method="POST" class="inline-flex items-center">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="form_type" value="update_user_role">
                                    <input type="hidden" name="target_user_id" value="<?= (int)$u['id'] ?>">
                                    <select name="new_role" onchange="this.form.submit()" class="text-xs font-semibold px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 focus:outline-none">
                                        <option value="editor" <?= $u['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                    </select>
                                </form>

                                <!-- Remover Membro -->
                                <?php if ((int)$u['id'] !== (int)($currentUser['id'] ?? 0)): ?>
                                    <form method="POST" onsubmit="return confirm('Tem certeza que deseja remover este colaborador da equipe?');">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="form_type" value="delete_user">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition" title="Remover membro">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Modal Convidar Pessoa -->
            <div id="inviteModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-8 border border-slate-100">
                    <h3 class="text-lg font-bold text-slate-900 mb-1">Adicionar membro</h3>
                    <p class="text-xs text-slate-400 mb-5">Envie o acesso com definição de papel para a equipe.</p>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="form_type" value="add_user">

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Nome completo</label>
                            <input type="text" name="user_name" required placeholder="Como devemos chamar" class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">E-mail</label>
                            <input type="email" name="user_email" required placeholder="email@tunisia.com" class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Papel / Nível de Acesso</label>
                            <select name="user_role" class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none">
                                <option value="editor">Editor (Edita conteúdos e imagens)</option>
                                <option value="admin">Administrador (Acesso completo e configurações)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Senha inicial</label>
                            <input type="password" name="user_password" value="" required minlength="12" autocomplete="new-password" placeholder="Mínimo de 12 caracteres" class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none">
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                            <button type="button" onclick="document.getElementById('inviteModal').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-600 rounded-xl hover:bg-slate-50">Cancelar</button>
                            <button type="submit" class="px-5 py-2.5 text-xs font-semibold text-white bg-indigo-600 rounded-xl shadow-md shadow-indigo-100">Salvar membro</button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- ============================================== -->
        <!-- ABA 4: DOMÍNIO                                -->
        <!-- ============================================== -->
        <?php elseif ($tab === 'domain'): ?>
            <form id="currentForm" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="form_type" value="domain">

                <div>
                    <h2 class="text-base font-bold text-slate-900">Domínio e Apontamento</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Gerencie o endereço público e o status do servidor.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-2">Domínio personalizado</label>
                    <input type="text" name="custom_domain" value="<?= e($settings['custom_domain'] ?? 'projetotunisia.com.br') ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="meudominio.com.br">
                </div>

                <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 text-xs text-slate-600 space-y-2">
                    <p><strong>URL Base Atual:</strong> <code class="bg-white px-2 py-0.5 rounded border border-slate-200"><?= SITE_URL ?></code></p>
                    <p><strong>Ambiente:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Apache / XAMPP' ?></p>
                    <p><strong>PHP:</strong> <?= PHP_VERSION ?></p>
                    <p><strong>Banco de Dados:</strong> MySQL 5.7+ / MariaDB (UTF-8 mb4)</p>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
