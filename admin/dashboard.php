<?php
/**
 * Dashboard Administrativo — Tunísia CMS
 * Métricas reais calculadas do banco (Visualizações 30d vs 30d anteriores, Seções, Publicados, Rascunhos)
 * Lista de seções com reordenação direta e Últimas alterações com autor e ação
 */
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
require_once __DIR__ . '/../includes/admin_header.php';

$pdo = getPDO();

// 1. Métricas Reais do Banco
$totalSections = 0;
$publishedCount = 0;
$draftCount = 0;
$totalViews30d = 0;
$totalViewsPrev30d = 0;
$viewsDiffPercent = 0.0;
$viewsTrendUp = true;

$sectionsList = [];
$recentActivity = [];

if ($pdo) {
    try {
        // Contagens de seções
        $totalSections = (int)$pdo->query("SELECT COUNT(*) FROM sections")->fetchColumn();
        $publishedCount = (int)$pdo->query("SELECT COUNT(*) FROM sections WHERE status = 'published'")->fetchColumn();
        $draftCount = (int)$pdo->query("SELECT COUNT(*) FROM sections WHERE status = 'draft'")->fetchColumn();

        // Visualizações reais dos últimos 30 dias
        $stmtViews = $pdo->query("SELECT COUNT(*) FROM page_views WHERE created_at >= (NOW() - INTERVAL 30 DAY)");
        $totalViews30d = (int)($stmtViews ? $stmtViews->fetchColumn() : 0);

        // Visualizações do período de 30 a 60 dias atrás (para comparação)
        $stmtPrev = $pdo->query("SELECT COUNT(*) FROM page_views WHERE created_at >= (NOW() - INTERVAL 60 DAY) AND created_at < (NOW() - INTERVAL 30 DAY)");
        $totalViewsPrev30d = (int)($stmtPrev ? $stmtPrev->fetchColumn() : 0);

        if ($totalViewsPrev30d > 0) {
            $viewsDiffPercent = round((($totalViews30d - $totalViewsPrev30d) / $totalViewsPrev30d) * 100, 1);
            $viewsTrendUp = ($viewsDiffPercent >= 0);
        } else {
            $viewsDiffPercent = $totalViews30d > 0 ? 100.0 : 0.0;
            $viewsTrendUp = true;
        }

        // Seções ordenadas pelo display_order
        $stmt = $pdo->query("SELECT * FROM sections ORDER BY display_order ASC");
        $sectionsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Últimas atividades reais de auditoria com dados do usuário
        $stmtLogs = $pdo->query("
            SELECT a.*, u.name as user_name, u.avatar as user_avatar 
            FROM audit_logs a 
            LEFT JOIN users u ON a.user_id = u.id 
            ORDER BY a.created_at DESC 
            LIMIT 5
        ");
        $recentActivity = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Dashboard query error: " . $e->getMessage());
    }
}
?>

<!-- Boas-vindas e Botões de Ação Topo -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">Bom dia, <?= e($currentUser['name'] ?? 'Admin') ?></h1>
        <p class="text-sm text-slate-500 mt-0.5">Acompanhe e gerencie tudo que acontece no seu site com dados em tempo real.</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <a href="<?= SITE_URL ?>/index.php" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 hover:border-slate-300 text-slate-700 text-sm font-medium rounded-xl shadow-sm hover:shadow transition">
            <i data-lucide="eye" class="w-4 h-4 text-slate-500"></i>
            <span>Visualizar o site</span>
        </a>
        <a href="<?= ADMIN_URL ?>/content.php" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl shadow-md shadow-indigo-100 transition">
            <i data-lucide="edit-3" class="w-4 h-4"></i>
            <span>Editar conteúdo</span>
        </a>
    </div>
</div>

<!-- Grid de Cards de Estatísticas Reais -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <!-- Visualizações Reais dos últimos 30 dias -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow transition">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Visualizações</span>
            <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                <i data-lucide="users" class="w-5 h-5"></i>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-900 tracking-tight mb-2">
            <?= number_format($totalViews30d, 0, ',', '.') ?>
        </div>
        <div class="flex items-center gap-1.5 text-xs font-medium <?= $viewsTrendUp ? 'text-emerald-600' : 'text-rose-600' ?>">
            <i data-lucide="<?= $viewsTrendUp ? 'trending-up' : 'trending-down' ?>" class="w-3.5 h-3.5"></i>
            <span><?= ($viewsTrendUp ? '+' : '') . $viewsDiffPercent ?>% nos últimos 30 dias</span>
        </div>
    </div>

    <!-- Total de Seções -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow transition">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total de seções</span>
            <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <i data-lucide="box" class="w-5 h-5"></i>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-900 tracking-tight mb-2"><?= sprintf('%02d', $totalSections) ?></div>
        <div class="flex items-center gap-1.5 text-xs font-medium text-emerald-600">
            <i data-lucide="check" class="w-3.5 h-3.5"></i>
            <span>Todas as seções cadastradas</span>
        </div>
    </div>

    <!-- Conteúdos Publicados -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow transition">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Conteúdos Publicados</span>
            <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <i data-lucide="activity" class="w-5 h-5"></i>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-900 tracking-tight mb-2"><?= sprintf('%02d', $publishedCount) ?></div>
        <div class="flex items-center gap-1.5 text-xs font-medium text-slate-500">
            <i data-lucide="globe" class="w-3.5 h-3.5 text-indigo-500"></i>
            <span>Visíveis no site público</span>
        </div>
    </div>

    <!-- Rascunhos -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-sm hover:shadow transition">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Rascunhos</span>
            <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-500 flex items-center justify-center">
                <i data-lucide="file-clock" class="w-5 h-5"></i>
            </div>
        </div>
        <div class="text-2xl font-bold text-slate-900 tracking-tight mb-2"><?= sprintf('%02d', $draftCount) ?></div>
        <div class="flex items-center gap-1.5 text-xs font-medium text-amber-600">
            <i data-lucide="clock" class="w-3.5 h-3.5"></i>
            <span><?= $draftCount > 0 ? 'Aguardando publicação' : 'Nenhum rascunho pendente' ?></span>
        </div>
    </div>
</div>

<!-- Layout Principal de 2 Colunas -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
    
    <!-- Coluna Esquerda: Seções do Site com Suporte a Reordenação Direta -->
    <div class="lg:col-span-2 min-w-0 bg-white rounded-2xl p-4 sm:p-6 border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between pb-5 border-b border-slate-100 mb-4">
            <div>
                <h2 class="text-base font-bold text-slate-900">Seções do site</h2>
                <p class="text-xs text-slate-400">Edite ou reordene rapidamente a ordem de exibição.</p>
            </div>
            
            <div class="flex items-center gap-2">
                <div id="dashReorderActions" class="hidden items-center gap-2">
                    <button type="button" onclick="cancelDashReorder()" class="px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 rounded-lg">Cancelar</button>
                    <button type="button" onclick="saveDashOrder()" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm">Salvar</button>
                </div>
                <button type="button" id="btnDashReorder" onclick="toggleDashReorder()" class="text-xs font-semibold text-slate-500 hover:text-indigo-600 flex items-center gap-1.5 transition">
                    <span>Reordenar</span>
                    <i data-lucide="menu" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        <div id="dashSectionsList" class="divide-y divide-slate-100">
            <?php foreach ($sectionsList as $sec): ?>
                <div data-id="<?= (int)$sec['id'] ?>" class="dash-section-item py-3.5 flex items-center justify-between gap-4 hover:bg-slate-50/70 px-2 rounded-xl transition">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <div class="dash-drag-icon hidden cursor-grab text-slate-400 hover:text-indigo-600">
                            <i data-lucide="grip-vertical" class="w-4 h-4"></i>
                        </div>
                        <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-500 shrink-0">
                            <i data-lucide="layout" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-slate-800 truncate"><?= e($sec['title']) ?></h3>
                            <p class="text-xs text-slate-400 truncate"><?= e($sec['subtitle'] ?? 'Seção do site público') ?></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-4 shrink-0">
                        <?php if (($sec['status'] ?? 'published') === 'published'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                                Publicado
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                                Rascunho
                            </span>
                        <?php endif; ?>
                        
                        <span class="text-xs text-slate-400 hidden sm:inline" title="<?= e(date('d/m/Y H:i', strtotime($sec['updated_at'] ?? 'now'))) ?>">
                            <?= timeAgo($sec['updated_at'] ?? date('Y-m-d H:i:s')) ?>
                        </span>
                        
                        <a href="<?= ADMIN_URL ?>/editor.php?id=<?= $sec['id'] ?>" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-indigo-600 flex items-center justify-center transition" title="Editar">
                            <i data-lucide="pencil" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Coluna Direita: Últimas Alterações Reais & Card Documentação -->
    <div class="space-y-6">
        <!-- Últimas Alterações com autor e ação reais -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm">
            <div class="mb-4">
                <h2 class="text-base font-bold text-slate-900">Últimas alterações</h2>
                <p class="text-xs text-slate-400">Atividade recente da equipe em tempo real.</p>
            </div>

            <?php if (empty($recentActivity)): ?>
                <div class="py-6 text-center text-xs text-slate-400">
                    Nenhuma atividade registrada recentemente.
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentActivity as $act): ?>
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-700 font-bold text-xs shrink-0 mt-0.5 overflow-hidden">
                                <?php if (!empty($act['user_avatar'])): ?>
                                    <img src="<?= SITE_URL ?>/<?= ltrim($act['user_avatar'], '/') ?>" alt="Avatar" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span><?= strtoupper(substr($act['user_name'] ?? 'U', 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs min-w-0 flex-1">
                                <p class="text-slate-800 font-medium">
                                    <strong class="text-slate-900"><?= e($act['user_name'] ?? 'Usuário') ?></strong> <?= e($act['action']) ?>
                                </p>
                                <p class="text-slate-500 truncate"><?= e($act['details'] ?? '') ?></p>
                                <span class="text-[10px] text-slate-400" title="<?= e(date('d/m/Y H:i', strtotime($act['created_at']))) ?>">
                                    <?= timeAgo($act['created_at']) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 pt-4 border-t border-slate-100">
                <a href="<?= ADMIN_URL ?>/history.php" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center gap-1.5 transition">
                    <span>Ver todo o histórico aqui</span>
                    <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>
        </div>

        <!-- Banner Precisa de Ajuda -->
        <div class="rounded-2xl p-6 bg-indigo-600 text-white shadow-lg shadow-indigo-200">
            <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center text-white mb-4">
                <i data-lucide="help-circle" class="w-6 h-6"></i>
            </div>
            <h3 class="text-base font-bold mb-1">Precisa de ajuda?</h3>
            <p class="text-xs text-indigo-100 mb-5 leading-relaxed">Acesse o guia de uso do painel e aprenda a editar cada elemento com facilidade.</p>
            <a href="<?= ADMIN_URL ?>/support.php" class="inline-flex items-center gap-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/20 px-3.5 py-2 rounded-xl transition">
                <span>Ver documentação</span>
                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>
    </div>
</div>

<script>
    let isDashReordering = false;
    let dashDragged = null;
    const dashCsrf = '<?= getCsrfToken() ?>';

    function toggleDashReorder() {
        isDashReordering = !isDashReordering;
        const items = document.querySelectorAll('.dash-section-item');
        const actions = document.getElementById('dashReorderActions');
        const btnToggle = document.getElementById('btnDashReorder');

        if (isDashReordering) {
            actions.classList.remove('hidden');
            actions.classList.add('flex');
            btnToggle.classList.add('hidden');

            items.forEach(item => {
                item.setAttribute('draggable', 'true');
                item.classList.add('cursor-grab');
                const icon = item.querySelector('.dash-drag-icon');
                if (icon) icon.classList.remove('hidden');

                item.addEventListener('dragstart', (e) => {
                    dashDragged = item;
                    item.classList.add('opacity-40');
                });
                item.addEventListener('dragend', () => {
                    item.classList.remove('opacity-40');
                    dashDragged = null;
                });
                item.addEventListener('dragover', (e) => {
                    e.preventDefault();
                });
                item.addEventListener('drop', (e) => {
                    e.preventDefault();
                    if (dashDragged && dashDragged !== item) {
                        const list = document.getElementById('dashSectionsList');
                        const all = Array.from(list.querySelectorAll('.dash-section-item'));
                        if (all.indexOf(dashDragged) < all.indexOf(item)) {
                            item.after(dashDragged);
                        } else {
                            item.before(dashDragged);
                        }
                    }
                });
            });
        } else {
            actions.classList.add('hidden');
            actions.classList.remove('flex');
            btnToggle.classList.remove('hidden');
            items.forEach(item => {
                item.removeAttribute('draggable');
                item.classList.remove('cursor-grab');
                const icon = item.querySelector('.dash-drag-icon');
                if (icon) icon.classList.add('hidden');
            });
        }
    }

    function cancelDashReorder() {
        window.location.reload();
    }

    async function saveDashOrder() {
        const items = document.querySelectorAll('.dash-section-item');
        const order = Array.from(items).map(i => i.getAttribute('data-id'));

        try {
            const res = await fetch('<?= ADMIN_URL ?>/api/reorder_sections.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: dashCsrf, order: order })
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message || 'Ordem salva com sucesso!');
                window.location.reload();
            } else {
                alert(data.error || 'Erro ao salvar.');
            }
        } catch (e) {
            alert('Falha na comunicação com o servidor.');
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
