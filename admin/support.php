<?php
/**
 * Central de Suporte e Ajuda — Tunísia CMS
 * Busca na central, FAQ em acordeão, Guia rápido, Link da documentação e Formulário de chamados reais
 */
$pageTitle = 'Suporte';
$activePage = 'support';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$pdo = getPDO();
// Mensagem de sucesso guardada na sessão (padrão "post → redirect → get": recarregar a página não reenvia o chamado)
$successMsg = (string)($_SESSION['flash_support_ok'] ?? '');
unset($_SESSION['flash_support_ok']);
$errorMsg = '';
$formSubject = '';
$formMessage = '';

function supportRedirect(string $okMessage): void {
    $_SESSION['flash_support_ok'] = $okMessage;
    header('Location: ' . ADMIN_URL . '/support.php#chamados');
    exit;
}

// Administradores podem atualizar o status de qualquer chamado.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ticket_status') {
    $user = getCurrentUser();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $newStatus = $_POST['status'] ?? 'open';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Sessão inválida ou expirada. Recarregue a página e tente de novo.';
    } elseif (($user['role'] ?? '') !== 'admin') {
        $errorMsg = 'Você não tem permissão para alterar o status dos chamados.';
    } elseif ($ticketId < 1 || !in_array($newStatus, ['open', 'in_progress', 'resolved'], true)) {
        $errorMsg = 'Dados do chamado inválidos.';
    } elseif ($pdo) {
        try {
            $stmt = $pdo->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$newStatus, $ticketId]);
            logAudit('atualizou chamado de suporte', "Chamado #{$ticketId}: {$newStatus}", (int)($user['id'] ?? 0));
            supportRedirect('Status do chamado atualizado.');
        } catch (Exception $e) {
            error_log('Erro ao atualizar chamado de suporte: ' . $e->getMessage());
            $errorMsg = 'Não foi possível atualizar o chamado.';
        }
    }
}

// Resposta a um chamado existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply_ticket') {
    $user = getCurrentUser();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $replyMsg = mb_substr(trim($_POST['reply_message'] ?? ''), 0, 5000);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Sessão inválida ou expirada. Recarregue a página e tente de novo.';
    } elseif ($ticketId < 1 || $replyMsg === '') {
        $errorMsg = 'A resposta não pode ser vazia.';
    } elseif ($pdo) {
        try {
            $stmtCheck = $pdo->prepare('SELECT id, user_id FROM support_tickets WHERE id = ?');
            $stmtCheck->execute([$ticketId]);
            $tRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$tRow || (($user['role'] ?? '') !== 'admin' && (int)$tRow['user_id'] !== (int)($user['id'] ?? 0))) {
                $errorMsg = 'Você não tem permissão para responder a este chamado.';
            } else {
                $stmtIns = $pdo->prepare('INSERT INTO support_messages (ticket_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())');
                $stmtIns->execute([$ticketId, (int)($user['id'] ?? 0), $replyMsg]);

                // Se quem respondeu é admin, notifica o solicitante (ou atualiza o status do chamado)
                $pdo->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);
                logAudit('respondeu chamado de suporte', "Chamado #{$ticketId}", (int)($user['id'] ?? 0));

                if (($user['role'] ?? '') === 'admin' && (int)$tRow['user_id'] !== (int)$user['id']) {
                    createNotification('suporte', 'Nova Resposta no Suporte', "{$user['name']} respondeu ao seu chamado #{$ticketId}.");
                }

                supportRedirect('Resposta enviada com sucesso!');
            }
        } catch (Exception $e) {
            error_log('Erro ao responder chamado: ' . $e->getMessage());
            $errorMsg = 'Não foi possível enviar a resposta agora.';
        }
    }
}

// Envio de chamado de suporte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'new_ticket') {
    $formSubject = mb_substr(trim($_POST['subject'] ?? ''), 0, 150);
    $formMessage = mb_substr(trim($_POST['message'] ?? ''), 0, 5000);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Sessão inválida ou expirada. Recarregue a página e tente de novo.';
    } elseif ($formSubject === '' || $formMessage === '') {
        $errorMsg = 'Preencha o assunto e a mensagem do chamado.';
    } else {
        $attachmentPath = null;

        // Anexo opcional: só formatos seguros (imagem, PDF ou texto), até 5 MB.
        if (!empty($_FILES['attachment']['name'])) {
            $file = $_FILES['attachment'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'Não foi possível enviar o anexo. Tente um arquivo menor.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errorMsg = 'O anexo passa de 5 MB. Envie um arquivo menor.';
            } else {
                $allowed = [
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
                    'application/pdf' => 'pdf', 'text/plain' => 'txt',
                ];
                $mime = mime_content_type($file['tmp_name']);
                if (!isset($allowed[$mime])) {
                    $errorMsg = 'Formato de anexo não permitido. Use imagem (JPG, PNG, WebP, GIF), PDF ou TXT.';
                } else {
                    $newName = 'ticket_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                    if (!is_dir(UPLOADS_DIR)) { @mkdir(UPLOADS_DIR, 0755, true); }
                    if (move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $newName)) {
                        $attachmentPath = 'uploads/media/' . $newName;
                    } else {
                        $errorMsg = 'Falha ao salvar o anexo no servidor.';
                    }
                }
            }
        }

        if ($errorMsg === '' && $pdo) {
            try {
                $user = getCurrentUser();
                $userId = (int)($user['id'] ?? 1);
                $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, subject, message, attachment, status, created_at) VALUES (?, ?, ?, ?, 'open', NOW())");
                $stmt->execute([$userId, $formSubject, $formMessage, $attachmentPath]);
                logAudit('abriu chamado', "Assunto: {$formSubject}", $userId);
                supportRedirect('Chamado enviado! A equipe responderá por aqui assim que possível.');
            } catch (Exception $e) {
                error_log('Erro ao enviar chamado: ' . $e->getMessage());
                $errorMsg = 'Não foi possível registrar o chamado agora. Tente novamente em instantes.';
            }
        }
    }
}

// Lista de chamados enviados pelo usuário
$ticketsList = [];
if ($pdo) {
    try {
        $user = getCurrentUser();
        if (($user['role'] ?? '') === 'admin') {
            $stmt = $pdo->query("SELECT t.*, u.name AS requester_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.created_at DESC");
        } else {
            $userId = (int)($user['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT t.*, u.name AS requester_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.user_id = ? ORDER BY t.created_at DESC");
            $stmt->execute([$userId]);
        }
        $ticketsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Carrega as mensagens de cada chamado
        if (!empty($ticketsList)) {
            $tIds = array_column($ticketsList, 'id');
            $inSql = implode(',', array_fill(0, count($tIds), '?'));
            $stmtMsg = $pdo->prepare("SELECT m.*, u.name as sender_name, u.role as sender_role, u.avatar as sender_avatar FROM support_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.ticket_id IN ($inSql) ORDER BY m.created_at ASC");
            $stmtMsg->execute($tIds);
            $msgGroup = [];
            foreach ($stmtMsg->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $msgGroup[$m['ticket_id']][] = $m;
            }
            foreach ($ticketsList as &$tk) {
                $tk['messages'] = $msgGroup[$tk['id']] ?? [];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching tickets: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php
$isAdminUser = (($currentUser['role'] ?? '') === 'admin');
$ticketStatus = [
    'open'        => ['Aberto',        'bg-amber-100 text-amber-800'],
    'in_progress' => ['Em andamento',  'bg-sky-100 text-sky-800'],
    'resolved'    => ['Resolvido',     'bg-emerald-100 text-emerald-800'],
];
$faqs = [
    ['Como salvar alterações sem publicar no site imediatamente?',
     'Ao editar uma seção em <strong>Conteúdo do Site</strong>, clique em <strong>Salvar rascunho</strong>. O texto fica guardado como rascunho e os visitantes continuam vendo a versão publicada. Quando estiver pronto, use <strong>Publicar alterações</strong>.'],
    ['Como edito os cards, etapas e listas que aparecem dentro de uma seção?',
     'Abra a seção no editor e role até <strong>Conteúdo interno da seção</strong>. Cada item abre ao clicar; você pode mudar a ordem com as setas, duplicar ou remover. Lembre de clicar em Salvar rascunho ou Publicar para guardar.'],
    ['Como troco a imagem de uma seção?',
     'No editor da seção, em <strong>Imagem da seção</strong>, clique em <strong>Substituir</strong> e escolha o arquivo (JPG, PNG ou WebP). Para reutilizar uma imagem já enviada, copie o caminho dela na <strong>Biblioteca de mídia</strong>.'],
    ['Como mudo a ordem das seções no site?',
     'Em <strong>Conteúdo do Site</strong>, clique em <strong>Reordenar seções</strong>, arraste os cards e depois em <strong>Salvar ordem</strong>. Também dá para reordenar rapidamente pelo Dashboard.'],
    ['Posso restaurar uma versão antiga se errar uma edição?',
     'Sim. Abra <strong>Histórico de versões</strong>, encontre a alteração e clique em <strong>Restaurar versão</strong>. O sistema pede confirmação e registra a restauração no próprio histórico.'],
    ['Como cadastrar novos membros na equipe?',
     'Em <strong>Configurações &gt; Equipe e acesso</strong>, use <strong>Convidar pessoa</strong> e escolha o perfil: Administrador ou Editor.'],
    ['Como altero minha foto, e-mail ou senha?',
     'Clique na sua foto no canto superior direito e escolha <strong>Meu perfil</strong>. Para trocar a senha, informe a senha atual e a nova.'],
    ['Como encontro algo rápido no painel?',
     'Use a barra de busca no topo (atalho: tecla <strong>/</strong> ou <strong>Ctrl+K</strong>). Ela procura seções, conteúdo dentro das seções, arquivos de mídia, histórico e páginas do painel.'],
    ['Como funciona o registro de visualizações de visitantes?',
     'O CMS conta os acessos à página inicial pública, ignorando robôs, cliques vindos do próprio painel e recarregamentos repetidos do mesmo IP em curto intervalo.'],
];
$attachmentUrl = fn($path) => SITE_URL . '/' . ltrim((string)$path, '/');
?>

<!-- Header da Página -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 sm:mb-8">
    <div class="min-w-0">
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">Central de Suporte e Ajuda</h1>
        <p class="text-sm text-slate-500 mt-0.5">Encontre respostas rápidas ou fale com a equipe técnica.</p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <a href="#chamado" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 hover:border-slate-300 text-slate-700 text-xs font-semibold rounded-xl transition">
            <i data-lucide="message-square-plus" class="w-4 h-4 text-slate-500"></i>
            <span>Abrir chamado</span>
        </a>
        <a href="https://github.com/gamerhsfdm/pi-2026" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-100 transition">
            <i data-lucide="book-open" class="w-4 h-4"></i>
            <span>Documentação</span>
        </a>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-start gap-3" role="status">
        <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 shrink-0"></i>
        <span><?= e($successMsg) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-3" role="alert">
        <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 shrink-0"></i>
        <span><?= e($errorMsg) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">

    <!-- Coluna esquerda: guia rápido + FAQ -->
    <div class="lg:col-span-7 space-y-6 min-w-0">

        <!-- Guia rápido -->
        <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                    <i data-lucide="zap" class="w-5 h-5"></i>
                </div>
                <h2 class="text-base font-bold text-slate-900">Guia rápido</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <a href="<?= ADMIN_URL ?>/content.php" class="block p-3.5 bg-slate-50 hover:bg-indigo-50/60 rounded-xl border border-slate-100 hover:border-indigo-100 transition">
                    <strong class="text-slate-800 block mb-1">1. Edite o conteúdo</strong>
                    <span class="text-slate-500 leading-relaxed block">Escolha uma seção, altere textos, imagens e os itens internos.</span>
                </a>
                <a href="<?= ADMIN_URL ?>/preview.php" class="block p-3.5 bg-slate-50 hover:bg-indigo-50/60 rounded-xl border border-slate-100 hover:border-indigo-100 transition">
                    <strong class="text-slate-800 block mb-1">2. Confira o preview</strong>
                    <span class="text-slate-500 leading-relaxed block">Veja o site em desktop, tablet e celular antes de publicar.</span>
                </a>
                <a href="<?= ADMIN_URL ?>/history.php" class="block p-3.5 bg-slate-50 hover:bg-indigo-50/60 rounded-xl border border-slate-100 hover:border-indigo-100 transition">
                    <strong class="text-slate-800 block mb-1">3. Use o histórico</strong>
                    <span class="text-slate-500 leading-relaxed block">Compare mudanças e restaure versões antigas com segurança.</span>
                </a>
            </div>
        </div>

        <!-- FAQ -->
        <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <h2 class="text-base font-bold text-slate-900">Perguntas frequentes</h2>
                <span id="faqCount" class="text-xs text-slate-400"><?= count($faqs) ?> perguntas</span>
            </div>

            <div class="relative mb-4">
                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                <input type="search" id="faqSearchInput" placeholder="Filtrar perguntas…" autocomplete="off" aria-label="Filtrar perguntas frequentes"
                       class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-base sm:text-sm focus:outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100">
            </div>

            <div id="faqList" class="space-y-2.5">
                <?php foreach ($faqs as $i => [$question, $answer]): ?>
                    <div class="faq-item border border-slate-200 rounded-2xl overflow-hidden">
                        <button type="button" class="faq-btn w-full p-4 text-left font-semibold text-[13px] leading-snug text-slate-800 flex items-center justify-between gap-3 hover:bg-slate-50 transition" aria-expanded="false" aria-controls="faq-a-<?= $i ?>" id="faq-q-<?= $i ?>">
                            <span><?= e($question) ?></span>
                            <i data-lucide="chevron-down" class="faq-chevron w-4 h-4 text-slate-400 shrink-0 transition-transform"></i>
                        </button>
                        <div id="faq-a-<?= $i ?>" role="region" aria-labelledby="faq-q-<?= $i ?>" class="faq-answer hidden px-4 pb-4 pt-3 text-[13px] text-slate-600 bg-slate-50/60 leading-relaxed border-t border-slate-100">
                            <?= $answer ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="faqEmpty" class="hidden text-center py-8">
                <p class="text-sm font-semibold text-slate-700">Nenhuma pergunta encontrada</p>
                <p class="text-xs text-slate-500 mt-1">Tente outra palavra ou <a href="#chamado" class="text-indigo-600 font-semibold hover:underline">abra um chamado</a>.</p>
            </div>
        </div>
    </div>

    <!-- Coluna direita: chamado + lista -->
    <div class="lg:col-span-5 space-y-6 min-w-0">

        <!-- Novo chamado -->
        <div id="chamado" class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm scroll-mt-4">
            <h2 class="text-base font-bold text-slate-900 mb-1">Falar com o suporte</h2>
            <p class="text-xs text-slate-500 mb-5">Para dúvidas avançadas ou problemas técnicos. Quanto mais detalhes, mais rápido conseguimos ajudar.</p>

            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="new_ticket">

                <div>
                    <label for="ticketSubject" class="block text-xs font-semibold text-slate-700 mb-1.5">Assunto</label>
                    <input type="text" id="ticketSubject" name="subject" required maxlength="150" value="<?= e($formSubject) ?>" placeholder="Ex.: Não consigo publicar uma imagem"
                           class="w-full px-3.5 py-2.5 text-base sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100">
                </div>

                <div>
                    <label for="ticketMessage" class="block text-xs font-semibold text-slate-700 mb-1.5">Mensagem</label>
                    <textarea id="ticketMessage" name="message" rows="5" required maxlength="5000" placeholder="O que você estava fazendo, o que esperava e o que aconteceu?"
                              class="w-full px-3.5 py-2.5 text-base sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-100 resize-y"><?= e($formMessage) ?></textarea>
                </div>

                <div>
                    <label for="ticketFile" class="block text-xs font-semibold text-slate-700 mb-1.5">Anexo <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="file" id="ticketFile" name="attachment" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain"
                           class="block w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                    <p class="text-[11px] text-slate-400 mt-1.5">Captura de tela, PDF ou TXT, até 5 MB.</p>
                </div>

                <button type="submit" class="w-full py-3 sm:py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm sm:text-xs rounded-xl shadow-md shadow-indigo-100 transition">
                    Enviar chamado
                </button>
            </form>
        </div>

        <!-- Lista de chamados -->
        <div id="chamados" class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm scroll-mt-4">
            <div class="flex items-center justify-between gap-3 mb-4">
                <h3 class="text-sm font-bold text-slate-900"><?= $isAdminUser ? 'Chamados de suporte' : 'Meus chamados' ?></h3>
                <?php if ($ticketsList): ?><span class="text-xs text-slate-400"><?= count($ticketsList) ?></span><?php endif; ?>
            </div>

            <?php if (empty($ticketsList)): ?>
                <div class="text-center py-6">
                    <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-2"><i data-lucide="inbox" class="w-5 h-5"></i></div>
                    <p class="text-xs text-slate-500">Nenhum chamado aberto ainda.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3 max-h-[560px] overflow-y-auto custom-scrollbar pr-1">
                    <?php foreach ($ticketsList as $t): ?>
                        <?php [$stLabel, $stClass] = $ticketStatus[$t['status']] ?? $ticketStatus['open']; ?>
                        <details class="group p-3.5 bg-slate-50 border border-slate-100 rounded-xl text-xs">
                            <summary class="list-none cursor-pointer flex items-start justify-between gap-3 [&::-webkit-details-marker]:hidden">
                                <div class="min-w-0">
                                    <strong class="text-slate-800 block break-words"><?= e($t['subject']) ?></strong>
                                    <span class="text-[11px] text-slate-400"><?= timeAgo($t['created_at']) ?><?= $isAdminUser ? ' · ' . e($t['requester_name'] ?? 'Conta removida') : '' ?></span>
                                </div>
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $stClass ?>"><?= $stLabel ?></span>
                            </summary>

                            <div class="mt-3 pt-3 border-t border-slate-200/70 space-y-3">
                                <p class="text-slate-600 leading-relaxed whitespace-pre-line break-words"><?= e($t['message']) ?></p>

                                <?php if (!empty($t['attachment'])): ?>
                                    <a href="<?= e($attachmentUrl($t['attachment'])) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-indigo-600 hover:underline">
                                        <i data-lucide="paperclip" class="w-3.5 h-3.5"></i> Ver anexo
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($t['messages'])): ?>
                                    <div class="space-y-2 pt-2 border-t border-slate-200/60">
                                        <div class="text-[11px] font-bold text-slate-700">Respostas do atendimento:</div>
                                        <?php foreach ($t['messages'] as $msg): ?>
                                            <div class="p-2.5 rounded-lg bg-white border border-slate-200/80 space-y-1">
                                                <div class="flex items-center justify-between text-[10px] text-slate-400">
                                                    <span class="font-semibold text-slate-700"><?= e($msg['sender_name'] ?? 'Membro') ?> <?= ($msg['sender_role'] ?? '') === 'admin' ? '<span class="text-[9px] bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded font-bold">Equipe</span>' : '' ?></span>
                                                    <span><?= timeAgo($msg['created_at']) ?></span>
                                                </div>
                                                <p class="text-[12px] text-slate-600 whitespace-pre-line leading-relaxed"><?= e($msg['message']) ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Formulário de Resposta rápida -->
                                <form method="POST" class="pt-2 border-t border-slate-200/60 space-y-2">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="reply_ticket">
                                    <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                    <textarea name="reply_message" rows="2" required placeholder="Escreva uma resposta para este chamado..." class="w-full px-2.5 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:border-indigo-500 resize-y"></textarea>
                                    <div class="flex justify-end">
                                        <button type="submit" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white text-[11px] font-semibold rounded-lg shadow-sm transition">
                                            Enviar resposta
                                        </button>
                                    </div>
                                </form>

                                <?php if ($isAdminUser): ?>
                                    <form method="POST" class="flex flex-col min-[420px]:flex-row min-[420px]:items-center gap-2 pt-2 border-t border-slate-200/60">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="action" value="update_ticket_status">
                                        <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                        <select name="status" aria-label="Status do chamado" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs">
                                            <?php foreach ($ticketStatus as $val => [$label]): ?>
                                                <option value="<?= $val ?>" <?= $t['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Atualizar status</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    // ---- acordeão ----
    function setOpen(item, open) {
        const btn = item.querySelector('.faq-btn'), ans = item.querySelector('.faq-answer'), chev = item.querySelector('.faq-chevron');
        ans.classList.toggle('hidden', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
    }
    document.querySelectorAll('.faq-item').forEach(item => {
        item.querySelector('.faq-btn').addEventListener('click', () => setOpen(item, item.querySelector('.faq-answer').classList.contains('hidden')));
    });

    // ---- filtro (ignora acentos e maiúsculas) ----
    const fold = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const input = document.getElementById('faqSearchInput');
    const items = Array.from(document.querySelectorAll('.faq-item'));
    const countEl = document.getElementById('faqCount');
    const emptyEl = document.getElementById('faqEmpty');

    function filter() {
        const terms = fold(input.value.trim()).split(/\s+/).filter(Boolean);
        let shown = 0;
        items.forEach(it => {
            const text = fold(it.textContent);
            const match = terms.every(t => text.includes(t));
            it.style.display = match ? '' : 'none';
            if (match) { shown++; setOpen(it, terms.length > 0 && items.length > 0 && shownCandidates(terms) <= 3); }
        });
        countEl.textContent = terms.length ? shown + (shown === 1 ? ' resultado' : ' resultados') : items.length + ' perguntas';
        emptyEl.classList.toggle('hidden', shown !== 0);
    }
    // abre as respostas automaticamente quando restam poucas correspondências
    function shownCandidates(terms) { return items.filter(it => terms.every(t => fold(it.textContent).includes(t))).length; }

    input.addEventListener('input', filter);

    // vindo da busca global: support.php?q=termo
    const q = new URLSearchParams(location.search).get('q');
    if (q) { input.value = q; filter(); const l = document.getElementById('faqList'); if (l) l.scrollIntoView({ block: 'center' }); }
})();
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
