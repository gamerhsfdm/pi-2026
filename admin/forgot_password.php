<?php
/**
 * Solicitação de redefinição de senha.
 * - Resposta SEMPRE genérica (não revela se o e-mail existe).
 * - Limites: 3 pedidos/hora por conta e 5/hora por IP.
 * - O e-mail é enviado após a resposta (não vaza informação pelo tempo de resposta).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/auth_layout.php';

if (isLoggedIn()) { header('Location: ' . ADMIN_URL . '/dashboard.php'); exit; }

$pdo = getPDO();
$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $ip = getClientIp();
        $done = true; // a resposta é a mesma em qualquer caso

        if ($pdo && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                ensureSchema($pdo);
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                $byIp = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
                $byIp->execute([$ip]);
                $ipOk = (int)$byIp->fetchColumn() < 5;

                if ($user && $ipOk) {
                    $byUser = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
                    $byUser->execute([(int)$user['id']]);
                    if ((int)$byUser->fetchColumn() < 3) {
                        $token = bin2hex(random_bytes(32));
                        // Só o hash fica no banco: um vazamento da tabela não permite redefinir senhas.
                        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, created_at) VALUES (?, ?, (NOW() + INTERVAL 1 HOUR), ?, NOW())")
                            ->execute([(int)$user['id'], hash('sha256', $token), $ip]);

                        $link = siteBaseUrl() . '/admin/reset_password.php?token=' . $token;
                        $body = "Olá, " . $user['name'] . "!\n\n"
                              . "Recebemos um pedido para redefinir a senha do painel do Projeto Tunísia.\n"
                              . "Use o link abaixo (válido por 1 hora e de uso único):\n\n{$link}\n\n"
                              . "Se você não fez esse pedido, ignore este e-mail: sua senha continua a mesma.\n";
                        queueAppMail($user['email'], 'Redefinição de senha — Projeto Tunísia', $body);
                        logAudit('solicitou redefinição de senha', 'Pedido de redefinição para ' . $user['email'], (int)$user['id']);
                    }
                }
            } catch (Throwable $e) {
                error_log('Erro na recuperação de senha: ' . $e->getMessage());
            }
        }
    }
}

authPageStart('Recuperar senha', 'Recuperar senha', 'Informe o e-mail da sua conta e enviaremos um link para criar uma nova senha.');
if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif;
if ($done): ?>
    <div class="msg ok">Se o e-mail estiver cadastrado e ativo, você receberá em instantes um link para redefinir a senha. Confira também a caixa de spam.</div>
<?php else: ?>
    <form method="POST" action="forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autocomplete="username" placeholder="seu@email.com">
        <button type="submit">Enviar link de redefinição</button>
    </form>
<?php endif;
authPageEnd();
