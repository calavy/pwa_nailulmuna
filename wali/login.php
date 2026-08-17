<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';

if (isset($_SESSION['wali']['santri_id'])) {
    app_redirect('wali/index.php');
}

$identity = trim((string) ($_GET['identity'] ?? $_GET['nis'] ?? ''));
    $qs = $identity !== '' ? '?identity=' . urlencode($identity) : '';
app_redirect('login.php' . $qs);
