<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app_path.php';

unset($_SESSION['wali']);
set_flash('success', 'Anda sudah keluar dari portal wali.');
header('Location: ' . app_wali_login_href());
exit;
