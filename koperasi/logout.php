<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

cashless_koperasi_logout();

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
if ($role === 'petugas_koperasi') {
    unset($_SESSION['user']);
}

set_flash('success', 'Anda telah keluar.');
app_redirect('login.php');
