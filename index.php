<?php
/**
 * Site Principal — Projeto Tunísia
 * Integrado dinamicamente com o banco de dados do Tunísia CMS
 */
// O site público não usa sessão: evita criar arquivo de sessão e cookie para cada visitante.
define('NO_SESSION', true);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/section_items.php';
require_once __DIR__ . '/includes/section_style.php';

// Registra acesso real de visitante (gravação ocorre após o envio da resposta)
recordPageView();
require_once __DIR__ . '/includes/scheduler.php';
registerSchedulerCheck(); // publicações agendadas (reserva sem cron), após enviar a resposta

// ---- Cache de página: visitantes anônimos recebem o HTML pronto, sem consultar o banco ----
$cacheTtl = siteCacheTtl();
$cacheEligible = $cacheTtl > 0
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && empty($_SERVER['QUERY_STRING'])
    && !isset($_COOKIE[session_name()]); // quem tem sessão do painel sempre vê a versão atual (preview)

if ($cacheEligible) {
    $cacheFile = siteCacheFile();
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $cacheTtl) {
        $etag = '"' . dechex((int)filemtime($cacheFile)) . '-' . dechex((int)filesize($cacheFile)) . '"';
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('ETag: ' . $etag);
        header('X-Cache: HIT');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        readfile($cacheFile);
        exit;
    }
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('X-Cache: MISS');
    // Só grava o cache se a página foi gerada com dados reais do banco (veja $siteCacheable abaixo).
    ob_start(function ($buffer) {
        if (!empty($GLOBALS['siteCacheable']) && http_response_code() === 200) {
            writeSiteCache($buffer);
        }
        return $buffer;
    });
} else {
    header('Cache-Control: private, no-cache');
}

$pdo = getPDO();

// Carrega configurações gerais
$settings = [];
$sections = [];

if ($pdo) {
    try {
        $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
        while ($row = $stmtSettings->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $orderedSections = [];
        $stmtSections = $pdo->query("SELECT * FROM sections WHERE deleted_at IS NULL AND is_visible = 1 AND status = 'published' ORDER BY display_order ASC");
        while ($sec = $stmtSections->fetch()) {
            $sections[$sec['identifier']] = $sec;
            $orderedSections[] = $sec['identifier'];
        }
    } catch (Exception $e) {
        error_log("Erro ao carregar dados do site: " . $e->getMessage());
    }
}

// Carrega o conteúdo interno das seções com UMA consulta (a migração inicial só roda se ainda não foi feita).
$itemsBySection = [];
if ($pdo && $sections) {
    $itemsByDbId = [];
    try {
        $sectionIds = array_map(fn($row) => (int)$row['id'], array_values($sections));
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmtItems = $pdo->prepare("SELECT * FROM section_items WHERE section_id IN ($placeholders) ORDER BY display_order ASC, id ASC");
        $stmtItems->execute($sectionIds);
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
            $itemsByDbId[(int)$itemRow['section_id']][] = $itemRow;
        }
    } catch (Throwable $e) {
        error_log('Erro ao carregar itens das seções: ' . $e->getMessage());
    }
    foreach ($sections as $identifier => $sectionRow) {
        $custom = json_decode((string)($sectionRow['custom_data'] ?? ''), true);
        if (is_array($custom) && !empty($custom['_section_items_migrated'])) {
            $itemsBySection[$identifier] = $itemsByDbId[(int)$sectionRow['id']] ?? [];
            continue;
        }
        try { $itemsBySection[$identifier] = ensureSectionItems($pdo, (int)$sectionRow['id'], (string)$identifier); }
        catch (Throwable $e) { error_log('Erro ao migrar itens da seção ' . $identifier . ': ' . $e->getMessage()); $itemsBySection[$identifier] = []; }
    }
}
// Pré-visualização do painel (admin/section_preview.php): troca apenas a seção em edição pelos dados
// ainda não publicados (rascunho do formulário), para o preview usar exatamente este mesmo HTML e CSS.
// Sem a constante SX_SECTION_PREVIEW (ou seja, em toda visita real ao site) este bloco não faz nada.
if (defined('SX_SECTION_PREVIEW') && isset($sxPreviewPayload) && is_array($sxPreviewPayload)) {
    $pvId = (string)($sxPreviewPayload['identifier'] ?? '');
    if ($pvId !== '') {
        $sections[$pvId] = array_merge(
            $sections[$pvId] ?? ['id' => 0, 'display_order' => 999],
            (array)($sxPreviewPayload['row'] ?? []),
            ['identifier' => $pvId, 'style_data' => json_encode((array)($sxPreviewPayload['style'] ?? []), JSON_UNESCAPED_UNICODE)]
        );
        $itemsBySection[$pvId] = (array)($sxPreviewPayload['items'] ?? []);
        if (!isset($orderedSections) || !in_array($pvId, $orderedSections, true)) $orderedSections[] = $pvId;
    }
}
// Só páginas geradas com dados reais do banco podem ir para o cache.
$siteCacheable = ($pdo !== null && !empty($sections));

// Com o banco disponível, uma seção excluída/oculta/em rascunho no painel NÃO aparece no site.
// Os textos padrão abaixo só servem quando o banco está fora do ar.
$siteFromDb = ($pdo !== null && !empty($sections));
$showSection = fn(string $id): bool => !$siteFromDb || isset($sections[$id]);

// Aparência personalizada por seção (alinhamento, fontes, cores, fundo...), definida no painel.
$sectionStyles = [];
foreach ($sections as $sxId => $sxRow) {
    $sectionStyles[$sxId] = decodeSectionStyle($sxRow['style_data'] ?? null);
}
$sxClass = function (string $id) use ($sectionStyles): string {
    $c = sectionStyleClasses($sectionStyles[$id] ?? sectionStyleDefaults());
    return $c !== '' ? ' ' . $c : '';
};
$sxAttrs = fn(string $id): string => sectionStyleAttrs($sectionStyles[$id] ?? sectionStyleDefaults());
$sxFontsUrl = sectionStyleFontsUrl($sectionStyles);

// Banner: o vídeo de fundo é substituído quando o painel define imagem/cor de fundo (ou "ocultar vídeo").
// Sem isso o <video> (que cobre toda a seção) esconderia qualquer fundo escolhido.
$heroStyle = $sectionStyles['hero'] ?? sectionStyleDefaults();
$hideHeroVideo = $heroStyle['hide_video'] === '1' || $heroStyle['bg_image'] !== '' || $heroStyle['bg_mode'] !== '';

// Fallbacks seguros com os dados originais
$hero = $sections['hero'] ?? [
    'title' => 'Tunísia: Cultura,<br />Inovação e<br /><span>Conhecimento</span>',
    'badge' => 'Projeto Integrador 2026',
    'description' => 'Uma jornada completa pelos aspectos culturais, econômicos, históricos e tecnológicos de um dos países mais fascinantes do Mediterrâneo.',
    'primary_btn_text' => 'Explorar Projeto →',
    'primary_btn_link' => '#sobre',
    'secondary_btn_text' => 'Saiba Mais',
    'secondary_btn_link' => '#mapa',
    'video_path' => 'assets/img/videotunisia.mp4'
];

$sobre = $sections['sobre'] ?? [
    'badge' => 'SOBRE O PROJETO',
    'title' => 'Projeto Integrador',
    'description' => 'Um trabalho multidisciplinar que conecta conhecimentos de diferentes áreas do saber, desenvolvendo competências essenciais para a formação acadêmica e pessoal dos alunos.'
];

$mapa = $sections['mapa'] ?? [
    'badge' => 'EXPLORE A TUNÍSIA',
    'title' => 'Mapa Interativo',
    'description' => 'Descubra as principais cidades tunisianas, suas curiosidades e atrações turísticas. Clique nos marcadores para explorar cada região.'
];

$cultura = $sections['cultura'] ?? [
    'badge' => 'SEMANA CULTURAL',
    'title' => 'Celebrando a Tunísia',
    'description' => 'Uma semana dedicada a vivenciar e compartilhar a rica cultura tunisiana através de experiências imersivas e apresentações envolventes.'
];

$emp = $sections['empreendedorismo'] ?? [
    'badge' => 'FEIRA DO EMPREENDEDORISMO',
    'title' => 'Empreendedorismo com <span class="highlight">Sabor Tunisiano</span>',
    'description' => 'Transformando tradição em oportunidade através do Bambalouni, um doce típico tunisiano que combina cultura, gastronomia e potencial de mercado.'
];

$conhecimento = $sections['conhecimento'] ?? [
    'badge' => 'FEIRA DO CONHECIMENTO',
    'title' => 'Descobertas e <span class="highlight">Pesquisas</span>',
    'description' => 'Resultados das pesquisas desenvolvidas sobre a Tunísia, reunindo dados geográficos, econômicos, tecnológicos, educacionais e ambientais.'
];

$cronograma = $sections['cronograma'] ?? [
    'badge' => 'DESENVOLVIMENTO DO PI',
    'title' => 'Cronograma do Projeto',
    'description' => 'Acompanhe as principais etapas do desenvolvimento do Projeto Integrador, desde a escolha do país até a apresentação final.'
];

$rodape = $sections['rodape'] ?? [
    'title' => 'Rodapé e Contatos',
    'subtitle' => 'Informações Institucionais',
    'description' => 'Projeto Integrador dedicado ao estudo da cultura, história, economia, turismo e inovação da Tunísia.'
];
$baseUrl = siteBaseUrl();
$canonicalUrl = $baseUrl . '/';
$ogImageUrl = $baseUrl . '/assets/img/og-image.jpg';
$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            '@id' => $canonicalUrl . '#website',
            'url' => $canonicalUrl,
            'name' => $settings['site_name'] ?? 'Projeto Tunísia',
            'description' => $settings['site_description'] ?? '',
            'inLanguage' => $settings['primary_language'] ?? 'pt-BR',
        ],
        [
            '@type' => 'EducationalOrganization',
            'name' => 'Projeto Integrador 2026 — Senac',
            'url' => $canonicalUrl,
            'logo' => $baseUrl . '/assets/img/logo.png',
            'email' => $settings['admin_email'] ?? null,
        ],
    ],
];
$structuredData['@graph'][1] = array_filter($structuredData['@graph'][1], fn($v) => $v !== null && $v !== '');

?>
<!doctype html>
<html lang="<?= e($settings['primary_language'] ?? 'pt-BR') ?>">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= e($settings['site_title'] ?? $settings['site_name'] ?? 'Projeto Tunísia') ?></title>
    <meta name="description" content="<?= e($settings['site_description'] ?? 'Projeto Tunísia') ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="theme-color" content="#0f172a">
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <link rel="icon" type="image/png" href="./assets/img/logo.png">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($settings['site_name'] ?? 'Projeto Tunísia') ?>">
    <meta property="og:locale" content="<?= e(str_replace('-', '_', $settings['primary_language'] ?? 'pt-BR')) ?>">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <meta property="og:title" content="<?= e($settings['site_title'] ?? $settings['site_name'] ?? 'Projeto Tunísia') ?>">
    <meta property="og:description" content="<?= e($settings['site_description'] ?? 'Projeto Tunísia') ?>">
    <meta property="og:image" content="<?= e($ogImageUrl) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($settings['site_title'] ?? $settings['site_name'] ?? 'Projeto Tunísia') ?>">
    <meta name="twitter:description" content="<?= e($settings['site_description'] ?? 'Projeto Tunísia') ?>">
    <meta name="twitter:image" content="<?= e($ogImageUrl) ?>">
    <?php if (!$hideHeroVideo): ?>
    <link rel="preload" as="image" href="./assets/img/hero-poster.webp" type="image/webp" fetchpriority="high">
    <?php endif; ?>
    <script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <link rel="stylesheet" href="./assets/css/style.css?v=<?= assetVersion('assets/css/style.css') ?>" />
    <link rel="stylesheet" href="./assets/css/section-styles.css?v=<?= assetVersion('assets/css/section-styles.css') ?>" />
    <script>document.documentElement.classList.add('js');</script>
    <?php if ($sxFontsUrl !== ''): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e($sxFontsUrl) ?>" />
    <?php endif; ?>
  </head>
  <body>
    <header class="header">
      <div class="container nav-container">
        <div class="logo">
          <div class="logo-img">
            <?= pictureTag('assets/img/logo.png', 'Logo Projeto Tunísia', ['loading' => 'eager']) ?>
          </div>
          <div class="logo-text">
            <h2><?= e($settings['site_name'] ?? 'Projeto Tunísia') ?></h2>
            <span>Cultura, Inovação e Conhecimento</span>
          </div>
        </div>

        <nav class="nav nav-menu">
          <a href="#">Início</a>
          <?php if ($showSection('sobre')): ?><a href="#sobre">Sobre o Projeto</a><?php endif; ?>
          <?php if ($showSection('mapa')): ?><a href="#mapa">Mapa Interativo</a><?php endif; ?>
          <?php if ($showSection('cultura')): ?><a href="#cultura">Semana Cultural</a><?php endif; ?>
          <?php if ($showSection('empreendedorismo')): ?><a href="#empreendedorismo">Empreendedorismo</a><?php endif; ?>
          <?php if ($showSection('conhecimento')): ?><a href="#conhecimento">Feira do Conhecimento</a><?php endif; ?>
          <a href="<?= ADMIN_URL ?>/index.php" class="btn-header-mobile">Painel Admin</a>
        </nav>

        <a href="<?= ADMIN_URL ?>/index.php" class="btn-header"> Painel Admin </a>

        <button class="hamburger" aria-label="Abrir Menu">
          <span class="bar"></span>
          <span class="bar"></span>
          <span class="bar"></span>
        </button>
      </div>
    </header>

    <section class="hero<?= $hideHeroVideo ? ' hero-novideo' : '' ?><?= $sxClass('hero') ?>" id="inicio"<?= $sxAttrs('hero') ?>>
      <?php if (!$hideHeroVideo): ?>
      <video autoplay loop muted playsinline preload="metadata" poster="./assets/img/hero-poster.webp" class="hero-video" data-hero-video>
        <source src="./<?= e($hero['video_path'] ?? 'assets/img/videotunisia.mp4') ?>" type="video/mp4" />
        Seu navegador não suporta vídeos.
      </video>
      <?php endif; ?>

      <div class="overlay"></div>

      <div class="container hero-content">
        <div class="tag">
          <span class="dot"></span>
          <?= e($hero['badge'] ?? 'Projeto Integrador 2026') ?>
        </div>

        <h1>
          <?= nl2br(e($hero['title'] ?? 'Tunísia: Cultura, Inovação e Conhecimento')) ?>
        </h1>

        <?php if (!empty($hero['subtitle'])): ?><p class="sx-subtitle"><?= e($hero['subtitle']) ?></p><?php endif; ?>
        <p class="sx-description">
          <?= e($hero['description'] ?? '') ?>
        </p>

        <div class="hero-buttons">
          <a href="<?= e($hero['primary_btn_link'] ?? '#sobre') ?>" class="btn-primary"> <?= e($hero['primary_btn_text'] ?? 'Explorar Projeto →') ?> </a>
          <a href="<?= e($hero['secondary_btn_link'] ?? '#mapa') ?>" class="btn-secondary"> <?= e($hero['secondary_btn_text'] ?? 'Saiba Mais') ?> </a>
        </div>
      </div>

      <div class="scroll-indicator"></div>
    </section>

    <?php if ($showSection('sobre')): ?>
    <section class="sobre-projeto<?= $sxClass('sobre') ?>" id="sobre"<?= $sxAttrs('sobre') ?>>
      <div class="titulo-section">
        <span><?= e($sobre['badge'] ?? 'SOBRE O PROJETO') ?></span>
        <h2><?= e($sobre['title'] ?? 'Projeto Integrador') ?></h2>
        <?php if (!empty($sobre['subtitle'])): ?><p class="sx-subtitle"><?= e($sobre['subtitle']) ?></p><?php endif; ?>
        <p class="sx-description"><?= e($sobre['description'] ?? '') ?></p>
      </div>

      <div class="cards">
        <?php foreach (($itemsBySection['sobre'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'card') continue; ?>
          <div class="card">
            <div class="icone"><?= e($item['icon'] ?? '✨') ?></div>
            <h3><?= e($item['title'] ?? '') ?></h3>
            <p><?= e($item['description'] ?? '') ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="objetivos-box">
        <div class="coluna">
          <h3>Objetivos do Trabalho</h3>
          <ul><?php foreach (($itemsBySection['sobre'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'objective') continue; ?><li><?= e($item['description'] ?? $item['title'] ?? '') ?></li><?php endforeach; ?></ul>
        </div>
        <div class="coluna">
          <h3>Competências Desenvolvidas</h3>
          <ul><?php foreach (($itemsBySection['sobre'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'competency') continue; ?><li><?= e($item['description'] ?? $item['title'] ?? '') ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($showSection('mapa')): ?>
    <section class="mapa-section<?= $sxClass('mapa') ?>" id="mapa"<?= $sxAttrs('mapa') ?>>
      <div class="container">
        <div class="section-header">
          <span><?= e($mapa['badge'] ?? 'EXPLORE A TUNÍSIA') ?></span>
          <h2><?= e($mapa['title'] ?? 'Mapa Interativo') ?></h2>
          <?php if (!empty($mapa['subtitle'])): ?><p class="sx-subtitle"><?= e($mapa['subtitle']) ?></p><?php endif; ?>
          <p class="sx-description"><?= e($mapa['description'] ?? '') ?></p>
        </div>

        <div class="mapa-container">
          <div class="mapa-box">
            <?= pictureTag($mapa['image_path'] ?? 'assets/img/mapa.png', 'Mapa da Tunísia') ?>

            <?php foreach (($itemsBySection['mapa'] ?? []) as $marker): if (($marker['item_type'] ?? '') !== 'map_marker') continue; $coords = explode(',', (string)($marker['link_url'] ?? '40%,50%')); $top = trim($coords[0] ?? '40%'); $left = trim($coords[1] ?? '50%'); ?>
              <button class="marker" style="top: <?= e($top) ?>; left: <?= e($left) ?>" data-city="<?= e($marker['title'] ?? '') ?>" data-regiao="<?= e($marker['subtitle'] ?? '') ?>" data-atracao="<?= e($marker['description'] ?? '') ?>" data-curiosidade="<?= e($marker['badge'] ?? '') ?>" aria-label="Ver <?= e($marker['title'] ?? 'local') ?>"></button>
            <?php endforeach; ?>
          </div>

          <div class="info-card" id="map-info-card">
            <div class="info-header">
              <div class="icon-box">🌍</div>
              <div>
                <h3 id="info-title">Informações Gerais</h3>
                <span id="info-subtitle">República da Tunísia</span>
              </div>
            </div>

            <div class="info-item">
              <span id="label-1">👥 População / Região</span>
              <strong id="val-1">12,3 milhões</strong>
            </div>

            <div class="info-item">
              <span id="label-2">🗣️ Idioma / Atração</span>
              <strong id="val-2">Árabe e Francês</strong>
            </div>

            <div class="info-item">
              <span id="label-3">💲 Moeda / Curiosidade</span>
              <strong id="val-3">Dinar Tunisiano (TND)</strong>
            </div>

            <div class="info-item">
              <span>🌍 Continente</span>
              <strong>África</strong>
            </div>

            <div class="info-item">
              <span>📏 Área Total</span>
              <strong>163.610 km²</strong>
            </div>

            <div class="country-code" id="info-code">TN</div>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($showSection('cultura')): ?>
    <section class="semana-cultural<?= $sxClass('cultura') ?>" id="cultura"<?= $sxAttrs('cultura') ?>>
      <div class="container">
        <div class="section-header">
          <span><?= e($cultura['badge'] ?? 'SEMANA CULTURAL') ?></span>
          <h2><?= e($cultura['title'] ?? 'Celebrando a Tunísia') ?></h2>
          <?php if (!empty($cultura['subtitle'])): ?><p class="sx-subtitle"><?= e($cultura['subtitle']) ?></p><?php endif; ?>
          <p class="sx-description"><?= e($cultura['description'] ?? '') ?></p>
        </div>

        <div class="cultural-grid">
          <?php foreach (($itemsBySection['cultura'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'cultural_card') continue; ?>
            <div class="cultural-card">
              <div class="cultural-image">
                <?php if (!empty($item['image_path'])): ?><?= pictureTag((string)$item['image_path'], (string)($item['title'] ?? '')) ?><?php endif; ?>
                <div class="cultural-icon"><?= e($item['icon'] ?? '✨') ?></div>
              </div>
              <div class="cultural-content"><h3><?= e($item['title'] ?? '') ?></h3><p><?= e($item['description'] ?? '') ?></p><span><?= e($item['subtitle'] ?? '') ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($showSection('empreendedorismo')): ?>
    <section class="empreendedorismo<?= $sxClass('empreendedorismo') ?>" id="empreendedorismo"<?= $sxAttrs('empreendedorismo') ?>>
      <div class="container">
        <div class="section-header">
          <span><?= e($emp['badge'] ?? 'FEIRA DO EMPREENDEDORISMO') ?></span>
          <h2><?= $emp['title'] ?></h2>
          <?php if (!empty($emp['subtitle'])): ?><p class="sx-subtitle"><?= e($emp['subtitle']) ?></p><?php endif; ?>
          <p class="sx-description"><?= e($emp['description'] ?? '') ?></p>
        </div>

        <div class="business-grid">
          <?php foreach (($itemsBySection['empreendedorismo'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'business') continue; ?>
            <div class="business-card"><div class="business-image"><?php
              // Usa a imagem do próprio item; se estiver vazia, cai na "Imagem da seção" definida no admin.
              $bizImg = trim((string)($item['image_path'] ?? '')) !== '' ? $item['image_path'] : ($emp['image_path'] ?? '');
              if ($bizImg !== ''): ?><?= pictureTag((string)$bizImg, (string)($item['title'] ?? '')) ?><?php endif; ?></div><div class="business-content"><h3><?= e($item['title'] ?? '') ?></h3><p><?= e($item['description'] ?? '') ?></p></div></div>
          <?php endforeach; ?>
          <div class="features">
            <?php foreach (($itemsBySection['empreendedorismo'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'feature') continue; ?>
              <div class="feature"><div class="feature-icon"><?= e($item['icon'] ?? '✨') ?></div><div><h4><?= e($item['title'] ?? '') ?></h4><p><?= e($item['description'] ?? '') ?></p></div></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="business-models">
          <?php foreach (($itemsBySection['empreendedorismo'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'model') continue; ?>
            <div class="model-card"><h3><?= e($item['title'] ?? '') ?></h3><ul><?php foreach (preg_split('/\s*;\s*/', (string)($item['description'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul></div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($showSection('conhecimento')): ?>
    <section class="feira-conhecimento<?= $sxClass('conhecimento') ?>" id="conhecimento"<?= $sxAttrs('conhecimento') ?>>
      <div class="container">
        <div class="section-header">
          <span><?= e($conhecimento['badge'] ?? 'FEIRA DO CONHECIMENTO') ?></span>
          <h2><?= titleWithHighlight($conhecimento['title'] ?? '') ?></h2>
          <?php if (!empty($conhecimento['subtitle'])): ?><p class="sx-subtitle"><?= e($conhecimento['subtitle']) ?></p><?php endif; ?>
          <p class="sx-description"><?= e($conhecimento['description'] ?? '') ?></p>
        </div>

        <div class="knowledge-grid">
          <?php foreach (($itemsBySection['conhecimento'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'knowledge') continue; ?>
            <div class="knowledge-card"><div class="knowledge-icon"><?= e($item['icon'] ?? '📘') ?></div><h3><?= e($item['title'] ?? '') ?></h3><ul><?php foreach (preg_split('/\s*;\s*/', (string)($item['description'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul></div>
          <?php endforeach; ?>
        </div>

        <div class="knowledge-stats">
          <?php foreach (($itemsBySection['conhecimento'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'stat') continue; ?>
            <div class="stat-box"><h3><?= e($item['title'] ?? '') ?></h3><span><?= e($item['description'] ?? '') ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($showSection('cronograma')): ?>
    <section class="cronograma<?= $sxClass('cronograma') ?>" id="cronograma"<?= $sxAttrs('cronograma') ?>>
      <div class="container">
        <div class="section-header">
          <span><?= e($cronograma['badge'] ?? 'DESENVOLVIMENTO DO PI') ?></span>
          <h2><?= e($cronograma['title'] ?? 'Cronograma do Projeto') ?></h2>
          <?php if (!empty($cronograma['subtitle'])): ?><p class="sx-subtitle"><?= e($cronograma['subtitle']) ?></p><?php endif; ?>
          <p class="sx-description"><?= e($cronograma['description'] ?? '') ?></p>
        </div>

        <div class="timeline">
          <?php foreach (($itemsBySection['cronograma'] ?? []) as $item): if (($item['item_type'] ?? '') !== 'timeline') continue; ?>
            <div class="timeline-item"><div class="timeline-dot"></div><div class="timeline-card"><div class="timeline-date"><?= e($item['subtitle'] ?? '') ?></div><h3><?= e($item['title'] ?? '') ?></h3><p><?= e($item['description'] ?? '') ?></p></div></div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php
      $knownIdentifiers = ['hero', 'sobre', 'mapa', 'cultura', 'empreendedorismo', 'conhecimento', 'cronograma', 'rodape'];
      foreach ($sections as $identifier => $customSection):
        if (in_array($identifier, $knownIdentifiers, true)) continue;
    ?>
      <section id="<?= e($identifier) ?>" class="custom-content-section<?= $sxClass((string)$identifier) ?>" style="padding: 5rem 1.5rem; background: #f8fafc;<?= e(sectionStyleInline($sectionStyles[$identifier] ?? sectionStyleDefaults())) ?>"<?= (($sectionStyles[$identifier]['animation'] ?? '') !== '') ? ' data-sx-anim="' . e($sectionStyles[$identifier]['animation']) . '"' : '' ?>>
        <div class="container" style="max-width: 1100px; margin: 0 auto;">
          <?php $customStyle = $sectionStyles[$identifier] ?? sectionStyleDefaults(); ?>
          <div class="sx-content-layout">
            <?php if (!empty($customSection['image_path']) && ($customStyle['content_layout'] ?? '') !== 'text'): ?>
              <figure class="sx-content-media"><?= pictureTag((string)$customSection['image_path'], (string)$customSection['title']) ?></figure>
            <?php endif; ?>
            <div class="sx-content-copy">
              <?php if (!empty($customSection['badge'])): ?><span class="sx-badge" style="display:inline-block;margin-bottom:1rem;font-weight:700;letter-spacing:.08em;color:#64748b;"><?= e($customSection['badge']) ?></span><?php endif; ?>
              <h2 style="font-size:clamp(1.8rem,4vw,3rem);font-weight:800;margin-bottom:1rem;"><?= e($customSection['title']) ?></h2>
              <?php if (!empty($customSection['subtitle'])): ?><p class="sx-subtitle" style="font-size:1.1rem;color:#64748b;margin-bottom:1rem;"><?= e($customSection['subtitle']) ?></p><?php endif; ?>
              <?php if (!empty($customSection['description'])): ?><div class="sx-description" style="line-height:1.8;"><?= nl2br(e($customSection['description'])) ?></div><?php endif; ?>
            </div>
          </div>
          <?php foreach (($itemsBySection[$identifier] ?? []) as $item): ?>
            <article style="margin-top:1.25rem;padding:1.25rem;background:#fff;border:1px solid #e2e8f0;border-radius:1rem;">
              <?php if (!empty($item['icon'])): ?><span style="font-size:1.5rem;"> <?= e($item['icon']) ?> </span><?php endif; ?>
              <h3 style="font-size:1.2rem;font-weight:700;margin:.5rem 0;"><?= e($item['title'] ?? '') ?></h3>
              <?php if (!empty($item['subtitle'])): ?><p style="color:#64748b;margin-bottom:.5rem;"><?= e($item['subtitle']) ?></p><?php endif; ?>
              <?php if (!empty($item['description'])): ?><p style="line-height:1.7;"><?= nl2br(e($item['description'])) ?></p><?php endif; ?>
              <?php if (!empty($item['image_path'])): ?><?= pictureTag((string)$item['image_path'], (string)($item['title'] ?? ''), ['style' => 'max-width:100%;height:auto;margin-top:1rem;border-radius:.75rem;']) ?><?php endif; ?>
              <?php if (!empty($item['link_url'])): ?><a href="<?= e($item['link_url']) ?>" style="display:inline-block;margin-top:.75rem;color:#4f46e5;">Saiba mais</a><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <footer class="footer<?= $sxClass('rodape') ?>"<?= $sxAttrs('rodape') ?>>
      <div class="container">
        <div class="footer-grid">
          <div class="footer-column">
            <div class="footer-logo">
              <?= pictureTag('assets/img/logo.png', 'Bandeira ou brasão da Tunísia') ?>
              <div>
                <h3><?= e($settings['site_name'] ?? 'Projeto Tunísia') ?></h3>
                <span>Projeto Integrador 2026</span>
              </div>
            </div>
            <p><?= e($rodape['description'] ?? '') ?></p>
            <div class="social-links">
              <a href="#" aria-label="Instagram">📷</a>
              <a href="#" aria-label="Facebook">📘</a>
              <a href="#" aria-label="YouTube">▶️</a>
              <a href="#" aria-label="LinkedIn">💼</a>
            </div>
          </div>

          <div class="footer-column">
            <h4>Navegação</h4>
            <ul>
              <li><a href="#inicio">Início</a></li>
              <?php if ($showSection('sobre')): ?><li><a href="#sobre">Sobre o Projeto</a></li><?php endif; ?>
              <?php if ($showSection('mapa')): ?><li><a href="#mapa">Mapa Interativo</a></li><?php endif; ?>
              <?php if ($showSection('cultura')): ?><li><a href="#cultura">Semana Cultural</a></li><?php endif; ?>
              <?php if ($showSection('empreendedorismo')): ?><li><a href="#empreendedorismo">Empreendedorismo</a></li><?php endif; ?>
              <?php if ($showSection('conhecimento')): ?><li><a href="#conhecimento">Feira do Conhecimento</a></li><?php endif; ?>
            </ul>
          </div>

          <div class="footer-column">
            <h4><?= e($rodape['title'] ?? 'Informações') ?></h4>
            <ul>
              <li><strong>Escola:</strong> Senac</li>
              <li><strong>Curso:</strong> Técnico em TI</li>
              <li><strong>Turma:</strong> 2º Ano</li>
              <li><strong>Ano:</strong> 2026</li>
            </ul>
          </div>

          <div class="footer-column">
            <h4>Contato</h4>
            <ul>
              <li>📧 <?= e($settings['admin_email'] ?? 'projeto@tunisia.com') ?></li>
              <li>📍 Brasil</li>
              <li>🌎 Projeto Acadêmico</li>
            </ul>
          </div>
        </div>

        <div class="footer-bottom">
          <p>© 2026 <?= e($settings['site_name'] ?? 'Projeto Tunísia') ?> • Desenvolvido para o Projeto Integrador</p>
        </div>
      </div>
    </footer>

    <script>
      // Sincroniza a ordem visual do site com sections.display_order salva no CMS.
      (function () {
        const orderedIdentifiers = <?= json_encode(array_values($orderedSections ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        if (!orderedIdentifiers || !orderedIdentifiers.length) return;
        const idMap = { hero: 'inicio' };
        const footer = document.querySelector('footer.footer');
        if (!footer || !footer.parentNode) return;
        const parent = footer.parentNode;

        // Itera na ordem correta e move cada seção imediatamente antes do footer
        orderedIdentifiers.forEach(function (identifier) {
          if (identifier === 'rodape') return;
          const element = document.getElementById(idMap[identifier] || identifier);
          if (element && element.tagName && element.tagName.toLowerCase() === 'section') {
            parent.insertBefore(element, footer);
          }
        });
      })();
    </script>
    <script src="./assets/js/index.js?v=<?= assetVersion('assets/js/index.js') ?>" defer></script>
  </body>
</html>