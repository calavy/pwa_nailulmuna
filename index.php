<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user'])) {
    app_post_login_redirect($pdo);
}

app_redirect('beranda.php');
