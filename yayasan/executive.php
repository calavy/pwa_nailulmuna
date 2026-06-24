<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';

header('Location: ' . yayasan_home_href(), true, 302);
exit;
