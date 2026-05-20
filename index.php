<?php

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app_path.php';

if (isset($_SESSION['user'])) {
    $role = (string) ($_SESSION['user']['role'] ?? '');
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    app_redirect('dashboard.php');
}

app_redirect('login.php');
