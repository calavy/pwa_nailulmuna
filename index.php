<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/includes/auth.php';

if (app_is_wali_host()) {
    if (isset($_SESSION['wali']['santri_id'])) {
        app_redirect('wali/index.php');
    }
    app_redirect('wali/login.php');
}

if (isset($_SESSION['user'])) {
    app_post_login_redirect($pdo);
}

app_redirect('beranda.php');
