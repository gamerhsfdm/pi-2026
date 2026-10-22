<?php
/**
 * Publicação agendada e rotinas periódicas (backup diário).
 *
 * Como executar:
 *   - Recomendado: cron do servidor chamando `php scripts/cron.php` a cada minuto
 *     (ou, em hospedagens sem terminal, uma URL com token: /cron.php?token=CRON_TOKEN).
 *   - Reserva automática: sem cron, visitas ao site/painel disparam a verificação no máximo
 *     1x por minuto (maybeRunScheduler), após o envio da resposta ao visitante.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/section_style.php';

function getSiteSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function saveSiteSetting(PDO $pdo, string $key, string $value, string $group = 'publishing'): void {
    $pdo->prepare('REPLACE INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)')
        ->execute([$key, $value, $group]);
}

/**
 * Aplica o conteúdo de um rascunho (payload JSON do editor) à seção publicada.
 * Deve ser chamado dentro de uma transação aberta pelo chamador.
 */
function applySectionPayload(PDO $pdo, int $sectionId, array $data): void {
    $update = $pdo->prepare("UPDATE sections SET
            title = COALESCE(NULLIF(?, ''), title), subtitle = ?, badge = ?, description = ?,
            primary_btn_text = ?, primary_btn_link = ?, secondary_btn_text = ?, secondary_btn_link = ?,
            image_path = ?, video_path = ?, is_visible = ?,
            status = 'published', draft_data = NULL, publish_at = NULL, updated_at = NOW()
        WHERE id = ?");
    $update->execute([
        mb_substr((string)($data['title'] ?? ''), 0, 150),
        (string)($data['subtitle'] ?? ''),
        ($data['badge'] ?? '') !== '' ? $data['badge'] : null,
        (string)($data['description'] ?? $data['subtitle'] ?? ''),
        (string)($data['primary_btn_text'] ?? ''),
        (string)($data['primary_btn_link'] ?? ''),
        (string)($data['secondary_btn_text'] ?? ''),
        (string)($data['secondary_btn_link'] ?? ''),
        ($data['image_path'] ?? '') !== '' ? $data['image_path'] : null,
        ($data['video_path'] ?? '') !== '' ? $data['video_path'] : null,
        isset($data['is_visible']) ? (int)(bool)$data['is_visible'] : 1,
        $sectionId,
    ]);

    if (array_key_exists('style', $data)) {
        $styleJson = json_encode(sanitizeSectionStyle($data['style']), JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE sections SET style_data = ? WHERE id = ?')->execute([$styleJson, $sectionId]);
    }

    if (isset($data['section_items']) && is_array($data['section_items'])) {
        $pdo->prepare('DELETE FROM section_items WHERE section_id = ?')->execute([$sectionId]);
        $insert = $pdo->prepare('INSERT INTO section_items (section_id,item_type,title,subtitle,description,icon,image_path,badge,link_url,meta_data,display_order,status,is_visible) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($data['section_items'] as $index => $item) {
            if (!is_array($item) || empty($item['title'])) continue;
            $insert->execute([
                $sectionId,
                $item['item_type'] ?? 'card',
                mb_substr((string)$item['title'], 0, 150),
                $item['subtitle'] ?? null,
                $item['description'] ?? null,
                $item['icon'] ?? null,
                $item['image_path'] ?? null,
                $item['badge'] ?? null,
                $item['link_url'] ?? null,
                $item['meta_data'] ?? null,
                (int)($item['display_order'] ?? ($index + 1)),
                'published',
                isset($item['is_visible']) ? (int)(bool)$item['is_visible'] : 1,
            ]);
        }
    }
}

/** Publica as seções cuja data agendada já chegou. Retorna quantas foram publicadas. */
function publishDueSections(PDO $pdo): int {
    if (getSiteSetting($pdo, 'enable_scheduling', '1') !== '1') return 0;
    ensureSchema($pdo);

    // Trava no MySQL: evita que cron e visitantes publiquem a mesma seção ao mesmo tempo.
    if ((int)$pdo->query("SELECT GET_LOCK('tunisia_publish', 0)")->fetchColumn() !== 1) return 0;

    $published = 0;
    try {
        $due = $pdo->query("SELECT id, title, draft_data FROM sections WHERE publish_at IS NOT NULL AND publish_at <= NOW() ORDER BY publish_at ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($due as $row) {
            $sectionId = (int)$row['id'];
            $data = json_decode((string)$row['draft_data'], true);
            try {
                $pdo->beginTransaction();
                if (is_array($data)) {
                    applySectionPayload($pdo, $sectionId, $data);
                    $pdo->prepare("INSERT INTO versions (section_id, title, data_payload, user_id, user_name, action_type, note, created_at)
                                   VALUES (?, ?, ?, NULL, 'Agendador', 'published', 'Publicação agendada', NOW())")
                        ->execute([$sectionId, mb_substr((string)($data['title'] ?? $row['title']), 0, 150), (string)$row['draft_data']]);
                } else {
                    // Nada para publicar (o rascunho já foi publicado/descartado): só limpa o agendamento.
                    $pdo->prepare('UPDATE sections SET publish_at = NULL WHERE id = ?')->execute([$sectionId]);
                }
                $pdo->commit();

                if (is_array($data)) {
                    $published++;
                    $title = (string)($data['title'] ?? $row['title']);
                    logAudit('publicação agendada', "Seção '{$title}' publicada automaticamente pelo agendador", 0);
                    createNotification('publicou', 'Publicação agendada concluída', "A seção '{$title}' foi publicada automaticamente no horário agendado.", $sectionId, 0);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Erro ao publicar a seção agendada #{$sectionId}: " . $e->getMessage());
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('tunisia_publish')")->fetchColumn();
    }

    if ($published > 0) purgeSiteCache(); // depois do commit: o próximo visitante já vê a versão nova
    return $published;
}

/**
 * Reserva sem cron: verifica publicações vencidas no máximo 1x por minuto.
 * Pensado para rodar em register_shutdown_function (após a resposta ser enviada).
 */
function maybeRunScheduler(): void {
    if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0755, true); }
    if (!is_dir(CACHE_DIR) || !is_writable(CACHE_DIR)) return; // sem pasta gravável, só via cron
    $stamp = CACHE_DIR . '/scheduler.last';
    if (is_file($stamp) && (time() - (int)filemtime($stamp)) < 60) return;
    @touch($stamp);

    $pdo = getPDO();
    if (!$pdo) return;
    try {
        publishDueSections($pdo);
    } catch (Throwable $e) {
        error_log('Erro no agendador: ' . $e->getMessage());
    }
}

/** Registra maybeRunScheduler() para rodar após a resposta (use no topo de páginas). */
function registerSchedulerCheck(): void {
    register_shutdown_function(function () {
        finishResponse();
        maybeRunScheduler();
    });
}

/**
 * Rotina completa do cron: publicações vencidas + backup diário (se "backup automático" estiver ativo).
 * @return array{published:int, backup:?array}
 */
function runScheduledJobs(PDO $pdo): array {
    $result = ['published' => publishDueSections($pdo), 'backup' => null];

    if (getSiteSetting($pdo, 'auto_backup', '0') === '1') {
        $last = strtotime(getSiteSetting($pdo, 'last_backup_at', '')) ?: 0;
        if ($last === 0 || (time() - $last) >= 23 * 3600) {
            $result['backup'] = createBackup($pdo);
        }
    }
    return $result;
}
