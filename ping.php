<?php

declare(strict_types=1);

/**
 * Cek cepat dari HP/ngrok — tanpa login. Hapus atau blok di production jika perlu.
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/helpers/app_path.php';

$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$base = app_base_path();
$login = app_href('/login.php');
$beranda = app_href('/beranda.php');
$dbOk = false;
$dbMsg = 'belum dicek';

$dbFile = __DIR__ . '/config/database.php';
if (is_file($dbFile)) {
    try {
        require_once $dbFile;
        $dbOk = isset($pdo) && $pdo instanceof PDO;
        $dbMsg = $dbOk ? 'OK' : 'gagal';
    } catch (Throwable $e) {
        $dbMsg = 'gagal: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cek koneksi</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1.25rem; line-height: 1.5; }
        .ok { color: #0f766e; font-weight: 700; }
        a { display: inline-block; margin: 0.35rem 0; }
        code { background: #f1f5f9; padding: 0.15rem 0.35rem; border-radius: 4px; word-break: break-all; }
    </style>
</head>
<body>
    <h1 class="ok">Server aplikasi aktif</h1>
    <p>Host: <code><?= htmlspecialchars($host) ?></code></p>
    <p>Base path: <code><?= htmlspecialchars($base !== '' ? $base : '(root)') ?></code></p>
    <p>Database: <strong><?= htmlspecialchars($dbMsg) ?></strong></p>
    <p><a href="<?= htmlspecialchars($beranda) ?>">Buka beranda</a></p>
    <p><a href="<?= htmlspecialchars($login) ?>">Buka login</a></p>
</body>
</html>
