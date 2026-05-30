<?php

declare(strict_types=1);

/**
 * Font Awesome lokal dengan URL webfont absolut (agar ikon tampil di subfolder & PWA).
 */
require_once __DIR__ . '/../../helpers/app_path.php';

$src = dirname(__DIR__, 2) . '/assets/vendor/fontawesome/6.5.2/all.min.css';
if (!is_file($src)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Font Awesome vendor tidak ditemukan. Jalankan scripts/install-vendor-assets.ps1';
    exit;
}

$fontBase = app_href('/assets/vendor/fontawesome/6.5.2/webfonts/');
$css = (string) file_get_contents($src);
$css = str_replace('../webfonts/', $fontBase, $css);

$mtime = (string) filemtime($src);
$etag = '"' . md5($mtime . '|' . $fontBase) . '"';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

echo $css;
