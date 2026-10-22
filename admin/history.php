<?php
/**
 * Histórico de Versões, Comparação e Restauração Dinâmica — Tunísia CMS
 * Sem dados fixos ou mockados. Respeita tempo relativo, retenção e confirmação.
 */
$pageTitle = 'Histórico de versões';
$activePage = 'history';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/section_style.php';
requireAuth();

$pdo = getPDO();
$successMsg = '';
$errorMsg = '';

// Aplica a política de retenção configurada antes de listar
if (function_exists('applyHistoryRetention')) {
    applyHistoryRetention();
}

// Ação de Restauração (com confirmação por checkbox)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_version_id'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $errorMsg = 'Sessão inválida ou expirada.';
    } else {
        $versionId = (int)$_POST['restore_version_id'];
        $confirmCheckbox = isset($_POST['confirm_restore']);

        if (!$confirmCheckbox) {
            $errorMsg = 'Você deve confirmar que entende que esta ação substituirá o conteúdo publicado.';
        } elseif ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM versions WHERE id = ?");
                $stmt->execute([$versionId]);
                $ver = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($ver) {
                    $data = json_decode($ver['data_payload'], true);
                    if ($data) {
                        // Restaura todos os campos existentes no snapshot. Versões antigas continuam compatíveis.
                        $pdo->beginTransaction();
                        $stmtUpdate = $pdo->prepare("UPDATE sections SET title = ?, subtitle = ?, badge = ?, description = ?, primary_btn_text = ?, primary_btn_link = ?, secondary_btn_text = ?, secondary_btn_link = ?, image_path = ?, video_path = ?, status = 'published', is_visible = ?, updated_at = NOW() WHERE id = ?");
                        $stmtUpdate->execute([
                            (string)($data['title'] ?? ''),
                            (string)($data['subtitle'] ?? ''),
                            $data['badge'] ?? null,
                            (string)($data['description'] ?? $data['subtitle'] ?? ''),
                            $data['primary_btn_text'] ?? '',
                            $data['primary_btn_link'] ?? '',
                            $data['secondary_btn_text'] ?? '',
                            $data['secondary_btn_link'] ?? '',
                            $data['image_path'] ?? null,
                            $data['video_path'] ?? null,
                            isset($data['is_visible']) ? (int)(bool)$data['is_visible'] : 1,
                            (int)$ver['section_id']
                        ]);

                        // Só substitui os itens quando a versão realmente contém esse snapshot.
                        if (isset($data['section_items']) && is_array($data['section_items'])) {
                            $pdo->prepare('DELETE FROM section_items WHERE section_id = ?')->execute([(int)$ver['section_id']]);
                            $insertItem = $pdo->prepare('INSERT INTO section_items (section_id,item_type,title,subtitle,description,icon,image_path,badge,link_url,meta_data,display_order,status,is_visible) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                            foreach ($data['section_items'] as $index => $item) {
                                if (!is_array($item) || empty($item['title'])) continue;
                                $insertItem->execute([
                                    (int)$ver['section_id'],
                                    $item['item_type'] ?? 'card',
                                    mb_substr((string)$item['title'], 0, 150),
                                    $item['subtitle'] ?? null,
                                    $item['description'] ?? null,
                                    $item['icon'] ?? null,
                                    $item['image_path'] ?? null,
                                    $item['badge'] ?? null,
                                    $item['link_url'] ?? null,
                                    $item['meta_data'] ?? null,
                                    (int)($item['display_order'] ?? ($index + 1)),
                                    'published',
                                    isset($item['is_visible']) ? (int)(bool)$item['is_visible'] : 1
                                ]);
                            }
                        }

                        // Restaura também a aparência (fontes, cores, layout) gravada naquela versão
                        if (array_key_exists('style', $data)) {
                            $pdo->prepare('UPDATE sections SET style_data = ? WHERE id = ?')
                                ->execute([json_encode(sanitizeSectionStyle($data['style']), JSON_UNESCAPED_UNICODE), (int)$ver['section_id']]);
                        }

                        // Registra nova versão preservando o histórico
                        $user = getCurrentUser();
                        $userName =$user['name'] ?? 'Admin';
                        $userId = (int)($user['id'] ?? 1);

                        $stmtNewVer =$pdo->prepare("INSERT INTO versions (section_id, title, data_payload, user_id, user_name, action_type, note, created_at) VALUES (?, ?, ?, ?, ?, 'restored', ?, NOW())");
                        $stmtNewVer->execute([$ver['section_id'],
                            $ver['title'],$ver['data_payload'],
                            $userId,$userName,
                            'Restaurado a partir da versão #' . $ver['id']
                        ]);

                        if (function_exists('logAudit')) {
                            logAudit('restaurou versão', "{$userName} restaurou a versão #{$ver['id']} da seção '{$ver['title']}'", $userId);
                        }
                        if (function_exists('createNotification')) {
                            createNotification(
                                'restaurou',
                                'Versão Restaurada',
                                "{$userName} restaurou a versão anterior da seção '{$ver['title']}'",
                                (int)$ver['section_id'],$userId
                            );
                        }

                        $pdo->commit();
                        if (function_exists('purgeSiteCache')) {
                            purgeSiteCache();
                        }
                        $successMsg = "Versão #{$ver['id']} restaurada e publicada com sucesso! Um novo registro foi gravado no histórico.";
                    }
                }
            } catch (Throwable $e) {
                if ($pdo && $pdo->inTransaction())$pdo->rollBack();
                error_log('Erro ao restaurar versão: ' . $e->getMessage());$errorMsg = 'Não foi possível restaurar esta versão. Verifique os logs do servidor.';
            }
        }
    }
}

// Lista de versões reais do banco
$versionsList = [];
if ($pdo) {
    try {
        $stmt =$pdo->query("
            SELECT v.*, u.avatar as user_avatar, s.identifier as section_identifier 
            FROM versions v 
            LEFT JOIN users u ON v.user_id = u.id 
            LEFT JOIN sections s ON v.section_id = s.id 
            ORDER BY v.created_at DESC
        ");
        $versionsList =$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching versions: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<!-- Header da Página -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Histórico de versões</h1>
        <p class="text-sm text-slate-500 mt-0.5">Consulte alterações reais e restaure versões anteriores do seu site.</p>
    </div>

    <div>
        <button type="button" onclick="openCompareModal()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 hover:border-slate-300 text-slate-700 text-xs font-semibold rounded-xl shadow-sm transition">
            <i data-lucide="git-compare" class="w-4 h-4 text-slate-500"></i>
            <span>Comparar versões</span>
        </button>
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

<!-- Lista de Histórico Real -->
<?php if (empty($versionsList)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-12 text-center">
        <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
            <i data-lucide="history" class="w-6 h-6"></i>
        </div>
        <h3 class="text-base font-bold text-slate-800 mb-1">Nenhuma versão gravada ainda</h3>
        <p class="text-xs text-slate-500 max-w-sm mx-auto mb-6">As alterações feitas nas seções do site aparecerão listadas aqui com data, autor e status.</p>
        <a href="<?= ADMIN_URL ?>/content.php" class="text-xs font-semibold text-indigo-600 hover:underline">Ir para Conteúdo do Site</a>
    </div>
<?php else: ?>
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="divide-y divide-slate-100">
            <?php foreach ($versionsList as$v): ?>
                <?php 
                    $data = json_decode($v['data_payload'], true) ?: [];
                    $statusBadge = 'Publicado';$badgeBg = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                    if (($v['action_type'] ?? '') === 'saved_draft') {
                        $statusBadge = 'Rascunho';$badgeBg = 'bg-amber-50 text-amber-700 border-amber-200';
                    } elseif (($v['action_type'] ?? '') === 'restored') {$statusBadge = 'Restaurado';
                        $badgeBg = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                    }$fullDate = date('d/m/Y \à\s H:i:s', strtotime($v['created_at']));$relDate = function_exists('timeAgo') ? timeAgo($v['created_at']) :$v['created_at'];
                ?>
                <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-slate-50/70 transition">
                    <div class="flex items-start gap-4">
                        <!-- Avatar do autor -->
                        <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center font-bold text-slate-700 shrink-0 overflow-hidden shadow-sm">
                            <?php if (!empty($v['user_avatar'])): ?>
                                <img src="<?= SITE_URL ?>/<?= ltrim($v['user_avatar'], '/') ?>" alt="Avatar" class="w-full h-full object-cover">
                            <?php else: ?>
                                <span class="text-xs"><?= strtoupper(substr($v['user_name'] ?? 'U', 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <h3 class="text-sm font-bold text-slate-900"><?= e($v['title']) ?></h3>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border <?= $badgeBg ?>">
                                    <?= $statusBadge ?>
                                </span>
                            </div>

                            <p class="text-xs text-slate-500 mb-1">
                                Por <span class="font-semibold text-slate-700"><?= e($v['user_name'] ?? 'Usuário') ?></span>
                                • <span title="<?= e($fullDate) ?>" class="cursor-help hover:text-slate-800 transition"><?= $relDate ?></span>
                                <?php if (!empty($v['note'])): ?>
                                    <span class="text-slate-400"> • <?= e($v['note']) ?></span>
                                <?php endif; ?>
                            </p>

                            <p class="text-xs text-slate-400 line-clamp-1 italic">
                                "<?= e($data['subtitle'] ?? $data['title'] ?? '') ?>"
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0 self-end md:self-center">
                        <button type="button" onclick='openRestoreModal(<?= json_encode($v, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' class="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 hover:text-indigo-600 shadow-sm transition flex items-center gap-1.5">
                            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                            <span>Restaurar versão</span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================== -->
<!-- MODAL DE RESTAURAÇÃO                           -->
<!-- ============================================== -->
<div id="restoreModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 transition-all">
        <div class="text-center mb-6">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto mb-3">
                <i data-lucide="alert-triangle" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-900">Restaurar esta versão?</h3>
            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                Você está prestes a reverter o conteúdo da seção <strong id="modalSectionTitle" class="text-slate-800"></strong> para a versão criada em <span id="modalVersionDate" class="font-semibold text-slate-800"></span>.
            </p>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="restore_version_id" id="modalVersionIdInput" value="">

            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 mb-5">
                <p class="text-[11px] text-slate-500 mb-2">
                    <strong>Autor original:</strong> <span id="modalAuthorName"></span>
                </p>
                <p class="text-[11px] text-slate-500">
                    A versão atual será preservada no histórico e poderá ser restaurada a qualquer momento.
                </p>
            </div>

            <div class="mb-6">
                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input type="checkbox" id="chkConfirmRestore" name="confirm_restore" value="1" onchange="toggleRestoreBtn()" class="w-4 h-4 mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-0">
                    <span class="text-xs text-slate-600 leading-normal">
                        Entendo que esta ação substituirá o conteúdo atualmente publicado no site.
                    </span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="button" onclick="closeRestoreModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                    Cancelar
                </button>
                <button type="submit" id="btnSubmitRestore" disabled class="px-5 py-2.5 rounded-xl bg-slate-300 text-white text-xs font-semibold transition cursor-not-allowed">
                    Confirmar restauração
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================== -->
<!-- MODAL DE COMPARAÇÃO DE VERSÕES                 -->
<!-- ============================================== -->
<div id="compareModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-4xl w-full p-6 lg:p-8 shadow-2xl border border-slate-100 transition-all flex flex-col max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
            <div>
                <h3 class="text-lg font-bold text-slate-900">Comparar versões</h3>
                <p class="text-xs text-slate-500">Analise as diferenças de conteúdo entre duas versões reais gravadas.</p>
            </div>
            <button type="button" onclick="closeCompareModal()" class="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-50">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Seletores de Versão Real -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Versão Anterior</label>
                <select id="selectVerLeft" onchange="updateComparisonView()" class="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl font-medium text-slate-700 focus:outline-none focus:border-indigo-500">
                    <?php foreach ($versionsList as $idx =>$vl): ?>
                        <option value="<?= (int)$vl['id'] ?>" <?= $idx === 1 ? 'selected' : '' ?>>
                            #<?= $vl['id'] ?> - <?= e($vl['title']) ?> (<?= e($vl['user_name']) ?>, <?= date('d/m/Y H:i', strtotime($vl['created_at'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Versão Mais Recente</label>
                <select id="selectVerRight" onchange="updateComparisonView()" class="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl font-medium text-slate-700 focus:outline-none focus:border-indigo-500">
                    <?php foreach ($versionsList as $idx =>$vl): ?>
                        <option value="<?= (int)$vl['id'] ?>" <?= $idx === 0 ? 'selected' : '' ?>>
                            #<?= $vl['id'] ?> - <?= e($vl['title']) ?> (<?= e($vl['user_name']) ?>, <?= date('d/m/Y H:i', strtotime($vl['created_at'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Visualização Lado a Lado com Diferenças Destacadas -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 flex-1 overflow-y-auto custom-scrollbar p-1">
            <!-- Coluna Esquerda: Versão Anterior -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 mb-4">
                    <span class="text-xs font-bold text-slate-700" id="leftCardTitle">Versão Anterior</span>
                    <span class="text-[11px] text-slate-400" id="leftCardDate">--</span>
                </div>
                <div class="space-y-4 text-xs">
                    <div>
                        <span class="font-semibold text-slate-600 block mb-1">Título:</span>
                        <div id="diffLeftTitle" class="p-2.5 bg-white rounded-xl border border-slate-200 text-slate-800"></div>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-600 block mb-1">Subtítulo / Descrição:</span>
                        <div id="diffLeftSubtitle" class="p-2.5 bg-white rounded-xl border border-slate-200 text-slate-800 whitespace-pre-line leading-relaxed"></div>
                    </div>
                </div>
            </div>

            <!-- Coluna Direita: Versão Mais Recente -->
            <div class="bg-indigo-50/40 rounded-2xl p-5 border border-indigo-100">
                <div class="flex items-center justify-between pb-3 border-b border-indigo-100 mb-4">
                    <span class="text-xs font-bold text-indigo-900" id="rightCardTitle">Versão Mais Recente</span>
                    <span class="text-[11px] text-indigo-500" id="rightCardDate">--</span>
                </div>
                <div class="space-y-4 text-xs">
                    <div>
                        <span class="font-semibold text-slate-600 block mb-1">Título:</span>
                        <div id="diffRightTitle" class="p-2.5 bg-white rounded-xl border border-indigo-100 text-slate-800"></div>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-600 block mb-1">Subtítulo / Descrição:</span>
                        <div id="diffRightSubtitle" class="p-2.5 bg-white rounded-xl border border-indigo-100 text-slate-800 whitespace-pre-line leading-relaxed"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rodapé da Comparação -->
        <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
            <span class="text-xs text-slate-400">
                Texto com <span class="bg-red-100 text-red-700 px-1 rounded">remoções</span> e <span class="bg-emerald-100 text-emerald-700 px-1 rounded">adições</span> destacadas.
            </span>
            <div class="flex items-center gap-3">
                <button type="button" onclick="closeCompareModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 rounded-xl">Fechar</button>
                <button type="button" id="btnRestoreLeftFromCompare" onclick="restoreFromCompare()" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-sm transition">
                    Restaurar versão anterior
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const allVersions = <?= json_encode($versionsList, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

    function openRestoreModal(version) {
        const modal = document.getElementById('restoreModal');
        const idInput = document.getElementById('modalVersionIdInput');
        const sectionTitle = document.getElementById('modalSectionTitle');
        const versionDate = document.getElementById('modalVersionDate');
        const authorName = document.getElementById('modalAuthorName');
        const chk = document.getElementById('chkConfirmRestore');

        idInput.value = version.id;
        sectionTitle.textContent = version.title;
        authorName.textContent = version.user_name || 'Usuário';

        const d = new Date(version.created_at);
        versionDate.textContent = d.toLocaleDateString('pt-BR') + ' às ' + d.toLocaleTimeString('pt-BR');

        chk.checked = false;
        toggleRestoreBtn();

        modal.classList.remove('hidden');
        lucide.createIcons();
    }

    function closeRestoreModal() {
        document.getElementById('restoreModal').classList.add('hidden');
    }

    function toggleRestoreBtn() {
        const chk = document.getElementById('chkConfirmRestore');
        const btn = document.getElementById('btnSubmitRestore');
        if (chk.checked) {
            btn.disabled = false;
            btn.className = 'px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-md transition cursor-pointer';
        } else {
            btn.disabled = true;
            btn.className = 'px-5 py-2.5 rounded-xl bg-slate-300 text-white text-xs font-semibold transition cursor-not-allowed';
        }
    }

    function openCompareModal() {
        document.getElementById('compareModal').classList.remove('hidden');
        updateComparisonView();
        lucide.createIcons();
    }

    function closeCompareModal() {
        document.getElementById('compareModal').classList.add('hidden');
    }

    function getVersionById(id) {
        return allVersions.find(v => parseInt(v.id) === parseInt(id));
    }

    function updateComparisonView() {
        const leftId = document.getElementById('selectVerLeft').value;
        const rightId = document.getElementById('selectVerRight').value;

        const left = getVersionById(leftId);
        const right = getVersionById(rightId);

        if (!left || !right) return;

        const leftData = JSON.parse(left.data_payload || '{}');
        const rightData = JSON.parse(right.data_payload || '{}');

        document.getElementById('leftCardTitle').textContent = `Versão #${left.id} (${left.user_name})`;
        document.getElementById('leftCardDate').textContent = new Date(left.created_at).toLocaleString('pt-BR');

        document.getElementById('rightCardTitle').textContent = `Versão #${right.id} (${right.user_name})`;
        document.getElementById('rightCardDate').textContent = new Date(right.created_at).toLocaleString('pt-BR');

        renderDiff(leftData.title || '', rightData.title || '', 'diffLeftTitle', 'diffRightTitle');
        renderDiff(leftData.subtitle || '', rightData.subtitle || '', 'diffLeftSubtitle', 'diffRightSubtitle');
    }

    function renderDiff(textA, textB, elAId, elBId) {
        const elA = document.getElementById(elAId);
        const elB = document.getElementById(elBId);

        if (textA === textB) {
            elA.innerHTML = escapeHtml(textA);
            elB.innerHTML = escapeHtml(textB);
            return;
        }

        elA.innerHTML = `<span class="bg-red-50 text-red-700 px-1 py-0.5 rounded border border-red-200 line-through">${escapeHtml(textA)}</span>`;
        elB.innerHTML = `<span class="bg-emerald-50 text-emerald-700 px-1 py-0.5 rounded border border-emerald-200 font-semibold">${escapeHtml(textB)}</span>`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function restoreFromCompare() {
        const leftId = document.getElementById('selectVerLeft').value;
        const left = getVersionById(leftId);
        if (left) {
            closeCompareModal();
            openRestoreModal(left);
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>