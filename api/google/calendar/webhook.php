<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../helpers/app.php';
require_once __DIR__ . '/../../../helpers/google_calendar_sync.php';

date_default_timezone_set('Asia/Jakarta');

ensure_pondok_settings_defaults($pdo);
google_calendar_webhook_handle($pdo);
