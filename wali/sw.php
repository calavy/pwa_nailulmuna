<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app_path.php';

header('Content-Type: application/javascript; charset=utf-8');
$waliSwScope = rtrim(app_base_path(), '/') . '/wali/';
if ($waliSwScope === '' || $waliSwScope[0] !== '/') {
    $waliSwScope = '/' . ltrim($waliSwScope, '/');
}
header('Service-Worker-Allowed: ' . $waliSwScope);
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/pwa_offline.php';

echo pwa_render_service_worker_js($pdo, app_base_path(), true, true);
