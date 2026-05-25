<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/helpers/app_path.php';

if (!app_is_local_dev()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

require_once __DIR__ . '/config/database.php';

echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Cek server</title></head><body style="font-family:sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;">';
echo '<h1>Server OK</h1>';
echo '<p>Folder proyek: <code>' . htmlspecialchars(__DIR__) . '</code></p>';
echo '<p>Base path: <code>' . htmlspecialchars(app_base_path() !== '' ? app_base_path() : '(root domain)') . '</code></p>';
echo '<p>Lingkungan: <strong>' . (app_is_local_dev() ? 'Lokal (dev)' : 'Production') . '</strong></p>';
$dbOk = isset($pdo) && $pdo instanceof PDO;
echo '<p>Database: <strong>' . ($dbOk ? 'Terhubung' : 'Gagal / belum dikonfigurasi') . '</strong></p>';
echo '<p>Login: <a href="' . htmlspecialchars(app_url('login.php')) . '">' . htmlspecialchars(app_url('login.php')) . '</a></p>';
echo '<p>PHP: ' . htmlspecialchars(PHP_VERSION) . '</p>';
if (!app_is_local_dev()) {
    echo '<p style="color:#0f766e;font-size:0.9rem;">Password default <code>admin/admin123</code> tidak aktif di server production.</p>';
}
echo '<p><a href="' . htmlspecialchars(app_url('login.php')) . '" style="display:inline-block;margin-top:1rem;padding:0.5rem 1rem;background:#0f766e;color:#fff;text-decoration:none;border-radius:6px;">Buka Login</a></p>';
echo '<p class="text-muted" style="font-size:0.9rem;color:#64748b;">Panduan: baca file <strong>CARA-PAKAI.md</strong> di folder proyek.</p>';
echo '</body></html>';
