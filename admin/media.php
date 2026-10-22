<?php
/**
 * Biblioteca de Mídia — Tunísia CMS
 * Fiel à página 6 do design
 */
$pageTitle = 'Biblioteca de mídia';
$activePage = 'media';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$pdo = getPDO();
$successMsg = '';
$errorMsg = '';

// Processamento de Upload Real (Com suporte completo a vídeos e imagens)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media_file'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $errorMsg = 'Token de sessão inválido.';
    } else {
        $file = $_FILES['media_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'video/mp4', 'video/webm', 'video/ogg'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $fileMime = $finfo->file($file['tmp_name']) ?: '';
            if (!is_uploaded_file($file['tmp_name'])) { $fileMime = ''; }
            $fileSize = $file['size'];

            // Limite de 50MB para vídeos / arquivos pesados
            if ($fileSize > 50 * 1024 * 1024) {
                $errorMsg = 'O arquivo excede o limite máximo permitido de 50MB.';
            } elseif (!in_array($fileMime, $allowedMimes)) {
                $errorMsg = 'Formato de arquivo inválido. Permitidos: JPG, PNG, WebP, GIF, PDF, MP4 e WEBM.';
            } else {
                $origName = mb_substr(basename((string)$file['name']), 0, 255);
                $extensions = [
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 
                    'image/gif' => 'gif', 'application/pdf' => 'pdf', 
                    'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogg'
                ];
                $cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName) ?: 'arquivo';
                $baseName = pathinfo($cleanName, PATHINFO_FILENAME);
                $cleanName = $baseName . '.' . ($extensions[$fileMime] ?? 'bin');
                $uniqueName = bin2hex(random_bytes(8)) . '_' . $cleanName;
                $targetPath = UPLOADS_DIR . '/' . $uniqueName;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    if (str_starts_with($fileMime, 'image/')) {
                        generateImageVariants($targetPath, $fileMime);
                    }
                    if ($pdo) {
                        $type = str_starts_with($fileMime, 'video/') ? 'video' : (str_contains($fileMime, 'pdf') ? 'document' : 'image');
                        $stmt = $pdo->prepare("INSERT INTO media (filename, original_name, filepath, file_type, mime_type, file_size, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $currentUser = getCurrentUser();
                        $stmt->execute([$cleanName, $origName, 'uploads/media/' . $uniqueName, $type, $fileMime, $fileSize, (int)($currentUser['id'] ?? 0) ?: null]);
                        logAudit('upload de mídia', $origName);
                    }
                    $successMsg = 'Arquivo enviado com sucesso!';
                } else {
                    $errorMsg = 'Falha ao salvar o arquivo no servidor.';
                }
            }
        } else {
            $errorMsg = 'Nenhum arquivo enviado ou erro no upload.';
        }
    }
}

// Exclusão segura: somente POST autenticado com token CSRF válido.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_media_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMsg = 'Sessão inválida ou expirada. Atualize a página e tente novamente.';
    } elseif (!$pdo) {
        $errorMsg = 'Banco de dados indisponível.';
    } else {
        $delId = (int)$_POST['delete_media_id'];
        $stmt = $pdo->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$delId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            $relative = ltrim((string)$item['filepath'], '/\\');
            $baseDir = realpath(__DIR__ . '/../uploads/media');
            $fullPath = realpath(__DIR__ . '/../' . $relative);
            // Nunca permite que um caminho manipulado remova arquivos fora da biblioteca.
            if ($fullPath && $baseDir && str_starts_with($fullPath, $baseDir . DIRECTORY_SEPARATOR) && is_file($fullPath)) {
                if (!@unlink($fullPath)) {
                    $errorMsg = 'Não foi possível remover o arquivo do servidor.';
                } else {
                    // Remove também as versões otimizadas geradas no upload.
                    $stem = preg_replace('/\.[A-Za-z0-9]+$/', '', $fullPath);
                    foreach ([$stem . '.webp', $stem . '-thumb.webp'] as $derived) {
                        if (is_file($derived) && str_starts_with(realpath($derived) ?: '', $baseDir . DIRECTORY_SEPARATOR)) {
                            @unlink($derived);
                        }
                    }
                }
            }
            if ($errorMsg === '') {
                $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$delId]);
                logAudit('excluiu mídia', (string)$item['filename']);
                $successMsg = 'Arquivo excluído com sucesso.';
            }
        }
    }
}

// Atualização de texto alternativo para acessibilidade e SEO.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_alt_text_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMsg = 'Sessão inválida ou expirada.';
    } elseif ($pdo) {
        $altId = (int)$_POST['save_alt_text_id'];
        $altText = trim((string)($_POST['alt_text'] ?? ''));
        $altText = mb_substr($altText, 0, 255);
        $stmtAlt = $pdo->prepare('UPDATE media SET alt_text = ? WHERE id = ?');
        $stmtAlt->execute([$altText !== '' ? $altText : null, $altId]);
        logAudit('atualizou texto alternativo de mídia', 'Mídia #' . $altId);
        $successMsg = 'Texto alternativo atualizado.';
    }
}

// Lista de arquivos (com filtro opcional ?q= vindo da busca global)
$mediaQuery = trim((string)($_GET['q'] ?? ''));
$mediaList = [];
if ($pdo) {
    try {
        if ($mediaQuery !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mediaQuery) . '%';
            $stmtM = $pdo->prepare("SELECT * FROM media WHERE filename LIKE ? OR original_name LIKE ? ORDER BY created_at DESC");
            $stmtM->execute([$like, $like]);
            $mediaList = $stmtM->fetchAll();
        } else {
            $mediaList = $pdo->query("SELECT * FROM media ORDER BY created_at DESC")->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Media fetch error: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<!-- Header da Página -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Biblioteca de mídia</h1>
        <p class="text-sm text-slate-500 mt-0.5">Gerencie imagens, vídeos e documentos do site</p>
    </div>

    <div>
        <label for="fileUploadInput" class="cursor-pointer inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl shadow-md shadow-indigo-100 transition">
            <i data-lucide="upload-cloud" class="w-4 h-4"></i>
            <span>Enviar arquivos</span>
        </label>
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

<!-- Área Drag & Drop de Upload (Aceita vídeos MP4/WebM e imagens) -->
<form id="uploadForm" method="POST" enctype="multipart/form-data" class="mb-8">
    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
    <input type="file" id="fileUploadInput" name="media_file" class="hidden" accept="image/*,.pdf,video/mp4,video/webm,video/ogg" onchange="document.getElementById('uploadForm').submit()">

    <div onclick="document.getElementById('fileUploadInput').click()" class="border-2 border-dashed border-slate-200 hover:border-indigo-400 bg-white hover:bg-indigo-50/20 rounded-3xl p-6 sm:p-8 text-center cursor-pointer transition flex flex-col items-center justify-center group">
        <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3 group-hover:scale-110 transition">
            <i data-lucide="send" class="w-6 h-6"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-800">Arraste arquivos para enviar</h3>
        <p class="text-xs text-slate-400 mt-1">ou clique em "Enviar arquivos" • Imagens, PDFs e Vídeos (MP4/WebM) até 50 MB</p>
    </div>
</form>

<!-- Barra de Resumo e Armazenamento -->
<div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm mb-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-slate-500 font-medium">
        <span class="text-slate-800 font-bold"><?= count($mediaList) ?> arquivos</span>
    </div>

    <div class="flex items-center gap-3 w-full sm:w-64">
        <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
            <div class="bg-indigo-600 h-full w-[28%] rounded-full"></div>
        </div>
        <span class="text-xs text-slate-500 font-medium shrink-0">Uso atualizado</span>
    </div>
</div>

<?php if ($mediaQuery !== ''): ?>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/60 px-4 py-3 text-xs text-indigo-900">
        <span><?= count($mediaList) ?> <?= count($mediaList) === 1 ? 'arquivo encontrado' : 'arquivos encontrados' ?> para <strong>“<?= e($mediaQuery) ?>”</strong></span>
        <a href="<?= ADMIN_URL ?>/media.php" class="font-semibold text-indigo-700 hover:underline">Limpar filtro</a>
    </div>
    <?php if (empty($mediaList)): ?>
        <div class="bg-white rounded-3xl border border-slate-200 p-10 text-center mb-6">
            <p class="text-sm font-bold text-slate-800">Nenhum arquivo com esse nome</p>
            <p class="text-xs text-slate-500 mt-1">Tente outra palavra ou envie um novo arquivo.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Grid de Miniaturas de Mídia -->
<div class="grid grid-cols-1 min-[480px]:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6">
    <?php foreach ($mediaList as $m): ?>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md transition flex flex-col justify-between group">
            <!-- Renderização da Miniatura (Imagem ou Vídeo) -->
            <div class="h-44 bg-slate-900 overflow-hidden relative flex items-center justify-center">
                <?php
                    $path = SITE_URL . '/' . $m['filepath'];
                    $fileType = $m['file_type'] ?? 'image';
                ?>
                <?php if ($fileType === 'image'): ?>
                    <?php
                        $thumbRel = preg_replace('/\.[A-Za-z0-9]+$/', '-thumb.webp', (string)$m['filepath']);
                        if (is_file(__DIR__ . '/../' . $thumbRel)) { $path = SITE_URL . '/' . $thumbRel; }
                    ?>
                    <img src="<?= e($path) ?>" loading="lazy" decoding="async" alt="<?= e($m['alt_text'] ?? $m['filename']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                <?php elseif ($fileType === 'video' || str_starts_with($m['mime_type'] ?? '', 'video/')): ?>
                    <video src="<?= e(SITE_URL . '/' . $m['filepath']) ?>" class="w-full h-full object-cover opacity-80" muted preload="metadata"></video>
                    <div class="absolute inset-0 bg-black/30 flex items-center justify-center pointer-events-none">
                        <i data-lucide="play-circle" class="w-10 h-10 text-white"></i>
                    </div>
                <?php else: ?>
                    <div class="text-center p-4 text-slate-400">
                        <i data-lucide="file-text" class="w-10 h-10 mx-auto mb-2"></i>
                        <span class="text-xs uppercase"><?= e($fileType) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Dados do Arquivo -->
            <div class="p-4">
                <p class="font-semibold text-slate-800 text-xs truncate mb-1" title="<?= e($m['filename']) ?>">
                    <?= e($m['filename']) ?>
                </p>
                <p class="text-[11px] text-slate-400 mb-4">
                    <?= e(strtoupper((string)($m['mime_type'] ?? $fileType))) ?> · <?= number_format(($m['file_size'] ?? 0) / (1024 * 1024), 1) ?> MB
                </p>

                <form method="post" action="<?= ADMIN_URL ?>/media.php" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="save_alt_text_id" value="<?= (int)$m['id'] ?>">
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1" for="alt-<?= (int)$m['id'] ?>">Texto alternativo</label>
                    <input id="alt-<?= (int)$m['id'] ?>" name="alt_text" maxlength="255" value="<?= e($m['alt_text'] ?? '') ?>" placeholder="Descreva o arquivo" class="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg">
                    <button type="submit" class="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-800">Salvar descrição</button>
                </form>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="navigator.clipboard.writeText('<?= e($m['filepath']) ?>'); alert('Caminho copiado para a área de transferência: <?= e($m['filepath']) ?>');" class="flex-1 py-2 px-3 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-semibold rounded-xl flex items-center justify-center gap-1.5 transition" title="Copiar caminho para usar no editor">
                        <i data-lucide="copy" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Copiar Caminho</span>
                    </button>
                    <form method="post" action="<?= ADMIN_URL ?>/media.php" onsubmit="return confirm('Tem certeza que deseja excluir esta mídia permanentemente?')">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="delete_media_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="p-2 border border-slate-200 hover:border-red-200 hover:bg-red-50 text-slate-400 hover:text-red-600 rounded-xl transition" title="Excluir" aria-label="Excluir mídia">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>