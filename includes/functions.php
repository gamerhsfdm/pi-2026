<?php
/**
 * Funções Auxiliares e Segurança
 * Projeto Tunísia CMS
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Mailer.php';

// Gera e valida token CSRF
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Escapa saída para prevenção de XSS
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Controle de Autenticação e Sessão
function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user']['id']);
}

function isAdmin(): bool {
    return isLoggedIn() && (($_SESSION['user']['role'] ?? '') === 'admin');
}

function requireAuth(bool $adminOnly = false): void {
    if (!isLoggedIn()) {
        header('Location: ' . ADMIN_URL . '/index.php');
        exit;
    }
    if ($adminOnly && !isAdmin()) {
        http_response_code(403);
        die('Acesso negado: Requer privilégios de Administrador.');
    }
}

// Registro de Auditoria no Banco
function logAudit(string $action, string $details = '', ?int $userId = null): void {
    $pdo = getPDO();
    if (!$pdo) return;
    try {
        if ($userId === null && isLoggedIn()) {
            $userId = (int)$_SESSION['user']['id'];
        }
        if ($userId === 0) $userId = null; // 0 = ação do sistema (agendador/cron)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $action, $details, $ip]);
        // Qualquer ação administrativa registrada pode alterar o site público.
        if (!in_array($action, ['login', 'logout', 'login bloqueado', 'login falhou', 'cadastro'], true)) {
            purgeSiteCache();
        }
    } catch (Exception $e) {
        error_log("Erro ao salvar log de auditoria: " . $e->getMessage());
    }
}

// Formatação amigável de datas relativas (ex: Há 12 min)
function timeAgo(string $datetime): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, new DateTimeZone('America/Sao_Paulo'));
    if (!$date) {
        try { $date = new DateTimeImmutable($datetime, new DateTimeZone('America/Sao_Paulo')); }
        catch (Throwable $e) { return 'Recentemente'; }
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $diff = $now->getTimestamp() - $date->getTimestamp();
    if ($diff < 0) return 'Agora mesmo';
    if ($diff < 60) return 'Agora mesmo';
    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return 'Há ' . $minutes . ' ' . ($minutes === 1 ? 'minuto' : 'minutos');
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return 'Há ' . $hours . ' ' . ($hours === 1 ? 'hora' : 'horas');
    }
    if ($diff < 2592000) {
        $days = (int) floor($diff / 86400);
        return 'Há ' . $days . ' ' . ($days === 1 ? 'dia' : 'dias');
    }
    return $date->format('d/m/Y');
}

// Cria Notificação no Sistema com verificação de aviso por email
function createNotification(string $action, string $title, string $message, ?int $sectionId = null, ?int $userId = null): void {
    $pdo = getPDO();
    if (!$pdo) return;
    try {
        if ($userId === null && isLoggedIn()) {
            $userId = (int)$_SESSION['user']['id'];
        }
        if ($userId === 0) $userId = null; // 0 = ação do sistema (agendador/cron)
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, action, title, message, section_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
        $stmt->execute([$userId, $action, $title, $message, $sectionId]);

        // Verifica configuração de aviso por e-mail em publicação
        if ($action === 'publicou') {
            $stmtSet = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'notify_on_publish'");
            $notifyOnPublish = $stmtSet ? $stmtSet->fetchColumn() : '0';
            if ($notifyOnPublish === '1') {
                $stmtEmail = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'notify_email'");
                $targetEmail = $stmtEmail ? $stmtEmail->fetchColumn() : '';
                if (!empty($targetEmail)) {
                    queueAppMail((string)$targetEmail, "Tunísia CMS: Conteúdo Publicado - " . $title, $message);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao criar notificação: " . $e->getMessage());
    }
}

// Retorna contagem de notificações não lidas
function getUnreadNotificationsCount(): int {
    $pdo = getPDO();
    if (!$pdo) return 0;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Registro de visualizações reais — barato e fora do caminho crítico da resposta.
function recordPageView(): void {
    if (PHP_SAPI === 'cli') return;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Ignora chamadas ao painel administrativo ou recursos estáticos
    if (strpos($uri, '/admin') !== false || preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|mp4|webp|xml|txt)(\?.*)?$/i', $uri)) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    // Ignora bots e crawlers conhecidos
    if (preg_match('/bot|crawl|slurp|spider|mediapartners|lighthouse|headless/i', $ua)) {
        return;
    }

    // Caminho rápido: o cookie evita qualquer acesso ao banco para visitantes recorrentes (15 min).
    if (isset($_COOKIE['pv_seen'])) return;
    if (!headers_sent()) {
        setcookie('pv_seen', '1', [
            'expires' => time() + 900, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => isHttpsRequest(),
        ]);
    }

    $ip = getClientIp();
    $ua = substr($ua, 0, 250);

    // A gravação acontece depois que a resposta já foi enviada ao visitante.
    register_shutdown_function(function () use ($ip, $ua) {
        finishResponse();
        $pdo = getPDO();
        if (!$pdo) return;
        try {
            // Visitantes sem cookie continuam limitados a 1 registro por IP a cada 15 minutos.
            $stmtCheck = $pdo->prepare("SELECT id FROM page_views WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 15 MINUTE) LIMIT 1");
            $stmtCheck->execute([$ip]);
            if (!$stmtCheck->fetch()) {
                $pdo->prepare("INSERT INTO page_views (ip_address, user_agent, created_at) VALUES (?, ?, NOW())")->execute([$ip, $ua]);
            }
            // Manutenção ocasional (1 em 200 visitas): mantém as tabelas enxutas.
            if (random_int(1, 200) === 1) {
                $pdo->exec("DELETE FROM page_views WHERE created_at < (NOW() - INTERVAL 180 DAY)");
                $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)");
            }
        } catch (Throwable $e) {
            error_log("Erro ao registrar visualização: " . $e->getMessage());
        }
    });
}

// Aplica regra de retenção do histórico de versões definida nas Configurações
function applyHistoryRetention(): void {
    $pdo = getPDO();
    if (!$pdo) return;
    try {
        $stmt = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'history_retention'");
        $days = (int)($stmt ? $stmt->fetchColumn() : 30);
        if ($days > 0) {
            $stmtDel = $pdo->prepare("DELETE FROM versions WHERE created_at < (NOW() - INTERVAL ? DAY)");
            $stmtDel->execute([$days]);
        }
    } catch (Exception $e) {
        error_log("Erro ao aplicar retenção de versões: " . $e->getMessage());
    }
}

// Retorna URL de avatar do usuário com fallback visual limpo
function getUserAvatarUrl(?array $user): string {
    if (!empty($user['avatar'])) {
        return SITE_URL . '/' . ltrim($user['avatar'], '/');
    }
    return '';
}


// ---------------------------------------------------------------------------
// Segurança de login: limite de tentativas
// ---------------------------------------------------------------------------

/** IP do cliente. Só considera X-Forwarded-For se TRUST_PROXY=1 (atrás de proxy/CDN confiável). */
function getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (envValue('TRUST_PROXY', '0') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return substr($ip, 0, 45);
}

/** Cria a tabela de tentativas em instalações antigas que ainda não a possuem. */
function ensureLoginAttemptsTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `ip_address` VARCHAR(45) NOT NULL,
        `email` VARCHAR(150) NOT NULL DEFAULT '',
        `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_login_ip` (`ip_address`, `attempted_at`),
        INDEX `idx_login_email` (`email`, `attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Minutos restantes de bloqueio (0 = liberado).
 * Bloqueia por IP (LOGIN_MAX_ATTEMPTS falhas na janela) e, contra ataques distribuídos,
 * por e-mail com limite 4x maior — assim um invasor não consegue trancar facilmente a conta de outra pessoa.
 */
function loginLockoutMinutes(PDO $pdo, string $email, string $ip): int {
    try {
        ensureLoginAttemptsTable($pdo);
        $email = mb_strtolower(mb_substr($email, 0, 150));
        $window = LOGIN_WINDOW_MINUTES;

        $q = $pdo->prepare("SELECT COUNT(*) AS n, MIN(attempted_at) AS first_at FROM login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)");
        $q->execute([$ip]);
        $byIp = $q->fetch();

        $q = $pdo->prepare("SELECT COUNT(*) AS n, MIN(attempted_at) AS first_at FROM login_attempts WHERE email = ? AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)");
        $q->execute([$email]);
        $byEmail = $q->fetch();

        $firstAt = null;
        if ((int)($byIp['n'] ?? 0) >= LOGIN_MAX_ATTEMPTS) {
            $firstAt = $byIp['first_at'];
        } elseif ((int)($byEmail['n'] ?? 0) >= LOGIN_MAX_ATTEMPTS * 4) {
            $firstAt = $byEmail['first_at'];
        }
        if ($firstAt === null) return 0;

        $unlockTs = strtotime((string)$firstAt) + ($window * 60);
        return max(1, (int)ceil(($unlockTs - time()) / 60));
    } catch (Throwable $e) {
        error_log('Erro ao verificar bloqueio de login: ' . $e->getMessage());
        return 0; // falha aberta apenas se o controle em si quebrar; o login continua protegido por senha
    }
}

function recordLoginFailure(PDO $pdo, string $email, string $ip): void {
    try {
        ensureLoginAttemptsTable($pdo);
        $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES (?, ?, NOW())")
            ->execute([$ip, mb_strtolower(mb_substr($email, 0, 150))]);
        logAudit('login falhou', 'Tentativa de login inválida para ' . mb_substr($email, 0, 150));
    } catch (Throwable $e) {
        error_log('Erro ao registrar falha de login: ' . $e->getMessage());
    }
}

function clearLoginFailures(PDO $pdo, string $email, string $ip): void {
    try {
        ensureLoginAttemptsTable($pdo);
        $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email = ?")
            ->execute([$ip, mb_strtolower(mb_substr($email, 0, 150))]);
    } catch (Throwable $e) {
        error_log('Erro ao limpar falhas de login: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Cache da página pública (arquivo HTML regenerado quando o conteúdo muda)
// ---------------------------------------------------------------------------

function siteCacheTtl(): int {
    return max(0, (int)envValue('CACHE_TTL', '600')); // segundos; 0 desativa
}

function siteCacheFile(): string {
    return CACHE_DIR . '/index.html';
}

/** Remove o HTML em cache; chamado automaticamente a cada ação administrativa auditada. */
function purgeSiteCache(): void {
    $file = siteCacheFile();
    if (is_file($file)) {
        @unlink($file);
    }
}

/** Grava o HTML em cache de forma atômica (arquivo temporário + rename). */
function writeSiteCache(string $html): void {
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0755, true) && !is_dir(CACHE_DIR)) {
        return;
    }
    $tmp = CACHE_DIR . '/index.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $html, LOCK_EX) !== false) {
        @rename($tmp, siteCacheFile());
    }
    @unlink($tmp);
}

// ---------------------------------------------------------------------------
// URL pública, imagens otimizadas (WebP) e miniaturas
// ---------------------------------------------------------------------------

/** URL absoluta do site (PUBLIC_URL no ambiente tem prioridade; senão, deduzida da requisição). */
function siteBaseUrl(): string {
    $configured = trim((string)envValue('PUBLIC_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }
    return (isHttpsRequest() ? 'https' : 'http') . '://' . $host . SITE_URL;
}

function isExternalUrl(string $path): bool {
    return (bool)preg_match('#^(https?:)?//#i', $path);
}

/** Retorna o caminho relativo do .webp equivalente se ele existir no disco (ou null). */
function webpSibling(string $relativePath): ?string {
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== false || isExternalUrl($relativePath)) return null;
    if (!preg_match('/\.(png|jpe?g)$/i', $relativePath)) return null;
    $webp = preg_replace('/\.(png|jpe?g)$/i', '.webp', $relativePath);
    return is_file(__DIR__ . '/../' . $webp) ? $webp : null;
}

/**
 * Gera <picture> com WebP quando houver versão otimizada; senão, <img> comum.
 * Opções: loading (lazy|eager), class, style, fetchpriority.
 */
function pictureTag(string $path, string $alt, array $opts = []): string {
    $path = trim($path);
    if ($path === '') return '';
    $loading = ($opts['loading'] ?? 'lazy') === 'eager' ? 'eager' : 'lazy';
    $attrs = ' alt="' . e($alt) . '" loading="' . $loading . '" decoding="async"';
    if (!empty($opts['class']))         $attrs .= ' class="' . e($opts['class']) . '"';
    if (!empty($opts['style']))         $attrs .= ' style="' . e($opts['style']) . '"';
    if (!empty($opts['fetchpriority'])) $attrs .= ' fetchpriority="' . e($opts['fetchpriority']) . '"';

    if (isExternalUrl($path)) {
        return '<img src="' . e($path) . '"' . $attrs . ' />';
    }
    $rel = ltrim($path, '/');
    $webp = webpSibling($rel);
    $img = '<img src="./' . e($rel) . '"' . $attrs . ' />';
    if ($webp === null) return $img;
    return '<picture><source srcset="./' . e($webp) . '" type="image/webp">' . $img . '</picture>';
}

/**
 * Gera versões WebP (principal e miniatura) de uma imagem enviada. Requer a extensão GD com suporte a WebP.
 * Retorna os caminhos relativos criados (pode ser vazio se GD/WebP não estiver disponível).
 */
function generateImageVariants(string $absPath, string $mime): array {
    $made = [];
    if (!function_exists('imagewebp') || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return $made;
    }
    $loader = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime];
    if (!function_exists($loader)) return $made;
    $info = @getimagesize($absPath);
    if (!$info || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > 40000000) return $made; // evita estourar memória
    $src = @$loader($absPath);
    if (!$src) return $made;
    imagepalettetotruecolor($src);
    imagealphablending($src, true);
    imagesavealpha($src, true);

    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $absPath);
    foreach ([['.webp', 2000, 80], ['-thumb.webp', 480, 75]] as [$suffix, $maxW, $quality]) {
        $w = imagesx($src); $h = imagesy($src);
        $img = $src;
        if ($w > $maxW) {
            $scaled = imagescale($src, $maxW, (int)round($h * ($maxW / $w)));
            if ($scaled) { $img = $scaled; }
        }
        $target = $base . $suffix;
        if ($mime === 'image/webp' && $suffix === '.webp') { // já é WebP: não recria o mesmo arquivo
            if ($img !== $src) imagedestroy($img);
            continue;
        }
        if (@imagewebp($img, $target, $quality)) {
            $made[] = $target;
        }
        if ($img !== $src) imagedestroy($img);
    }
    imagedestroy($src);
    return $made;
}

/** Versão do arquivo estático (data de modificação) para renovar o cache do navegador. */
function assetVersion(string $relativePath): string {
    $file = __DIR__ . '/../' . ltrim($relativePath, '/');
    return is_file($file) ? (string)filemtime($file) : '1';
}

/**
 * Entrega a resposta ao visitante antes de tarefas pesadas executadas no encerramento do script.
 * Com PHP-FPM usa fastcgi_finish_request(); nos demais (ex.: XAMPP/mod_php) descarrega os buffers.
 */
function finishResponse(): void {
    if (PHP_SAPI === 'cli') return;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

/**
 * Título com destaque: escapa todo o HTML, exceto <span class="highlight">…</span>
 * (usado nos títulos padrão, ex.: "Descobertas e <span class="highlight">Pesquisas</span>").
 */
function titleWithHighlight(?string $title): string {
    $escaped = e((string)$title);
    return (string)preg_replace(
        '/&lt;span class=(?:&quot;|&#0?39;|&apos;)highlight(?:&quot;|&#0?39;|&apos;)&gt;(.*?)&lt;\/span&gt;/s',
        '<span class="highlight">$1</span>',
        $escaped
    );
}

