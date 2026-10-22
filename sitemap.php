<?php
/** sitemap.xml dinâmico (a URL /sitemap.xml é reescrita para este arquivo pelo .htaccess). */
define('NO_SESSION', true);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$base = siteBaseUrl();
$lastmod = date('c');
$pdo = getPDO();
if ($pdo) {
    try {
        $value = $pdo->query("SELECT MAX(updated_at) FROM sections WHERE status = 'published' AND is_visible = 1")->fetchColumn();
        if ($value) {
            $lastmod = date('c', strtotime((string)$value));
        }
    } catch (Throwable $e) {
        error_log('Erro ao gerar sitemap: ' . $e->getMessage());
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= htmlspecialchars($base . '/', ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
