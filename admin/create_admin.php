<?php
/**
 * Bootstrap de administrador. Somente CLI: não exponha este script pela web.
 * Exemplo PowerShell:
 *   $env:ADMIN_EMAIL="admin@seudominio.com"
 *   $env:ADMIN_PASSWORD="uma-senha-forte-e-unica"
 *   $env:ADMIN_NAME="Administrador"
 *   & "C:\xampp\php\php.exe" admin\create_admin.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}
require_once __DIR__ . '/../config/database.php';
$email = filter_var(getenv('ADMIN_EMAIL') ?: '', FILTER_VALIDATE_EMAIL);
$password = (string)(getenv('ADMIN_PASSWORD') ?: '');
$name = trim((string)(getenv('ADMIN_NAME') ?: 'Administrador'));
if (!$email || strlen($password) < 12) {
    fwrite(STDERR, "Defina ADMIN_EMAIL válido e ADMIN_PASSWORD com pelo menos 12 caracteres no ambiente.\n");
    exit(2);
}
$pdo = getPDO();
if (!$pdo) {
    fwrite(STDERR, "Banco indisponível. Confira DB_HOST/DB_NAME/DB_USER/DB_PASS.\n");
    exit(1);
}
try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name,email,password,role,status,created_at) VALUES (?,?,?,'admin','active',NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),password=VALUES(password),role='admin',status='active'");
    $stmt->execute([$name, $email, $hash]);
    echo "Administrador configurado para {$email}. Remova as variáveis ADMIN_PASSWORD do ambiente após concluir.\n";
} catch (Throwable $e) {
    error_log('Falha no bootstrap de administrador: ' . $e->getMessage());
    fwrite(STDERR, "Não foi possível configurar o administrador. Consulte o log do servidor.\n");
    exit(1);
}
