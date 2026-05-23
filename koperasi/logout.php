<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

cashless_koperasi_logout();
set_flash('success', 'Anda telah keluar dari portal koperasi.');
app_redirect('koperasi/index.php');
