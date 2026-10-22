<?php
/**
 * Meu Perfil — Tunísia CMS
 * Alteração de foto de perfil (upload com pré-visualização), nome, e-mail e senha.
 * Atualiza dados em sessão e sincroniza avatar em todo o painel.
 */
$pageTitle = 'Meu Perfil';
$activePage = 'settings';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$pdo = getPDO();
$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 1);

$successMsg = '';
$errorMsg = '';

// Recarrega dados mais atualizados do banco
if ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dbUser) {
        $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], [
            'id' => (int)$dbUser['id'],
            'name' => $dbUser['name'],
            'email' => $dbUser['email'],
            'role' => $dbUser['role'],
            'avatar' => $dbUser['avatar']
        ]);
        $currentUser = $_SESSION['user'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $errorMsg = 'Sessão inválida ou expirada.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $avatarPath = $currentUser['avatar'] ?? null;

        if (empty($name) || empty($email)) {
            $errorMsg = 'Nome e e-mail são obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Endereço de e-mail inválido.';
        } else {
            // Verifica duplicidade de e-mail
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmtCheck->execute([$email, $userId]);
            if ($stmtCheck->fetch()) {
                $errorMsg = 'Este e-mail já está em uso por outro membro.';
            } else {
                // Upload ou Recorte de Foto de Perfil
                $croppedData = trim($_POST['avatar_cropped_data'] ?? '');
                if ($croppedData !== '' && preg_match('/^data:image\/(png|jpeg|webp);base64,/', $croppedData, $matches)) {
                    $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $croppedData);
                    $decoded = base64_decode($base64);
                    if ($decoded !== false && strlen($decoded) <= 3 * 1024 * 1024) {
                        $avatarName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                        if (!is_dir(UPLOADS_DIR)) {
                            @mkdir(UPLOADS_DIR, 0777, true);
                        }
                        $dest = UPLOADS_DIR . '/' . $avatarName;
                        if (file_put_contents($dest, $decoded)) {
                            $avatarPath = 'uploads/media/' . $avatarName;
                        }
                    }
                } elseif (!empty($_FILES['avatar_file']['name']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                    $mime = mime_content_type($_FILES['avatar_file']['tmp_name']);
                    $fileSize = $_FILES['avatar_file']['size'];

                    if (!in_array($mime, $allowedMimes)) {
                        $errorMsg = 'Formato de imagem inválido. Use JPG, PNG ou WebP.';
                    } elseif ($fileSize > 2 * 1024 * 1024) {
                        $errorMsg = 'A foto deve ter no máximo 2MB.';
                    } else {
                        $ext = pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION);
                        $avatarName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                        if (!is_dir(UPLOADS_DIR)) {
                            @mkdir(UPLOADS_DIR, 0777, true);
                        }
                        $dest = UPLOADS_DIR . '/' . $avatarName;
                        if (move_uploaded_file($_FILES['avatar_file']['tmp_name'], $dest)) {
                            $avatarPath = 'uploads/media/' . $avatarName;
                        }
                    }
                }

                if (empty($errorMsg)) {
                    // Troca de Senha Opcional
                    $updatePassword = false;
                    $newHash = null;

                    if (!empty($newPassword)) {
                        if (empty($currentPassword)) {
                            $errorMsg = 'Digite sua senha atual para definir uma nova senha.';
                        } elseif (mb_strlen($newPassword) < 8) {
                            $errorMsg = 'A nova senha deve ter no mínimo 8 caracteres.';
                        } elseif ($newPassword !== $confirmPassword) {
                            $errorMsg = 'A confirmação da nova senha não confere.';
                        } else {
                            // Valida senha atual
                            $stmtP = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                            $stmtP->execute([$userId]);
                            $currHash = $stmtP->fetchColumn();
                            if (!$currHash || !password_verify($currentPassword, $currHash)) {
                                $errorMsg = 'Senha atual incorreta.';
                            } else {
                                $updatePassword = true;
                                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                            }
                        }
                    }

                    if (empty($errorMsg)) {
                        if ($updatePassword) {
                            $stmtUp = $pdo->prepare("UPDATE users SET name = ?, email = ?, avatar = ?, password = ?, updated_at = NOW() WHERE id = ?");
                            $stmtUp->execute([$name, $email, $avatarPath, $newHash, $userId]);
                        } else {
                            $stmtUp = $pdo->prepare("UPDATE users SET name = ?, email = ?, avatar = ?, updated_at = NOW() WHERE id = ?");
                            $stmtUp->execute([$name, $email, $avatarPath, $userId]);
                        }

                        // Atualiza sessão
                        $_SESSION['user']['name'] = $name;
                        $_SESSION['user']['email'] = $email;
                        $_SESSION['user']['avatar'] = $avatarPath;
                        $currentUser = $_SESSION['user'];

                        logAudit('atualizou perfil', "{$name} atualizou suas informações pessoais", $userId);
                        $successMsg = 'Perfil atualizado com sucesso!';
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<!-- Header da Página -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Meu Perfil</h1>
        <p class="text-sm text-slate-500 mt-0.5">Gerencie seus dados de acesso, cargo e foto de exibição.</p>
    </div>

    <div>
        <a href="<?= ADMIN_URL ?>/settings.php" class="px-4 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition">
            Configurações do sistema
        </a>
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

<form method="POST" enctype="multipart/form-data" class="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm max-w-3xl space-y-8">
    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

    <!-- Seção: Foto de Perfil -->
    <div class="flex flex-col sm:flex-row items-center gap-6 pb-8 border-b border-slate-100">
        <div class="relative w-24 h-24 rounded-full bg-slate-100 border-2 border-slate-200 overflow-hidden shrink-0 shadow-sm group">
            <?php if (!empty($currentUser['avatar'])): ?>
                <img id="avatarPreviewImg" src="<?= SITE_URL ?>/<?= ltrim($currentUser['avatar'], '/') ?>" alt="Avatar" class="w-full h-full object-cover">
            <?php else: ?>
                <div id="avatarFallback" class="w-full h-full bg-amber-400 flex items-center justify-center font-bold text-slate-900 text-2xl">
                    <?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?>
                </div>
                <img id="avatarPreviewImg" src="" alt="Avatar" class="w-full h-full object-cover hidden">
            <?php endif; ?>
        </div>

        <div>
            <h3 class="text-sm font-bold text-slate-900 mb-1">Foto de perfil</h3>
            <p class="text-xs text-slate-400 mb-3">JPG, PNG ou WebP. Tamanho máximo de 2MB. Você pode ajustar e recortar antes de salvar.</p>
            <label class="inline-flex items-center gap-2 px-4 py-2 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 cursor-pointer shadow-sm transition">
                <i data-lucide="camera" class="w-3.5 h-3.5 text-slate-500"></i>
                <span>Escolher nova foto</span>
                <input type="file" id="avatarFileInput" name="avatar_file" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="handleAvatarSelect(event)">
            </label>
            <input type="hidden" name="avatar_cropped_data" id="avatarCroppedData" value="">
        </div>
    </div>

    <!-- Modal de Recorte Interativo de Avatar -->
    <div id="cropModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 hidden">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900">Ajustar e Recortar Foto</h3>
                <button type="button" onclick="closeCropModal()" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="flex flex-col items-center gap-3">
                <div class="relative w-64 h-64 bg-slate-900 rounded-2xl overflow-hidden flex items-center justify-center">
                    <canvas id="cropCanvas" width="256" height="256" class="cursor-grab active:cursor-grabbing"></canvas>
                    <!-- Guia circular -->
                    <div class="absolute inset-0 pointer-events-none rounded-full border-2 border-white/80 shadow-[0_0_0_9999px_rgba(15,23,42,0.5)]"></div>
                </div>
                <div class="w-full flex items-center gap-3 px-2">
                    <i data-lucide="zoom-out" class="w-4 h-4 text-slate-400"></i>
                    <input type="range" id="cropZoom" min="1" max="3" step="0.05" value="1" class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer">
                    <i data-lucide="zoom-in" class="w-4 h-4 text-slate-400"></i>
                </div>
                <p class="text-[11px] text-slate-400 text-center">Arraste a imagem para enquadrar e use o controle deslizante para ampliar.</p>
            </div>
            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeCropModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">Cancelar</button>
                <button type="button" onclick="applyCrop()" class="px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md shadow-indigo-100 transition">Confirmar recorte</button>
            </div>
        </div>
    </div>

    <!-- Informações Pessoais -->
    <div class="space-y-4">
        <h3 class="text-sm font-bold text-slate-900">Informações Pessoais</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nome Completo</label>
                <input type="text" name="name" value="<?= e($currentUser['name'] ?? '') ?>" required class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">E-mail</label>
                <input type="email" name="email" value="<?= e($currentUser['email'] ?? '') ?>" required class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Papel no Sistema</label>
            <input type="text" disabled value="<?= $currentUser['role'] === 'admin' ? 'Administrador do CMS' : 'Editor de Conteúdo' ?>" class="w-full px-4 py-2.5 text-xs bg-slate-100 border border-slate-200 rounded-xl text-slate-500 cursor-not-allowed">
            <p class="text-[11px] text-slate-400 mt-1">O papel só pode ser alterado por outro Administrador em Configurações.</p>
        </div>
    </div>

    <!-- Alteração de Senha -->
    <div class="space-y-4 pt-6 border-t border-slate-100">
        <h3 class="text-sm font-bold text-slate-900">Alterar Senha</h3>
        <p class="text-xs text-slate-400">Deixe em branco se não desejar alterar a senha atual.</p>

        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">Senha Atual</label>
            <input type="password" name="current_password" placeholder="Digite sua senha atual..." class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nova Senha</label>
                <input type="password" name="new_password" minlength="8" placeholder="Mínimo de 8 caracteres" class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Confirmar Nova Senha</label>
                <input type="password" name="confirm_password" minlength="8" placeholder="Repita a nova senha" class="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500">
            </div>
        </div>
    </div>

    <div class="pt-6 border-t border-slate-100 flex justify-end">
        <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs rounded-xl shadow-md shadow-indigo-100 transition">
            Salvar alterações do perfil
        </button>
    </div>
</form>

<script>
    let rawImg = new Image();
    let cropState = { x: 0, y: 0, zoom: 1, isDragging: false, startX: 0, startY: 0 };
    const canvas = document.getElementById('cropCanvas');
    const ctx = canvas.getContext('2d');
    const zoomInput = document.getElementById('cropZoom');

    function handleAvatarSelect(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            rawImg.onload = function() {
                cropState.zoom = 1;
                zoomInput.value = 1;
                cropState.x = (canvas.width - rawImg.width) / 2;
                cropState.y = (canvas.height - rawImg.height) / 2;
                // auto escala para caber no canvas
                const minScale = Math.max(canvas.width / rawImg.width, canvas.height / rawImg.height);
                cropState.baseScale = minScale;
                drawCanvas();
                document.getElementById('cropModal').classList.remove('hidden');
                if (window.lucide) lucide.createIcons();
            };
            rawImg.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    function drawCanvas() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const scale = (cropState.baseScale || 1) * cropState.zoom;
        const w = rawImg.width * scale;
        const h = rawImg.height * scale;
        ctx.drawImage(rawImg, cropState.x, cropState.y, w, h);
    }

    canvas.addEventListener('mousedown', (e) => {
        cropState.isDragging = true;
        cropState.startX = e.clientX - cropState.x;
        cropState.startY = e.clientY - cropState.y;
    });

    window.addEventListener('mousemove', (e) => {
        if (!cropState.isDragging) return;
        cropState.x = e.clientX - cropState.startX;
        cropState.y = e.clientY - cropState.startY;
        drawCanvas();
    });

    window.addEventListener('mouseup', () => { cropState.isDragging = false; });

    zoomInput.addEventListener('input', (e) => {
        const oldZoom = cropState.zoom;
        cropState.zoom = parseFloat(e.target.value);
        // Ajusta centro ao dar zoom
        const scaleDiff = cropState.zoom / oldZoom;
        const cx = canvas.width / 2;
        const cy = canvas.height / 2;
        cropState.x = cx - (cx - cropState.x) * scaleDiff;
        cropState.y = cy - (cy - cropState.y) * scaleDiff;
        drawCanvas();
    });

    function closeCropModal() {
        document.getElementById('cropModal').classList.add('hidden');
    }

    function applyCrop() {
        // Gera recorte em alta qualidade (320x320)
        const outputCanvas = document.createElement('canvas');
        outputCanvas.width = 320;
        outputCanvas.height = 320;
        const oCtx = outputCanvas.getContext('2d');
        const scaleRatio = 320 / canvas.width;

        const scale = (cropState.baseScale || 1) * cropState.zoom * scaleRatio;
        const w = rawImg.width * scale;
        const h = rawImg.height * scale;
        const ox = cropState.x * scaleRatio;
        const oy = cropState.y * scaleRatio;

        oCtx.drawImage(rawImg, ox, oy, w, h);
        const dataUrl = outputCanvas.toDataURL('image/jpeg', 0.9);

        document.getElementById('avatarCroppedData').value = dataUrl;
        const previewImg = document.getElementById('avatarPreviewImg');
        const fallback = document.getElementById('avatarFallback');
        previewImg.src = dataUrl;
        previewImg.classList.remove('hidden');
        if (fallback) fallback.classList.add('hidden');

        closeCropModal();
    }
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

