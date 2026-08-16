<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/app_path.php';

if (isset($_SESSION['user']) && $pdo instanceof PDO) {
    app_post_login_redirect($pdo);
}

app_redirect('login.php');
