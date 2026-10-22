<?php
/**
 * Tela de Login e Criação de Conta — Tunísia CMS
 * Fiel ao design das telas de autenticação
 */
require_once __DIR__ . '/../includes/functions.php';

$pdo = getPDO();
$activeTab = $_GET['tab'] ?? 'login'; // 'login' ou 'register'
if (!in_array($activeTab, ['login', 'register'], true)) {
    $activeTab = 'login';
}

$error = '';
// Credenciais de teste pré-preenchidas SOMENTE em acesso local (localhost/127.0.0.1/::1)
// ou se LOGIN_PREFILL=1 no .env. Em produção (domínio real) o formulário vem vazio.
$hostName = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$loginPrefill = in_array($hostName, ['localhost', '127.0.0.1', '[::1]'], true) || envValue('LOGIN_PREFILL', '0') === '1';
$prefillEmail = $loginPrefill ? 'adm@adm.com' : '';
$prefillPassword = $loginPrefill ? 'Adm@123' : '';
$success = ($_GET['reset'] ?? '') === '1' ? 'Senha redefinida com sucesso. Entre com a nova senha.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCsrfToken($csrf)) {
        $error = 'Sessão expirada. Por favor, tente novamente.';
        $activeTab = in_array($action, ['login', 'register'], true) ? $action : 'login';
    } elseif ($action === 'login') {
        $activeTab = 'login';
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIp = getClientIp();

        if (empty($email) || empty($password)) {
            $error = 'Preencha todos os campos.';
        } elseif (!$pdo) {
            // Nunca autentica com credenciais fixas quando o banco está indisponível.
            $error = 'Banco de dados não conectado. Ative o MySQL e tente novamente.';
        } elseif (($waitMinutes = loginLockoutMinutes($pdo, $email, $clientIp)) > 0) {
            $error = 'Muitas tentativas de acesso. Aguarde ' . $waitMinutes . ' minuto' . ($waitMinutes === 1 ? '' : 's') . ' e tente novamente.';
            logAudit('login bloqueado', 'Limite de tentativas atingido para ' . mb_substr($email, 0, 150));
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Executa sempre um password_verify para não revelar, pelo tempo de resposta, se o e-mail existe.
            $hashToCheck = $user['password'] ?? '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
            $passwordOk = password_verify($password, $hashToCheck) && $user;

            if ($passwordOk) {
                if (($user['status'] ?? 'active') !== 'active') {
                    $error = 'Sua conta ainda está pendente de aprovação ou inativa pela administração.';
                } else {
                    clearLoginFailures($pdo, $email, $clientIp);
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id' => (int)$user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'avatar' => $user['avatar'] ?? null
                    ];
                    // Remove cookie legado que guardava o e-mail em texto puro.
                    if (isset($_COOKIE['cms_remember_email'])) {
                        setcookie('cms_remember_email', '', time() - 3600, '/');
                    }
                    logAudit('login', 'Usuário realizou login com sucesso', (int)$user['id']);
                    header('Location: ' . ADMIN_URL . '/dashboard.php');
                    exit;
                }
            } else {
                recordLoginFailure($pdo, $email, $clientIp);
                $error = 'E-mail ou senha incorretos.';
            }
        }
    } elseif ($action === 'register') {
        $activeTab = 'register';
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $terms = !empty($_POST['terms']);

        if (empty($name) || empty($email) || empty($password) || empty($confirmPassword)) {
            $error = 'Por favor, preencha todos os campos do formulário.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Por favor, insira um endereço de e-mail válido.';
        } elseif (mb_strlen($password) < 12) {
            $error = 'A senha deve conter no mínimo 12 caracteres.';
        } elseif ($password !== $confirmPassword) {
            $error = 'As senhas informadas não coincidem.';
        } elseif (!$terms) {
            $error = 'Você precisa concordar com os Termos de Uso e Política de Privacidade.';
        } else {
            if ($pdo) {
                // Verifica duplicidade de email
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmtCheck->execute([$email]);
                if ($stmtCheck->fetch()) {
                    $error = 'Este e-mail já está cadastrado. Tente entrar ou recuperar sua senha.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmtInsert = $pdo->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (?, ?, ?, 'editor', 'inactive', NOW())");
                    $stmtInsert->execute([$name, $email, $hash]);
                    $newUserId = (int)$pdo->lastInsertId();

                    logAudit('cadastro', "Novo usuário cadastrado: {$name} ({$email})", $newUserId);

                    $success = 'Cadastro recebido. Um administrador precisa ativar sua conta antes do primeiro acesso.';
                    $activeTab = 'login';
                }
            } else {
                $error = 'Não foi possível conectar ao banco de dados para criar sua conta.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $activeTab === 'register' ? 'Criar Conta' : 'Entrar' ?> — Tunísia CMS</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/app.css?v=<?= assetVersion('assets/css/app.css') ?>">
    <script src="<?= SITE_URL ?>/admin/assets/js/lucide.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }
        .hero-bg {
            background-image: url('<?= SITE_URL ?>/assets/img/auth-bg.png');
            background-size: cover;
            background-position: center;
        }
        .auth-card-tabs {
            background: #e9ecef;
            border-radius: 9999px;
            padding: 5px;
        }
        .auth-tab-btn {
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        .auth-tab-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .auth-tab-btn:not(.active) {
            color: #64748b;
        }
        .auth-tab-btn:not(.active):hover {
            color: #0f172a;
        }
        .auth-input-wrapper {
            position: relative;
        }
        .auth-input {
            width: 100%;
            padding: 0.78rem 2.8rem 0.78rem 2.9rem;
            font-size: 0.875rem;
            color: #1e293b;
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            transition: all 0.15s ease;
        }
        .auth-input:focus {
            outline: none;
            border-color: #0f172a;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.05);
        }
        .auth-input::placeholder {
            color: #94a3b8;
            font-size: 0.84rem;
        }
        .auth-icon-left {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        .auth-icon-right {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-icon-right:hover {
            color: #334155;
        }
        .auth-submit-btn {
            width: 100%;
            padding: 0.82rem 1.25rem;
            background-color: #ffffff;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 600;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .auth-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        .auth-hero-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            width: 48%;
            min-height: 100vh;
            padding: 3.5rem 4rem;
            color: #ffffff;
            background-color: #0f172a;
            background-image: linear-gradient(to top, rgba(0, 0, 0, 0.85) 0%, rgba(0, 0, 0, 0.4) 50%, rgba(0, 0, 0, 0.25) 100%), url('<?= SITE_URL ?>/assets/img/auth-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            flex-shrink: 0;
        }
        .auth-form-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2.5rem 2rem;
            background-color: #fafafa;
            min-height: 100vh;
        }
        @media (max-width: 900px) {
            .auth-container {
                flex-direction: column;
            }
            .auth-hero-panel {
                width: 100%;
                min-height: 280px;
                padding: 2.5rem 1.5rem;
            }
        }
    </style>
</head>
<body class="h-full bg-white text-slate-800 antialiased">

<div class="auth-container">
    
    <!-- Painel Esquerdo Hero Image (Fiel às fotos do mockup) -->
    <div class="auth-hero-panel">
        <div></div> <!-- Espaçador topo -->

        <!-- Conteúdo do Topo / Meio -->
        <div class="my-auto" style="max-width: 480px;">
            <span style="color: #fde68a; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; display: inline-block; margin-bottom: 1rem;">
                Painel de conteúdo
            </span>
            <h1 style="font-size: clamp(2rem, 3.2vw, 3rem); font-weight: 800; line-height: 1.15; margin-bottom: 1.25rem; color: #ffffff; letter-spacing: -0.025em;">
                Histórias que conectam dois territórios.
            </h1>
            <p style="color: #e2e8f0; font-size: 0.95rem; line-height: 1.6; font-weight: 400; max-width: 420px;">
                Gerencie cada detalhe da experiência digital do Projeto Tunísia.
            </p>
        </div>

        <!-- Rodapé do lado esquerdo -->
        <div style="padding-top: 2rem;">
            <span style="font-size: 0.75rem; font-weight: 600; color: #cbd5e1; letter-spacing: 0.05em;">
                2026 projeto Tunísia
            </span>
        </div>
    </div>

    <!-- Painel Direito Formulário (Entrar / Criar conta) -->
    <div class="auth-form-panel">
        <div class="w-full max-w-[420px]">

            <!-- Alternador de Abas (Entrar / Criar conta) -->
            <div class="flex justify-center mb-8">
                <div class="auth-card-tabs flex items-center p-1 bg-slate-200/80 rounded-2xl w-full max-w-[280px]">
                    <button type="button" id="tabBtnLogin" onclick="switchTab('login')" class="auth-tab-btn flex-1 py-2 text-center <?= $activeTab === 'login' ? 'active' : '' ?>">
                        Entrar
                    </button>
                    <button type="button" id="tabBtnRegister" onclick="switchTab('register')" class="auth-tab-btn flex-1 py-2 text-center <?= $activeTab === 'register' ? 'active' : '' ?>">
                        Criar conta
                    </button>
                </div>
            </div>

            <!-- Alertas de Erro ou Sucesso -->
            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs sm:text-sm flex items-start gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5 shrink-0 text-red-500 mt-0.5"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs sm:text-sm flex items-start gap-3">
                    <i data-lucide="check-circle" class="w-5 h-5 shrink-0 text-emerald-600 mt-0.5"></i>
                    <div><?= e($success) ?></div>
                </div>
            <?php endif; ?>

            <!-- ============================================== -->
            <!-- ABA 1: ENTRAR (BEM-VINDO DE VOLTA)            -->
            <!-- ============================================== -->
            <div id="sectionLogin" class="<?= $activeTab === 'login' ? 'block' : 'hidden' ?>">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Bem-vindo de volta</h2>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">Entre com suas credenciais para acessar o painel.</p>
                </div>

                <form method="POST" action="index.php?tab=login" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="login">

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5">E-mail</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="mail" class="w-4 h-4 auth-icon-left"></i>
                            <input type="email" name="email" value="<?= e($_POST['email'] ?? $prefillEmail) ?>" required autocomplete="username" class="auth-input" placeholder="seu@email.com">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5">Senha</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="lock" class="w-4 h-4 auth-icon-left"></i>
                            <input type="password" id="loginPassword" name="password" value="<?= e($prefillPassword) ?>" required autocomplete="current-password" class="auth-input" placeholder="Digite sua senha">
                            <button type="button" class="auth-icon-right" onclick="togglePasswordVisibility('loginPassword', this)" title="Exibir/Ocultar senha">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1 pb-2">
                        <span></span>
                        <a href="forgot_password.php" class="text-xs text-slate-500 hover:text-slate-800 transition">
                            Esqueci minha senha
                        </a>
                    </div>

                    <button type="submit" class="auth-submit-btn">
                        Entrar no painel
                    </button>

                    <p class="text-[11px] text-center text-slate-400 mt-4">
                        Acesso restrito a administradores autorizados.
                    </p>
                </form>
            </div>

            <!-- ============================================== -->
            <!-- ABA 2: CRIAR CONTA (CRIE SUA CONTA)           -->
            <!-- ============================================== -->
            <div id="sectionRegister" class="<?= $activeTab === 'register' ? 'block' : 'hidden' ?>">
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Crie sua conta</h2>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">Preencha seus dados para solicitar acesso ao CMS.</p>
                </div>

                <form method="POST" action="index.php?tab=register" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="register">

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nome completo</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="user" class="w-4 h-4 auth-icon-left"></i>
                            <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required class="auth-input" placeholder="Como devemos chamar você">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5">E-mail</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="mail" class="w-4 h-4 auth-icon-left"></i>
                            <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required class="auth-input" placeholder="seu@email.com">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5">Senha</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="lock" class="w-4 h-4 auth-icon-left"></i>
                            <input type="password" id="regPassword" name="password" required minlength="8" class="auth-input" placeholder="Mínimo de 8 caracteres">
                            <button type="button" class="auth-icon-right" onclick="togglePasswordVisibility('regPassword', this)" title="Exibir/Ocultar senha">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1.5">Confirmar sua senha</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="lock" class="w-4 h-4 auth-icon-left"></i>
                            <input type="password" id="regConfirmPassword" name="confirm_password" required minlength="8" class="auth-input" placeholder="Repita sua senha">
                            <button type="button" class="auth-icon-right" onclick="togglePasswordVisibility('regConfirmPassword', this)" title="Exibir/Ocultar senha">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pt-1 pb-2">
                        <label class="flex items-start gap-2.5 cursor-pointer select-none">
                            <input type="checkbox" name="terms" value="1" required class="w-4 h-4 mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-0">
                            <span class="text-xs text-slate-500 leading-normal">
                                Li e concordo com os Termos de Uso e a Política de Privacidade
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="auth-submit-btn">
                        Criar minha conta
                    </button>

                    <p class="text-[11px] text-center text-slate-400 mt-4">
                        Seu acesso poderá passar por aprovação da administração.
                    </p>
                </form>
            </div>

            <!-- Link para voltar ao site público -->
            <div class="mt-8 text-center border-t border-slate-200/60 pt-5">
                <a href="<?= SITE_URL ?>/index.php" class="text-xs text-slate-500 hover:text-slate-800 transition inline-flex items-center gap-1.5">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Voltar ao site público
                </a>
            </div>

        </div>
    </div>

</div>

<script>
    function switchTab(tab) {
        const secLogin = document.getElementById('sectionLogin');
        const secRegister = document.getElementById('sectionRegister');
        const btnLogin = document.getElementById('tabBtnLogin');
        const btnRegister = document.getElementById('tabBtnRegister');

        if (tab === 'register') {
            secLogin.classList.add('hidden');
            secLogin.classList.remove('block');
            secRegister.classList.remove('hidden');
            secRegister.classList.add('block');

            btnLogin.classList.remove('active');
            btnRegister.classList.add('active');
            window.history.replaceState(null, '', '?tab=register');
        } else {
            secRegister.classList.add('hidden');
            secRegister.classList.remove('block');
            secLogin.classList.remove('hidden');
            secLogin.classList.add('block');

            btnRegister.classList.remove('active');
            btnLogin.classList.add('active');
            window.history.replaceState(null, '', '?tab=login');
        }
    }

    function togglePasswordVisibility(inputId, button) {
        const input = document.getElementById(inputId);
        if (!input) return;

        if (input.type === 'password') {
            input.type = 'text';
            button.innerHTML = '<i data-lucide="eye-off" class="w-4 h-4"></i>';
        } else {
            input.type = 'password';
            button.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';
        }
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
</body>
</html>
