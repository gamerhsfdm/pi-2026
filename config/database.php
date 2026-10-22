<?php
/**
 * Configuração de Conexão PDO, ambiente e segurança de sessão
 * Projeto Tunísia - CMS
 */

/**
 * Carrega variáveis de um arquivo .env (formato CHAVE=valor) sem sobrescrever
 * variáveis que já existam no ambiente do servidor.
 */
function loadEnvFile(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            continue;
        }
        // Remove aspas simples/duplas ao redor do valor.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false && !isset($_ENV[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function envValue(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? null;
    }
    return ($value === false || $value === null) ? $default : (string)$value;
}

loadEnvFile(__DIR__ . '/../.env');

// Ambiente: por padrão "production" (seguro). Use APP_ENV=development no XAMPP.
define('APP_ENV', envValue('APP_ENV', 'production'));
define('APP_DEBUG', in_array(strtolower((string)envValue('APP_DEBUG', 'false')), ['1', 'true', 'on', 'yes'], true));

// Erros nunca vão para a tela em produção; sempre são registrados em log.
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Timezone padrão do Brasil
date_default_timezone_set('America/Sao_Paulo');

// Configurações do Banco de Dados
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_PORT', envValue('DB_PORT', '3306'));
define('DB_NAME', envValue('DB_NAME', 'projeto_tunisia'));
define('DB_USER', envValue('DB_USER', 'root'));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Configurações da Aplicação
define('SITE_URL', rtrim((string)envValue('SITE_URL', '/ProjetoIntegrador-2026'), '/'));
define('ADMIN_URL', SITE_URL . '/admin');
define('UPLOADS_URL', SITE_URL . '/uploads/media');
define('UPLOADS_DIR', __DIR__ . '/../uploads/media');
define('CACHE_DIR', __DIR__ . '/../cache');

// Segurança de login (tentativas por janela de tempo)
define('LOGIN_MAX_ATTEMPTS', max(3, (int)envValue('LOGIN_MAX_ATTEMPTS', '5')));
define('LOGIN_WINDOW_MINUTES', max(1, (int)envValue('LOGIN_WINDOW_MINUTES', '15')));

/** Indica se a requisição atual chegou por HTTPS (ou por proxy confiável). */
function isHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    // Só confia no cabeçalho do proxy se o administrador habilitar explicitamente.
    return envValue('TRUST_PROXY', '0') === '1'
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

// Redireciona HTTP -> HTTPS quando FORCE_HTTPS=1 (produção com certificado instalado).
if (PHP_SAPI !== 'cli' && envValue('FORCE_HTTPS', '0') === '1' && !isHttpsRequest() && !headers_sent()) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
}

/** Cabeçalhos de segurança HTTP aplicados a todas as páginas PHP. */
function sendSecurityHeaders(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN'); // o preview do painel usa iframe da mesma origem
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",          // há handlers/scripts inline no painel
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https:",
        "media-src 'self'",
        "connect-src 'self'",
        "frame-src 'self'",
        "frame-ancestors 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
    if (isHttpsRequest()) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
}
sendSecurityHeaders();

function getPDO(): ?PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Mantém NOW()/CURRENT_TIMESTAMP alinhados ao horário de Brasília.
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (PDOException $e) {
            error_log("Erro de Conexão com Banco de Dados: " . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

// Inicia sessão de forma segura se ainda não iniciada
if (PHP_SAPI !== 'cli' && !defined('NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
