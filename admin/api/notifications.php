<?php
/**
 * API: Gerenciamento de Notificações
 * Ações: mark_read, mark_all_read, get_latest
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acesso não autorizado']);
    exit;
}

$pdo = getPDO();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

if ($action === 'get_latest') {
    $stmt = $pdo->query("
        SELECT n.*, u.name as user_name, u.avatar as user_avatar, s.identifier as section_identifier
        FROM notifications n
        LEFT JOIN users u ON n.user_id = u.id
        LEFT JOIN sections s ON n.section_id = s.id
        ORDER BY n.created_at DESC
        LIMIT 6
    ");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($list as &$item) {
        $item['time_ago'] = timeAgo($item['created_at']);
        $item['created_at_fmt'] = date('d/m/Y H:i', strtotime($item['created_at']));
        $item['avatar_url'] = !empty($item['user_avatar']) ? SITE_URL . '/' . ltrim($item['user_avatar'], '/') : '';
    }

    $unreadCount = getUnreadNotificationsCount();

    echo json_encode(['success' => true, 'notifications' => $list, 'unread_count' => $unreadCount]);
    exit;
}

if ($action === 'mark_read' || $action === 'mark_all_read') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST para alterar notificações']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrf = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sessão inválida ou expirada']);
        exit;
    }

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? $input['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $stmt->execute([$id]);
        }
        echo json_encode(['success' => true, 'unread_count' => getUnreadNotificationsCount()]);
        exit;
    }

    $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    echo json_encode(['success' => true, 'unread_count' => getUnreadNotificationsCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação não especificada']);

