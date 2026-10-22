<?php
/**
 * Preview do Site — Tunísia CMS
 * Fiel à página 7 do design
 */
$pageTitle = 'Preview Do Site';
$activePage = 'preview';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$pdo = getPDO();
$successMsg = '';

// Publicar alterações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_all'])) {
    if ($pdo) {
        $pdo->query("UPDATE sections SET status = 'published'");
        logAudit('publicou todas as alterações', 'Site completo atualizado');
    }
    $successMsg = 'Todas as alterações foram publicadas com sucesso no site principal!';
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<!-- Header do Preview (Fiel à página 7) -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
    <div>
        <p class="text-xs text-slate-400">Revise os conteúdos como seus visitantes irão vê-lo</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <a href="<?= SITE_URL ?>/index.php" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 hover:border-slate-300 text-slate-700 text-xs font-semibold rounded-xl transition">
            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
            <span>Abrir em nova aba</span>
        </a>

        <form method="POST">
            <input type="hidden" name="publish_all" value="1">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-100 transition">
                <span>Publicar Alterações</span>
            </button>
        </form>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3">
        <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600"></i>
        <span><?= e($successMsg) ?></span>
    </div>
<?php endif; ?>

<!-- Janela de Simulação do Navegador -->
<div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
    <!-- Barra Superior Browser -->
    <div class="p-3 sm:p-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 bg-slate-50/50">
        <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-slate-300"></span>
            <span class="w-3 h-3 rounded-full bg-slate-300"></span>
            <span class="w-3 h-3 rounded-full bg-slate-300"></span>
            <span class="hidden sm:inline ml-2 text-xs text-slate-500 bg-white border border-slate-200 px-4 py-1.5 rounded-full font-mono">projetotunisia.com.br</span>
        </div>

        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl text-xs font-medium">
            <button type="button" id="btnDeviceDesktop" onclick="setDevice('desktop')" class="px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition">Desktop</button>
            <button type="button" id="btnDeviceTablet" onclick="setDevice('tablet')" class="px-3 py-1 rounded-lg text-slate-500 hover:text-slate-800 transition">Tablet</button>
            <button type="button" id="btnDeviceMobile" onclick="setDevice('mobile')" class="px-3 py-1 rounded-lg text-slate-500 hover:text-slate-800 transition">Mobile</button>
        </div>
    </div>

    <!-- Container do Iframe / Site Vivo -->
    <div class="bg-slate-200/50 p-2 sm:p-4 flex justify-center items-start sm:items-center min-h-[480px] sm:min-h-[650px] transition-all">
        <div id="previewFrameContainer" class="w-full max-w-full h-[560px] sm:h-[700px] bg-white rounded-2xl shadow-xl overflow-hidden transition-all duration-300">
            <iframe id="previewIframe" src="<?= SITE_URL ?>/index.php" class="w-full h-full border-0"></iframe>
        </div>
    </div>
</div>

<script>
    function setDevice(mode) {
        const container = document.getElementById('previewFrameContainer');
        const btnDesktop = document.getElementById('btnDeviceDesktop');
        const btnTablet = document.getElementById('btnDeviceTablet');
        const btnMobile = document.getElementById('btnDeviceMobile');

        [btnDesktop, btnTablet, btnMobile].forEach(btn => {
            btn.className = 'px-3 py-1 rounded-lg text-slate-500 hover:text-slate-800 transition';
        });

        if (mode === 'desktop') {
            container.style.width = '100%';
            btnDesktop.className = 'px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition';
        } else if (mode === 'tablet') {
            container.style.width = '768px';
            btnTablet.className = 'px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition';
        } else if (mode === 'mobile') {
            container.style.width = '375px';
            btnMobile.className = 'px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition';
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

