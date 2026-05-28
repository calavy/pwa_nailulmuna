<?php

declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/pwa_offline.php';

echo pwa_render_service_worker_js($pdo, app_base_path(), true);
