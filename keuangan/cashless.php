<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../helpers/app_hub.php';
app_hub_redirect_landing('keuangan_cashless');
