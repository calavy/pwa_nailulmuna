<?php

require_once __DIR__ . '/config/session.php';

// Sebelum session dihancurkan: konsumsi token edit pembayaran yang masih aktif
// di session ini, agar otomatis berstatus 'habis' (1× pakai).
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/helpers/pembayaran_edit_token.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        pembayaran_edit_token_consume_session($pdo);
    }
} catch (Throwable $e) {
    // Diam — jangan ganggu alur logout.
}

session_unset();
session_destroy();

session_start();
$_SESSION['flash']['success'] = 'Anda telah logout.';

header('Location: ' . app_href('/login.php'));
exit;
