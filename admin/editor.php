<?php
/**
 * Editor de Conteúdo — Tunísia CMS
 * Preview Dinâmico de acordo com a seção selecionada (Hero, Sobre, Mapa, Cultura, etc.)
 * Atualização em tempo real na digitação e alternância de dispositivo (Desktop/Tablet)
 */
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
require_once __DIR__ . '/../includes/section_items.php';
require_once __DIR__ . '/../includes/scheduler.php';
require_once __DIR__ . '/../includes/section_style.php';

$pdo = getPDO();
if ($pdo) ensureSchema($pdo); // garante a coluna publish_at em bancos antigos
$schedulingEnabled = $pdo ? (getSiteSetting($pdo, 'enable_scheduling', '1') === '1') : false;
$sectionId = max(0, (int)($_GET['id'] ?? 0));
$isNewSection = ($sectionId === 0);
$successMsg = '';
$errorMsg = '';

// Carrega a seção do banco
$section = null;
if ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE id = ? LIMIT 1");
    $stmt->execute([$sectionId]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Se houver rascunho salvo, o formulário edita esse snapshot sem alterar a versão pública.
$loadedDraft = [];
if (is_array($section) && !empty($section['draft_data'])) {
    $decodedDraft = json_decode((string)$section['draft_data'], true);
    if (is_array($decodedDraft)) {
        $loadedDraft = $decodedDraft;
        foreach (['title','subtitle','description','badge','primary_btn_text','primary_btn_link','secondary_btn_text','secondary_btn_link','image_path','video_path','is_visible'] as $draftField) {
            if (array_key_exists($draftField, $decodedDraft)) $section[$draftField] = $decodedDraft[$draftField];
        }
    }
}

// Concorrência: verifica e renova lock de edição (válido por 3 minutos)
$isLockedByOther = false;
$lockedByUserName = '';
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);

if ($pdo && $section && !empty($section['id'])) {
    if (!empty($section['locked_by']) && (int)$section['locked_by'] !== $currentUserId && !empty($section['locked_at'])) {
        $lockAge = time() - strtotime($section['locked_at']);
        if ($lockAge < 180) { // menos de 3 minutos
            $isLockedByOther = true;
            $stmtLockUser = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
            $stmtLockUser->execute([$section['locked_by']]);
            $lockedByUserName = $stmtLockUser->fetchColumn() ?: 'Outro membro da equipe';
        }
    }
    // Se não estiver bloqueado por outro, adquire ou renova o lock para o usuário atual
    if (!$isLockedByOther && $currentUserId > 0) {
        $pdo->prepare("UPDATE sections SET locked_by = ?, locked_at = NOW() WHERE id = ?")->execute([$currentUserId, $sectionId]);
    }
}

// Fallback inicial se não existir
if (!$section) {
    $section = [
        'id' => 0,
        'identifier' => '',
        'title' => '',
        'subtitle' => '',
        'description' => '',
        'badge' => '',
        'primary_btn_text' => '',
        'primary_btn_link' => '',
        'secondary_btn_text' => '',
        'secondary_btn_link' => '',
        'image_path' => '',
        'video_path' => '',
        'status' => 'draft',
        'display_order' => 0,
        'is_visible' => 1
    ];
}

$sectionItems = [];
if ($pdo && !$isNewSection && !empty($section['id'])) {
    try { $sectionItems = ensureSectionItems($pdo, (int)$section['id'], (string)$section['identifier']); }
    catch (Throwable $e) { error_log('Erro ao carregar itens da seção: ' . $e->getMessage()); }
    if (isset($loadedDraft['section_items']) && is_array($loadedDraft['section_items'])) $sectionItems = $loadedDraft['section_items'];
}

$styleValues = isset($loadedDraft['style']) && is_array($loadedDraft['style'])
    ? sanitizeSectionStyle($loadedDraft['style'])
    : decodeSectionStyle(isset($section['style_data']) ? (string)$section['style_data'] : '');

$pageTitle = $isNewSection ? 'Nova seção' : ($section['title'] ?: 'Editar seção');
$activePage = 'content';

// Processa salvamento (Salvar Rascunho ou Publicar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $errorMsg = 'Sessão inválida ou expirada.';
    } else {
        $action = $_POST['action'] ?? 'save_draft';
        $publishBlocked = ($action === 'publish' && !isAdmin());
        if ($publishBlocked) $action = 'save_draft';
        $scheduleRequested = ($action === 'schedule' && isAdmin() && $schedulingEnabled);
        $unscheduleRequested = ($action === 'unschedule' && isAdmin());
        if ($action === 'schedule' || $action === 'unschedule') $action = 'save_draft';
        $styleValues = sanitizeSectionStyle($_POST['sx'] ?? []);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $description = trim($_POST['description'] ?? $subtitle);
        $primaryBtnText = trim($_POST['primary_btn_text'] ?? '');
        $primaryBtnLink = trim($_POST['primary_btn_link'] ?? '');
        $secondaryBtnText = trim($_POST['secondary_btn_text'] ?? '');
        $secondaryBtnLink = trim($_POST['secondary_btn_link'] ?? '');
        $imagePath = trim($_POST['image_path'] ?? ($section['image_path'] ?? ''));
        $badge = trim($_POST['badge'] ?? '');
        $videoPath = trim($_POST['video_path'] ?? '');
        $isVisible = isset($_POST['is_visible']) ? 1 : 0;

        $bgChoice = trim($_POST['sx']['bg_image'] ?? '');
        if (preg_match('/\.(mp4|webm|ogg)$/i', $bgChoice)) {
            $videoPath = $bgChoice;
            $styleValues['bg_image'] = '';
        }

        if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $fileMime = mime_content_type($_FILES['image_file']['tmp_name']);
            if (in_array($fileMime, $allowedTypes, true)) {
                $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$fileMime];
                $sId = ($sectionId > 0) ? $sectionId : 'new';
                $newName = 'section_' . $sId . '_' . time() . '.' . $ext;
                
                if (!is_dir(UPLOADS_DIR)) {
                    @mkdir(UPLOADS_DIR, 0777, true);
                }
                $target = UPLOADS_DIR . '/' . $newName;
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target)) {
                    if (function_exists('generateImageVariants')) {
                        generateImageVariants($target, $fileMime);
                    }
                    $imagePath = 'uploads/media/' . $newName;
                }
            }
        }

        if ($pdo) {
            try {
                $scheduleAt = null;
                if ($scheduleRequested) {
                    $rawSchedule = trim((string)($_POST['publish_at'] ?? ''));
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $rawSchedule, new DateTimeZone('America/Sao_Paulo'));
                    if (!$dt || $dt->format('Y-m-d\TH:i') !== $rawSchedule) {
                        throw new RuntimeException('Informe uma data e hora válidas para agendar.');
                    }
                    if ($dt->getTimestamp() <= time() + 60) {
                        throw new RuntimeException('Escolha uma data e hora futuras (pelo menos 1 minuto à frente).');
                    }
                    $scheduleAt = $dt->format('Y-m-d H:i:00');
                }
                $status = ($action === 'publish') ? 'published' : 'draft';
                if ($isNewSection) {
                    $rawIdentifier = trim($_POST['identifier'] ?? $title);
                    $identifier = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rawIdentifier)), '-'));
                    if ($identifier === '') {
                        throw new RuntimeException('Informe um título ou identificador válido para a seção.');
                    }
                    $checkIdentifier = $pdo->prepare('SELECT id FROM sections WHERE identifier = ? LIMIT 1');
                    $checkIdentifier->execute([$identifier]);
                    if ($checkIdentifier->fetch()) {
                        $identifier .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                    }
                    $nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM sections')->fetchColumn();
                    $stmt = $pdo->prepare("INSERT INTO sections (identifier, title, subtitle, badge, description, primary_btn_text, primary_btn_link, secondary_btn_text, secondary_btn_link, image_path, video_path, display_order, status, is_visible, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$identifier, $title, $subtitle, $badge ?: null, $description, $primaryBtnText, $primaryBtnLink, $secondaryBtnText, $secondaryBtnLink, $imagePath ?: null, $videoPath ?: null, $nextOrder, $status, $isVisible]);
                    $sectionId = (int)$pdo->lastInsertId();
                    $isNewSection = false;
                    $section['id'] = $sectionId;
                    $section['identifier'] = $identifier;
                } elseif ($action === 'publish') {
                    $stmt = $pdo->prepare("UPDATE sections SET title = ?, subtitle = ?, badge = ?, description = ?, primary_btn_text = ?, primary_btn_link = ?, secondary_btn_text = ?, secondary_btn_link = ?, image_path = ?, video_path = ?, status = 'published', draft_data = NULL, is_visible = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$title, $subtitle, $badge ?: null, $description, $primaryBtnText, $primaryBtnLink, $secondaryBtnText, $secondaryBtnLink, $imagePath ?: null, $videoPath ?: null, $isVisible, $sectionId]);
                }

                $itemTypes = $_POST['item_type'] ?? [];
                $itemTitles = $_POST['item_title'] ?? [];
                $itemSubtitles = $_POST['item_subtitle'] ?? [];
                $itemDescriptions = $_POST['item_description'] ?? [];
                $itemIcons = $_POST['item_icon'] ?? [];
                $itemImages = $_POST['item_image_path'] ?? [];
                $itemBadges = $_POST['item_badge'] ?? [];
                $itemLinks = $_POST['item_link_url'] ?? [];
                $draftItems = [];
                $itemCount = max(count($itemTitles), count($itemTypes));
                $allowedItemTypes = ['card','cultural_card','timeline','knowledge','business','feature','model','objective','competency','custom','stat','map_marker'];
                if ($action === 'publish') {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM section_items WHERE section_id = ?')->execute([$sectionId]);
                    $itemInsert = $pdo->prepare('INSERT INTO section_items (section_id,item_type,title,subtitle,description,icon,image_path,badge,link_url,display_order,status,is_visible) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                }
                for ($i = 0; $i < $itemCount; $i++) {
                    $itemTitle = trim((string)($itemTitles[$i] ?? ''));
                    $itemDescription = trim((string)($itemDescriptions[$i] ?? ''));
                    $itemSubtitle = trim((string)($itemSubtitles[$i] ?? ''));
                    if ($itemTitle === '' && $itemDescription === '' && $itemSubtitle === '') continue;
                    $itemType = (string)($itemTypes[$i] ?? 'card');
                    if (!in_array($itemType, $allowedItemTypes, true)) $itemType = 'card';
                    $item = [
                        'item_type' => $itemType,
                        'title' => $itemTitle ?: 'Novo item',
                        'subtitle' => $itemSubtitle ?: null,
                        'description' => $itemDescription ?: null,
                        'icon' => trim((string)($itemIcons[$i] ?? '')) ?: null,
                        'image_path' => trim((string)($itemImages[$i] ?? '')) ?: null,
                        'badge' => trim((string)($itemBadges[$i] ?? '')) ?: null,
                        'link_url' => trim((string)($itemLinks[$i] ?? '')) ?: null,
                        'meta_data' => null,
                        'display_order' => $i + 1,
                        'status' => $status,
                        'is_visible' => 1
                    ];
                    $draftItems[] = $item;
                    if ($action === 'publish') {
                        $itemInsert->execute([$sectionId, $itemType, $item['title'], $item['subtitle'], $item['description'], $item['icon'], $item['image_path'], $item['badge'], $item['link_url'], $i + 1, 'published', 1]);
                    }
                }
                if ($action === 'publish') {
                    $pdo->commit();
                    $sectionItems = ensureSectionItems($pdo, (int)$sectionId, (string)($section['identifier'] ?? $identifier ?? ''));
                } else {
                    $sectionItems = $draftItems;
                }

                $snapshotItems = $draftItems;
                if ($action === 'publish') {
                    $snapshotStmt = $pdo->prepare('SELECT item_type,title,subtitle,description,icon,image_path,badge,link_url,meta_data,display_order,status,is_visible FROM section_items WHERE section_id = ? ORDER BY display_order,id');
                    $snapshotStmt->execute([$sectionId]);
                    $snapshotItems = $snapshotStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $payload = json_encode([
                    'identifier' => $section['identifier'] ?? '',
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'description' => $description,
                    'primary_btn_text' => $primaryBtnText,
                    'primary_btn_link' => $primaryBtnLink,
                    'secondary_btn_text' => $secondaryBtnText,
                    'secondary_btn_link' => $secondaryBtnLink,
                    'image_path' => $imagePath,
                    'badge' => $badge,
                    'video_path' => $videoPath,
                    'is_visible' => $isVisible,
                    'status' => $status,
                    'style' => $styleValues,
                    'section_items' => $snapshotItems
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($action === 'publish') {
                    $pdo->prepare('UPDATE sections SET draft_data = NULL, style_data = ? WHERE id = ?')
                        ->execute([json_encode($styleValues, JSON_UNESCAPED_UNICODE), $sectionId]);
                } else {
                    $pdo->prepare('UPDATE sections SET draft_data = ? WHERE id = ?')->execute([$payload, $sectionId]);
                }

                if ($action === 'publish' || $unscheduleRequested) {
                    $pdo->prepare('UPDATE sections SET publish_at = NULL WHERE id = ?')->execute([$sectionId]);
                    $section['publish_at'] = null;
                } elseif ($scheduleAt !== null) {
                    $pdo->prepare('UPDATE sections SET publish_at = ? WHERE id = ?')->execute([$scheduleAt, $sectionId]);
                    $section['publish_at'] = $scheduleAt;
                }

                $user = getCurrentUser();
                $userName = $user['name'] ?? 'Admin';
                $userId = (int)($user['id'] ?? 1);

                $actionType = ($action === 'publish') ? 'published' : 'saved_draft';
                $note = ($action === 'publish') ? 'Publicado pelo editor' : 'Rascunho salvo pelo editor';

                $stmtVer = $pdo->prepare("INSERT INTO versions (section_id, title, data_payload, user_id, user_name, action_type, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmtVer->execute([$sectionId, $title, $payload, $userId, $userName, $actionType, $note]);

                $auditAction = ($action === 'publish' ? 'publicou' : 'salvou rascunho');
                if ($scheduleAt !== null) $auditAction = 'agendou publicação';
                if ($unscheduleRequested) $auditAction = 'cancelou agendamento';
                logAudit($auditAction, "{$userName} {$auditAction} a seção: {$title}", $userId);
                createNotification(
                    $auditAction,
                    ($action === 'publish' ? 'Seção Publicada' : 'Rascunho Criado'),
                    "{$userName} {$auditAction} alterações na seção '{$title}'",
                    $sectionId,
                    $userId
                );

                applyHistoryRetention();

                $successMsg = $publishBlocked ? 'Rascunho salvo. A publicação no site precisa ser aprovada por um administrador.' : (($action === 'publish') ? 'Conteúdo publicado com sucesso no site principal!' : 'Rascunho salvo com sucesso!');
                if ($scheduleAt !== null) $successMsg = 'Publicação agendada para ' . (new DateTime($scheduleAt))->format('d/m/Y \\à\\s H:i') . '. O conteúdo atual ficou salvo como rascunho e será publicado automaticamente.';
                if ($unscheduleRequested) $successMsg = 'Agendamento cancelado. O conteúdo continua salvo como rascunho.';

                $section['title'] = $title;
                $section['description'] = $description;
                $section['subtitle'] = $subtitle;
                $section['badge'] = trim($_POST['badge'] ?? ($section['badge'] ?? ''));
                $section['video_path'] = $videoPath;
                $section['is_visible'] = isset($_POST['is_visible']) ? 1 : 0;
                $section['primary_btn_text'] = $primaryBtnText;
                $section['primary_btn_link'] = $primaryBtnLink;
                $section['secondary_btn_text'] = $secondaryBtnText;
                $section['secondary_btn_link'] = $secondaryBtnLink;
                $section['image_path'] = $imagePath;
                if ($action === 'publish') $section['status'] = 'published';
                $section['draft_data'] = ($action === 'publish') ? null : $payload;
                $pageTitle = $title;
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'Erro ao salvar alterações: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<!-- Header do Editor -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">
                <?= e($section['identifier']) ?>
            </span>
            <span class="text-xs text-slate-400">ID #<?= (int)$section['id'] ?></span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight" id="headerTitle"><?= e($section['title']) ?></h1>
        <p class="text-sm text-slate-500 mt-0.5">Faça alterações e veja o resultado dinamicamente no preview ao lado.</p>
    </div>

    <div class="flex items-center gap-3">
        <a href="<?= ADMIN_URL ?>/content.php" class="px-4 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition">
            Voltar
        </a>
        <a href="<?= SITE_URL ?>/index.php#<?= e($section['identifier']) ?>" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl shadow-sm transition">
            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
            <span>Ver no site público</span>
        </a>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3">
        <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600"></i>
        <span><?= e($successMsg) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-3">
        <i data-lucide="alert-circle" class="w-5 h-5 text-red-600"></i>
        <span><?= e($errorMsg) ?></span>
    </div>
<?php endif; ?>

<style>
.si-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.editor-guide{padding:14px 16px;border:1px solid #e0e7ff;border-radius:16px;background:linear-gradient(135deg,#f8faff,#f5f3ff);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.editor-guide-copy{display:flex;gap:10px;align-items:flex-start}.editor-guide-icon{width:30px;height:30px;border-radius:10px;background:#4f46e5;color:#fff;display:grid;place-items:center;font-size:14px;flex:none}.editor-guide b{display:block;font-size:12px;color:#312e81}.editor-guide p{margin:2px 0 0;font-size:11px;line-height:1.45;color:#64748b}.editor-steps{display:flex;align-items:center;gap:5px;flex-wrap:wrap}.editor-step{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:999px;background:#fff;border:1px solid #e0e7ff;color:#475569;font-size:10.5px;font-weight:700;text-decoration:none}.editor-step i{font-style:normal;display:grid;place-items:center;width:15px;height:15px;border-radius:50%;background:#e0e7ff;color:#4338ca;font-size:9px}.editor-step:hover{border-color:#a5b4fc;color:#4338ca}
.si-head-actions{display:flex;gap:8px;flex-shrink:0}
.si-head-copy{max-width:560px}.si-head-copy p{line-height:1.5}
.si-status{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:10px}.si-status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:10.5px;font-weight:700}.si-status-pill strong{color:#334155}.si-status-dot{width:6px;height:6px;border-radius:50%;background:#6366f1}.si-status-dot.ready{background:#10b981}.si-status-dot.warn{background:#f59e0b}
.si-btn-primary,.si-btn-ghost{font-size:12px;font-weight:600;padding:8px 14px;border-radius:10px;border:1px solid transparent;cursor:pointer;transition:background .15s,border-color .15s}
.si-btn-primary{background:#4f46e5;color:#fff}
.si-btn-primary:hover{background:#4338ca}
.si-btn-ghost{background:#fff;color:#475569;border-color:#e2e8f0}
.si-btn-ghost:hover{background:#f8fafc}
.si-btn-primary:focus-visible,.si-btn-ghost:focus-visible,.si-chip:focus-visible,.si-icon-btn:focus-visible,.si-summary:focus-visible{outline:2px solid #6366f1;outline-offset:2px}

.si-filters{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.si-filters[hidden]{display:none}
.si-chip{font-size:11px;font-weight:600;padding:5px 11px;border-radius:999px;border:1px solid #e2e8f0;background:#fff;color:#475569;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.si-chip span{font-weight:500;color:#94a3b8}
.si-chip:hover{border-color:#c7d2fe}
.si-chip[aria-pressed="true"]{background:#eef2ff;border-color:#a5b4fc;color:#3730a3}
.si-chip[aria-pressed="true"] span{color:#6366f1}

.si-list{display:flex;flex-direction:column;gap:8px}
.si-empty{border:1px dashed #cbd5e1;border-radius:14px;padding:28px;text-align:center;background:#f8fafc}

.section-item-row{border:1px solid #e2e8f0;border-radius:14px;background:#fff;transition:border-color .15s,box-shadow .15s}
.section-item-row:hover{border-color:#cbd5e1}
.section-item-row.is-open{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(99,102,241,.08)}
.section-item-row.is-new{animation:siFlash 1.2s ease-out}
@keyframes siFlash{from{background:#eef2ff}to{background:#fff}}
@media (prefers-reduced-motion:reduce){.section-item-row.is-new{animation:none}}

.si-row-head{display:flex;align-items:center;gap:4px;padding-right:8px}
.si-summary{flex:1;min-width:0;display:flex;align-items:center;gap:12px;padding:10px 8px 10px 12px;background:none;border:0;border-radius:14px;text-align:left;cursor:pointer;font:inherit;color:inherit}
.si-thumb{width:40px;height:40px;border-radius:10px;background:#f1f5f9;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;overflow:hidden}
.si-thumb img{width:100%;height:100%;object-fit:cover}
.si-text{min-width:0;flex:1}
.si-title{display:block;font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.si-title.is-empty{color:#94a3b8;font-weight:500}
.si-sub{display:block;font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.si-type{font-size:10.5px;font-weight:600;color:#4338ca;background:#eef2ff;padding:3px 8px;border-radius:6px;white-space:nowrap;flex-shrink:0}
.si-chevron{display:block;width:16px;height:16px;color:#94a3b8;transition:transform .15s}
.si-summary>span:last-child{display:flex;flex-shrink:0}
.is-open .si-chevron{transform:rotate(90deg)}
.si-tools{display:flex;gap:2px;flex-shrink:0}
.si-icon-btn{width:30px;height:30px;border-radius:8px;border:0;background:none;color:#64748b;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
.si-icon-btn:hover{background:#f1f5f9;color:#0f172a}
.si-icon-btn.danger:hover{background:#fff1f2;color:#be123c}
.si-icon-btn:disabled{opacity:.3;cursor:default;background:none}
.si-icon-btn svg{width:15px;height:15px}

.si-body{padding:4px 16px 16px;border-top:1px solid #f1f5f9;margin:0 0 0;display:none}
.is-open .si-body{display:block}
.si-editor-note{display:flex;align-items:center;gap:7px;margin-top:13px;padding:8px 10px;border-radius:9px;background:#f8fafc;color:#64748b;font-size:11px}.si-editor-note b{color:#475569}
.si-field-tools{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:3px}.si-counter{font-size:10.5px;color:#94a3b8;font-variant-numeric:tabular-nums}.si-counter.is-long{color:#b45309}.si-required{color:#e11d48;font-size:11px}
.si-complete{font-size:10px;font-weight:700;color:#b45309;background:#fffbeb;border:1px solid #fde68a;padding:3px 7px;border-radius:999px;white-space:nowrap}.si-complete.ready{color:#047857;background:#ecfdf5;border-color:#a7f3d0}
.si-row-head,.si-summary,.si-body,.si-grid,.si-field{box-sizing:border-box}
.si-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}
.si-field{display:flex;flex-direction:column;gap:5px;min-width:0}
.si-field.full{grid-column:1 / -1}
.si-field label{font-size:11.5px;font-weight:600;color:#334155}
.si-field .hint{font-size:11px;color:#94a3b8;margin-top:-1px}
.si-field input,.si-field select,.si-field textarea{box-sizing:border-box;width:100%;font-size:13px;padding:8px 11px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;color:#0f172a;font-family:inherit}
.si-field textarea{resize:vertical;min-height:84px;line-height:1.5}
.si-field input:focus,.si-field select:focus,.si-field textarea:focus{outline:none;border-color:#6366f1;background:#fff;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.si-field[hidden]{display:none}

.si-more{margin-top:14px;border-top:1px dashed #e2e8f0;padding-top:10px}
.si-more>summary{font-size:11.5px;font-weight:600;color:#64748b;cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:6px}
.si-more>summary::-webkit-details-marker{display:none}
.si-more>summary:hover{color:#4338ca}
.si-more[hidden]{display:none}
.si-more .si-grid{margin-top:12px}

@media (max-width:640px){
  .si-grid{grid-template-columns:1fr}
  .si-type{display:none}
  .si-row-head{flex-wrap:wrap;padding-right:0}
  .si-summary{flex:1 1 100%}
  .si-tools{display:none;width:100%;justify-content:flex-end;padding:0 10px 8px;box-sizing:border-box}
  .is-open .si-tools{display:flex}
  .si-head-actions{width:100%}
  .si-head-actions button{flex:1}
  .editor-guide{align-items:flex-start}.editor-steps{width:100%}
}
</style>

<!-- Formulário Principal e Preview ao Lado -->
<form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">
    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

    <!-- Coluna Esquerda: Formulário de Edição (7 Colunas) -->
    <div class="lg:col-span-7 min-w-0 bg-white rounded-3xl p-5 sm:p-8 border border-slate-200 shadow-sm space-y-6">
        <?php if (!empty($isLockedByOther)): ?>
        <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-center gap-3">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 shrink-0"></i>
            <div>
                <strong class="font-bold">Aviso de edição simultânea:</strong>
                <p class="mt-0.5"><strong><?= e($lockedByUserName) ?></strong> está editando esta seção no momento. Para evitar conflitos, combine as alterações antes de publicar.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="editor-guide" aria-label="Como editar esta seção">
            <div class="editor-guide-copy"><span class="editor-guide-icon">✦</span><div><b>Edite em quatro etapas simples</b><p>As alterações aparecem no preview ao lado e só chegam ao site quando você publicar.</p></div></div>
            <nav class="editor-steps" aria-label="Ir para uma etapa"><a class="editor-step" href="#mainContentFields"><i>1</i>Conteúdo</a><a class="editor-step" href="#sectionItemsEditor"><i>2</i>Itens</a><a class="editor-step" href="#sxPanel"><i>3</i>Aparência</a><a class="editor-step" href="#editorActions"><i>4</i>Salvar</a></nav>
        </div>

        <div id="mainContentFields">
            <h2 class="text-base font-bold text-slate-900">1. Conteúdo principal</h2>
            <p class="text-xs text-slate-400 mt-0.5">Defina o título, textos, chamadas e a visibilidade da seção.</p>
        </div>

        <?php if ($isNewSection): ?>
        <div><label class="block text-xs font-semibold text-slate-700 mb-2">Identificador da seção (âncora do site)</label><input type="text" name="identifier" value="" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl" placeholder="ex.: galeria, equipe, contato"></div>
        <?php endif; ?>
        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-2">Título Principal</label>
            <input type="text" id="inputTitle" name="title" value="<?= e($section['title']) ?>" required class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Título da seção...">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-2">Subtítulo / Descrição</label>
            <textarea id="inputSubtitle" name="subtitle" rows="2" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Subtítulo curto..."><?= e($section['subtitle'] ?? '') ?></textarea>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-2">Descrição completa exibida no site</label>
            <textarea name="description" rows="4" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Texto completo da seção..."><?= e($section['description'] ?? '') ?></textarea>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-2">Etiqueta / destaque</label>
                <input type="text" id="inputBadge" name="badge" value="<?= e($section['badge'] ?? '') ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl" placeholder="Ex.: SEMANA CULTURAL">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-2">Caminho do vídeo (opcional)</label>
                <input type="text" id="inputVideo" name="video_path" value="<?= e($section['video_path'] ?? '') ?>" oninput="previewSelectedVideo(this.value)" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl" placeholder="assets/img/video.mp4">
            </div>
        </div>
        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700"><input type="checkbox" name="is_visible" value="1" <?= !empty($section['is_visible']) ? 'checked' : '' ?>> Exibir esta seção no site público</label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-2">Texto do botão principal</label>
                <input type="text" id="inputBtn1Text" name="primary_btn_text" value="<?= e($section['primary_btn_text'] ?? '') ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Ex.: Explorar Projeto">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-2">Link do botão principal</label>
                <input type="text" name="primary_btn_link" value="<?= e($section['primary_btn_link'] ?? ($isNewSection ? '' : '#sobre')) ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Ex.: #sobre ou https://...">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-2">Texto do segundo botão</label>
                <input type="text" id="inputBtn2Text" name="secondary_btn_text" value="<?= e($section['secondary_btn_text'] ?? '') ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Ex.: Saiba Mais">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-2">Link do segundo botão</label>
                <input type="text" name="secondary_btn_link" value="<?= e($section['secondary_btn_link'] ?? ($isNewSection ? '' : '#mapa')) ?>" class="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="Ex.: #mapa ou https://...">
            </div>
        </div>

        <?php
        $itemTypeMeta = [
            'card'        => ['Card',                 'Pesquisa, Cultura…',            ['icon','description']],
            'cultural_card'=> ['Card cultural',       'Imagem + texto',                ['icon','image','subtitle','description']],
            'timeline'    => ['Etapa do cronograma',  'Data + descrição',              ['subtitle','description']],
            'knowledge'   => ['Área de conhecimento', 'Tópicos separados por ;',      ['icon','description']],
            'business'    => ['Produto principal',    'Imagem + descrição',            ['image','description']],
            'feature'     => ['Destaque',             'Ícone + frase curta',           ['icon','description']],
            'model'       => ['Modelo / lista',       'Tópicos separados por ;',       ['description']],
            'objective'   => ['Objetivo',             'Item de lista',                 ['description']],
            'competency'  => ['Competência',          'Item de lista',                 ['description']],
            'stat'        => ['Estatística',          'Número + legenda',              ['description']],
            'map_marker'  => ['Marcador do mapa',     'Posição + curiosidade',         ['icon','subtitle','badge','link','description']],
            'custom'      => ['Personalizado',        'Todos os campos',               ['icon','image','subtitle','badge','link','description']],
        ];
        $fieldLabels = [
            'subtitle'    => ['Subtítulo / data', ''],
            'icon'        => ['Ícone / emoji', ''],
            'image'       => ['Caminho da imagem', 'assets/img/…'],
            'badge'       => ['Etiqueta', ''],
            'link'        => ['Link / posição no mapa', 'ex.: 28%,55%'],
            'description' => ['Descrição / conteúdo', ''],
        ];
        ?>
        <script type="application/json" id="itemTypeMeta"><?= json_encode(['types' => $itemTypeMeta, 'fields' => $fieldLabels], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

        <div class="pt-6 border-t border-slate-100" id="sectionItemsEditor">
            <div class="si-head">
                <div class="si-head-copy">
                    <h2 class="text-base font-bold text-slate-900">2. Conteúdo interno da seção</h2>
                    <p class="text-xs text-slate-500 mt-1">Cards, etapas, listas e destaques exibidos dentro desta seção. Clique em um item para editar e use os controles à direita para organizar a sequência.</p>
                    <div class="si-status" id="siStatus" aria-live="polite"></div>
                </div>
                <div class="si-head-actions">
                    <button type="button" id="siToggleAll" class="si-btn-ghost">Expandir todos</button>
                    <button type="button" id="siAdd" class="si-btn-primary">+ Adicionar item</button>
                </div>
            </div>

            <div id="siFilters" class="si-filters" role="toolbar" aria-label="Filtrar itens por tipo"></div>

            <div id="sectionItemsList" class="si-list">
                <?php foreach ($sectionItems as $item): ?>
                <div class="section-item-row" data-type="<?= e($item['item_type'] ?? 'card') ?>">
                    <input type="hidden" name="item_type[]" value="<?= e($item['item_type'] ?? 'card') ?>">
                    <input type="hidden" name="item_title[]" value="<?= e($item['title'] ?? '') ?>">
                    <input type="hidden" name="item_subtitle[]" value="<?= e($item['subtitle'] ?? '') ?>">
                    <input type="hidden" name="item_icon[]" value="<?= e($item['icon'] ?? '') ?>">
                    <input type="hidden" name="item_image_path[]" value="<?= e($item['image_path'] ?? '') ?>">
                    <input type="hidden" name="item_badge[]" value="<?= e($item['badge'] ?? '') ?>">
                    <input type="hidden" name="item_link_url[]" value="<?= e($item['link_url'] ?? '') ?>">
                    <textarea name="item_description[]" hidden><?= e($item['description'] ?? '') ?></textarea>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="siEmpty" class="si-empty" hidden>
                <p class="font-semibold text-slate-700">Nenhum item nesta seção</p>
                <p class="text-xs text-slate-500 mt-1">Adicione um card, etapa ou destaque para ele aparecer no site.</p>
            </div>
        </div>

        <?php
            $sxChoices = sectionStyleChoices();
            $sxGlyph = [
                'text_align' => ['left' => '⇤', 'center' => '↔', 'right' => '⇥'],
            ];
            $sxChips = function (string $name, string $label, string $hint = '') use ($sxChoices, $styleValues, $sxGlyph) {
                echo '<div class="sxp-field"><span class="sxp-label">' . e($label) . '</span><div class="sxp-chips" role="radiogroup" aria-label="' . e($label) . '">';
                foreach ($sxChoices[$name] as $value => $text) {
                    $glyph = $sxGlyph[$name][$value] ?? '';
                    echo '<label><input type="radio" name="sx[' . e($name) . ']" value="' . e((string)$value) . '"' . ((string)$styleValues[$name] === (string)$value ? ' checked' : '') . '><span>' . ($glyph !== '' ? e($glyph) . ' ' : '') . e($text) . '</span></label>';
                }
                echo '</div>' . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</div>';
            };
            $sxSelect = function (string $name, string $label, ?array $options = null) use ($sxChoices, $styleValues) {
                $options = $options ?? $sxChoices[$name];
                echo '<label class="sxp-field"><span class="sxp-label">' . e($label) . '</span><select name="sx[' . e($name) . ']">';
                foreach ($options as $value => $text) {
                    echo '<option value="' . e((string)$value) . '"' . ((string)$styleValues[$name] === (string)$value ? ' selected' : '') . '>' . e($text) . '</option>';
                }
                echo '</select></label>';
            };
            $sxColor = function (string $name, string $label, string $hint = '') use ($styleValues) {
                $v = $styleValues[$name];
                echo '<div class="sxp-field sxp-color" data-field="' . e($name) . '"><span class="sxp-label">' . e($label) . '</span><div class="sxp-color-row">'
                   . '<input type="color" class="sxp-picker" value="' . e($v !== '' ? $v : '#888888') . '" aria-label="' . e($label) . '">'
                   . '<input type="text" class="sxp-hex" name="sx[' . e($name) . ']" value="' . e($v) . '" placeholder="padrão" maxlength="7" spellcheck="false" autocomplete="off">'
                   . '<button type="button" class="sxp-clear" title="Voltar ao padrão do site" aria-label="Limpar ' . e($label) . '">×</button></div>'
                   . '<div class="sxp-swatches"></div>'
                   . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</div>';
            };
            $sxFontOptions = ['' => 'Padrão do site'];
            foreach (SX_FONTS as $fk => $fv) { $sxFontOptions[$fk] = $fv['label']; }

            $sxMedia = [];
            if ($pdo) {
                try {
                    $mq = $pdo->query("SELECT filepath, filename, mime_type FROM media WHERE mime_type LIKE 'image/%' OR mime_type LIKE 'video/%' OR file_type = 'video' ORDER BY created_at DESC LIMIT 60");
                    foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $mrow) {
                        $rawPath = trim((string)$mrow['filepath']);
                        $absolutePath = __DIR__ . '/../' . ltrim($rawPath, '/');
                        if (!empty($rawPath) && file_exists($absolutePath)) {
                            $sxMedia[] = [
                                'filepath' => $rawPath,
                                'filename' => $mrow['filename'],
                                'is_video' => str_starts_with($mrow['mime_type'] ?? '', 'video/') || str_ends_with(strtolower($rawPath), '.mp4')
                            ];
                        }
                    }
                } catch (Throwable $e) { 
                    error_log('Erro ao listar mídia para o fundo: ' . $e->getMessage()); 
                }
            }
            $sxTabs = ['layout' => ['📐', 'Layout'], 'midia' => ['🖼️', 'Imagem'], 'fundo' => ['🖼️', 'Fundo'], 'texto' => ['🔤', 'Texto'], 'cores' => ['🎨', 'Cores'], 'cards' => ['🃏', 'Cards e botões'], 'efeitos' => ['✨', 'Efeitos']];
            $sxPresets = [
                'clean' => ['Claro elegante', 'linear-gradient(135deg,#ffffff,#e2e8f0)'], 'dark' => ['Escuro', 'linear-gradient(135deg,#0b1020,#1e293b)'],
                'gradient' => ['Degradê', 'linear-gradient(135deg,#4f46e5,#9333ea)'], 'soft' => ['Suave', 'linear-gradient(135deg,#f0f9ff,#e0e7ff)'],
                'editorial' => ['Editorial', 'linear-gradient(135deg,#faf7f2,#e7dccb)'], 'impact' => ['Impacto', 'linear-gradient(135deg,#111111,#ef4444)'],
                'ocean' => ['Oceano', 'linear-gradient(135deg,#0369a1,#0f766e)'], 'sunset' => ['Pôr do sol', 'linear-gradient(135deg,#c2410c,#be185d)'],
            ];
        ?>
        <style>
            .sxp{border:1px solid #e2e8f0;border-radius:1.25rem;background:#fff;overflow:hidden;overflow-anchor:none;box-shadow:0 10px 30px -12px rgba(79,70,229,.25)}
            .sxp>summary{cursor:pointer;list-style:none;padding:1.05rem 1.2rem;color:#fff;display:flex;align-items:center;gap:.7rem;background:linear-gradient(120deg,#4f46e5,#9333ea 60%,#ec4899)}
            .sxp>summary::-webkit-details-marker{display:none}
            .sxp>summary b{font-size:.92rem;letter-spacing:.01em}
            .sxp>summary small{color:rgba(255,255,255,.85);font-size:.72rem;font-weight:500}
            .sxp>summary::after{content:"▾";margin-left:auto;transition:transform .2s}
            .sxp[open]>summary::after{transform:rotate(180deg)}
            .sxp-body{padding:1.1rem 1.2rem 1.25rem;display:flex;flex-direction:column;gap:1rem;min-width:0;overflow-anchor:none}
            .sxp-presets{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:.5rem}
            .sxp-preset{display:flex;align-items:center;gap:.5rem;border:1px solid #e2e8f0;background:#fff;border-radius:.8rem;padding:.4rem .6rem;font-size:.72rem;font-weight:700;color:#334155;cursor:pointer;transition:all .15s}
            .sxp-preset i{width:1.4rem;height:1.4rem;border-radius:.45rem;flex:none;box-shadow:inset 0 0 0 1px rgba(15,23,42,.12)}
            .sxp-preset:hover{border-color:#818cf8;transform:translateY(-1px);box-shadow:0 6px 14px -6px rgba(79,70,229,.45)}
            .sxp-tabs{display:flex;gap:.35rem;overflow-x:auto;padding:.3rem;background:#f1f5f9;border-radius:.9rem;scrollbar-width:none}
            .sxp-tabs::-webkit-scrollbar{display:none}
            .sxp-tab{flex:1 0 auto;border:0;background:transparent;border-radius:.65rem;padding:.5rem .8rem;font-size:.75rem;font-weight:700;color:#64748b;cursor:pointer;white-space:nowrap;display:flex;gap:.4rem;align-items:center;justify-content:center;transition:all .15s}
            .sxp-tab:hover{color:#0f172a}
            .sxp-tab[aria-selected="true"]{background:#fff;color:#4f46e5;box-shadow:0 2px 8px -2px rgba(15,23,42,.18)}
            /*
             * As abas ficam dentro de um formulário em grade, ao lado de um preview sticky.
             * Sem uma área reservada, cada display:flex alterava a altura da coluna inteira e
             * o contêiner principal (que tem rolagem própria) ancorava o scroll em outro ponto.
             * A rolagem passa a ser local ao conteúdo da aba, sem deslocar editor/preview.
             */
            .sxp-pane{display:none;flex-direction:column;gap:1rem;min-width:0;max-width:100%;overflow:hidden;overflow-anchor:none}
            /* A aba ativa usa altura natural até o limite; evita forçar um bloco vazio e deslocar o scroll da página. */
            .sxp-pane.is-active{display:flex;height:auto;min-height:0;max-height:clamp(330px,52vh,590px);overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;overscroll-behavior-y:contain;padding:.15rem .2rem .5rem;scrollbar-gutter:stable;overflow-anchor:none}
            .sxp-pane.is-active::-webkit-scrollbar{width:6px}.sxp-pane.is-active::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
            .sxp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.9rem}
            .sxp-wide{grid-column:1/-1}
            .sxp-field{display:flex;flex-direction:column;gap:.35rem;min-width:0}
            .sxp-label{font-size:.7rem;font-weight:800;color:#475569;letter-spacing:.02em}
            .sxp-field small{font-weight:400;color:#94a3b8;font-size:.66rem}
            .sxp-field select,.sxp-hex{width:100%;padding:.55rem .65rem;border:1px solid #e2e8f0;border-radius:.7rem;background:#f8fafc;font-size:.8rem;color:#0f172a}
            .sxp-field select:focus,.sxp-hex:focus{outline:2px solid #818cf8;outline-offset:1px;background:#fff}
            .sxp-chips{display:flex;flex-wrap:wrap;gap:.3rem;position:relative}
            .sxp-chips label{cursor:pointer;position:relative;display:inline-block}
            .sxp-chips input{display:none!important}
            .sxp-chips span{display:block;padding:.4rem .7rem;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;font-size:.72rem;font-weight:600;color:#475569;transition:all .12s;user-select:none}
            .sxp-chips label:hover span{border-color:#a5b4fc}
            .sxp-chips input:checked+span{background:#4f46e5;border-color:#4f46e5;color:#fff;box-shadow:0 4px 10px -4px rgba(79,70,229,.6)}
            .sxp-layouts{display:grid;grid-template-columns:repeat(auto-fit,minmax(105px,1fr));gap:.5rem}.sxp-layout{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:66px;cursor:pointer;border:1px solid #e2e8f0;border-radius:.75rem;padding:.5rem;background:#f8fafc;text-align:center;font-size:.68rem;font-weight:700;color:#475569;overflow:hidden}.sxp-layout input{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer;z-index:2}.sxp-layout:focus-within{outline:2px solid #818cf8;outline-offset:2px}.sxp-layout:has(input:checked){border-color:#4f46e5;background:#eef2ff;color:#4338ca;box-shadow:0 0 0 2px rgba(79,70,229,.12)}/* Miniaturas ilustrativas dos modelos de conteúdo */
            .sxp-layout-icon{width:100%;height:32px;display:flex;flex-direction:row;gap:3px;align-items:center;justify-content:center;margin:0 0 4px;pointer-events:none}
            .sxp-layout-icon i{display:block;flex:none;background:#c7d2fe;border-radius:2px}
            .sxp-layout-icon .m{width:32%;height:100%}
            .sxp-layout-icon .t{width:48%;height:58%;background:#94a3b8}
            .sxp-layout.left .sxp-layout-icon{flex-direction:row}
            .sxp-layout.right .sxp-layout-icon{flex-direction:row-reverse}
            .sxp-layout.top .sxp-layout-icon,.sxp-layout.bottom .sxp-layout-icon{height:32px;flex-direction:column;gap:2px}
            .sxp-layout.top .sxp-layout-icon .m,.sxp-layout.bottom .sxp-layout-icon .m{width:72%;height:45%}
            .sxp-layout.top .sxp-layout-icon .t,.sxp-layout.bottom .sxp-layout-icon .t{width:48%;height:30%}
            .sxp-layout.bottom .sxp-layout-icon{flex-direction:column-reverse}
            .sxp-layout.text .sxp-layout-icon{opacity:.9}
            .sxp-color-row{display:flex;gap:.35rem;align-items:center}
            .sxp-picker{width:2.5rem;height:2.25rem;padding:2px;border:1px solid #e2e8f0;border-radius:.6rem;background:#fff;cursor:pointer;flex:none}
            .sxp-clear{border:1px solid #e2e8f0;background:#fff;border-radius:.6rem;width:1.9rem;height:2.25rem;cursor:pointer;color:#94a3b8;flex:none}
            .sxp-clear:hover{color:#dc2626;border-color:#fecaca}
            .sxp-swatches{display:flex;flex-wrap:wrap;gap:.25rem}
            .sxp-sw{width:1.15rem;height:1.15rem;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px #cbd5e1;cursor:pointer;padding:0;transition:transform .12s}
            .sxp-sw:hover{transform:scale(1.25)}
            .sxp-media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:.5rem;max-height:15rem;overflow:auto;padding:.2rem}
            .sxp-media{position:relative;cursor:pointer;border-radius:.7rem;overflow:hidden;border:2px solid transparent;background:#f1f5f9;aspect-ratio:4/3;display:block}
            .sxp-media input{display:none!important}
            .sxp-media img{width:100%;height:100%;object-fit:cover;display:block}
            .sxp-media em{position:absolute;inset:auto 0 0 0;background:linear-gradient(transparent,rgba(15,23,42,.8));color:#fff;font-size:.58rem;font-style:normal;padding:.9rem .3rem .2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .sxp-media.none{display:grid;place-items:center;font-size:.7rem;font-weight:700;color:#64748b}
            .sxp-media:has(input:checked){border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.25)}
            .sxp-range{display:flex;align-items:center;gap:.6rem}
            .sxp-range input{flex:1;accent-color:#4f46e5}
            .sxp-range output{font-size:.75rem;font-weight:800;color:#4f46e5;min-width:2.6rem;text-align:right}
            .sxp-switch{display:flex;align-items:center;gap:.6rem;font-size:.78rem;font-weight:600;color:#334155;cursor:pointer}
            .sxp-switch input{appearance:none;width:2.4rem;height:1.35rem;border-radius:999px;background:#cbd5e1;position:relative;transition:background .15s;flex:none;cursor:pointer}
            .sxp-switch input::after{content:"";position:absolute;top:2px;left:2px;width:1.05rem;height:1.05rem;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .15s}
            .sxp-switch input:checked{background:#4f46e5}
            .sxp-switch input:checked::after{transform:translateX(1.05rem)}
            .sxp-foot{display:flex;flex-wrap:wrap;gap:.7rem;align-items:center;justify-content:space-between;border-top:1px dashed #e2e8f0;padding-top:.9rem}
            #sxContrast{font-size:.72rem;font-weight:600;flex:1;min-width:200px}
            #sxContrast.warn{color:#b45309}#sxContrast.ok{color:#15803d}#sxContrast.hint{color:#94a3b8;font-weight:500}
            .sxp-reset{border:1px solid #e2e8f0;background:#fff;border-radius:.7rem;padding:.45rem .9rem;font-size:.72rem;font-weight:700;color:#475569;cursor:pointer}
            .sxp-reset:hover{color:#dc2626;border-color:#fecaca}
            .sxp [hidden]{display:none!important}
            @media (max-width:767px){.sxp-pane.is-active{height:auto;min-height:0;max-height:70vh}.sxp-tabs{position:relative;top:auto;z-index:1}}
        </style>

        <details class="sxp" id="sxPanel" <?= !sectionStyleIsEmpty($styleValues) ? 'open' : '' ?>>
            <summary><span style="font-size:1.2rem">🎨</span><b>3. Aparência e layout</b><small>posição, fontes, cores, fundo, cards e efeitos — com preview ao vivo</small></summary>
            <div class="sxp-body">
                <div>
                    <p class="sxp-label" style="margin:0 0 .45rem">Estilos prontos <span style="font-weight:500;color:#94a3b8">(ponto de partida — ajuste depois)</span></p>
                    <div class="sxp-presets">
                        <?php foreach ($sxPresets as $pk => $pv): ?>
                            <button type="button" class="sxp-preset" data-preset="<?= e($pk) ?>"><i style="background:<?= e($pv[1]) ?>"></i><?= e($pv[0]) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sxp-tabs" role="tablist" aria-label="Grupos de aparência">
                    <?php $first = true; foreach ($sxTabs as $tk => $tv): ?>
                        <button type="button" class="sxp-tab" role="tab" data-tab="<?= e($tk) ?>" aria-controls="sxp-pane-<?= e($tk) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" tabindex="<?= $first ? '0' : '-1' ?>"><span aria-hidden="true"><?= $tv[0] ?></span> <?= e($tv[1]) ?></button>
                    <?php $first = false; endforeach; ?>
                </div>

                <!-- LAYOUT -->
                <div class="sxp-pane is-active" id="sxp-pane-layout" data-pane="layout" role="tabpanel">
                    <div class="sxp-grid">
                        <div class="sxp-wide"><?php $sxChips('text_align', 'Alinhamento do conteúdo'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('content_width', 'Largura do conteúdo', 'Limita a largura do texto e dos cards; "Tela cheia" ocupa toda a largura.'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('padding_y', 'Espaço acima e abaixo da seção'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('min_height', 'Altura mínima da seção'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('header_gap', 'Distância entre o título e o conteúdo'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('grid_cols', 'Cards por linha', 'Em telas menores a grade reduz sozinha.'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('grid_gap', 'Espaço entre os cards'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('hide_on', 'Visibilidade por dispositivo'); ?></div>
                    </div>
                </div>

                <!-- IMAGEM NO CONTEÚDO -->
                <div class="sxp-pane" id="sxp-pane-midia" data-pane="midia" role="tabpanel" hidden>
                    <div class="sxp-grid">
                        <div class="sxp-wide"><p class="sxp-label">Imagem dentro do conteúdo</p><small>Esta imagem é um elemento real do layout, independente do fundo da seção.</small></div>
                        <div class="sxp-wide sxp-field"><span class="sxp-label">Modelo visual</span><div class="sxp-layouts" role="radiogroup" aria-label="Modelo visual"><?php foreach (['' => ['Texto', 'text'], 'image-left' => ['Imagem à esquerda', 'left'], 'image-right' => ['Imagem à direita', 'right'], 'image-top' => ['Imagem acima', 'top'], 'image-bottom' => ['Texto acima', 'bottom']] as $lv => $ld): ?><label class="sxp-layout <?= e($ld[1]) ?>"><input type="radio" name="sx[content_layout]" value="<?= e($lv) ?>" <?= $styleValues['content_layout'] === $lv ? 'checked' : '' ?>><span class="sxp-layout-icon"><i class="m"></i><i class="t"></i></span><?= e($ld[0]) ?></label><?php endforeach; ?></div><small>Imagem e texto lado a lado se reorganizam automaticamente no celular.</small></div>
                        <div class="sxp-wide">
                            <span class="sxp-label">Selecionar imagem da biblioteca</span>
                            <div class="sxp-media-grid" style="margin-top:.4rem">
                                <label class="sxp-media none"><input type="radio" name="image_path" value="" <?= empty($section['image_path']) ? 'checked' : '' ?>>Remover</label>
                                <?php foreach ($sxMedia as $mrow): if ($mrow['is_video']) continue; $imgFilepath = $mrow['filepath']; $thumbRel = preg_replace('/\.[A-Za-z0-9]+$/', '-thumb.webp', $imgFilepath); $thumbUrl = file_exists(__DIR__ . '/../' . $thumbRel) ? SITE_URL . '/' . $thumbRel : SITE_URL . '/' . ltrim($imgFilepath, '/'); ?>
                                    <label class="sxp-media" title="<?= e($mrow['filename']) ?>"><input type="radio" name="image_path" value="<?= e($imgFilepath) ?>" <?= ($section['image_path'] ?? '') === $imgFilepath ? 'checked' : '' ?>><img src="<?= e($thumbUrl) ?>" alt="" loading="lazy"><em><?= e($mrow['filename']) ?></em></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="sxp-wide"><?php $sxChips('media_width', 'Largura da imagem'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('media_ratio', 'Proporção da imagem'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('media_fit', 'Ajuste da imagem'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('media_radius', 'Cantos da imagem'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('content_gap', 'Espaço entre imagem e conteúdo'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('content_vertical', 'Alinhamento vertical'); ?></div>
                    </div>
                </div>

                <!-- FUNDO -->
                <div class="sxp-pane" id="sxp-pane-fundo" data-pane="fundo" role="tabpanel" hidden>
                    <div class="sxp-grid">
                        <div class="sxp-wide"><?php $sxChips('bg_mode', 'Cor de fundo'); ?></div>
                        <div data-show="bg_mode:solid,gradient"><?php $sxColor('bg_color', 'Cor do fundo'); ?></div>
                        <div data-show="bg_mode:gradient"><?php $sxColor('bg_color2', 'Segunda cor do degradê'); ?></div>
                        <div data-show="bg_mode:gradient" class="sxp-wide"><?php $sxChips('bg_direction', 'Direção do degradê'); ?></div>

                        <div class="sxp-wide">
                            <span class="sxp-label">Mídia de fundo <span style="font-weight:500;color:#94a3b8">(Imagens e Vídeos da Biblioteca)</span></span>
                            <div class="sxp-media-grid" style="margin-top:.4rem">
                                <label class="sxp-media none"><input type="radio" name="sx[bg_image]" value="" <?= ($styleValues['bg_image'] === '' && empty($section['video_path'])) ? 'checked' : '' ?>>Nenhuma</label>
                                <?php foreach ($sxMedia as $mrow):
                                    $imgFilepath = $mrow['filepath'];
                                    $isVideo = $mrow['is_video'];
                                    $isSelected = ($styleValues['bg_image'] === $imgFilepath || (!empty($section['video_path']) && $section['video_path'] === $imgFilepath));
                                    $thumbRel = preg_replace('/\.[A-Za-z0-9]+$/', '-thumb.webp', $imgFilepath);
                                    $thumbUrl = file_exists(__DIR__ . '/../' . $thumbRel) ? SITE_URL . '/' . $thumbRel : SITE_URL . '/' . ltrim($imgFilepath, '/');
                                ?>
                                    <label class="sxp-media" title="<?= e($mrow['filename']) ?>">
                                        <input type="radio" name="sx[bg_image]" value="<?= e($imgFilepath) ?>" <?= $isSelected ? 'checked' : '' ?>>
                                        <?php if ($isVideo): ?>
                                            <div class="w-full h-full bg-slate-900 flex items-center justify-center text-white">
                                                <i data-lucide="video" class="w-6 h-6 text-indigo-400"></i>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?= e($thumbUrl) ?>" alt="" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                        <em><?= e($mrow['filename']) ?></em>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!$sxMedia): ?><small>Nenhum arquivo encontrado na biblioteca. Envie arquivos em <a href="<?= ADMIN_URL ?>/media.php" style="color:#4f46e5;font-weight:700">Mídia</a>.</small><?php endif; ?>
                        </div>

                        <div data-show="bg_image:*" class="sxp-field">
                            <span class="sxp-label">Escurecer o fundo (melhora a leitura)</span>
                            <div class="sxp-range"><input type="range" name="sx[bg_overlay]" min="0" max="80" step="5" value="<?= e($styleValues['bg_overlay'] !== '' ? $styleValues['bg_overlay'] : '35') ?>"><output>35%</output></div>
                        </div>
                        <div data-show="bg_image:*" class="sxp-field">
                            <span class="sxp-label">Efeito</span>
                            <label class="sxp-switch"><input type="checkbox" name="sx[bg_fixed]" value="1" <?= $styleValues['bg_fixed'] === '1' ? 'checked' : '' ?>> Parallax (imagem fixa ao rolar)</label>
                        </div>
                    </div>
                </div>

                <!-- TEXTO -->
                <div class="sxp-pane" id="sxp-pane-texto" data-pane="texto" role="tabpanel" hidden>
                    <div class="sxp-grid">
                        <?php $sxSelect('title_font', 'Fonte do título', $sxFontOptions); $sxSelect('body_font', 'Fonte do texto', $sxFontOptions); ?>
                        <div class="sxp-wide"><?php $sxChips('title_size', 'Tamanho do título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_weight', 'Peso do título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_transform', 'Caixa do título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_style', 'Estilo do título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_spacing', 'Espaço entre letras do título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_leading', 'Altura da linha do título', 'Útil quando o título quebra em várias linhas.'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_effect', 'Efeito no título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('title_decor', 'Detalhe sob o título'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('body_size', 'Tamanho do texto'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('body_leading', 'Altura da linha do texto'); ?></div>
                    </div>
                </div>

                <!-- CORES -->
                <div class="sxp-pane" id="sxp-pane-cores" data-pane="cores" role="tabpanel" hidden>
                    <div class="sxp-grid">
                        <?php
                            $sxColor('title_color', 'Cor do título');
                            $sxColor('body_color', 'Cor do texto');
                            $sxColor('accent_color', 'Cor de destaque', 'Selo, palavra destacada, detalhe sob o título e botão principal.');
                        ?>
                    </div>
                </div>

                <!-- CARDS E BOTÕES -->
                <div class="sxp-pane" id="sxp-pane-cards" data-pane="cards" role="tabpanel" hidden>
                    <div class="sxp-grid">
                        <div class="sxp-wide"><?php $sxChips('card_style', 'Estilo dos cards'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('card_radius', 'Cantos dos cards'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('card_border_width', 'Espessura da borda'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('card_shadow', 'Intensidade da sombra'); ?></div>
                        <div class="sxp-wide"><?php $sxChips('card_hover', 'Efeito ao passar o mouse'); ?></div>
                        <?php $sxColor('card_bg', 'Fundo dos cards'); $sxColor('card_text', 'Texto dos cards'); $sxColor('card_border_color', 'Cor da borda'); ?>
                        <div class="sxp-wide"><?php $sxChips('btn_radius', 'Formato dos botões'); ?></div>
                    </div>
                </div>

                <!-- EFEITOS -->
                <div class="sxp-pane" id="sxp-pane-efeitos" data-pane="efeitos" role="tabpanel" hidden>
                    <div class="sxp-grid">
                        <div class="sxp-wide"><?php $sxChips('animation', 'Animação ao rolar a página', 'Respeita a opção "reduzir movimento" do sistema do visitante.'); ?></div>
                        <div class="sxp-wide sxp-field">
                            <span class="sxp-label">Selo</span>
                            <label class="sxp-switch"><input type="checkbox" name="sx[hide_badge]" value="1" <?= $styleValues['hide_badge'] === '1' ? 'checked' : '' ?>> Ocultar o selo (etiqueta pequena acima do título)</label>
                        </div>
                        <div class="sxp-wide sxp-field"><span class="sxp-label">Elementos visíveis</span>
                            <label class="sxp-switch"><input type="checkbox" name="sx[hide_title]" value="1" <?= $styleValues['hide_title'] === '1' ? 'checked' : '' ?>> Ocultar título</label>
                            <label class="sxp-switch"><input type="checkbox" name="sx[hide_description]" value="1" <?= $styleValues['hide_description'] === '1' ? 'checked' : '' ?>> Ocultar descrição</label>
                            <label class="sxp-switch"><input type="checkbox" name="sx[hide_buttons]" value="1" <?= $styleValues['hide_buttons'] === '1' ? 'checked' : '' ?>> Ocultar botões</label>
                            <label class="sxp-switch"><input type="checkbox" name="sx[hide_media]" value="1" <?= $styleValues['hide_media'] === '1' ? 'checked' : '' ?>> Ocultar imagem</label>
                        </div>
                    </div>
                </div>

                <div class="sxp-foot">
                    <div id="sxContrast" class="hint" role="status" aria-live="polite"></div>
                    <button type="button" class="sxp-reset" id="sxReset">↺ Restaurar padrão do site</button>
                </div>
            </div>
        </details>

        <?php if (isAdmin()): ?>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center gap-2 mb-1">
                <i data-lucide="calendar-clock" class="w-4 h-4 text-indigo-600"></i>
                <p class="text-xs font-bold text-slate-800">Publicação agendada</p>
            </div>
            <?php if (!$schedulingEnabled): ?>
                <p class="text-xs text-slate-500">O agendamento está desativado em Configurações → Fluxo de publicação.</p>
            <?php else: ?>
                <?php $scheduledAt = !empty($section['publish_at']) ? date('Y-m-d\\TH:i', strtotime((string)$section['publish_at'])) : ''; ?>
                <?php if ($scheduledAt !== ''): ?>
                    <p class="text-xs text-indigo-700 font-semibold mb-2">Agendada para <?= e(date('d/m/Y \\à\\s H:i', strtotime((string)$section['publish_at']))) ?> (horário de Brasília).</p>
                <?php else: ?>
                    <p class="text-xs text-slate-500 mb-2">O conteúdo será salvo como rascunho e publicado sozinho na data escolhida (horário de Brasília).</p>
                <?php endif; ?>
                <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                    <input type="datetime-local" name="publish_at" value="<?= e($scheduledAt) ?>" min="<?= e(date('Y-m-d\\TH:i')) ?>" class="px-3 py-2 text-sm bg-white border border-slate-200 rounded-xl">
                    <button type="submit" name="action" value="schedule" class="px-4 py-2 rounded-xl bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 text-xs font-semibold transition">Agendar publicação</button>
                    <?php if ($scheduledAt !== ''): ?>
                        <button type="submit" name="action" value="unschedule" class="px-4 py-2 rounded-xl text-slate-500 hover:text-red-600 text-xs font-semibold transition">Cancelar agendamento</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="editorActions" class="pt-6 border-t border-slate-100 flex flex-col-reverse sm:flex-row sm:items-center justify-between gap-3 sm:gap-4">
                <button type="submit" name="action" value="save_draft" class="px-5 py-2.5 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold transition">
                4. Salvar rascunho
            </button>
            <button type="submit" name="action" value="publish" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-md shadow-indigo-100 transition">
                4. Publicar alterações
            </button>
        </div>
    </div>

    <!-- Coluna Direita: Preview Vivo da Seção com Redimensionamento (5 Colunas) -->
    <div class="lg:col-span-5 min-w-0 flex flex-col lg:sticky lg:top-6">
        <!-- Barra de Navegação Mockup Browser -->
        <div class="bg-white rounded-t-3xl border border-b-0 border-slate-200 p-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-slate-300"></span>
                <span class="w-3 h-3 rounded-full bg-slate-300"></span>
                <span class="w-3 h-3 rounded-full bg-slate-300"></span>
                <span class="ml-2 text-xs font-medium text-slate-400 bg-slate-100 px-3 py-1 rounded-full">projetotunisia.com.br/#<?= e($section['identifier']) ?></span>
            </div>

            <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl text-xs font-medium text-slate-600">
                <button type="button" id="btnPreviewDesktop" onclick="setPreviewWidth('desktop')" class="px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition">Desktop</button>
                <button type="button" id="btnPreviewTablet" onclick="setPreviewWidth('tablet')" class="px-3 py-1 rounded-lg text-slate-500 hover:text-slate-800 transition">Tablet</button>
            </div>
        </div>

        <!-- Janela de Visualização Mockup -->
        <div id="previewMockupWrapper" class="w-full transition-all duration-300 flex justify-center">
            <?php 
                $activeBg = !empty($section['video_path']) ? $section['video_path'] : ($styleValues['bg_image'] ?? '');
                $initialIsVideo = preg_match('/\.(mp4|webm|ogg)$/i', $activeBg);
                $initialVideoUrl = $initialIsVideo ? SITE_URL . '/' . ltrim($activeBg, '/') : '';
                $initialImgUrl = (!$initialIsVideo && !empty($activeBg)) ? SITE_URL . '/' . ltrim($activeBg, '/') : '';
            ?>
            <div id="previewMockupCard" class="w-full bg-slate-900 rounded-b-3xl border border-slate-200 overflow-hidden relative shadow-lg min-h-[460px] flex flex-col justify-between text-white p-6 transition-all duration-300" style="background-image: <?= $initialImgUrl ? "linear-gradient(rgba(15, 23, 42, 0.7), rgba(15, 23, 42, 0.8)), url('{$initialImgUrl}')" : 'none' ?>; background-size: cover; background-position: center;">
                
                <!-- Vídeo de fundo em tempo real -->
                <video id="previewVideoBg" autoplay muted loop playsinline class="absolute inset-0 w-full h-full object-cover pointer-events-none <?= !$initialIsVideo ? 'hidden' : '' ?>" style="z-index: 0;">
                    <source src="<?= e($initialVideoUrl) ?>" type="video/mp4">
                </video>
                <div id="previewVideoOverlay" class="absolute inset-0 bg-slate-950/60 pointer-events-none <?= !$initialIsVideo ? 'hidden' : '' ?>" style="z-index: 1;"></div>

                <!-- Navbar Mockup Interna -->
                <div class="relative flex items-center justify-between border-b border-white/10 pb-4" style="z-index: 2;">
                    <span class="font-bold text-sm tracking-tight text-white">Tunísia CMS</span>
                    <span class="text-[10px] bg-indigo-500/30 border border-indigo-400/30 text-indigo-200 px-2.5 py-0.5 rounded-full font-medium">Seção: #<?= e($section['identifier']) ?></span>
                </div>

                <!-- Conteúdo Central Vivo da Seção Aberta -->
                <div id="liveContentLayout" class="relative my-auto py-8 flex flex-col gap-5" style="z-index: 2;">
                    <img id="liveContentImage" src="<?= !empty($section['image_path']) ? e(SITE_URL . '/' . ltrim((string)$section['image_path'], '/')) : '' ?>" alt="" class="hidden w-full max-h-52 object-cover rounded-xl">
                    <div id="liveContentCopy">
                    <div class="mb-2">
                        <span id="liveBadge" class="text-[10px] font-bold uppercase tracking-wider text-amber-300 bg-amber-400/20 px-2 py-0.5 rounded">
                            <?= e($section['badge'] ?? 'Seção') ?>
                        </span>
                    </div>
                    <h3 id="liveTitle" class="text-xl md:text-2xl font-bold leading-tight text-white mb-3">
                        <?= e($section['title'] ?: 'Título da Seção') ?>
                    </h3>
                    <p id="liveSubtitle" class="text-xs text-white/80 leading-relaxed max-w-sm">
                        <?= e($section['subtitle'] ?? $section['description'] ?? 'Descrição ou subtítulo da seção exibido aqui...') ?>
                    </p>
                    <div class="flex items-center gap-2 mt-5">
                        <span id="liveBtn1" class="px-3 py-1 bg-indigo-600 text-white rounded text-[10px] font-semibold <?= empty($section['primary_btn_text']) ? 'hidden' : '' ?>"><?= e($section['primary_btn_text']) ?></span>
                        <span id="liveBtn2" class="px-3 py-1 bg-white/20 text-white rounded text-[10px] <?= empty($section['secondary_btn_text']) ? 'hidden' : '' ?>"><?= e($section['secondary_btn_text']) ?></span>
                    </div>
                    </div>
                </div>

                <!-- Rodapé Mockup -->
                <div class="relative text-[10px] text-white/50 border-t border-white/10 pt-3 flex justify-between items-center" style="z-index: 2;">
                    <span>Pré-visualização ao vivo</span>
                    <span>Modo de Edição</span>
                </div>
            </div>
        </div>
        
    </div>
</form>

<script>
(function () {
    const panel = document.getElementById('sxPanel');
    if (!panel) return;

    const FONTS = <?= json_encode(array_map(fn($f) => ['stack' => $f['stack'], 'google' => $f['google']], SX_FONTS), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    const FIELDS = <?= json_encode(array_keys(sectionStyleDefaults())) ?>;
    const DEFAULTS = <?= json_encode(sectionStyleDefaults()) ?>;
    const BASE = <?= json_encode((string)SITE_URL, JSON_UNESCAPED_SLASHES) ?>;

    const DIRS = { down: 'to bottom', right: 'to right', diag: 'to bottom right', up: 'to top' };
    const TITLE_PX = { sm: 20, md: 26, lg: 32, xl: 40, '2xl': 50 };
    const BODY_PX = { sm: 11, md: 12, lg: 14, xl: 16 };
    const SPACING = { tight: '-0.04em', normal: '0', wide: '0.08em' };
    const T_LEAD = { tight: '1.05', normal: '1.25', relaxed: '1.5' };
    const B_LEAD = { tight: '1.4', normal: '1.7', relaxed: '2.05' };
    const BTN_R = { sq: '4px', md: '10px', pill: '999px' };
    const PALETTE = ['#0f172a', '#ffffff', '#ef4444', '#f59e0b', '#10b981', '#0ea5e9', '#6366f1', '#ec4899'];

    const PRESETS = {
        clean:     { text_align: 'center', bg_mode: 'solid', bg_color: '#ffffff', title_color: '#0f172a', body_color: '#475569', accent_color: '#4f46e5', title_weight: '800', title_decor: 'line' },
        dark:      { bg_mode: 'solid', bg_color: '#0b1020', title_color: '#ffffff', body_color: '#94a3b8', accent_color: '#38bdf8', card_bg: '#1e293b', card_text: '#e2e8f0', card_style: 'bordered', card_hover: 'glow' },
        gradient:  { bg_mode: 'gradient', bg_color: '#4f46e5', bg_color2: '#9333ea', bg_direction: 'diag', title_color: '#ffffff', body_color: '#eef2ff', accent_color: '#fde68a', card_hover: 'lift' },
        soft:      { bg_mode: 'gradient', bg_color: '#f0f9ff', bg_color2: '#e0e7ff', bg_direction: 'down', title_color: '#1e293b', body_color: '#475569', accent_color: '#6366f1', card_style: 'soft', card_radius: 'xl', animation: 'fade-in', btn_radius: 'pill' },
        editorial: { text_align: 'left', content_width: 'narrow', bg_mode: 'solid', bg_color: '#faf7f2', title_font: 'playfair', body_font: 'merriweather', title_weight: '700', title_size: 'lg', title_leading: 'normal', body_leading: 'relaxed', title_color: '#1c1917', body_color: '#44403c', accent_color: '#b45309', title_decor: 'line' },
        impact:    { text_align: 'center', title_font: 'oswald', title_transform: 'uppercase', title_spacing: 'wide', title_size: 'xl', title_leading: 'tight', bg_mode: 'solid', bg_color: '#111111', title_color: '#ffffff', body_color: '#d4d4d4', accent_color: '#ef4444', animation: 'fade-up', btn_radius: 'sq' },
        ocean:     { bg_mode: 'gradient', bg_color: '#0369a1', bg_color2: '#0f766e', bg_direction: 'diag', title_color: '#ffffff', body_color: '#f0fdfa', accent_color: '#fef08a', card_style: 'glass', card_hover: 'lift', title_decor: 'bar', card_radius: 'lg' },
        sunset:    { bg_mode: 'gradient', bg_color: '#c2410c', bg_color2: '#be185d', bg_direction: 'diag', title_color: '#ffffff', body_color: '#fff7ed', accent_color: '#fde047', title_decor: 'dots', animation: 'fade-up', btn_radius: 'pill' }
    };

    const card = document.getElementById('previewMockupCard');
    const liveTitle = document.getElementById('liveTitle');
    const liveSub = document.getElementById('liveSubtitle');
    const liveBadge = document.getElementById('liveBadge');
    const liveBtn1 = document.getElementById('liveBtn1');
    const liveBtn2 = document.getElementById('liveBtn2');
    const contrastBox = document.getElementById('sxContrast');
    const liveContentLayout = document.getElementById('liveContentLayout');
    const liveContentCopy = document.getElementById('liveContentCopy');
    const liveContentImage = document.getElementById('liveContentImage');

    let liveDecor = null;
    if (liveTitle) {
        liveDecor = document.createElement('div');
        liveDecor.id = 'liveDecor';
        liveDecor.style.display = 'none';
        liveTitle.insertAdjacentElement('afterend', liveDecor);
    }

    function els(n) { return panel.querySelectorAll('[name="sx[' + n + ']"]'); }
    function val(n) {
        const list = els(n);
        if (!list.length) return '';
        if (list[0].type === 'radio') { const c = Array.from(list).find(e => e.checked); return c ? c.value : ''; }
        if (list[0].type === 'checkbox') return list[0].checked ? '1' : '';
        return list[0].value;
    }
    function setVal(n, v) {
        const list = els(n);
        if (!list.length) return;
        if (list[0].type === 'radio') { list.forEach(e => { e.checked = (e.value === v); }); return; }
        if (list[0].type === 'checkbox') { list[0].checked = (v === '1'); return; }
        if (list[0].type === 'range' && v === '') v = '35';
        list[0].value = v;
        const picker = list[0].closest('.sxp-color') && list[0].closest('.sxp-color').querySelector('.sxp-picker');
        if (picker && /^#[0-9a-f]{6}$/i.test(v)) picker.value = v;
    }

    const tabs = panel.querySelectorAll('.sxp-tab');
    function showTab(name) {
        tabs.forEach(t => {
            const selected = t.dataset.tab === name;
            t.setAttribute('aria-selected', selected ? 'true' : 'false');
            t.tabIndex = selected ? 0 : -1;
        });
        panel.querySelectorAll('.sxp-pane').forEach(p => {
            const selected = p.dataset.pane === name;
            p.classList.toggle('is-active', selected);
            p.hidden = !selected;
            if (selected) {
                // Reinicia somente a rolagem interna da aba, nunca a rolagem da página.
                p.scrollTop = 0;
            }
        });
    }
    tabs.forEach(t => t.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const tabName = t.dataset.tab;
        if (!tabName) return;
        showTab(tabName);
    }));

    // Navegação por teclado sem acionar o scroll global da página.
    tabs.forEach((t, index) => t.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const current = Array.from(tabs).indexOf(t);
        let next = current;
        if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
        if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        const target = tabs[next];
        if (target) { showTab(target.dataset.tab); target.focus({ preventScroll: true }); }
    }));

    panel.querySelectorAll('.sxp-chips label').forEach(lbl => {
        lbl.addEventListener('click', function(e) {
            const rad = this.querySelector('input[type="radio"]');
            if (rad) {
                rad.checked = true;
                refresh();
            }
        });
    });

    const loaded = {};
    function ensureFont(key) {
        const f = FONTS[key];
        if (!f || !f.google || loaded[key]) return;
        loaded[key] = true;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + f.google + '&display=swap';
        document.head.appendChild(link);
    }

    function lum(hex) {
        const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
        if (!m) return null;
        const c = [m[1], m[2], m[3]].map(h => { const x = parseInt(h, 16) / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }
    function ratio(a, b) {
        const la = lum(a), lb = lum(b);
        if (la === null || lb === null) return null;
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }
    function updateContrast() {
        try {
            const mode = val('bg_mode'), bg1 = val('bg_color'), bg2 = val('bg_color2') || bg1;
            const title = val('title_color'), body = val('body_color');
            if (contrastBox) contrastBox.className = 'hint';
            if (val('bg_image')) {
                if (contrastBox) contrastBox.textContent = 'Com mídia de fundo, confira a leitura no preview.';
                return;
            }
            if (!title && !body) { if (contrastBox) contrastBox.textContent = ''; return; }
            if (!mode || !bg1) {
                if (contrastBox) contrastBox.textContent = 'Dica: ao mudar a cor do texto, defina também a cor do fundo.';
                return;
            }
            const bgs = mode === 'gradient' ? [bg1, bg2] : [bg1];
            const worst = (c) => Math.min.apply(null, bgs.map(b => ratio(c, b)).filter(r => r !== null));
            const problems = [];
            if (title) { const r = worst(title); if (isFinite(r) && r < 3) problems.push('título (' + r.toFixed(1) + ':1)'); }
            if (body)  { const r = worst(body);  if (isFinite(r) && r < 4.5) problems.push('texto (' + r.toFixed(1) + ':1)'); }
            if (problems.length) {
                if (contrastBox) { contrastBox.className = 'warn'; contrastBox.textContent = '⚠ Contraste baixo em: ' + problems.join(' e '); }
            } else {
                if (contrastBox) { contrastBox.className = 'ok'; contrastBox.textContent = '✓ Bom contraste entre texto e fundo.'; }
            }
        } catch (err) {
            console.error("Erro em updateContrast:", err);
        }
    }

    function updateVisibility() {
        try {
            panel.querySelectorAll('[data-show]').forEach(box => {
                const parts = box.getAttribute('data-show').split(':');
                const current = val(parts[0]);
                box.hidden = parts[1] === '*' ? current === '' : parts[1].split(',').indexOf(current) === -1;
            });
            panel.querySelectorAll('.sxp-range').forEach(r => {
                const out = r.querySelector('output'), inp = r.querySelector('input');
                if (out && inp) out.textContent = inp.value + '%';
            });
        } catch (err) {
            console.error("Erro em updateVisibility:", err);
        }
    }

    function updatePreview() {
        try {
            if (!card) return;
            card.style.textAlign = val('text_align');

            const mode = val('bg_mode'), c1 = val('bg_color'), c2 = val('bg_color2') || c1, img = val('bg_image');
            const videoBg = document.getElementById('previewVideoBg');
            const videoOverlay = document.getElementById('previewVideoOverlay');

            const layers = [];
            const isVideoFile = /\.(mp4|webm|ogg)$/i.test(img);

            if (img && isVideoFile) {
                const fullVideoUrl = /^(https?:)?\/\//.test(img) ? img : BASE + '/' + img.replace(/^\/+/, '');
                if (videoBg && videoOverlay) {
                    videoBg.src = fullVideoUrl;
                    videoBg.classList.remove('hidden');
                    videoOverlay.classList.remove('hidden');
                    videoBg.load();
                    videoBg.play().catch(e => console.log("Autoplay bloqueado:", e));
                }
            } else {
                if (videoBg && videoOverlay) {
                    videoBg.pause();
                    videoBg.src = '';
                    videoBg.classList.add('hidden');
                    videoOverlay.classList.add('hidden');
                }

                if (img) {
                    const a = (parseInt(val('bg_overlay') || '0', 10) || 0) / 100;
                    if (a > 0) layers.push('linear-gradient(rgba(0,0,0,' + a + '),rgba(0,0,0,' + a + '))');
                    
                    const fullImgUrl = /^(https?:)?\/\//.test(img) ? img : BASE + '/' + img.replace(/^\/+/, '');
                    layers.push("url('" + fullImgUrl + "')");
                }
            }

            if (mode === 'solid' && c1) layers.push(c1);
            else if (mode === 'gradient' && c1) layers.push('linear-gradient(' + (DIRS[val('bg_direction')] || 'to bottom') + ',' + c1 + ',' + c2 + ')');
            
            card.style.background = layers.join(',');
            card.style.backgroundSize = img && !isVideoFile ? 'cover' : '';
            card.style.backgroundPosition = img && !isVideoFile ? 'center' : '';

            const tf = val('title_font'), bf = val('body_font');
            if (tf) ensureFont(tf);
            if (bf) ensureFont(bf);
            const accent = val('accent_color');

            if (liveTitle) {
                const size = TITLE_PX[val('title_size')];
                liveTitle.style.fontFamily = (tf && tf !== 'sans' && FONTS[tf]) ? FONTS[tf].stack : '';
                liveTitle.style.fontSize = size ? size + 'px' : '';
                liveTitle.style.lineHeight = T_LEAD[val('title_leading')] || ((size || tf) ? '1.15' : '');
                liveTitle.style.fontWeight = val('title_weight');
                liveTitle.style.textTransform = val('title_transform');
                liveTitle.style.fontStyle = val('title_style');
                liveTitle.style.letterSpacing = SPACING[val('title_spacing')] || '';
                const effect = val('title_effect');
                liveTitle.style.textShadow = effect === 'shadow' ? '0 6px 28px rgba(0,0,0,.45)' : '';
                if (effect === 'gradient') {
                    liveTitle.style.backgroundImage = 'linear-gradient(90deg,' + (accent || '#e11d48') + ',' + (val('title_color') || '#6366f1') + ')';
                    liveTitle.style.webkitBackgroundClip = 'text';
                    liveTitle.style.backgroundClip = 'text';
                    liveTitle.style.webkitTextFillColor = 'transparent';
                    liveTitle.style.color = 'transparent';
                } else {
                    liveTitle.style.backgroundImage = '';
                    liveTitle.style.webkitBackgroundClip = '';
                    liveTitle.style.backgroundClip = '';
                    liveTitle.style.webkitTextFillColor = '';
                    liveTitle.style.color = val('title_color');
                }
            }
            if (liveDecor) {
                const d = val('title_decor'), al = val('text_align');
                liveDecor.style.display = d ? 'block' : 'none';
                liveDecor.style.marginTop = '2px'; liveDecor.style.marginBottom = '12px';
                liveDecor.style.marginLeft = (al === 'center') ? 'auto' : (al === 'right' ? 'auto' : '0');
                liveDecor.style.marginRight = (al === 'center') ? 'auto' : (al === 'right' ? '0' : 'auto');
                const col = accent || '#e11d48';
                liveDecor.style.height = d === 'bar' ? '5px' : (d === 'dots' ? '8px' : '3px');
                liveDecor.style.width = d === 'bar' ? '110px' : (d === 'dots' ? '48px' : '52px');
                liveDecor.style.borderRadius = '4px';
                liveDecor.style.background = d === 'dots'
                    ? 'radial-gradient(circle,' + col + ' 3px,transparent 3.5px) 0 0/16px 8px repeat-x'
                    : (d === 'bar' ? 'linear-gradient(90deg,' + col + ',' + (val('title_color') || '#6366f1') + ')' : col);
            }
            if (liveSub) {
                liveSub.style.fontFamily = (bf && bf !== 'sans' && FONTS[bf]) ? FONTS[bf].stack : '';
                liveSub.style.fontSize = BODY_PX[val('body_size')] ? BODY_PX[val('body_size')] + 'px' : '';
                liveSub.style.lineHeight = B_LEAD[val('body_leading')] || '';
                liveSub.style.color = val('body_color');
                const al = val('text_align');
                liveSub.style.marginLeft = (al === 'center' || al === 'right') ? 'auto' : '';
                liveSub.style.marginRight = (al === 'center') ? 'auto' : '';
            }
            if (liveBadge) {
                liveBadge.style.color = accent;
                liveBadge.style.display = val('hide_badge') === '1' ? 'none' : '';
            }
            [liveBtn1, liveBtn2].forEach(b => { if (b) b.style.borderRadius = BTN_R[val('btn_radius')] || ''; });
            if (liveBtn1) liveBtn1.style.background = accent;
            if (liveTitle) liveTitle.style.display = val('hide_title') === '1' ? 'none' : '';
            if (liveSub) liveSub.style.display = val('hide_description') === '1' ? 'none' : '';
            if (liveBtn1) liveBtn1.parentElement.style.display = val('hide_buttons') === '1' ? 'none' : '';
            if (liveContentLayout && liveContentImage && liveContentCopy) {
                const chosen = panel.querySelector('input[name="image_path"]:checked');
                const path = chosen ? chosen.value : '';
                const layout = val('content_layout');
                const showImage = !!path && layout !== 'text' && val('hide_media') !== '1';
                liveContentImage.classList.toggle('hidden', !showImage);
                if (showImage) liveContentImage.src = BASE + '/' + path.replace(/^\/+/, '');
                liveContentLayout.style.display = (layout === 'image-left' || layout === 'image-right') ? 'flex' : 'flex';
                liveContentLayout.style.flexDirection = layout === 'image-right' ? 'row-reverse' : (layout === 'image-left' ? 'row' : (layout === 'image-bottom' ? 'column-reverse' : 'column'));
                liveContentLayout.style.alignItems = val('content_vertical') === 'start' ? 'flex-start' : (val('content_vertical') === 'end' ? 'flex-end' : 'center');
                liveContentLayout.style.gap = ({sm:'12px',md:'22px',lg:'36px'})[val('content_gap')] || '20px';
                liveContentImage.style.width = (layout === 'image-left' || layout === 'image-right') ? ({sm:'30%',md:'42%',lg:'56%'})[val('media_width')] || '42%' : '100%';
                liveContentImage.style.aspectRatio = ({square:'1',landscape:'4 / 3',portrait:'3 / 4',wide:'16 / 7'})[val('media_ratio')] || '';
                liveContentImage.style.objectFit = val('media_fit') || 'cover';
                liveContentImage.style.borderRadius = ({none:'0',md:'12px',lg:'24px',pill:'999px'})[val('media_radius')] || '12px';
                liveContentCopy.style.flex = '1 1 0';
            }
        } catch (err) {
            console.error("Erro em updatePreview:", err);
        }
    }

    function refresh() {
        try {
            updateVisibility();
            updatePreview();
            updateContrast();
        } catch (err) {
            console.error("Erro em refresh:", err);
        }
    }

    panel.addEventListener('input', refresh);
    panel.addEventListener('change', refresh);

    panel.querySelectorAll('.sxp-media input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', refresh);
    });

    // Os radios de modelo visual ocupam o próprio card (CSS position:relative no label).
    // Preserve a posição da página ao selecionar um modelo: alguns navegadores tentam
    // rolar até o input transparente, causando salto para baixo e uma grande área vazia.
    panel.querySelectorAll('.sxp-layout input[type="radio"]').forEach(radio => {
        radio.addEventListener('click', () => {
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            requestAnimationFrame(() => {
                if (Math.abs(window.scrollY - scrollY) > 2 || Math.abs(window.scrollX - scrollX) > 2) {
                    window.scrollTo({ left: scrollX, top: scrollY, behavior: 'auto' });
                }
            });
        });
        radio.addEventListener('change', refresh);
    });

    panel.querySelectorAll('.sxp-color').forEach(wrap => {
        const picker = wrap.querySelector('.sxp-picker');
        const hex = wrap.querySelector('.sxp-hex');
        if (picker && hex) {
            picker.addEventListener('input', () => { hex.value = picker.value; refresh(); });
            hex.addEventListener('input', () => {
                let v = hex.value.trim();
                if (v && v[0] !== '#') { v = '#' + v; hex.value = v; }
                if (/^#[0-9a-f]{6}$/i.test(v)) picker.value = v;
            });
        }
        const clearBtn = wrap.querySelector('.sxp-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => { if (hex) hex.value = ''; refresh(); });
        }
        const sw = wrap.querySelector('.sxp-swatches');
        if (sw) PALETTE.forEach(c => {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'sxp-sw'; b.style.background = c; b.title = c; b.setAttribute('aria-label', 'Usar ' + c);
            b.addEventListener('click', () => { if (hex) hex.value = c; if (picker) picker.value = c; refresh(); });
            sw.appendChild(b);
        });
    });

    function resetAll() { FIELDS.forEach(f => setVal(f, DEFAULTS[f])); }
    panel.querySelectorAll('.sxp-preset').forEach(btn => btn.addEventListener('click', () => {
        resetAll();
        const p = PRESETS[btn.getAttribute('data-preset')] || {};
        Object.keys(p).forEach(k => setVal(k, p[k]));
        refresh();
    }));
    const resetBtn = document.getElementById('sxReset');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => { resetAll(); refresh(); });
    }

    refresh();
})();
</script>

<script>
    const inputTitle = document.getElementById('inputTitle');
    const inputSubtitle = document.getElementById('inputSubtitle');
    const inputBadge = document.getElementById('inputBadge');
    const inputBtn1 = document.getElementById('inputBtn1Text');
    const inputBtn2 = document.getElementById('inputBtn2Text');

    const liveTitle = document.getElementById('liveTitle');
    const liveSubtitle = document.getElementById('liveSubtitle');
    const liveBadge = document.getElementById('liveBadge');
    const liveBtn1 = document.getElementById('liveBtn1');
    const liveBtn2 = document.getElementById('liveBtn2');
    const headerTitle = document.getElementById('headerTitle');

    (function () {
        const metaEl = document.getElementById('itemTypeMeta');
        if (!metaEl) return;
        const META = JSON.parse(metaEl.textContent);
        const TYPES = META.types, FIELDS = META.fields;
        const list = document.getElementById('sectionItemsList');
        const filtersEl = document.getElementById('siFilters');
        const emptyEl = document.getElementById('siEmpty');
        const statusEl = document.getElementById('siStatus');
        const toggleAllBtn = document.getElementById('siToggleAll');
        const SITE_URL = <?= json_encode(SITE_URL) ?>;
        let activeFilter = 'all';

        const ICONS = {
            up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>',
            down: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
            copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>',
            trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>',
            chevron: '<svg class="si-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>'
        };

        const FIELD_NAMES = { subtitle:'item_subtitle[]', icon:'item_icon[]', image:'item_image_path[]', badge:'item_badge[]', link:'item_link_url[]', description:'item_description[]' };
        const FIELD_ORDER = ['icon','subtitle','image','badge','link'];

        function labelFor(field, type) {
            const map = {
                timeline:   { subtitle: 'Período / data' },
                cultural_card: { subtitle: 'Frase de apoio' },
                map_marker: { subtitle: 'Região', badge: 'Curiosidade', link: 'Posição no mapa', description: 'Principal atração' },
                stat:       { description: 'Legenda do número' },
                knowledge:  { description: 'Tópicos (separe com ponto e vírgula)' },
                model:      { description: 'Tópicos (separe com ponto e vírgula)' },
                objective:  { description: 'Texto do objetivo' },
                competency: { description: 'Texto da competência' }
            };
            return (map[type] && map[type][field]) || FIELDS[field][0];
        }
        function hintFor(field, type) {
            if (field === 'link') return type === 'map_marker' ? 'Topo%, esquerda% — ex.: 28%,55%' : 'URL do botão "Saiba mais"';
            if (field === 'image') return 'ex.: assets/img/img01.png';
            if (field === 'icon') return 'Um emoji, ex.: 🍴';
            return '';
        }
        function titleLabel(type) {
            return type === 'stat' ? 'Número (ex.: 20+)' : type === 'map_marker' ? 'Nome da cidade' : 'Título';
        }

        function el(tag, attrs, html) {
            const n = document.createElement(tag);
            if (attrs) for (const k in attrs) n.setAttribute(k, attrs[k]);
            if (html != null) n.innerHTML = html;
            return n;
        }
        function field(name, labelText, control, cls, hint) {
            const w = el('div', { class: 'si-field' + (cls ? ' ' + cls : ''), 'data-field': name });
            const id = 'si' + Math.random().toString(36).slice(2, 9);
            control.id = id;
            const l = el('label', { for: id }); l.textContent = labelText;
            w.appendChild(l); w.appendChild(control);
            if (hint) { const h = el('span', { class: 'hint' }); h.textContent = hint; w.appendChild(h); }
            return w;
        }

        function buildRow(d, opts) {
            d = Object.assign({ type: 'card', title: '', subtitle: '', icon: '', image: '', badge: '', link: '', description: '' }, d || {});
            const row = el('div', { class: 'section-item-row', 'data-type': d.type });

            const head = el('div', { class: 'si-row-head' });
            const summary = el('button', { type: 'button', class: 'si-summary', 'aria-expanded': 'false' });
            const thumb = el('span', { class: 'si-thumb', 'aria-hidden': 'true' });
            const text = el('span', { class: 'si-text' });
            const tTitle = el('span', { class: 'si-title' });
            const tSub = el('span', { class: 'si-sub' });
            const complete = el('span', { class: 'si-complete' });
            text.append(tTitle, tSub);
            const typeChip = el('span', { class: 'si-type' });
            summary.append(thumb, text, complete, typeChip, el('span', null, ICONS.chevron));
            const tools = el('div', { class: 'si-tools' });
            const mk = (cls, label, icon) => { const b = el('button', { type: 'button', class: 'si-icon-btn ' + cls, title: label, 'aria-label': label }, icon); return b; };
            const bUp = mk('up', 'Mover para cima', ICONS.up);
            const bDown = mk('down', 'Mover para baixo', ICONS.down);
            const bDup = mk('dup', 'Duplicar item', ICONS.copy);
            const bDel = mk('danger', 'Remover item', ICONS.trash);
            tools.append(bUp, bDown, bDup, bDel);
            head.append(summary, tools);

            const body = el('div', { class: 'si-body' });
            const grid = el('div', { class: 'si-grid' });

            const select = el('select', { name: 'item_type[]' });
            Object.keys(TYPES).forEach(k => { const o = el('option', { value: k }); o.textContent = TYPES[k][0]; if (k === d.type) o.selected = true; select.appendChild(o); });
            const typeField = field('type', 'Tipo de conteúdo', select, '');
            const titleInput = el('input', { type: 'text', name: 'item_title[]', autocomplete: 'off' }); titleInput.value = d.title;
            const titleField = field('title', 'Título', titleInput, '');
            grid.append(typeField, titleField);

            const inputs = { type: select, title: titleInput };
            const wrappers = { title: titleField };

            const makeInput = (f) => {
                let c;
                if (f === 'description') { c = el('textarea', { name: FIELD_NAMES[f], rows: '3' }); }
                else { c = el('input', { type: 'text', name: FIELD_NAMES[f], autocomplete: 'off' }); }
                c.value = d[f] || '';
                inputs[f] = c;
                const w = field(f, FIELDS[f][0], c, f === 'description' ? 'full' : '', '');
                if (f === 'description') {
                    const tools = el('div', { class: 'si-field-tools' });
                    const help = el('span'); help.textContent = 'Escreva uma descrição clara para o visitante.';
                    const counter = el('span', { class: 'si-counter', 'data-counter': 'description' });
                    tools.append(help, counter); w.appendChild(tools);
                }
                wrappers[f] = w;
                return w;
            };
            const descW = makeInput('description');
            FIELD_ORDER.forEach(f => makeInput(f));
            const moreDetails = el('details', { class: 'si-more' });
            const moreSum = el('summary'); moreSum.textContent = 'Outros campos';
            const moreGrid = el('div', { class: 'si-grid' });
            moreDetails.append(moreSum, moreGrid);

            const fieldsHost = el('div', { class: 'si-grid', style: 'margin-top:14px' });
            fieldsHost.appendChild(descW);

            body.append(grid, fieldsHost, moreDetails);

            function applyType() {
                const type = select.value;
                row.dataset.type = type;
                const rel = (TYPES[type] || TYPES.card)[2];
                titleField.querySelector('label').textContent = titleLabel(type);
                typeChip.textContent = TYPES[type] ? TYPES[type][0] : type;

                const relHost = []; const otherHost = [];
                ['icon','subtitle','image','badge','link'].forEach(f => {
                    const w = wrappers[f];
                    const lab = w.querySelector('label'); lab.textContent = labelFor(f, type);
                    const h = w.querySelector('.hint'); if (h) h.remove();
                    const hint = hintFor(f, type);
                    if (hint) { const hs = el('span', { class: 'hint' }); hs.textContent = hint; w.appendChild(hs); }
                    (rel.includes(f) ? relHost : otherHost).push(w);
                });
                wrappers.description.querySelector('label').textContent = labelFor('description', type);

                fieldsHost.replaceChildren(...relHost, wrappers.description);
                moreGrid.replaceChildren(...otherHost);
                moreDetails.hidden = otherHost.length === 0;
                moreDetails.open = otherHost.some(w => (w.querySelector('input,textarea').value || '').trim() !== '');
                refreshSummary();
                updateStatus();
            }

            function refreshSummary() {
                const type = select.value;
                const title = titleInput.value.trim();
                tTitle.textContent = title || 'Item sem título';
                tTitle.classList.toggle('is-empty', !title);
                const detail = (inputs.subtitle.value || inputs.description.value || '').trim().replace(/\s+/g, ' ');
                tSub.textContent = detail;
                tSub.style.display = detail ? '' : 'none';
                const img = inputs.image.value.trim();
                const icon = inputs.icon.value.trim();
                thumb.replaceChildren();
                if (img && (TYPES[type] || TYPES.card)[2].includes('image')) {
                    const im = el('img', { alt: '', loading: 'lazy' });
                    im.src = /^(https?:)?\/\//.test(img) ? img : SITE_URL + '/' + img.replace(/^\/+/, '');
                    im.onerror = () => { thumb.textContent = icon || '🖼️'; };
                    thumb.appendChild(im);
                } else {
                    thumb.textContent = icon || (type === 'timeline' ? '📅' : type === 'stat' ? '#' : type === 'map_marker' ? '📍' : '•');
                }
                const description = (inputs.description.value || '').trim();
                const words = description ? description.split(/\s+/).length : 0;
                const counter = row.querySelector('[data-counter="description"]');
                if (counter) {
                    counter.textContent = description.length + ' caracteres · ' + words + ' palavras';
                    counter.classList.toggle('is-long', description.length > 600);
                }
                const ready = title !== '' && (description !== '' || type === 'stat' || type === 'map_marker');
                complete.textContent = ready ? 'Pronto' : 'Completar';
                complete.classList.toggle('ready', ready);
            }

            select.addEventListener('change', applyType);
            Object.keys(inputs).forEach(k => { if (k !== 'type') inputs[k].addEventListener('input', () => { refreshSummary(); updateStatus(); }); });

            function setOpen(open) {
                row.classList.toggle('is-open', open);
                summary.setAttribute('aria-expanded', open ? 'true' : 'false');
                updateToggleAll();
            }
            summary.addEventListener('click', () => setOpen(!row.classList.contains('is-open')));
            row._setOpen = setOpen;

            bUp.addEventListener('click', () => move(row, -1));
            bDown.addEventListener('click', () => move(row, 1));
            bDel.addEventListener('click', () => {
                const name = titleInput.value.trim() || 'este item';
                if (!confirm('Remover "' + name + '"? A remoção só vale depois de salvar.')) return;
                row.remove(); refresh();
            });
            bDup.addEventListener('click', () => {
                const copy = buildRow(Object.assign({}, snapshot(), { title: (titleInput.value.trim() ? titleInput.value.trim() + ' (cópia)' : '') }), { open: true, isNew: true });
                row.after(copy); refresh(); copy.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
            function snapshot() {
                return { type: select.value, title: titleInput.value, subtitle: inputs.subtitle.value, icon: inputs.icon.value, image: inputs.image.value, badge: inputs.badge.value, link: inputs.link.value, description: inputs.description.value };
            }

            row.append(head, body);
            applyType();
            if (opts && opts.open) setOpen(true);
            if (opts && opts.isNew) row.classList.add('is-new');
            row._focusTitle = () => titleInput.focus();
            return row;
        }

        function rows() { return Array.from(list.querySelectorAll(':scope > .section-item-row')); }
        function visibleRows() { return rows().filter(r => !r.hidden); }

        function move(row, dir) {
            const vis = visibleRows(); const i = vis.indexOf(row); const target = vis[i + dir];
            if (!target) return;
            if (dir < 0) target.before(row); else target.after(row);
            refresh();
            row.querySelector(dir < 0 ? '.up' : '.down').focus();
        }

        function updateToggleAll() {
            const vis = visibleRows();
            const allOpen = vis.length > 0 && vis.every(r => r.classList.contains('is-open'));
            if (toggleAllBtn) {
                toggleAllBtn.textContent = allOpen ? 'Recolher todos' : 'Expandir todos';
                toggleAllBtn.disabled = vis.length === 0;
            }
        }

        function renderFilters() {
            const counts = {}; const all = rows();
            all.forEach(r => counts[r.dataset.type] = (counts[r.dataset.type] || 0) + 1);
            const types = Object.keys(counts);
            if (activeFilter !== 'all' && !counts[activeFilter]) activeFilter = 'all';
            if (filtersEl) {
                filtersEl.replaceChildren();
                filtersEl.hidden = types.length < 2;
                const addChip = (key, label, n) => {
                    const b = el('button', { type: 'button', class: 'si-chip', 'aria-pressed': String(activeFilter === key) });
                    b.append(document.createTextNode(label), Object.assign(el('span'), { textContent: n }));
                    b.addEventListener('click', () => { activeFilter = key; refresh(); });
                    filtersEl.appendChild(b);
                };
                addChip('all', 'Todos', all.length);
                types.forEach(t => addChip(t, (TYPES[t] || [t])[0], counts[t]));
            }
        }

        function updateStatus() {
            if (!statusEl) return;
            const allRows = rows();
            const ready = allRows.filter(r => r.querySelector('.si-complete.ready')).length;
            const types = new Set(allRows.map(r => r.dataset.type)).size;
            statusEl.replaceChildren();
            const pill = (label, value, state) => {
                const p = el('span', { class: 'si-status-pill' });
                p.append(el('i', { class: 'si-status-dot ' + state }), document.createTextNode(label + ' '), Object.assign(el('strong'), { textContent: value }));
                statusEl.appendChild(p);
            };
            pill('Itens:', String(allRows.length), allRows.length ? 'ready' : '');
            pill('Prontos:', ready + '/' + allRows.length, ready === allRows.length && allRows.length ? 'ready' : 'warn');
            pill('Tipos:', String(types), '');
        }

        function refresh() {
            rows().forEach(r => { r.hidden = !(activeFilter === 'all' || r.dataset.type === activeFilter); });
            renderFilters();
            const vis = visibleRows();
            rows().forEach(r => {
                const idx = vis.indexOf(r);
                const up = r.querySelector('.up'), down = r.querySelector('.down');
                if (up) up.disabled = idx <= 0;
                if (down) down.disabled = idx === -1 || idx === vis.length - 1;
            });
            if (emptyEl) emptyEl.hidden = rows().length > 0;
            updateStatus();
            updateToggleAll();
        }

        if (list) {
            Array.from(list.children).forEach(old => {
                const g = n => { const x = old.querySelector('[name="' + n + '"]'); return x ? x.value : ''; };
                const row = buildRow({
                    type: g('item_type[]') || 'card', title: g('item_title[]'), subtitle: g('item_subtitle[]'), icon: g('item_icon[]'),
                    image: g('item_image_path[]'), badge: g('item_badge[]'), link: g('item_link_url[]'), description: g('item_description[]')
                });
                old.replaceWith(row);
            });
        }
        refresh();

        const addBtn = document.getElementById('siAdd');
        if (addBtn) {
            addBtn.addEventListener('click', () => {
                const last = rows().slice(-1)[0];
                const type = activeFilter !== 'all' ? activeFilter : (last ? last.dataset.type : 'card');
                const row = buildRow({ type }, { open: true, isNew: true });
                if (list) list.appendChild(row);
                refresh();
                row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                row._focusTitle();
            });
        }
        if (toggleAllBtn) {
            toggleAllBtn.addEventListener('click', () => {
                const vis = visibleRows();
                const open = !vis.every(r => r.classList.contains('is-open'));
                vis.forEach(r => r._setOpen(open));
            });
        }
    })();

    if (inputTitle && liveTitle) {
        inputTitle.addEventListener('input', () => {
            const val = inputTitle.value.trim() || 'Título da Seção';
            liveTitle.textContent = val;
            if (headerTitle) headerTitle.textContent = val;
        });
    }

    if (inputSubtitle && liveSubtitle) {
        inputSubtitle.addEventListener('input', () => {
            liveSubtitle.textContent = inputSubtitle.value.trim() || 'Subtítulo / Descrição da seção...';
        });
    }

    if (inputBadge && liveBadge) {
        inputBadge.addEventListener('input', () => {
            liveBadge.textContent = inputBadge.value.trim() || 'Seção';
        });
    }

    if (inputBtn1 && liveBtn1) {
        inputBtn1.addEventListener('input', () => {
            const val = inputBtn1.value.trim();
            liveBtn1.textContent = val;
            liveBtn1.classList.toggle('hidden', val === '');
        });
    }

    if (inputBtn2 && liveBtn2) {
        inputBtn2.addEventListener('input', () => {
            const val = inputBtn2.value.trim();
            liveBtn2.textContent = val;
            liveBtn2.classList.toggle('hidden', val === '');
        });
    }

    function applyMediaPreview(type, source) {
        const videoBg = document.getElementById('previewVideoBg');
        const videoOverlay = document.getElementById('previewVideoOverlay');
        const cardEl = document.getElementById('previewMockupCard');

        if (type === 'video') {
            const cleanPath = source.trim();
            if (cleanPath !== '') {
                const fullUrl = /^(https?:)?\/\//.test(cleanPath) ? cleanPath : BASE + '/' + cleanPath.replace(/^\/+/, '');
                if (videoBg && videoOverlay) {
                    videoBg.src = fullUrl;
                    videoBg.classList.remove('hidden');
                    videoOverlay.classList.remove('hidden');
                    videoBg.load();
                    videoBg.play().catch(e => console.log("Autoplay bloqueado:", e));
                }
            } else {
                clearSectionMedia();
            }
        } else if (type === 'image') {
            if (videoBg && videoOverlay) {
                videoBg.pause();
                videoBg.src = '';
                videoBg.classList.add('hidden');
                videoOverlay.classList.add('hidden');
            }
            if (cardEl) {
                cardEl.style.backgroundImage = `linear-gradient(rgba(15, 23, 42, 0.7), rgba(15, 23, 42, 0.8)), url('${source}')`;
            }
        }
    }

    function previewSelectedVideo(videoPath) {
        applyMediaPreview('video', videoPath);
    }

    function clearSectionMedia() {
        const videoInput = document.getElementById('inputVideo');
        if (videoInput) videoInput.value = '';

        const videoBg = document.getElementById('previewVideoBg');
        const videoOverlay = document.getElementById('previewVideoOverlay');
        const cardEl = document.getElementById('previewMockupCard');

        if (videoBg && videoOverlay) {
            videoBg.pause();
            videoBg.src = '';
            videoBg.classList.add('hidden');
            videoOverlay.classList.add('hidden');
        }
        if (cardEl) {
            cardEl.style.backgroundImage = 'none';
        }
    }

    function setPreviewWidth(mode) {
        const card = document.getElementById('previewMockupCard');
        const btnDesktop = document.getElementById('btnPreviewDesktop');
        const btnTablet = document.getElementById('btnPreviewTablet');

        if (!card || !btnDesktop || !btnTablet) return;

        if (mode === 'tablet') {
            card.style.maxWidth = '360px';
            btnTablet.className = 'px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition';
            btnDesktop.className = 'px-3 py-1 rounded-lg text-slate-500 hover:text-slate-800 transition';
        } else {
            card.style.maxWidth = '100%';
            btnDesktop.className = 'px-3 py-1 rounded-lg bg-white shadow-sm font-semibold text-slate-800 transition';
            btnTablet.className = 'px-3 py-1 rounded-lg text-slate-500 hover:text-slate-800 transition';
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
