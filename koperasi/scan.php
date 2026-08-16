<?php

declare(strict_types=1);

define('CASHLESS_KOPERASI_PORTAL', true);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

cashless_koperasi_ensure_schema($pdo);
cashless_koperasi_bootstrap_from_user_session($pdo);

require_once __DIR__ . '/../keuangan/cashless_scan.php';
