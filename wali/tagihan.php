<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';

header('Location: ' . app_href('/wali/keuangan.php?tab=tagihan'), true, 302);
exit;
