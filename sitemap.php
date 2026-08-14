<?php
header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = ($https ? 'https' : 'http') . '://' . $host;
$today = date('Y-m-d');

$pages = [
    ['loc' => $base . '/', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '1.0'],
    ['loc' => $base . '/welcome.php', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '1.0'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $page): ?>
  <url>
    <loc><?= htmlspecialchars($page['loc'], ENT_XML1) ?></loc>
    <lastmod><?= htmlspecialchars($page['lastmod'], ENT_XML1) ?></lastmod>
    <changefreq><?= htmlspecialchars($page['changefreq'], ENT_XML1) ?></changefreq>
    <priority><?= htmlspecialchars($page['priority'], ENT_XML1) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
