<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user'])) {
    $role = (string) ($_SESSION['user']['role'] ?? '');
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    if (is_super_admin()) {
        app_redirect('dashboard.php');
    }
    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        app_redirect('dashboard.php');
    }
    if ($allowedMap === []) {
        unset($_SESSION['user']);
        app_redirect('login.php');
    }
    require_once __DIR__ . '/helpers/user_permissions.php';
    $fallback = app_acl_first_allowed_path(user_permission_path_map(), $allowedMap);
    if ($fallback !== null) {
        app_redirect_path($fallback);
    }
    app_redirect('dashboard.php');
}

app_redirect('login.php');
