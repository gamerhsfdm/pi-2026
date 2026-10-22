<?php
/**
 * Conteúdo do Site — Tunísia CMS
 * Reordenação com Drag and Drop, Filtros reais e Salvamento
 */
$pageTitle = 'Conteúdo do site';
$activePage = 'content';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/section_style.php';

$pdo = getPDO();
$filter = $_GET['filter'] ?? 'all';

// Exclusão, Restauração e Limpeza de seção: somente administrador, via POST e com validação CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete_section', 'restore_section', 'force_delete'], true)) {
    requireAuth(true);
    $action = $_POST['action'];

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['content_flash_error'] = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } elseif (!$pdo) {
        $_SESSION['content_flash_error'] = 'Não foi possível conectar ao banco de dados.';
    } else {
        $secId = filter_var($_POST['section_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$secId) {
            $_SESSION['content_flash_error'] = 'Seção inválida.';
        } else {
            try {
                $find = $pdo->prepare('SELECT id, title, identifier, deleted_at FROM sections WHERE id = ? LIMIT 1');
                $find->execute([$secId]);
                $sectionFound = $find->fetch(PDO::FETCH_ASSOC);

                if (!$sectionFound) {
                    throw new RuntimeException('A seção não foi encontrada.');
                }

                if ($action === 'delete_section') {
                    // Soft delete: move para a lixeira
                    $stmt = $pdo->prepare('UPDATE sections SET deleted_at = NOW() WHERE id = ?');
                    $stmt->execute([$secId]);
                    purgeSiteCache();
                    logAudit('moveu seção para lixeira', 'Seção: ' . $sectionFound['title'], (int)(getCurrentUser()['id'] ?? 0));
                    createNotification('excluiu', 'Seção na Lixeira', "A seção “{$sectionFound['title']}” foi movida para a lixeira.");
                    $_SESSION['content_flash_success'] = 'A seção “' . $sectionFound['title'] . '” foi movida para a lixeira.';
                } elseif ($action === 'restore_section') {
                    // Restaura da lixeira
                    $stmt = $pdo->prepare('UPDATE sections SET deleted_at = NULL WHERE id = ?');
                    $stmt->execute([$secId]);
                    purgeSiteCache();
                    logAudit('restaurou seção da lixeira', 'Seção: ' . $sectionFound['title'], (int)(getCurrentUser()['id'] ?? 0));
                    createNotification('restaurou', 'Seção Restaurada', "A seção “{$sectionFound['title']}” foi restaurada da lixeira.");
                    $_SESSION['content_flash_success'] = 'A seção “' . $sectionFound['title'] . '” foi restaurada com sucesso.';
                } elseif ($action === 'force_delete') {
                    // Exclusão permanente
                    $stmt = $pdo->prepare('DELETE FROM sections WHERE id = ?');
                    $stmt->execute([$secId]);
                    purgeSiteCache();
                    logAudit('excluiu permanentemente seção', 'Seção: ' . $sectionFound['title'], (int)(getCurrentUser()['id'] ?? 0));
                    $_SESSION['content_flash_success'] = 'A seção “' . $sectionFound['title'] . '” foi excluída permanentemente.';
                }
            } catch (Throwable $e) {
                error_log('Erro ao processar seção: ' . $e->getMessage());
                $_SESSION['content_flash_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Ocorreu um erro ao processar a ação.';
            }
        }
    }

    $redirectFilter = $_POST['filter'] ?? 'all';
    if (!in_array($redirectFilter, ['all', 'published', 'drafts', 'trash'], true)) $redirectFilter = 'all';
    header('Location: ' . ADMIN_URL . '/content.php?filter=' . urlencode($redirectFilter));
    exit;
}

$flashSuccess = $_SESSION['content_flash_success'] ?? '';
$flashError = $_SESSION['content_flash_error'] ?? '';
unset($_SESSION['content_flash_success'], $_SESSION['content_flash_error']);

$sections = [];
if ($pdo) {
    try {
        if ($filter === 'trash') {
            $stmt = $pdo->query("SELECT * FROM sections WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
        } elseif ($filter === 'published') {
            $stmt = $pdo->query("SELECT * FROM sections WHERE deleted_at IS NULL AND status = 'published' ORDER BY display_order ASC");
        } elseif ($filter === 'drafts') {
            $stmt = $pdo->query("SELECT * FROM sections WHERE deleted_at IS NULL AND status = 'draft' ORDER BY display_order ASC");
        } else {
            $stmt = $pdo->query("SELECT * FROM sections WHERE deleted_at IS NULL ORDER BY display_order ASC");
        }
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching sections: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<?php if ($flashSuccess !== ''): ?>
    <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        <i data-lucide="check-circle" class="inline-block w-4 h-4 mr-1 align-text-bottom"></i><?= e($flashSuccess) ?>
    </div>
<?php endif; ?>
<?php if ($flashError !== ''): ?>
    <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <i data-lucide="alert-circle" class="inline-block w-4 h-4 mr-1 align-text-bottom"></i><?= e($flashError) ?>
    </div>
<?php endif; ?>

<!-- Header da Página -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Conteúdo do site</h1>
        <p class="text-sm text-slate-500 mt-0.5">Edite, organize e publique todas as seções visíveis do projeto.</p>
    </div>

    <div class="flex items-center gap-3">
        <!-- Controles de Reordenação (aparecem quando ativado) -->
        <div id="reorderActionGroup" class="hidden items-center gap-2">
            <button type="button" onclick="cancelReorder()" class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-xl transition">
                Cancelar
            </button>
            <button type="button" onclick="saveNewOrder()" id="btnSaveOrder" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl shadow-md transition flex items-center gap-1.5">
                <i data-lucide="check" class="w-4 h-4"></i>
                <span>Salvar ordem</span>
            </button>
        </div>

        <button type="button" id="btnToggleReorder" onclick="toggleReorderMode()" class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700 hover:text-indigo-600 bg-white border border-slate-200 px-4 py-2.5 rounded-xl shadow-sm transition">
            <i data-lucide="arrow-down-up" class="w-4 h-4 text-slate-500"></i>
            <span>Reordenar seções</span>
        </button>

        <a href="<?= ADMIN_URL ?>/editor.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-100 transition">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Nova seção</span>
        </a>
    </div>
</div>

<!-- Barra de Notificação de Modo de Reordenação -->
<div id="reorderBanner" class="hidden mb-6 p-4 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-900 text-sm flex items-center justify-between">
    <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center shrink-0">
            <i data-lucide="move" class="w-4 h-4"></i>
        </div>
        <div>
            <p class="font-bold text-xs uppercase tracking-wider text-indigo-700">Modo de Reordenação Ativo</p>
            <p class="text-xs text-indigo-800">Clique e arraste os cards para reorganizar a ordem de exibição no site público.</p>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <button type="button" onclick="cancelReorder()" class="px-3 py-1.5 rounded-lg bg-white text-slate-700 text-xs font-medium border border-indigo-200 hover:bg-slate-50">Cancelar</button>
        <button type="button" onclick="saveNewOrder()" class="px-4 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-semibold shadow hover:bg-indigo-700">Salvar Nova Ordem</button>
    </div>
</div>

<!-- Filtros Reais -->
<div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-6">
    <div class="flex items-center gap-2">
        <a href="<?= ADMIN_URL ?>/content.php?filter=all" class="px-4 py-2 rounded-xl text-xs font-semibold transition <?= $filter === 'all' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
            Todos
        </a>
        <a href="<?= ADMIN_URL ?>/content.php?filter=published" class="px-4 py-2 rounded-xl text-xs font-semibold transition <?= $filter === 'published' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
            Publicados
        </a>
        <a href="<?= ADMIN_URL ?>/content.php?filter=drafts" class="px-4 py-2 rounded-xl text-xs font-semibold transition <?= $filter === 'drafts' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
            Rascunhos
        </a>
        <a href="<?= ADMIN_URL ?>/content.php?filter=trash" class="px-4 py-2 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 <?= $filter === 'trash' ? 'bg-rose-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
            <i data-lucide="trash" class="w-3.5 h-3.5"></i>
            <span>Lixeira</span>
        </a>
    </div>

    <span class="text-xs text-slate-400 font-medium">
        <?= count($sections) ?> <?= count($sections) === 1 ? 'seção encontrada' : 'seções encontradas' ?>
    </span>
</div>

<!-- Grid de Cards de Seções com Drag and Drop -->
<?php if (empty($sections)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-12 text-center">
        <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
            <i data-lucide="file-question" class="w-6 h-6"></i>
        </div>
        <h3 class="text-base font-bold text-slate-800 mb-1">Nenhuma seção encontrada</h3>
        <p class="text-xs text-slate-500 max-w-sm mx-auto mb-6"><?= $filter === 'trash' ? 'A lixeira está vazia.' : 'Não há registros correspondentes ao filtro selecionado.' ?></p>
        <a href="<?= ADMIN_URL ?>/content.php?filter=all" class="text-xs font-semibold text-indigo-600 hover:underline">Ver todas as seções</a>
    </div>
<?php else: ?>
    <div id="sectionsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($sections as $s): ?>
            <?php
                // Processa o style_data para verificar se há fundo ou vídeo salvo nas configurações de estilo
                $styleData = decodeSectionStyle($s['style_data'] ?? '');
                
                $mediaPath = '';
                $isVideo = false;

                // 1. Prioridade para o campo dedicado de vídeo
                if (!empty($s['video_path'])) {
                    $mediaPath = $s['video_path'];
                    $isVideo = true;
                } 
                // 2. Verifica se foi selecionado vídeo ou imagem na aba fundo (style_data)
                elseif (!empty($styleData['bg_image'])) {
                    $mediaPath = $styleData['bg_image'];
                    $isVideo = (bool)preg_match('/\.(mp4|webm|ogg)$/i', $mediaPath);
                } 
                // 3. Fallback para image_path tradicional
                elseif (!empty($s['image_path'])) {
                    $mediaPath = $s['image_path'];
                    $isVideo = (bool)preg_match('/\.(mp4|webm|ogg)$/i', $mediaPath);
                }

                $mediaUrl = '';
                if (!empty($mediaPath)) {
                    $mediaUrl = preg_match('/^(https?:)?\/\//', $mediaPath) ? $mediaPath : SITE_URL . '/' . ltrim($mediaPath, '/');
                }
            ?>

            <div data-id="<?= (int)$s['id'] ?>" class="section-card bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md transition-all flex flex-col group relative">
                
                <!-- Indicador de Ordem / Drag Handle -->
                <div class="drag-handle hidden absolute top-3 left-3 z-10 bg-slate-900/80 backdrop-blur-sm text-white px-2.5 py-1 rounded-lg text-xs font-semibold items-center gap-1.5 cursor-grab shadow">
                    <i data-lucide="grip-vertical" class="w-3.5 h-3.5"></i>
                    <span class="order-number">#<?= (int)$s['display_order'] ?></span>
                </div>

                <!-- Status Badge -->
                <div class="absolute top-3 right-3 z-10">
                    <?php if (!empty($s['deleted_at'])): ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold bg-rose-500/90 backdrop-blur-sm text-white shadow-sm">
                            Na Lixeira
                        </span>
                    <?php elseif (($s['status'] ?? 'published') === 'published'): ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-500/90 backdrop-blur-sm text-white shadow-sm">
                            Publicado
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-500/90 backdrop-blur-sm text-white shadow-sm">
                            Rascunho
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Mídia da Seção (Vídeo ou Imagem real) -->
                <div class="h-44 bg-slate-900 overflow-hidden relative">
                    <?php if ($isVideo && $mediaUrl): ?>
                        <video src="<?= e($mediaUrl) ?>" autoplay muted loop playsinline class="w-full h-full object-cover group-hover:scale-105 transition duration-300"></video>
                        <div class="absolute bottom-2 right-2 bg-slate-900/80 backdrop-blur-sm text-white text-[10px] px-2 py-0.5 rounded-md flex items-center gap-1 font-semibold">
                            <i data-lucide="video" class="w-3 h-3 text-indigo-400"></i> Vídeo
                        </div>
                    <?php elseif ($mediaUrl): ?>
                        <img src="<?= e($mediaUrl) ?>" alt="<?= e($s['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" loading="lazy">
                    <?php else: ?>
                        <div class="w-full h-full flex flex-col items-center justify-center text-slate-500 bg-slate-100">
                            <i data-lucide="image-off" class="w-8 h-8 stroke-[1.5] mb-1"></i>
                            <span class="text-xs font-medium">Sem mídia</span>
                        </div>
                    <?php endif; ?>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/30 via-transparent to-transparent pointer-events-none"></div>
                </div>

                <!-- Dados da Seção -->
                <div class="p-5 flex-1 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">
                                <?= e($s['identifier']) ?>
                            </span>
                        </div>
                        <h3 class="font-bold text-slate-900 text-base mb-1 truncate"><?= e($s['title']) ?></h3>
                        <p class="text-xs text-slate-500 mb-3 line-clamp-2"><?= e($s['subtitle'] ?? 'Seção configurada no projeto') ?></p>
                        <p class="text-[11px] text-slate-400" title="<?= e(date('d/m/Y H:i', strtotime($s['updated_at'] ?? 'now'))) ?>">
                            Atualizado <?= timeAgo($s['updated_at'] ?? date('Y-m-d H:i:s')) ?>
                        </p>
                    </div>

                    <div class="mt-5 pt-4 border-t border-slate-100 flex items-center justify-between">
                        <?php if (empty($s['deleted_at'])): ?>
                            <a href="<?= ADMIN_URL ?>/editor.php?id=<?= (int)$s['id'] ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition">
                                <i data-lucide="edit-2" class="w-3.5 h-3.5"></i>
                                <span>Editar seção</span>
                            </a>

                            <div class="flex items-center gap-3">
                                <a href="<?= SITE_URL ?>/index.php#<?= e($s['identifier']) ?>" target="_blank" rel="noopener noreferrer" class="text-slate-400 hover:text-slate-700 text-xs font-medium flex items-center gap-1">
                                    <span>Ver</span>
                                    <i data-lucide="external-link" class="w-3 h-3"></i>
                                </a>
                                <?php if (isAdmin()): ?>
                                    <form method="post" action="<?= ADMIN_URL ?>/content.php" onsubmit="return confirm('Mover esta seção para a lixeira?');" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_section">
                                        <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="filter" value="<?= e(in_array($filter, ['all', 'published', 'drafts', 'trash'], true) ? $filter : 'all') ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 hover:border-red-300 transition" title="Mover para lixeira">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            <span>Excluir</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-2 w-full justify-between">
                                <form method="post" action="<?= ADMIN_URL ?>/content.php" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="restore_section">
                                    <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="filter" value="trash">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 text-xs font-semibold transition">
                                        <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                                        <span>Restaurar</span>
                                    </button>
                                </form>

                                <form method="post" action="<?= ADMIN_URL ?>/content.php" onsubmit="return confirm('Excluir esta seção permanentemente? Esta ação NÃO pode ser desfeita!');" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="force_delete">
                                    <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="filter" value="trash">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 text-xs font-semibold transition">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        <span>Excluir definitivo</span>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
    .section-card.dragging {
        opacity: 0.4;
        transform: scale(0.98);
        border: 2px dashed #6366f1 !important;
    }
    .section-card.drag-over {
        border-color: #4f46e5 !important;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.25) !important;
    }
    .drag-cursor {
        cursor: grab !important;
    }
    .drag-cursor:active {
        cursor: grabbing !important;
    }
</style>

<script>
    function confirmDeleteSection(form) {
        const title = form.closest('.section-card')?.querySelector('h3')?.textContent?.trim() || 'esta seção';
        return window.confirm('Tem certeza de que deseja excluir a seção “' + title + '”?\n\nOs cards/itens e o histórico de versões vinculados também serão excluídos. Essa ação não pode ser desfeita.');
    }

    let isReordering = false;
    let initialOrder = [];
    const csrfToken = '<?= getCsrfToken() ?>';

    function toggleReorderMode() {
        isReordering = !isReordering;
        const grid = document.getElementById('sectionsGrid');
        const cards = document.querySelectorAll('.section-card');
        const banner = document.getElementById('reorderBanner');
        const actionGroup = document.getElementById('reorderActionGroup');
        const toggleBtn = document.getElementById('btnToggleReorder');

        if (isReordering) {
            banner.classList.remove('hidden');
            actionGroup.classList.remove('hidden');
            actionGroup.classList.add('flex');
            toggleBtn.classList.add('hidden');

            initialOrder = Array.from(cards).map(c => c.getAttribute('data-id'));

            cards.forEach(card => {
                card.setAttribute('draggable', 'true');
                card.classList.add('drag-cursor');
                const handle = card.querySelector('.drag-handle');
                if (handle) {
                    handle.classList.remove('hidden');
                    handle.classList.add('flex');
                }
                initDragEvents(card);
            });
        } else {
            banner.classList.add('hidden');
            actionGroup.classList.add('hidden');
            actionGroup.classList.remove('flex');
            toggleBtn.classList.remove('hidden');

            cards.forEach(card => {
                card.removeAttribute('draggable');
                card.classList.remove('drag-cursor');
                const handle = card.querySelector('.drag-handle');
                if (handle) {
                    handle.classList.add('hidden');
                    handle.classList.remove('flex');
                }
            });
        }
    }

    function cancelReorder() {
        window.location.reload();
    }

    let draggedItem = null;

    function initDragEvents(card) {
        card.addEventListener('dragstart', (e) => {
            draggedItem = card;
            setTimeout(() => card.classList.add('dragging'), 0);
            e.dataTransfer.effectAllowed = 'move';
        });

        card.addEventListener('dragend', () => {
            if (draggedItem) {
                draggedItem.classList.remove('dragging');
                draggedItem = null;
            }
            document.querySelectorAll('.section-card').forEach(c => c.classList.remove('drag-over'));
            updateOrderBadges();
        });

        card.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (draggedItem && draggedItem !== card) {
                card.classList.add('drag-over');
            }
        });

        card.addEventListener('dragleave', () => {
            card.classList.remove('drag-over');
        });

        card.addEventListener('drop', (e) => {
            e.preventDefault();
            card.classList.remove('drag-over');
            if (draggedItem && draggedItem !== card) {
                const grid = document.getElementById('sectionsGrid');
                const allCards = Array.from(grid.querySelectorAll('.section-card'));
                const draggedIdx = allCards.indexOf(draggedItem);
                const targetIdx = allCards.indexOf(card);

                if (draggedIdx < targetIdx) {
                    card.after(draggedItem);
                } else {
                    card.before(draggedItem);
                }
                updateOrderBadges();
            }
        });
    }

    function updateOrderBadges() {
        const cards = document.querySelectorAll('.section-card');
        cards.forEach((c, idx) => {
            const badge = c.querySelector('.order-number');
            if (badge) {
                badge.textContent = '#' + (idx + 1);
            }
        });
    }

    async function saveNewOrder() {
        const btnSave = document.getElementById('btnSaveOrder');
        btnSave.disabled = true;
        btnSave.innerHTML = '<span>Salvando...</span>';

        const cards = document.querySelectorAll('.section-card');
        const newOrder = Array.from(cards).map(c => c.getAttribute('data-id'));

        try {
            const res = await fetch('<?= ADMIN_URL ?>/api/reorder_sections.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    order: newOrder
                })
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message || 'Ordem salva com sucesso!');
                window.location.reload();
            } else {
                alert(data.error || 'Erro ao salvar reordenação.');
                btnSave.disabled = false;
                btnSave.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i><span>Salvar ordem</span>';
                lucide.createIcons();
            }
        } catch (err) {
            alert('Falha na comunicação com o servidor.');
            btnSave.disabled = false;
            btnSave.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i><span>Salvar ordem</span>';
            lucide.createIcons();
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>