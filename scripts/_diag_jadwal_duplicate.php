<?php

declare(strict_types=1);

/**
 * Daftar jadwal dobel: nama kegiatan sama + tingkatan sama, ≥2 master kegiatan.id.
 *
 *   php scripts/_diag_jadwal_duplicate.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';

echo "=== Jadwal dobel (nama + tingkatan) ===\n\n";

if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
    echo "Tabel jadwal/kegiatan belum ada.\n";
    exit(1);
}

$groups = jadwal_find_duplicate_nama_tingkatan($pdo);
if ($groups === []) {
    echo "OK — tidak ada duplikat nama+tingkatan.\n";
    exit(0);
}

echo 'Ditemukan ' . count($groups) . " grup:\n\n";
foreach ($groups as $grp) {
    echo '  - ' . jadwal_duplicate_nama_tingkatan_message(is_array($grp) ? $grp : []) . "\n";
}

echo "\nRapikan di Jadwal → Kegiatan atau hapus slot jadwal yang bentrok.\n";
echo "Dampak: absensi Multi Scan bisa ambigu (activity_for_tingkatan LIMIT 1).\n";
exit(1);
