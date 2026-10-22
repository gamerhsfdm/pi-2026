<?php
/** Definição de nova senha a partir do link enviado por e-mail (token de uso único, 1 hora). */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/auth_layout.php';

if (isLoggedIn()) { header('Location: ' . ADMIN_URL . '/dashboard.php'); exit; }

$pdo = getPDO();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';
$reset = null;

if ($pdo && preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        ensureSchema($pdo);
        $stmt = $pdo->prepare("SELECT pr.id, pr.user_id, u.email FROM password_resets pr
                               JOIN users u ON u.id = pr.user_id
                               WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = 'active' LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
        $reset = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('Erro ao validar token de redefinição: ' . $e->getMessage());
    }
}

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    } elseif (mb_strlen($password) < 12) {
        $error = 'A senha deve ter pelo menos 12 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'As senhas não conferem.';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
            // Invalida este e qualquer outro link pendente da conta.
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                ->execute([(int)$reset['user_id']]);
            $pdo->commit();
            clearLoginFailures($pdo, (string)$reset['email'], getClientIp());
            logAudit('redefiniu a senha', 'Senha redefinida por link de e-mail', (int)$reset['user_id']);
            header('Location: ' . ADMIN_URL . '/index.php?reset=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Erro ao redefinir senha: ' . $e->getMessage());
            $error = 'Não foi possível redefinir a senha agora. Tente novamente.';
        }
    }
}

if (!$reset) {
    authPageStart('Link inválido', 'Link inválido ou expirado', 'Este link já foi usado ou passou de 1 hora. Solicite um novo.');
    ?><a class="back" href="forgot_password.php" style="margin-top:0">Solicitar novo link</a><?php
    authPageEnd();
    exit;
}

authPageStart('Nova senha', 'Criar nova senha', 'Escolha uma senha forte, com pelo menos 12 caracteres.');
if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
    <form method="POST" action="reset_password.php">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label for="password">Nova senha</label>
        <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password">
        <label for="password_confirm">Repita a nova senha</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="12" autocomplete="new-password">
        <button type="submit">Salvar nova senha</button>
    </form>
<?php authPageEnd();
