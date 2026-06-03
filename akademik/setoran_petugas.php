<?php

declare(strict_types=1);

/**
 * @deprecated Gunakan /akademik/setoran_penerima.php (penugasan terpusat).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

$peran = trim((string) ($_GET['tab'] ?? $_GET['peran'] ?? 'pembimbing'));
if (!in_array($peran, ['pembimbing', 'munawib'], true)) {
    $peran = 'pembimbing';
}
$refId = (int) ($_GET['pembimbing_id'] ?? $_GET['munawib_id'] ?? $_GET['ref_id'] ?? 0);
$qs = 'tab=tingkatan&peran=' . rawurlencode($peran);
if ($refId > 0) {
    $qs .= '&ref_id=' . $refId;
}
header('Location: ' . app_href('/akademik/setoran_penerima.php?' . $qs));
exit;
