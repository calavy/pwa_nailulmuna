<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app_path.php';

header('Location: ' . app_href('/settings/pesantren.php'), true, 302);
exit;
