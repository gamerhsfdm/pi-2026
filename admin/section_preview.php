<?php
/**
 * Pré-visualização fiel de uma seção — Tunísia CMS
 *
 * Recebe por POST os campos do formulário do editor (ainda não salvos) e devolve o HTML REAL do site:
 * quem monta a página é o próprio index.php, com o mesmo CSS e o mesmo JS do site público. Aqui só
 * se substitui a seção em edição pelos dados do formulário e se esconde o restante da página.
 * Nada é gravado no banco e nenhuma visita é contada (o endereço contém /admin e a requisição é POST).
 *
 * O editor injeta o resultado em um <iframe sandbox="allow-scripts"> (origem isolada do painel).
 */
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Sessão expirada. Entre novamente no painel.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Sessão inválida ou expirada. Atualize a página.');
}

require_once __DIR__ . '/../includes/section_items.php';
require_once __DIR__ . '/../includes/section_style.php';

$pdo = getPDO();
if (!$pdo) {
    http_response_code(503);
    exit('Não foi possível conectar ao banco de dados.');
}

// Leitura segura de campos simples e de listas enviadas pelo formulário.
$postText = function (string $key, string $default = ''): string {
    return (isset($_POST[$key]) && is_scalar($_POST[$key])) ? trim((string)$_POST[$key]) : $default;
};
$postList = function (string $key): array {
    return (isset($_POST[$key]) && is_array($_POST[$key])) ? array_values($_POST[$key]) : [];
};
$cell = function (array $list, int $i): string {
    return (isset($list[$i]) && is_scalar($list[$i])) ? trim((string)$list[$i]) : '';
};

// Seção em edição (se já existir no banco).
$sectionId = max(0, (int)($_POST['section_id'] ?? 0));
$dbRow = null;
if ($sectionId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM sections WHERE id = ? LIMIT 1');
    $stmt->execute([$sectionId]);
    $dbRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($dbRow) {
    $identifier = (string)$dbRow['identifier'];
} else {
    // Seção nova (ainda sem identificador salvo): usa um identificador provisório que nunca colide com as seções do site.
    $raw = $postText('identifier');
    if ($raw === '') $raw = $postText('title');
    $ascii = (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtolower($raw));
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    $identifier = 'previa-' . ($slug !== '' ? $slug : 'nova-secao');
}

// Campos de texto (mesmas regras do salvamento do editor).
$title = $postText('title');
$subtitle = $postText('subtitle');
$description = isset($_POST['description']) ? $postText('description') : $subtitle;
$badge = $postText('badge');
$imagePath = $postText('image_path', (string)($dbRow['image_path'] ?? ''));
$videoPath = $postText('video_path');

// Aparência: os valores passam pelo mesmo validador usado ao salvar (só listas fixas e cores #RRGGBB).
$sxInput = (isset($_POST['sx']) && is_array($_POST['sx'])) ? $_POST['sx'] : [];
$style = sanitizeSectionStyle($sxInput);
$bgChoice = (isset($sxInput['bg_image']) && is_scalar($sxInput['bg_image'])) ? trim((string)$sxInput['bg_image']) : '';
if (preg_match('/\.(mp4|webm|ogg)$/i', $bgChoice)) {
    $videoPath = $bgChoice;       // vídeo escolhido na aba Fundo vira o vídeo da seção
    $style['bg_image'] = '';
}

// Itens internos (cards, etapas, listas...) exatamente como o editor os gravaria.
$types = $postList('item_type');
$titles = $postList('item_title');
$subtitles = $postList('item_subtitle');
$descriptions = $postList('item_description');
$icons = $postList('item_icon');
$images = $postList('item_image_path');
$badges = $postList('item_badge');
$links = $postList('item_link_url');
$allowedTypes = ['card', 'cultural_card', 'timeline', 'knowledge', 'business', 'feature', 'model', 'objective', 'competency', 'custom', 'stat', 'map_marker'];
$items = [];
$count = max(count($titles), count($types));
for ($i = 0; $i < $count; $i++) {
    $itemTitle = $cell($titles, $i);
    $itemDescription = $cell($descriptions, $i);
    $itemSubtitle = $cell($subtitles, $i);
    if ($itemTitle === '' && $itemDescription === '' && $itemSubtitle === '') continue;
    $itemType = $cell($types, $i);
    if (!in_array($itemType, $allowedTypes, true)) $itemType = 'card';
    $itemIcon = $cell($icons, $i);
    $itemImage = $cell($images, $i);
    $itemBadge = $cell($badges, $i);
    $itemLink = $cell($links, $i);
    $items[] = [
        'item_type' => $itemType,
        'title' => $itemTitle !== '' ? $itemTitle : 'Novo item',
        'subtitle' => $itemSubtitle !== '' ? $itemSubtitle : null,
        'description' => $itemDescription !== '' ? $itemDescription : null,
        'icon' => $itemIcon !== '' ? $itemIcon : null,
        'image_path' => $itemImage !== '' ? $itemImage : null,
        'badge' => $itemBadge !== '' ? $itemBadge : null,
        'link_url' => $itemLink !== '' ? $itemLink : null,
        'meta_data' => null,
        'display_order' => $i + 1,
        'status' => 'published',
        'is_visible' => 1,
    ];
}

// Dados lidos pelo gancho do index.php (só ativo quando SX_SECTION_PREVIEW está definida).
$sxPreviewPayload = [
    'identifier' => $identifier,
    'row' => [
        'title' => $title,
        'subtitle' => $subtitle,
        'description' => $description,
        'badge' => $badge !== '' ? $badge : null,
        'primary_btn_text' => $postText('primary_btn_text'),
        'primary_btn_link' => $postText('primary_btn_link'),
        'secondary_btn_text' => $postText('secondary_btn_text'),
        'secondary_btn_link' => $postText('secondary_btn_link'),
        'image_path' => $imagePath !== '' ? $imagePath : null,
        'video_path' => $videoPath !== '' ? $videoPath : null,
        'is_visible' => 1,
        'status' => 'published',
        'deleted_at' => null,
    ],
    'items' => $items,
    'style' => $style,
];
define('SX_SECTION_PREVIEW', true);

// Qual elemento da página é a seção em edição e o que deve ser escondido.
$idAttr = (string)preg_replace('/[^A-Za-z0-9_\-]/', '', $identifier);
if ($identifier === 'hero') {
    $targetSelector = '#inicio';                       // o banner mantém o menu no topo, como no site
    $hideCss = 'body > section:not(#inicio), body > footer { display: none !important; }';
} elseif ($identifier === 'rodape') {
    $targetSelector = 'body > footer.footer';
    $hideCss = 'body > header, body > section { display: none !important; }';
} else {
    $targetSelector = 'body > section[id="' . $idAttr . '"]';
    $hideCss = 'body > header, body > footer, body > section:not([id="' . $idAttr . '"]) { display: none !important; }';
}

$baseHref = rtrim((string)SITE_URL, '/') . '/';

// Trecho inserido logo após <head>: base das URLs relativas + ajustes só do preview.
$headStart = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">'
    . '<script>'
    // Sem "observadores de rolagem": os cards e contadores aparecem no estado final, sem esperar a rolagem.
    . 'window.IntersectionObserver=function(){this.observe=function(){};this.unobserve=function(){};this.disconnect=function(){};this.takeRecords=function(){return[];};};'
    . '(function(){'
    . 'var SEL=' . json_encode($targetSelector, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
    . 'var q=function(s){return document.querySelector(s);};'
    // Estado final das animações do site (o que o visitante vê depois de rolar até a seção).
    . 'function settle(){'
    . 'var one=[q(".hero .tag"),q(".hero h1"),q(".hero p"),q(".hero .hero-buttons")];'
    . 'var all=document.querySelectorAll(".card,.cultural-card,.knowledge-card,.feature,.model-card,.timeline-item");'
    . 'var list=one.concat(Array.prototype.slice.call(all));'
    . 'list.forEach(function(el){if(!el)return;el.style.transition="none";el.style.opacity="1";el.style.transform="translateY(0)";});'
    . 'Array.prototype.forEach.call(document.querySelectorAll(".timeline-item"),function(el){el.classList.add("active");});'
    . '}'
    // Avisa o painel da altura real da seção para ele dimensionar o quadro do preview.
    . 'var queued=false;'
    . 'function report(){queued=false;var t=q(SEL);var b=t?Math.ceil(t.getBoundingClientRect().bottom+(window.pageYOffset||0)):document.documentElement.scrollHeight;parent.postMessage({sxpv:1,bottom:b},"*");}'
    . 'function ask(){if(queued)return;queued=true;(window.requestAnimationFrame||setTimeout)(report);}'
    . 'document.addEventListener("DOMContentLoaded",function(){setTimeout(settle,0);setTimeout(settle,900);'
    . 'if(window.ResizeObserver){new ResizeObserver(ask).observe(document.body);}ask();});'
    . 'window.addEventListener("load",function(){settle();ask();});'
    // Links do site não devem navegar dentro do preview.
    . 'document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a"):null;if(a)e.preventDefault();},true);'
    . '})();'
    . '</script>';

// Trecho inserido antes de </head> (depois do CSS do site, para vencer em caso de empate).
$headEnd = '<style>'
    . 'html{scroll-behavior:auto !important;overflow:hidden !important;}'
    . $hideCss
    . '::-webkit-scrollbar{display:none;}'
    . 'html.js .sx-anim{opacity:1 !important;transform:none !important;transition:none !important;}'
    . '</style>';

ob_start(function ($html) use ($headStart, $headEnd) {
    $html = (string)$html;
    $open = stripos($html, '<head>');
    if ($open !== false) {
        $html = substr($html, 0, $open + 6) . $headStart . substr($html, $open + 6);
    } else {
        $html = $headStart . $html;
    }
    $close = stripos($html, '</head>');
    if ($close !== false) {
        $html = substr($html, 0, $close) . $headEnd . substr($html, $close);
    }
    return $html;
});

require __DIR__ . '/../index.php';