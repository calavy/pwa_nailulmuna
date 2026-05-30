<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$target = app_href('/jadwal/index.php' . ($query !== '' ? '?' . $query : ''));
header('Location: ' . $target);
exit;
