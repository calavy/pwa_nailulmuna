<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app_path.php';
app_redirect('rekap/index.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
