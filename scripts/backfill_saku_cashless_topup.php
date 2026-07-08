<?php

declare(strict_types=1);

/**
 * Backfill top-up cashless dari pembayaran pos Saku yang belum punya TOPUP.
 *
 * Jalankan:
 *   php scripts/backfill_saku_cashless_topup.php           # dry-run (default)
 *   php scripts/backfill_saku_cashless_topup.php --apply   # eksekusi
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

keuangan_ensure_schema_deferred($pdo);
cashless_koperasi_ensure_schema($pdo);

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = !$apply;

echo "=== Backfill Saku → Cashless TOPUP ===\n";
echo 'Mode: ' . ($dryRun ? 'DRY-RUN (tanpa perubahan)' : 'APPLY (menulis ke database)') . "\n\n";

$res = keuangan_pembayaran_backfill_saku_topup($pdo, 0, $dryRun);

foreach ($res['rows'] as $row) {
    echo sprintf(
        "#%d santri=%d %s Rp %s [%s]\n",
        (int) ($row['pembayaran_id'] ?? 0),
        (int) ($row['santri_id'] ?? 0),
        (string) ($row['nama_santri'] ?? ''),
        number_format((int) ($row['nominal_saku'] ?? 0), 0, ',', '.'),
        (string) ($row['status'] ?? '')
    );
}

echo "\n" . (string) ($res['message'] ?? '') . "\n";

if (!$dryRun) {
    $orphan = count(keuangan_pembayaran_list_saku_tanpa_topup($pdo));
    echo 'Sisa pembayaran saku tanpa TOPUP: ' . $orphan . "\n";
}

exit($res['ok'] ? 0 : 1);
