<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);
header('Location: ' . app_href('/settings/tingkatan.php#pkpps'));
exit;
