<?php
/**
 * Logout — exige POST com token CSRF (evita deslogar o usuário por link/imagem de terceiros).
 * Uma requisição GET apenas mostra uma tela de confirmação, sem alterar o estado.
 */
require_once __DIR__ . '/../includes/functions.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    logAudit('logout', 'Usuário efetuou saída do sistema');

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    header('Location: ' . ADMIN_URL . '/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sair da conta</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f1f5f9; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        form { background: #fff; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 30px rgba(15,23,42,.08); text-align: center; max-width: 22rem; }
        h1 { font-size: 1.1rem; margin: 0 0 1rem; color: #0f172a; }
        button, a { display: inline-block; padding: .6rem 1.2rem; border-radius: .6rem; font-size: .9rem; text-decoration: none; cursor: pointer; border: 0; }
        button { background: #0f172a; color: #fff; }
        a { color: #475569; }
    </style>
</head>
<body>
    <form method="POST" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <h1>Deseja sair do painel?</h1>
        <button type="submit">Sair da conta</button>
        <a href="<?= e(ADMIN_URL) ?>/dashboard.php">Cancelar</a>
    </form>
</body>
</html>
