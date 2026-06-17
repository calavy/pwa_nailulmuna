<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app_path.php';

$id = (int) ($_GET['id'] ?? 0);
$target = app_href('/santri/kartu_id.php' . ($id > 0 ? '?id=' . $id : ''));
header('Location: ' . $target, true, 302);
exit;
