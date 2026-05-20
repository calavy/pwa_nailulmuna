<?php

declare(strict_types=1);

/**
 * Satu kali jalan: tautkan semua santri ke wali_santri dari data kafil (sama seperti simpan di form).
 * Jalankan dari CLI: php scripts/sync_santri_kafil_ke_wali.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wali.php';

ensure_wali_santri_table($pdo);
ensure_santri_identity_columns($pdo);

$ids = $pdo->query('SELECT id FROM santri ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$c = 0;
foreach ($ids as $sid) {
    sync_santri_wali_from_kafil($pdo, (int) $sid);
    $c++;
}
echo "Selesai: {$c} santri diproses.\n";
