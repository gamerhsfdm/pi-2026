<?php
/**
 * API: Busca global do painel
 * GET ?q=termo  →  { success, query, groups: [ { key, label, items: [ { title, subtitle, url, icon } ] } ] }
 *
 * Procura em: páginas do painel (e tópicos de ajuda), seções, itens internos das seções,
 * biblioteca de mídia, histórico de versões e (só administradores) equipe.
 */
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acesso não autorizado']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) $q = mb_substr($q, 0, 80);

/** Remove acentos e põe em minúsculas, para "configuracao" achar "Configurações". */
function searchNormalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    return strtr($s, $map);
}

/** Pontua um texto: 3 = começa com o termo, 2 = palavra começa com o termo, 1 = contém, 0 = não achou. */
function searchScore(string $haystack, array $terms): int {
    $h = searchNormalize($haystack);
    $total = 0;
    foreach ($terms as $t) {
        if ($t === '') continue;
        $pos = mb_strpos($h, $t);
        if ($pos === false) return 0;               // todos os termos precisam aparecer
        if ($pos === 0) $total += 3;
        elseif (mb_substr($h, $pos - 1, 1) === ' ') $total += 2;
        else $total += 1;
    }
    return $total;
}

function likeEscape(string $s): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

$groups = [];

// ---------------------------------------------------------------- Sem termo: atalhos
$pages = [
    ['Dashboard',            'Visão geral, visualizações e atividade recente',           '/dashboard.php',              'layout-grid', 'inicio painel visao geral estatisticas visualizacoes'],
    ['Conteúdo do Site',     'Lista de seções, reordenar e criar nova seção',            '/content.php',                'file-text',   'secoes paginas textos editar reordenar publicar rascunho nova secao'],
    ['Biblioteca de mídia',  'Imagens, vídeos e documentos enviados',                    '/media.php',                  'image',       'imagens fotos videos arquivos upload enviar midia galeria'],
    ['Preview do site',      'Veja o site público em desktop, tablet e celular',         '/preview.php',                'monitor',     'visualizar previa site publico celular tablet'],
    ['Histórico de versões', 'Restaurar e comparar versões anteriores',                  '/history.php',                'history',     'versoes restaurar desfazer comparar alteracoes backup'],
    ['Notificações',         'Publicações e atividades da equipe',                       '/notifications.php',          'bell',        'avisos alertas atividades lidas'],
    ['Configurações',        'Geral, publicação, equipe e domínio',                      '/settings.php',               'settings',    'ajustes preferencias configuracao'],
    ['Geral',                'Configurações > nome do site e e-mail de contato',         '/settings.php?tab=general',   'sliders',     'nome do site email contato configuracoes gerais'],
    ['Publicação',           'Configurações > aprovação e regras de publicação',         '/settings.php?tab=publishing','send',        'aprovacao publicar regras historico retencao'],
    ['Equipe e acesso',      'Configurações > convidar pessoas e permissões',            '/settings.php?tab=team',      'users',       'equipe usuarios convidar membros permissoes administrador editor acesso'],
    ['Domínio',              'Configurações > endereço do site',                         '/settings.php?tab=domain',    'globe',       'dominio endereco url site dns'],
    ['Meu perfil',           'Foto, nome, e-mail e senha',                               '/profile.php',                'user',        'conta foto avatar nome email senha trocar senha'],
    ['Suporte',              'Perguntas frequentes e abertura de chamados',              '/support.php',                'help-circle', 'ajuda duvidas faq chamado contato problema'],
    ['Como salvar sem publicar?', 'Suporte > use "Salvar rascunho" no editor',           '/support.php?q=rascunho',     'life-buoy',   'ajuda rascunho salvar sem publicar'],
    ['Como restaurar uma versão?', 'Suporte > Histórico de versões',                     '/support.php?q=restaurar',    'life-buoy',   'ajuda restaurar versao antiga erro'],
    ['Abrir chamado de suporte', 'Fale com a equipe técnica',                            '/support.php#chamado',        'life-buoy',   'ajuda contato falar suporte problema bug erro'],
];

if ($q === '') {
    $items = [];
    foreach (array_slice($pages, 0, 7) as $p) {
        if ($p[0] === 'Geral' || $p[0] === 'Publicação') continue;
        $items[] = ['title' => $p[0], 'subtitle' => $p[1], 'url' => ADMIN_URL . $p[2], 'icon' => $p[3]];
    }
    echo json_encode(['success' => true, 'query' => '', 'groups' => [['key' => 'quick', 'label' => 'Ir para', 'items' => $items]]], JSON_UNESCAPED_UNICODE);
    exit;
}

$terms = array_values(array_filter(preg_split('/\s+/u', searchNormalize($q))));

// ---------------------------------------------------------------- Páginas e ajuda
$scored = [];
foreach ($pages as $p) {
    $s = max(searchScore($p[0], $terms) * 3, searchScore($p[0] . ' ' . $p[1] . ' ' . $p[4], $terms));
    if ($s > 0) $scored[] = [$s, ['title' => $p[0], 'subtitle' => $p[1], 'url' => ADMIN_URL . $p[2], 'icon' => $p[3]]];
}
usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
if ($scored) {
    $groups[] = ['key' => 'pages', 'label' => 'Páginas e ajuda', 'items' => array_map(fn($r) => $r[1], array_slice($scored, 0, 5))];
}

$pdo = getPDO();
if ($pdo) {
    $like = likeEscape($q);

    // ------------------------------------------------------------ Seções
    try {
        $st = $pdo->prepare("SELECT id, identifier, title, subtitle, status FROM sections
                             WHERE title LIKE :q OR identifier LIKE :q OR subtitle LIKE :q OR description LIKE :q OR badge LIKE :q
                             ORDER BY display_order ASC LIMIT 6");
        $st->execute([':q' => $like]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $status = ($r['status'] ?? '') === 'published' ? 'Publicada' : 'Rascunho';
            $items[] = [
                'title' => $r['title'] ?: $r['identifier'],
                'subtitle' => $status . ' · #' . $r['identifier'],
                'url' => ADMIN_URL . '/editor.php?id=' . (int)$r['id'],
                'icon' => 'layout',
            ];
        }
        if ($items) $groups[] = ['key' => 'sections', 'label' => 'Seções', 'items' => $items];
    } catch (Throwable $e) { error_log('search sections: ' . $e->getMessage()); }

    // ------------------------------------------------------------ Itens internos (cards, etapas, marcadores…)
    try {
        $st = $pdo->prepare("SELECT i.id, i.section_id, i.title, i.subtitle, i.description, s.title AS section_title
                             FROM section_items i LEFT JOIN sections s ON s.id = i.section_id
                             WHERE i.title LIKE :q OR i.subtitle LIKE :q OR i.description LIKE :q OR i.badge LIKE :q
                             ORDER BY i.section_id ASC, i.display_order ASC LIMIT 6");
        $st->execute([':q' => $like]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $detail = trim((string)($r['subtitle'] ?: $r['description']));
            if (mb_strlen($detail) > 70) $detail = mb_substr($detail, 0, 70) . '…';
            $items[] = [
                'title' => $r['title'],
                'subtitle' => 'Em "' . ($r['section_title'] ?? 'Seção') . '"' . ($detail !== '' ? ' · ' . $detail : ''),
                'url' => ADMIN_URL . '/editor.php?id=' . (int)$r['section_id'],
                'icon' => 'square-stack',
            ];
        }
        if ($items) $groups[] = ['key' => 'items', 'label' => 'Conteúdo dentro das seções', 'items' => $items];
    } catch (Throwable $e) { error_log('search items: ' . $e->getMessage()); }

    // ------------------------------------------------------------ Mídia
    try {
        $st = $pdo->prepare("SELECT id, filename, original_name, filepath, file_type FROM media
                             WHERE filename LIKE :q OR original_name LIKE :q ORDER BY created_at DESC LIMIT 5");
        $st->execute([':q' => $like]);
        $items = [];
        $labels = ['image' => 'Imagem', 'video' => 'Vídeo', 'document' => 'Documento'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'title' => $r['original_name'] ?: $r['filename'],
                'subtitle' => ($labels[$r['file_type']] ?? 'Arquivo') . ' · Biblioteca de mídia',
                'url' => ADMIN_URL . '/media.php?q=' . rawurlencode($q),
                'icon' => $r['file_type'] === 'video' ? 'video' : ($r['file_type'] === 'document' ? 'file' : 'image'),
                'thumb' => $r['file_type'] === 'image' ? SITE_URL . '/' . ltrim($r['filepath'], '/') : '',
            ];
        }
        if ($items) $groups[] = ['key' => 'media', 'label' => 'Mídia', 'items' => $items];
    } catch (Throwable $e) { error_log('search media: ' . $e->getMessage()); }

    // ------------------------------------------------------------ Histórico
    try {
        $st = $pdo->prepare("SELECT id, title, user_name, action_type, note, created_at FROM versions
                             WHERE title LIKE :q OR user_name LIKE :q OR note LIKE :q ORDER BY created_at DESC LIMIT 4");
        $st->execute([':q' => $like]);
        $items = [];
        $acts = ['published' => 'Publicado', 'saved_draft' => 'Rascunho', 'restored' => 'Restaurado'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'title' => $r['title'],
                'subtitle' => ($acts[$r['action_type']] ?? 'Versão') . ' por ' . ($r['user_name'] ?? 'Usuário') . ' · ' . timeAgo($r['created_at']),
                'url' => ADMIN_URL . '/history.php',
                'icon' => 'history',
            ];
        }
        if ($items) $groups[] = ['key' => 'history', 'label' => 'Histórico de versões', 'items' => $items];
    } catch (Throwable $e) { error_log('search versions: ' . $e->getMessage()); }

    // ------------------------------------------------------------ Equipe (somente administradores)
    if (isAdmin()) {
        try {
            $st = $pdo->prepare("SELECT id, name, email, role FROM users WHERE name LIKE :q OR email LIKE :q ORDER BY name ASC LIMIT 4");
            $st->execute([':q' => $like]);
            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = [
                    'title' => $r['name'],
                    'subtitle' => ($r['role'] === 'admin' ? 'Administrador' : 'Editor') . ' · ' . $r['email'],
                    'url' => ADMIN_URL . '/settings.php?tab=team',
                    'icon' => 'user',
                ];
            }
            if ($items) $groups[] = ['key' => 'team', 'label' => 'Equipe', 'items' => $items];
        } catch (Throwable $e) { error_log('search users: ' . $e->getMessage()); }
    }
}

echo json_encode(['success' => true, 'query' => $q, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
