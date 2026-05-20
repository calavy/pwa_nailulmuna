<?php

declare(strict_types=1);

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /pwa_nailulmuna/santri/mukimin_import.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
