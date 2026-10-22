<?php
/** robots.txt dinâmico (a URL /robots.txt é reescrita para este arquivo pelo .htaccess). */
define('NO_SESSION', true);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$base = siteBaseUrl();
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: " . SITE_URL . "/admin/\n";
echo "Disallow: " . SITE_URL . "/includes/\n";
echo "Disallow: " . SITE_URL . "/config/\n";
echo "\n";
echo "Sitemap: " . $base . "/sitemap.xml\n";
