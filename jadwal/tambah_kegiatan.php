<?php

declare(strict_types=1);

/**
 * @deprecated Gunakan /jadwal/kegiatan.php — redirect agar bookmark lama tetap jalan.
 */
require_once __DIR__ . '/../helpers/app_path.php';

$params = [];
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $params['edit_id'] = $editId;
}
$dest = app_href('/jadwal/kegiatan.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
header('Location: ' . $dest, true, 302);
exit;
