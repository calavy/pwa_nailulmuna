<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app_path.php';

header('Location: ' . app_href('/menu/menu_hub.php?id=menu-grp-pengaturan'), true, 302);
exit;
