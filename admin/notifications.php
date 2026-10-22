<?php
/**
 * Central de Notificações — Tunísia CMS
 * Interface moderna com filtros e ações de leitura.
 */

$pageTitle = 'Notificações';
$activePage = 'notifications';

require_once __DIR__ . '/../includes/functions.php';
requireAuth();

$pdo = getPDO();
$successMsg = '';
$errorMsg = '';

/**
 * Marcar todas como lidas.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_all_read'
) {
    $csrf = $_POST['csrf_token'] ?? '';

    if (verifyCsrfToken($csrf) && $pdo) {
        try {
            $pdo->exec("UPDATE notifications SET is_read = 1");
            $successMsg = 'Todas as notificações foram marcadas como lidas.';
        } catch (PDOException $e) {
            error_log('Erro ao marcar notificações: ' . $e->getMessage());
            $errorMsg = 'Não foi possível atualizar as notificações.';
        }
    } else {
        $errorMsg = 'Sua sessão pode ter expirado. Atualize a página e tente novamente.';
    }
}

/**
 * Marcar notificação individual como lida.
 */
if (isset($_GET['mark_id'])) {
    $markId = (int) $_GET['mark_id'];

    if ($markId > 0 && $pdo) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE notifications SET is_read = 1 WHERE id = ?"
            );
            $stmt->execute([$markId]);
        } catch (PDOException $e) {
            error_log('Erro ao marcar notificação: ' . $e->getMessage());
        }
    }

    // Evita repetir a ação ao atualizar a página.
    $redirectUrl = ADMIN_URL . '/notifications.php';
    $filter = $_GET['filter'] ?? 'all';

    if (in_array($filter, ['all', 'unread', 'read'], true)) {
        $redirectUrl .= '?filter=' . urlencode($filter);
    }

    header('Location: ' . $redirectUrl);
    exit;
}

/**
 * Carregar notificações.
 */
$notificationsList = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                n.*,
                u.name AS user_name,
                u.avatar AS user_avatar,
                s.identifier AS section_identifier,
                s.title AS section_title
            FROM notifications n
            LEFT JOIN users u ON n.user_id = u.id
            LEFT JOIN sections s ON n.section_id = s.id
            ORDER BY n.created_at DESC
        ");

        $notificationsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erro ao buscar notificações: ' . $e->getMessage());
        $errorMsg = 'Não foi possível carregar as notificações.';
    }
}

/**
 * Estatísticas.
 */
$totalCount = count($notificationsList);
$unreadCount = 0;
$readCount = 0;

foreach ($notificationsList as $notification) {
    if ((int) $notification['is_read'] === 0) {
        $unreadCount++;
    } else {
        $readCount++;
    }
}

/**
 * Filtro atual.
 */
$filter = $_GET['filter'] ?? 'all';

if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

$filteredNotifications = array_filter(
    $notificationsList,
    static function ($notification) use ($filter) {
        $isUnread = (int) $notification['is_read'] === 0;

        if ($filter === 'unread') {
            return $isUnread;
        }

        if ($filter === 'read') {
            return !$isUnread;
        }

        return true;
    }
);

require_once __DIR__ . '/../includes/admin_header.php';
?>

<style>
    .notifications-page {
        color: #172033;
        padding-bottom: 32px;
    }

    .notifications-page * {
        box-sizing: border-box;
    }

    .notifications-hero {
        position: relative;
        overflow: hidden;
        background: linear-gradient(120deg, #ffffff 0%, #f8faff 100%);
        border: 1px solid #e7eaf1;
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 24px;
    }

    .notifications-hero::after {
        content: "";
        position: absolute;
        width: 210px;
        height: 210px;
        border-radius: 50%;
        right: -75px;
        top: -115px;
        background: rgba(79, 70, 229, 0.06);
        pointer-events: none;
    }

    .notifications-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        position: relative;
        z-index: 1;
    }

    .notifications-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #6366f1;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .1em;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    .notifications-title {
        color: #111827;
        font-size: clamp(24px, 3vw, 32px);
        font-weight: 800;
        letter-spacing: -.045em;
        line-height: 1.2;
        margin: 0;
    }

    .notifications-description {
        color: #737d90;
        font-size: 13px;
        line-height: 1.7;
        margin-top: 9px;
        max-width: 500px;
    }

    .notifications-main-icon {
        width: 58px;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border-radius: 19px;
        color: #4f46e5;
        background: #eef2ff;
        border: 1px solid #e0e7ff;
    }

    .notifications-main-icon svg {
        width: 26px;
        height: 26px;
    }

    .notifications-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 25px;
    }

    .notification-stat {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
        padding: 20px;
        background: #fff;
        border: 1px solid #e8ebf2;
        border-radius: 20px;
        transition: border-color .2s, transform .2s, box-shadow .2s;
    }

    .notification-stat:hover {
        border-color: #d9dded;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(15, 23, 42, .035);
    }

    .notification-stat-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        flex-shrink: 0;
        border-radius: 14px;
    }

    .notification-stat-icon svg {
        width: 20px;
        height: 20px;
    }

    .stat-total {
        background: #f1f5f9;
        color: #475569;
    }

    .stat-unread {
        background: #eef2ff;
        color: #4f46e5;
    }

    .stat-read {
        background: #ecfdf5;
        color: #059669;
    }

    .notification-stat-label {
        color: #8490a3;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .notification-stat-number {
        color: #172033;
        font-size: 25px;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -.04em;
    }

    .notifications-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .notifications-section-title {
        color: #202a3b;
        font-size: 16px;
        font-weight: 800;
        letter-spacing: -.02em;
    }

    .notifications-section-subtitle {
        color: #8992a3;
        font-size: 12px;
        margin-top: 4px;
    }

    .notifications-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .notification-filter-group {
        display: flex;
        align-items: center;
        gap: 4px;
        background: #f1f3f8;
        border: 1px solid #e8ebf2;
        border-radius: 12px;
        padding: 4px;
    }

    .notification-filter {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 13px;
        border-radius: 9px;
        color: #717b8d;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        transition: background .2s, color .2s, box-shadow .2s;
    }

    .notification-filter:hover {
        color: #3730a3;
    }

    .notification-filter.active {
        background: #fff;
        color: #4338ca;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .08);
    }

    .notification-filter-count {
        min-width: 19px;
        padding: 2px 5px;
        border-radius: 6px;
        background: #eef2f7;
        color: #64748b;
        font-size: 10px;
        text-align: center;
    }

    .notification-filter.active .notification-filter-count {
        color: #4338ca;
        background: #eef2ff;
    }

    .notification-mark-all {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        padding: 10px 14px;
        color: #4f46e5;
        background: #fff;
        border: 1px solid #e0e7ff;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: background .2s, border-color .2s;
    }

    .notification-mark-all:hover {
        background: #eef2ff;
        border-color: #c7d2fe;
    }

    .notification-mark-all svg {
        width: 16px;
        height: 16px;
    }

    .notifications-list {
        overflow: hidden;
        background: #fff;
        border: 1px solid #e7eaf1;
        border-radius: 22px;
    }

    .notification-item {
        display: flex;
        align-items: flex-start;
        gap: 15px;
        padding: 21px 23px;
        border-bottom: 1px solid #f0f2f6;
        transition: background .2s;
    }

    .notification-item:last-child {
        border-bottom: none;
    }

    .notification-item:hover {
        background: #fafbfe;
    }

    .notification-item.is-unread {
        background: linear-gradient(90deg, #f7f8ff 0%, #fff 75%);
    }

    .notification-item.is-unread:hover {
        background: #f4f5ff;
    }

    .notification-avatar {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        flex-shrink: 0;
        overflow: hidden;
        border: 1px solid #e8eaf0;
        border-radius: 15px;
        background: #f1f5f9;
        color: #596579;
        font-size: 14px;
        font-weight: 800;
    }

    .notification-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .notification-content {
        flex: 1;
        min-width: 0;
    }

    .notification-item-heading {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 6px;
    }

    .notification-item-title {
        color: #202a3b;
        font-size: 13px;
        font-weight: 800;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .notification-unread-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 7px;
        color: #4f46e5;
        background: #eef2ff;
        border-radius: 6px;
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .notification-unread-badge::before {
        content: "";
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #6366f1;
    }

    .notification-message {
        color: #687386;
        font-size: 12px;
        line-height: 1.75;
        overflow-wrap: anywhere;
    }

    .notification-meta {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 11px;
        color: #97a0af;
        font-size: 11px;
    }

    .notification-meta-item {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .notification-meta-item svg {
        width: 13px;
        height: 13px;
    }

    .notification-section-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #5b55d6;
        font-weight: 700;
        text-decoration: none;
        transition: color .2s;
    }

    .notification-section-link:hover {
        color: #3730a3;
        text-decoration: underline;
    }

    .notification-section-link svg {
        width: 12px;
        height: 12px;
    }

    .notification-read-action {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        flex-shrink: 0;
        border: 1px solid #e8eaf2;
        border-radius: 11px;
        background: #fff;
        color: #8b95a6;
        text-decoration: none;
        transition: all .2s;
    }

    .notification-read-action:hover {
        color: #059669;
        background: #ecfdf5;
        border-color: #a7f3d0;
    }

    .notification-read-action svg {
        width: 17px;
        height: 17px;
    }

    .notifications-empty {
        padding: 60px 24px;
        text-align: center;
    }

    .notifications-empty-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 66px;
        height: 66px;
        margin: 0 auto 18px;
        color: #818cf8;
        background: #eef2ff;
        border: 1px solid #e0e7ff;
        border-radius: 22px;
    }

    .notifications-empty-icon svg {
        width: 29px;
        height: 29px;
    }

    .notifications-empty-title {
        color: #202a3b;
        font-size: 16px;
        font-weight: 800;
        margin-bottom: 8px;
    }

    .notifications-empty-description {
        max-width: 370px;
        margin: 0 auto;
        color: #8992a3;
        font-size: 12px;
        line-height: 1.8;
    }

    .notifications-alert {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        padding: 14px 16px;
        margin-bottom: 20px;
        border-radius: 13px;
        font-size: 12px;
        line-height: 1.6;
    }

    .notifications-alert svg {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }

    .notifications-alert.success {
        color: #047857;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
    }

    .notifications-alert.error {
        color: #b91c1c;
        background: #fef2f2;
        border: 1px solid #fecaca;
    }

    @media (max-width: 900px) {
        .notifications-stats {
            gap: 10px;
        }

        .notification-stat {
            padding: 15px;
            gap: 10px;
        }

        .notification-stat-icon {
            width: 38px;
            height: 38px;
        }

        .notifications-toolbar {
            align-items: flex-start;
            flex-direction: column;
        }

        .notifications-actions {
            width: 100%;
        }
    }

    @media (max-width: 600px) {
        .notifications-hero {
            padding: 21px;
            border-radius: 20px;
        }

        .notifications-main-icon {
            width: 46px;
            height: 46px;
            border-radius: 15px;
        }

        .notifications-stats {
            grid-template-columns: 1fr;
            gap: 9px;
        }

        .notification-stat {
            padding: 13px 15px;
        }

        .notification-stat-number {
            font-size: 22px;
        }

        .notifications-actions {
            align-items: stretch;
            flex-direction: column;
        }

        .notification-filter-group {
            width: 100%;
        }

        .notification-filter {
            flex: 1;
            padding: 9px 5px;
            gap: 5px;
            font-size: 11px;
        }

        .notification-mark-all {
            width: 100%;
        }

        .notifications-list {
            border-radius: 17px;
        }

        .notification-item {
            gap: 11px;
            padding: 17px 13px;
        }

        .notification-avatar {
            width: 38px;
            height: 38px;
            border-radius: 12px;
        }

        .notification-item-title {
            font-size: 12px;
        }

        .notification-message {
            font-size: 11px;
        }

        .notification-read-action {
            width: 31px;
            height: 31px;
        }

        .notifications-empty {
            padding: 42px 20px;
        }
    }
</style>

<div class="notifications-page">

    <!-- Cabeçalho -->
    <section class="notifications-hero">
        <div class="notifications-heading">
            <div>
                <div class="notifications-eyebrow">
                    <i data-lucide="bell-ring" style="width:14px;height:14px"></i>
                    Central de atividades
                </div>

                <h1 class="notifications-title">Notificações</h1>

                <p class="notifications-description">
                    Acompanhe as atualizações, publicações e atividades
                    da equipe em um só lugar.
                </p>
            </div>

            <div class="notifications-main-icon">
                <i data-lucide="bell"></i>
            </div>
        </div>
    </section>

    <!-- Mensagens de retorno -->
    <?php if ($successMsg): ?>
        <div class="notifications-alert success" role="status">
            <i data-lucide="check-circle"></i>
            <span><?= e($successMsg) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="notifications-alert error" role="alert">
            <i data-lucide="alert-circle"></i>
            <span><?= e($errorMsg) ?></span>
        </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <section class="notifications-stats" aria-label="Resumo das notificações">

        <div class="notification-stat">
            <div class="notification-stat-icon stat-total">
                <i data-lucide="layers"></i>
            </div>

            <div>
                <div class="notification-stat-label">Total de notificações</div>
                <div class="notification-stat-number">
                    <?= $totalCount ?>
                </div>
            </div>
        </div>

        <div class="notification-stat">
            <div class="notification-stat-icon stat-unread">
                <i data-lucide="bell-dot"></i>
            </div>

            <div>
                <div class="notification-stat-label">Não lidas</div>
                <div class="notification-stat-number">
                    <?= $unreadCount ?>
                </div>
            </div>
        </div>

        <div class="notification-stat">
            <div class="notification-stat-icon stat-read">
                <i data-lucide="check-check"></i>
            </div>

            <div>
                <div class="notification-stat-label">Já lidas</div>
                <div class="notification-stat-number">
                    <?= $readCount ?>
                </div>
            </div>
        </div>

    </section>

    <!-- Barra de filtros -->
    <div class="notifications-toolbar">

        <div>
            <h2 class="notifications-section-title">
                Suas atividades
            </h2>

            <p class="notifications-section-subtitle">
                <?= count($filteredNotifications) ?>
                <?= count($filteredNotifications) === 1
                    ? 'notificação encontrada'
                    : 'notificações encontradas' ?>
            </p>
        </div>

        <div class="notifications-actions">

            <nav class="notification-filter-group" aria-label="Filtrar notificações">

                <a
                    href="?filter=all"
                    class="notification-filter <?= $filter === 'all' ? 'active' : '' ?>"
                    <?= $filter === 'all' ? 'aria-current="page"' : '' ?>
                >
                    Todas
                    <span class="notification-filter-count">
                        <?= $totalCount ?>
                    </span>
                </a>

                <a
                    href="?filter=unread"
                    class="notification-filter <?= $filter === 'unread' ? 'active' : '' ?>"
                    <?= $filter === 'unread' ? 'aria-current="page"' : '' ?>
                >
                    Não lidas
                    <span class="notification-filter-count">
                        <?= $unreadCount ?>
                    </span>
                </a>

                <a
                    href="?filter=read"
                    class="notification-filter <?= $filter === 'read' ? 'active' : '' ?>"
                    <?= $filter === 'read' ? 'aria-current="page"' : '' ?>
                >
                    Lidas
                    <span class="notification-filter-count">
                        <?= $readCount ?>
                    </span>
                </a>

            </nav>

            <?php if ($unreadCount > 0): ?>
                <form method="POST">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(getCsrfToken()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="mark_all_read"
                    >

                    <button type="submit" class="notification-mark-all">
                        <i data-lucide="check-check"></i>
                        Marcar todas como lidas
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <!-- Lista de notificações -->
    <section class="notifications-list" aria-label="Lista de notificações">

        <?php if (empty($filteredNotifications)): ?>

            <div class="notifications-empty">
                <div class="notifications-empty-icon">
                    <i data-lucide="<?= $filter === 'unread' ? 'check-circle-2' : 'bell-off' ?>"></i>
                </div>

                <h3 class="notifications-empty-title">
                    <?php if ($filter === 'unread'): ?>
                        Tudo em dia!
                    <?php elseif ($filter === 'read'): ?>
                        Nenhuma notificação lida
                    <?php else: ?>
                        Nenhuma notificação por enquanto
                    <?php endif; ?>
                </h3>

                <p class="notifications-empty-description">
                    <?php if ($filter === 'unread'): ?>
                        Você não possui notificações pendentes de leitura.
                    <?php elseif ($filter === 'read'): ?>
                        As notificações que você já leu aparecerão aqui.
                    <?php else: ?>
                        Quando houver novas publicações, alterações ou
                        atividades da equipe, elas aparecerão aqui.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <?php foreach ($filteredNotifications as $notif): ?>

                <?php
                    $isUnread = (int) $notif['is_read'] === 0;

                    $createdTimestamp = strtotime($notif['created_at']);
                    $fullDate = $createdTimestamp
                        ? date('d/m/Y \à\s H:i', $createdTimestamp)
                        : '';

                    $userName = trim($notif['user_name'] ?? '');
                    $initial = $userName !== ''
                        ? strtoupper(function_exists('mb_substr')
                            ? mb_substr($userName, 0, 1, 'UTF-8')
                            : substr($userName, 0, 1))
                        : 'U';

                    $avatarPath = trim($notif['user_avatar'] ?? '');

                    $avatarUrl = $avatarPath !== ''
                        ? rtrim(SITE_URL, '/') . '/' . ltrim($avatarPath, '/')
                        : '';

                    $sectionTitle = trim($notif['section_title'] ?? '');
                ?>

                <article class="notification-item <?= $isUnread ? 'is-unread' : '' ?>">

                    <!-- Avatar -->
                    <div class="notification-avatar">
                        <?php if ($avatarUrl !== ''): ?>
                            <img
                                src="<?= e($avatarUrl) ?>"
                                alt="<?= e($userName !== '' ? $userName : 'Usuário') ?>"
                                loading="lazy"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                            >
                            <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center">
                                <?= e($initial) ?>
                            </span>
                        <?php else: ?>
                            <?= e($initial) ?>
                        <?php endif; ?>
                    </div>

                    <!-- Conteúdo -->
                    <div class="notification-content">

                        <div class="notification-item-heading">
                            <h3 class="notification-item-title">
                                <?= e($notif['title'] ?? 'Notificação') ?>
                            </h3>

                            <?php if ($isUnread): ?>
                                <span class="notification-unread-badge">
                                    Nova
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="notification-message">
                            <?= e($notif['message'] ?? '') ?>
                        </p>

                        <div class="notification-meta">

                            <span
                                class="notification-meta-item"
                                title="<?= e($fullDate) ?>"
                            >
                                <i data-lucide="clock-3"></i>

                                <?php if ($createdTimestamp): ?>
                                    <?= e(timeAgo($notif['created_at'])) ?>
                                <?php else: ?>
                                    Data indisponível
                                <?php endif; ?>
                            </span>

                            <?php if (!empty($notif['user_id']) && $userName !== ''): ?>
                                <span class="notification-meta-item">
                                    <i data-lucide="user-round"></i>
                                    <?= e($userName) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($notif['section_id'])): ?>
                                <a
                                    href="<?= e(ADMIN_URL) ?>/editor.php?id=<?= (int) $notif['section_id'] ?>"
                                    class="notification-section-link"
                                    title="<?= e($sectionTitle !== '' ? $sectionTitle : 'Editar seção vinculada') ?>"
                                >
                                    <i data-lucide="file-pen-line"></i>

                                    <?= $sectionTitle !== ''
                                        ? e($sectionTitle)
                                        : 'Editar seção vinculada' ?>

                                    <i data-lucide="arrow-up-right"></i>
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>

                    <!-- Ação individual -->
                    <?php if ($isUnread): ?>
                        <a
                            href="?mark_id=<?= (int) $notif['id'] ?>&amp;filter=<?= e($filter) ?>"
                            class="notification-read-action"
                            title="Marcar como lida"
                            aria-label="Marcar notificação como lida"
                        >
                            <i data-lucide="check"></i>
                        </a>
                    <?php else: ?>
                        <div
                            class="notification-read-action"
                            title="Notificação lida"
                            aria-label="Notificação lida"
                        >
                            <i data-lucide="check-check"></i>
                        </div>
                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
