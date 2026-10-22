<?php
/**
 * API: Reordenar Seções
 * Atualiza o display_order de cada seção e registra no log e notificações
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acesso não autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$csrf = $input['csrf_token'] ?? '';

if (!verifyCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sessão inválida ou expirada']);
    exit;
}

$order = $input['order'] ?? [];
if (!is_array($order) || empty($order) || count($order) !== count(array_unique(array_map('intval', $order)))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ordem inválida ou IDs duplicados']);
    exit;
}
$order = array_values(array_filter(array_map('intval', $order), static fn($id) => $id > 0));
if (!$order) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nenhuma seção válida foi enviada']);
    exit;
}

$pdo = getPDO();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco de dados']);
    exit;
}

try {
    $pdo->beginTransaction();
    $check = $pdo->prepare('SELECT id FROM sections WHERE id = ?');
    $stmt = $pdo->prepare("UPDATE sections SET display_order = ? WHERE id = ?");
    foreach ($order as $index => $sectionId) {
        $check->execute([$sectionId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Uma das seções enviadas não existe.');
        }
        $stmt->execute([$index + 1, $sectionId]);
    }
    $pdo->commit();
    purgeSiteCache();

    $user = getCurrentUser();
    $userName = $user['name'] ?? 'Usuário';
    logAudit('reordenou seções', 'Nova ordem das seções salva pelo usuário');
    createNotification('reordenou', 'Seções Reordenadas', "{$userName} alterou a ordem de exibição das seções no site.");

    echo json_encode(['success' => true, 'message' => 'Ordem das seções salva com sucesso!']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar a reordenação. Verifique os logs do servidor.']);
}

