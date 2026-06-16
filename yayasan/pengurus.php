<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app.php';

header('Location: ' . app_href('/yayasan/sdm.php'), true, 302);
exit;
