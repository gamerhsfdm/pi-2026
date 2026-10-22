<?php
/**
 * Aparência e layout por seção (alinhamento, largura, espaçamento, fundo, fontes, tamanhos,
 * cores, cards e animação).
 *
 * Segurança: o painel só aceita valores de listas fixas (ou cores #RRGGBB). Nenhum CSS digitado
 * pelo usuário chega ao site, então não há como injetar estilos/scripts por este recurso.
 * Os dados ficam em sections.style_data (JSON) e, nos rascunhos, em draft_data['style'].
 */

/** Fontes disponíveis. 'google' = família do Google Fonts (carregada só se alguma seção a usar). */
const SX_FONTS = [
    'sans'        => ['label' => 'Sans padrão do site',   'stack' => 'inherit',                                           'google' => null],
    'system'    => ['label' => 'Sistema (rápida)',      'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif", 'google' => null],
    'serif'     => ['label' => 'Serifada clássica',     'stack' => "Georgia, 'Times New Roman', serif",                     'google' => null],
    'mono'      => ['label' => 'Monoespaçada',          'stack' => "ui-monospace, 'Cascadia Code', Consolas, monospace",   'google' => null],
    'poppins'   => ['label' => 'Poppins (moderna)',     'stack' => "'Poppins', sans-serif",         'google' => 'Poppins:wght@400;600;700;800;900'],
    'montserrat'=> ['label' => 'Montserrat (geométrica)','stack' => "'Montserrat', sans-serif",     'google' => 'Montserrat:wght@400;600;700;800;900'],
    'nunito'    => ['label' => 'Nunito (amigável)',     'stack' => "'Nunito', sans-serif",          'google' => 'Nunito:wght@400;600;700;800;900'],
    'raleway'   => ['label' => 'Raleway (elegante)',    'stack' => "'Raleway', sans-serif",         'google' => 'Raleway:wght@400;600;700;800;900'],
    'oswald'    => ['label' => 'Oswald (impacto)',      'stack' => "'Oswald', sans-serif",          'google' => 'Oswald:wght@400;600;700'],
    'playfair'  => ['label' => 'Playfair Display (editorial)', 'stack' => "'Playfair Display', serif", 'google' => 'Playfair+Display:wght@400;600;700;800;900'],
    'merriweather' => ['label' => 'Merriweather (leitura)', 'stack' => "'Merriweather', serif",    'google' => 'Merriweather:wght@400;700;900'],
    'lora'      => ['label' => 'Lora (literária)',      'stack' => "'Lora', serif",                 'google' => 'Lora:wght@400;600;700'],
    'robotoslab'=> ['label' => 'Roboto Slab (técnica)', 'stack' => "'Roboto Slab', serif",          'google' => 'Roboto+Slab:wght@400;600;700;800;900'],
    'dancing'   => ['label' => 'Dancing Script (manuscrita)', 'stack' => "'Dancing Script', cursive", 'google' => 'Dancing+Script:wght@400;600;700'],
];

/** Opções de lista fixa: campo => [valor => rótulo]. Primeiro item vazio = "padrão do site". */
function sectionStyleChoices(): array {
    return [
        'text_align'      => ['' => 'Padrão', 'left' => 'Esquerda', 'center' => 'Centro', 'right' => 'Direita'],
        'content_width'   => ['' => 'Padrão', 'narrow' => 'Estreita (leitura)', 'normal' => 'Normal', 'wide' => 'Larga', 'full' => 'Tela cheia'],
        'padding_y'       => ['' => 'Padrão', 'none' => 'Nenhum', 'sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande', 'xl' => 'Enorme'],
        'bg_mode'         => ['' => 'Padrão do site', 'solid' => 'Cor sólida', 'gradient' => 'Degradê'],
        'bg_direction'    => ['down' => 'Para baixo ↓', 'right' => 'Para a direita →', 'diag' => 'Diagonal ↘', 'up' => 'Para cima ↑'],
        'title_size'      => ['' => 'Padrão', 'sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande', 'xl' => 'Extra grande', '2xl' => 'Gigante'],
        'title_weight'    => ['' => 'Padrão', '400' => 'Normal', '600' => 'Seminegrito', '700' => 'Negrito', '800' => 'Extranegrito', '900' => 'Preto'],
        'title_transform' => ['' => 'Padrão', 'none' => 'Como digitado', 'uppercase' => 'MAIÚSCULAS'],
        'title_spacing'   => ['' => 'Padrão', 'tight' => 'Junto', 'normal' => 'Normal', 'wide' => 'Espaçado'],
        'body_size'       => ['' => 'Padrão', 'sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande', 'xl' => 'Extra grande'],
        'card_style'      => ['' => 'Padrão', 'flat' => 'Plano', 'bordered' => 'Com borda', 'soft' => 'Sombra suave', 'elevated' => 'Sombra forte', 'glass' => 'Vidro', 'accent' => 'Borda destaque'],
        'card_radius'     => ['' => 'Padrão', 'sq' => 'Reto', 'md' => 'Arredondado', 'lg' => 'Bem arredondado', 'xl' => 'Muito arredondado'],
        'card_hover'      => ['' => 'Padrão', 'none' => 'Nenhum', 'lift' => 'Subir', 'glow' => 'Brilho', 'zoom' => 'Ampliar'],
        'card_border_width' => ['' => 'Padrão', 'none' => 'Sem borda', 'thin' => 'Fina', 'medium' => 'Média', 'thick' => 'Espessa'],
        'card_shadow'     => ['' => 'Padrão', 'none' => 'Sem sombra', 'soft' => 'Suave', 'medium' => 'Média', 'strong' => 'Forte'],
        'btn_radius'      => ['' => 'Padrão', 'sq' => 'Reto', 'md' => 'Suave', 'pill' => 'Pílula'],
        'grid_cols'       => ['' => 'Auto', '1' => '1', '2' => '2', '3' => '3', '4' => '4'],
        'grid_gap'        => ['' => 'Padrão', 'sm' => 'Curto', 'md' => 'Médio', 'lg' => 'Largo'],
        'header_gap'      => ['' => 'Padrão', 'sm' => 'Curto', 'md' => 'Médio', 'lg' => 'Largo'],
        'content_layout'  => ['' => 'Padrão', 'text' => 'Somente texto', 'image-left' => 'Imagem à esquerda', 'image-right' => 'Imagem à direita', 'image-top' => 'Imagem acima', 'image-bottom' => 'Imagem abaixo'],
        'media_width'     => ['' => 'Automática', 'sm' => 'Pequena', 'md' => 'Média', 'lg' => 'Grande'],
        'media_ratio'     => ['' => 'Original', 'square' => 'Quadrada', 'landscape' => 'Horizontal', 'portrait' => 'Vertical', 'wide' => 'Panorâmica'],
        'media_fit'       => ['' => 'Padrão (cover)', 'cover' => 'Preencher (cover)', 'contain' => 'Exibir inteira (contain)'],
        'media_radius'    => ['' => 'Padrão', 'none' => 'Reta', 'md' => 'Suave', 'lg' => 'Arredondada', 'pill' => 'Pílula'],
        'content_gap'     => ['' => 'Padrão', 'sm' => 'Compacto', 'md' => 'Confortável', 'lg' => 'Amplo'],
        'content_vertical'=> ['' => 'Padrão', 'start' => 'Topo', 'center' => 'Centro', 'end' => 'Base'],
        'min_height'      => ['' => 'Auto', 'half' => 'Meia tela', 'tall' => '¾ da tela', 'screen' => 'Tela cheia'],
        'title_leading'   => ['' => 'Padrão', 'tight' => 'Compacta', 'normal' => 'Normal', 'relaxed' => 'Folgada'],
        'body_leading'    => ['' => 'Padrão', 'tight' => 'Compacta', 'normal' => 'Normal', 'relaxed' => 'Folgada'],
        'title_style'     => ['' => 'Padrão', 'normal' => 'Normal', 'italic' => 'Itálico'],
        'title_decor'     => ['' => 'Nenhuma', 'line' => 'Linha curta', 'bar' => 'Barra degradê', 'dots' => 'Pontos'],
        'title_effect'    => ['' => 'Nenhum', 'gradient' => 'Texto em degradê', 'shadow' => 'Sombra'],
        'hide_on'         => ['' => 'Mostrar sempre', 'mobile' => 'Ocultar no celular', 'desktop' => 'Ocultar no computador'],
        'animation'       => ['' => 'Nenhuma', 'fade-up' => 'Surgir de baixo', 'fade-in' => 'Aparecer (fade)', 'zoom' => 'Zoom suave', 'slide-left' => 'Entrar pela esquerda', 'slide-right' => 'Entrar pela direita'],
    ];
}

const SX_COLOR_FIELDS = ['bg_color', 'bg_color2', 'title_color', 'body_color', 'accent_color', 'card_bg', 'card_text', 'card_border_color'];
const SX_FONT_FIELDS  = ['title_font', 'body_font'];

function sectionStyleDefaults(): array {
    $d = [];
    foreach (array_keys(sectionStyleChoices()) as $k)$d[$k] = ($k === 'bg_direction') ? 'down' : '';
    foreach (SX_COLOR_FIELDS as $k) $d[$k] = '';
    foreach (SX_FONT_FIELDS as $k) $d[$k] = '';
    $d['hide_badge'] = '';$d['hide_title'] = '';$d['hide_description'] = '';$d['hide_buttons'] = '';$d['hide_media'] = '';$d['bg_image'] = '';
    $d['bg_overlay'] = '';$d['bg_fixed'] = '';
    $d['hide_video'] = '';$d['hide_overlay'] = '';
    return $d;
}

/** Normaliza #abc / #aabbcc para #aabbcc minúsculo; qualquer outra coisa vira ''. */
function sxNormalizeColor($value): string {
    $v = strtolower(trim((string)$value));
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v,$m)) return '#' . $m[1] .$m[1] . $m[2] .$m[2] . $m[3] .$m[3];
    return preg_match('/^#[0-9a-f]{6}$/', $v) ?$v : '';
}

/** Valida a entrada (POST/JSON) e devolve só valores permitidos. */
function sxValidImagePath(string $path): bool {
    return $path !== '' && strpos($path, '..') === false
        && (bool)preg_match('#^(uploads/media|assets/img)/[A-Za-z0-9_.\-]+\.(png|jpe?g|webp|gif)$#i', $path);
}

function sanitizeSectionStyle($raw): array {$out = sectionStyleDefaults();
    if (!is_array($raw)) return$out;
    foreach (sectionStyleChoices() as $field =>$options) {
        $v = isset($raw[$field]) && is_scalar($raw[$field]) ? (string)$raw[$field] :$out[$field];$out[$field] = array_key_exists($v, $options) ?$v : $out[$field];
    }
    foreach (SX_COLOR_FIELDS as $field) $out[$field] = sxNormalizeColor($raw[$field] ?? '');
    foreach (SX_FONT_FIELDS as $field) {
        $v = isset($raw[$field]) && is_scalar($raw[$field]) ? (string)$raw[$field] : '';$out[$field] = ($v !== '' && isset(SX_FONTS[$v])) ?$v : '';
    }
    $out['hide_badge'] = !empty($raw['hide_badge']) ? '1' : '';
    $out['hide_title'] = !empty($raw['hide_title']) ? '1' : '';
    $out['hide_description'] = !empty($raw['hide_description']) ? '1' : '';
    $out['hide_buttons'] = !empty($raw['hide_buttons']) ? '1' : '';
    $out['hide_media'] = !empty($raw['hide_media']) ? '1' : '';
    $out['hide_video'] = !empty($raw['hide_video']) ? '1' : '';       // só tem efeito no banner (hero)
    $out['hide_overlay'] = !empty($raw['hide_overlay']) ? '1' : '';   // idem: remove o escurecimento padrão do banner

    // Imagem de fundo: só arquivos de uploads/media ou assets/img (nunca URL externa nem caminho livre).
    $img = isset($raw['bg_image']) && is_scalar($raw['bg_image']) ? trim((string)$raw['bg_image']) : '';$out['bg_image'] = sxValidImagePath($img) ?$img : '';
    $overlay = (isset($raw['bg_overlay']) && is_numeric($raw['bg_overlay'])) ? (int)$raw['bg_overlay'] : -1;
    $out['bg_overlay'] = ($out['bg_image'] !== '' && $overlay >= 0 &&$overlay <= 80) ? (string)((int)(round($overlay / 5) * 5)) : '';$out['bg_fixed'] = ($out['bg_image'] !== '' && !empty($raw['bg_fixed'])) ? '1' : '';
    // Degradê só faz sentido com cor inicial; sem ela, volta ao padrão do site.
    if ($out['bg_mode'] !== '' && $out['bg_color'] === '')$out['bg_mode'] = '';
    if ($out['bg_mode'] === '') {$out['bg_color'] = ''; $out['bg_color2'] = '';$out['bg_direction'] = 'down'; }
    if ($out['bg_mode'] === 'solid') {$out['bg_color2'] = ''; }
    return $out;
}

function decodeSectionStyle(?string $json): array {
    $data = ($json !== null && $json !== '') ? json_decode($json, true) : null;
    return sanitizeSectionStyle(is_array($data) ?$data : []);
}

function sectionStyleIsEmpty(array $style): bool {
    return $style === sectionStyleDefaults();
}

/** Classes CSS a acrescentar ao elemento <section>. Retorna '' se nada foi personalizado. */
function sectionStyleClasses(array $s): string {
    if (sectionStyleIsEmpty($s)) return '';$c = ['sx'];
    foreach ([
        'text_align' => 'ta', 'content_width' => 'w', 'padding_y' => 'py', 'title_size' => 'ts', 'title_weight' => 'tw',
        'title_spacing' => 'tl', 'body_size' => 'bs', 'card_style' => 'cs', 'card_radius' => 'cr', 'card_border_width' => 'cbw', 'card_shadow' => 'csh',
        'card_hover' => 'ch', 'btn_radius' => 'br', 'grid_cols' => 'gc', 'grid_gap' => 'gg', 'header_gap' => 'hg',
        'min_height' => 'mh', 'title_leading' => 'tld', 'body_leading' => 'bld', 'title_style' => 'tst',
        'title_decor' => 'td', 'title_effect' => 'te', 'hide_on' => 'ho', 'content_layout' => 'cl',
        'media_width' => 'mw', 'media_ratio' => 'mr', 'media_fit' => 'mf', 'media_radius' => 'mrad',
        'content_gap' => 'cgap', 'content_vertical' => 'cv'
    ] as $field =>$abbr) {
        if (isset($s[$field]) && $s[$field] !== '') $c[] = "sx-{$abbr}-{$s[$field]}";
    }
    if (isset($s['title_transform']) && $s['title_transform'] === 'uppercase')$c[] = 'sx-tt-upper';
    if (isset($s['title_transform']) && $s['title_transform'] === 'none')$c[] = 'sx-tt-none';
    if (sectionStyleBgValue($s) !== '')$c[] = 'sx-bg';
    if (isset($s['bg_image']) && $s['bg_image'] !== '')$c[] = 'sx-bgimg';
    if (isset($s['bg_fixed']) && $s['bg_fixed'] === '1')$c[] = 'sx-bgfix';
    if (isset($s['title_color']) && $s['title_color'] !== '')$c[] = 'sx-tc';
    if (isset($s['body_color']) && $s['body_color'] !== '')$c[] = 'sx-bc';
    if (isset($s['accent_color']) && $s['accent_color'] !== '')$c[] = 'sx-ac';
    if (isset($s['card_bg']) && $s['card_bg'] !== '')$c[] = 'sx-cb';
    if (isset($s['card_text']) && $s['card_text'] !== '')$c[] = 'sx-ct';
    if (isset($s['card_border_color']) && $s['card_border_color'] !== '')$c[] = 'sx-cborder';
    if (isset($s['title_font']) &&$s['title_font'] !== '' && $s['title_font'] !== 'sans')$c[] = 'sx-tf';
    if (isset($s['body_font']) &&$s['body_font'] !== '' && $s['body_font'] !== 'sans')$c[] = 'sx-bf';
    if (isset($s['animation']) && $s['animation'] !== '')$c[] = 'sx-anim';
    if (isset($s['hide_badge']) && $s['hide_badge'] === '1')$c[] = 'sx-hide-badge';
    foreach (['title' => 'sx-hide-title', 'description' => 'sx-hide-description', 'buttons' => 'sx-hide-buttons', 'media' => 'sx-hide-media'] as $field => $class) {
        if (($s['hide_' . $field] ?? '') === '1') $c[] = $class;
    }
    if (isset($s['hide_overlay']) && $s['hide_overlay'] === '1')$c[] = 'sx-no-overlay';
    return implode(' ', $c);
}

/**
 * Valor CSS completo do fundo (camadas): [escurecimento] + imagem + cor/degradê.
 * Tudo vem de campos já validados (cores #RRGGBB, caminho de arquivo restrito).
 */
function sectionStyleBgValue(array $s): string {$layers = [];
    if (!empty($s['bg_image']) && sxValidImagePath($s['bg_image'])) {
        $path =$s['bg_image'];
        if (function_exists('webpSibling') && ($webp = webpSibling($path))) $path =$webp;
        $alpha = (isset($s['bg_overlay']) && $s['bg_overlay'] === '' ? 0 : (int)($s['bg_overlay'] ?? 0)) / 100;
        if ($alpha > 0) {$a = number_format($alpha, 2, '.', '');$layers[] = "linear-gradient(rgba(0,0,0,{$a}),rgba(0,0,0,{$a}))";
        }
        // Caminho a partir da raiz do site: URL relativa em variável CSS seria resolvida contra o arquivo .css.
        $base = defined('SITE_URL') ? str_replace(["'", '"', '(', ')', ' ', '\\'], '', (string)SITE_URL) : '';
        $layers[] = "url('{$base}/{$path}')";
    }
    if (isset($s['bg_mode']) && $s['bg_mode'] === 'solid' && !empty($s['bg_color'])) {
        $layers[] =$s['bg_color']; // cor sólida precisa ser a última camada
    } elseif (isset($s['bg_mode']) && $s['bg_mode'] === 'gradient' && !empty($s['bg_color'])) {
        $dir = ['down' => 'to bottom', 'right' => 'to right', 'diag' => 'to bottom right', 'up' => 'to top'][$s['bg_direction'] ?? 'down'] ?? 'to bottom';
        $layers[] = 'linear-gradient(' .$dir . ',' . $s['bg_color'] . ',' . (!empty($s['bg_color2']) ? $s['bg_color2'] :$s['bg_color']) . ')';
    }
    return implode(',', $layers);
}

/** Variáveis CSS inline (apenas valores já validados). */
function sectionStyleInline(array $s): string {$v = [];
    $bg = sectionStyleBgValue($s);
    if ($bg !== '') $v[] = '--sx-bg:' .$bg;
    if (!empty($s['title_color']))  $v[] = '--sx-title-color:' .$s['title_color'];
    if (!empty($s['body_color']))   $v[] = '--sx-body-color:' .$s['body_color'];
    if (!empty($s['accent_color'])) $v[] = '--sx-accent:' .$s['accent_color'];
    if (!empty($s['card_bg']))      $v[] = '--sx-card-bg:' .$s['card_bg'];
    if (!empty($s['card_text']))    $v[] = '--sx-card-text:' .$s['card_text'];
    if (!empty($s['card_border_color'])) $v[] = '--sx-card-border:' .$s['card_border_color'];
    if (!empty($s['title_font']) && isset(SX_FONTS[$s['title_font']])) $v[] = '--sx-title-font:' . SX_FONTS[$s['title_font']]['stack'];
    if (!empty($s['body_font']) && isset(SX_FONTS[$s['body_font']]))   $v[] = '--sx-body-font:' . SX_FONTS[$s['body_font']]['stack'];
    if (!empty($s['title_weight'])) $v[] = '--sx-title-weight:' .$s['title_weight'];
    return implode(';', $v);
}

/**
 * Atributos HTML prontos: ` data-sx-anim="..." style="..."` (a classe é montada à parte).
 * Escapa tudo, mesmo vindo de lista validada (defesa em profundidade).
 */
function sectionStyleAttrs(array $s): string {$out = '';
    $inline = sectionStyleInline($s);
    if ($inline !== '') $out .= ' style="' . htmlspecialchars($inline, ENT_QUOTES, 'UTF-8') . '"';
    if (!empty($s['animation'])) $out .= ' data-sx-anim="' . htmlspecialchars($s['animation'], ENT_QUOTES, 'UTF-8') . '"';
    return $out;
}

/** URL única do Google Fonts para as fontes usadas (ou '' se nenhuma). */
function sectionStyleFontsUrl(array $styles): string {$families = [];
    foreach ($styles as$s) {
        foreach (SX_FONT_FIELDS as $field) {$key = $s[$field] ?? '';
            if ($key !== '' && !empty(SX_FONTS[$key]['google']))$families[$key] = SX_FONTS[$key]['google'];
        }
    }
    if (!$families) return '';
    return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $families) . '&display=swap';
}
