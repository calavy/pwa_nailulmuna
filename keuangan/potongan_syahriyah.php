<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_pengaturan_sections.php';

require_login();
require_roles(['admin', 'pengurus']);

$params = $_GET;
$params['bagian'] = 'santri_bulanan';
$params['sub'] = 'potongan';
header('Location: ' . app_rewrite_internal_url('/keuangan/pengaturan.php?' . http_build_query($params)));
exit;
