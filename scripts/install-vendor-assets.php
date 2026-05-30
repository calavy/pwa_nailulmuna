<?php

declare(strict_types=1);

/**
 * Unduh Bootstrap + Font Awesome ke assets/vendor (jalankan sekali saat online).
 * Contoh: C:\xampp\php\php.exe scripts/install-vendor-assets.php
 */

$root = dirname(__DIR__);
$base = $root . '/assets/vendor';
$files = [
    ['url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', 'out' => 'bootstrap/5.3.3/bootstrap.min.css'],
    ['url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', 'out' => 'bootstrap/5.3.3/bootstrap.bundle.min.js'],
    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css', 'out' => 'fontawesome/6.5.2/all.min.css'],
    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-solid-900.woff2', 'out' => 'fontawesome/6.5.2/webfonts/fa-solid-900.woff2'],
    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-regular-400.woff2', 'out' => 'fontawesome/6.5.2/webfonts/fa-regular-400.woff2'],
    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-brands-400.woff2', 'out' => 'fontawesome/6.5.2/webfonts/fa-brands-400.woff2'],
    ['url' => 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js', 'out' => 'html5-qrcode/2.3.8/html5-qrcode.min.js'],
];

foreach ($files as $f) {
    $dest = $base . '/' . $f['out'];
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Gagal buat folder: {$dir}\n");
        exit(1);
    }
    echo "GET {$f['url']}\n";
    $ctx = stream_context_create(['http' => ['timeout' => 120]]);
    $data = @file_get_contents($f['url'], false, $ctx);
    if ($data === false) {
        fwrite(STDERR, "Gagal unduh: {$f['url']}\n");
        exit(1);
    }
    file_put_contents($dest, $data);
}

echo "Selesai: {$base}\n";
