<?php

declare(strict_types=1);

/**
 * Perbaikan data keuangan (default dry-run).
 *
 *   php scripts/_repair_keuangan_data_quality.php
 *   php scripts/_repair_keuangan_data_quality.php --apply --only=tanggal
 *   php scripts/_repair_keuangan_data_quality.php --apply --only=santri,bukti
 */

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/helpers/keuangan_data_quality.php';
require_once $root . '/helpers/keuangan_tanggal_validasi.php';
require_once $root . '/helpers/keuangan_impor_ekspor.php';
require_once $root . '/helpers/tagihan_santri_eligible.php';
require_once $root . '/helpers/keuangan_transaksi.php';

if (!($pdo instanceof PDO)) {
    echo "FAIL: DB tidak tersedia\n";
    exit(1);
}

$apply = false;
$only = ['tanggal', 'santri', 'bukti'];
foreach ($argv ?? [] as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    }
    if (str_starts_with($arg, '--only=')) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    }
}

keuangan_ensure_schema_deferred($pdo);

$counts = ['tanggal_fixed' => 0, 'tanggal_skip' => 0, 'santri_deactivated' => 0, 'bukti_filled' => 0];

if (in_array('tanggal', $only, true)) {
    foreach (keuangan_data_quality_bad_dates($pdo, 5000) as $bad) {
        $table = (string) ($bad['table'] ?? '');
        $col = $table === 'keuangan_pembayaran' ? 'tanggal_bayar' : 'tanggal';
        $id = (int) ($bad['id'] ?? 0);
        if ($id <= 0 || !table_exists($pdo, $table)) {
            $counts['tanggal_skip']++;
            continue;
        }
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $counts['tanggal_skip']++;
            continue;
        }
        $suggest = $table === 'keuangan_pembayaran'
            ? keuangan_tanggal_suggest_pembayaran($pdo, $row)
            : keuangan_tanggal_suggest_transaksi_umum($pdo, $row);
        if ($suggest === null || !keuangan_tanggal_in_ta_window($pdo, $suggest)) {
            $counts['tanggal_skip']++;
            continue;
        }
        if ($apply) {
            $pdo->prepare("UPDATE {$table} SET {$col} = :t WHERE id = :id")->execute(['t' => $suggest, 'id' => $id]);
        }
        $counts['tanggal_fixed']++;
    }
}

if (in_array('santri', $only, true) && table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'is_aktif')) {
    $rows = $pdo->query('SELECT id, nis, COALESCE(is_aktif, 1) AS is_aktif FROM santri')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $canonicalActive = [];
    foreach ($rows as $r) {
        if ((int) ($r['is_aktif'] ?? 1) !== 1) {
            continue;
        }
        $key = keuangan_impor_ekspor_normalize_nis((string) ($r['nis'] ?? ''));
        if ($key === '' || tagihan_santri_nis_shadow((string) ($r['nis'] ?? ''))) {
            continue;
        }
        if (!isset($canonicalActive[$key])) {
            $canonicalActive[$key] = (int) $r['id'];
        }
    }
    foreach ($rows as $r) {
        $nis = (string) ($r['nis'] ?? '');
        if (!tagihan_santri_nis_shadow($nis) || (int) ($r['is_aktif'] ?? 1) !== 1) {
            continue;
        }
        $key = keuangan_impor_ekspor_normalize_nis($nis);
        if ($key === '' || !isset($canonicalActive[$key])) {
            continue;
        }
        if ($apply) {
            $pdo->prepare('UPDATE santri SET is_aktif = 0 WHERE id = :id')->execute(['id' => (int) $r['id']]);
        }
        $counts['santri_deactivated']++;
    }
}

if (in_array('bukti', $only, true) && table_exists($pdo, 'keuangan_pengeluaran') && column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti')) {
    foreach (keuangan_data_quality_pengeluaran_tanpa_bukti($pdo, 5000) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $met = (string) ($row['metode_keluar'] ?? 'KAS');
        $prefix = $met === 'TRANSFER' ? 'TRF' : 'KAS';
        $nb = $prefix . '-' . $id;
        if ($apply) {
            $pdo->prepare('UPDATE keuangan_pengeluaran SET no_bukti = :nb WHERE id = :id')->execute(['nb' => $nb, 'id' => $id]);
        }
        $counts['bukti_filled']++;
    }
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . " repair keuangan\n";
echo json_encode($counts, JSON_PRETTY_PRINT) . "\n";

exit(0);
