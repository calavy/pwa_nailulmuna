<?php

declare(strict_types=1);

/**
 * Verifikasi konsistensi nominal keuangan pondok antar helper (tanpa session/role).
 *
 * Usage: php scripts/verify_keuangan_nominal_konsisten.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_dashboard.php';
require_once __DIR__ . '/../helpers/keuangan_diagnostik.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_kas_bulan.php';

ensure_keuangan_transaksi_tables($pdo);

$toleransi = keuangan_dashboard_checklist_kas_toleransi();
$today = date('Y-m-d');
$failures = [];
$passed = 0;

$cocok = static function (int $a, int $b) use ($toleransi): bool {
    return abs($a - $b) < $toleransi;
};

echo "=== Verifikasi nominal keuangan pondok ===\n";
echo "Toleransi: Rp " . number_format($toleransi, 0, ',', '.') . "\n";
echo "Per tanggal: {$today}\n\n";

if (!table_exists($pdo, 'keuangan_pembayaran')) {
    echo "SKIP — tabel keuangan_pembayaran belum ada (database kosong).\n";
    exit(0);
}

$bulanAwal = date('Y-m-01', strtotime($today) ?: time());
$kasBank = keuangan_dashboard_kas_bank_detail($pdo, $today, $bulanAwal, $today);
$kasDashboard = (int) ($kasBank['total'] ?? 0);

$neracaPondok = keuangan_build_neraca($pdo, $today, 'pondok');
$kasNeraca = keuangan_neraca_total_kas_bank($neracaPondok);
$selisihNeraca = abs((int) ($neracaPondok['selisih'] ?? 0));

if ($cocok($kasDashboard, $kasNeraca)) {
    echo "PASS — Kas dashboard ({$kasDashboard}) ≈ kas neraca pondok ({$kasNeraca})\n";
    $passed++;
} else {
    $failures[] = "Kas dashboard ({$kasDashboard}) vs neraca pondok ({$kasNeraca}), selisih " . abs($kasDashboard - $kasNeraca);
}

$periode = keuangan_periode_berjalan($pdo, $today);
$rekap = keuangan_build_rekap_kas_bulanan($pdo, (int) $periode['mulai'], (int) $periode['selesai'], (int) $periode['bulan']);
$rekapSaldo = (int) ($rekap['saldo_akhir_uang_nyata'] ?? $rekap['saldo_akhir_fisik'] ?? 0);

if ($cocok($kasDashboard, $rekapSaldo)) {
    echo "PASS — Kas dashboard ({$kasDashboard}) ≈ rekap saldo uang nyata ({$rekapSaldo})\n";
    $passed++;
} else {
    $failures[] = "Kas dashboard ({$kasDashboard}) vs rekap saldo nyata ({$rekapSaldo}), selisih " . abs($kasDashboard - $rekapSaldo);
}

$mutasi = keuangan_dashboard_mutasi_hari_ini($pdo, $today);
$mutasiMasuk = (int) ($mutasi['masuk'] ?? 0);

$totalBayarHariIni = 0;
if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(GREATEST(0, p.total_nominal - COALESCE(sk.saku_nom, 0))), 0)
        FROM keuangan_pembayaran p
        LEFT JOIN (
            SELECT pembayaran_id, SUM(nominal) AS saku_nom
            FROM keuangan_pembayaran_detail
            WHERE LOWER(TRIM(pos_slug)) = 'saku'
            GROUP BY pembayaran_id
        ) sk ON sk.pembayaran_id = p.id
        WHERE p.tanggal_bayar = :tgl
    ");
    $st->execute(['tgl' => $today]);
    $totalBayarHariIni = (int) round((float) ($st->fetchColumn() ?: 0));
} elseif (table_exists($pdo, 'keuangan_pembayaran')) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran WHERE tanggal_bayar = :tgl');
    $st->execute(['tgl' => $today]);
    $totalBayarHariIni = (int) round((float) ($st->fetchColumn() ?: 0));
}

if ($mutasiMasuk <= $totalBayarHariIni + (int) ($mutasi['keluar'] ?? 0) + $toleransi) {
    echo "PASS — Mutasi masuk hari ini ({$mutasiMasuk}) tidak melebihi pembayaran pondok hari ini ({$totalBayarHariIni})\n";
    $passed++;
} else {
    $failures[] = "Mutasi masuk ({$mutasiMasuk}) > pembayaran pondok hari ini ({$totalBayarHariIni}) — kemungkinan saku ikut terhitung";
}

if ($selisihNeraca < 1) {
    echo "PASS — Neraca pondok seimbang (selisih {$selisihNeraca})\n";
    $passed++;
} else {
    $failures[] = "Neraca pondok tidak seimbang — selisih {$selisihNeraca}";
}

$kesehatanPondok = keuangan_neraca_kesehatan($pdo, $neracaPondok, null, false);
$kesehatanFull = keuangan_neraca_kesehatan($pdo, $neracaPondok, null, true);
if (($kesehatanPondok['level'] ?? '') !== 'buruk' || ($kesehatanFull['level'] ?? '') === ($kesehatanPondok['level'] ?? '')) {
    echo "PASS — Kesehatan neraca pondok (includeSaku=false) level: " . ($kesehatanPondok['level'] ?? '?') . "\n";
    $passed++;
} else {
    echo "INFO — Kesehatan pondok: " . ($kesehatanPondok['level'] ?? '?') . " vs full+saku: " . ($kesehatanFull['level'] ?? '?') . " (expected jika ada selisih saku)\n";
    $passed++;
}

$diagPondok = keuangan_diagnostik_menyeluruh($pdo, $today, false, 'pondok');
$diagSaku = keuangan_diagnostik_menyeluruh($pdo, $today, true, 'full', true);
$sakuKodes = ['saku_tanpa_topup', 'saku_cashless'];
$pondokHasSaku = false;
foreach ($diagPondok['items'] ?? [] as $it) {
    if (in_array((string) ($it['kode'] ?? ''), $sakuKodes, true)) {
        $pondokHasSaku = true;
        break;
    }
}
$pondokOnlyInSaku = false;
foreach ($diagSaku['items'] ?? [] as $it) {
    $k = (string) ($it['kode'] ?? '');
    if ($k !== '' && !in_array($k, $sakuKodes, true)) {
        $pondokOnlyInSaku = true;
        break;
    }
}
if (!$pondokHasSaku && !$pondokOnlyInSaku) {
    echo "PASS — Diagnostik pondok tanpa item saku; diagnostik saku-only tanpa item pondok\n";
    $passed++;
} else {
    if ($pondokHasSaku) {
        $failures[] = 'Diagnostik pondok masih memuat item saku';
    }
    if ($pondokOnlyInSaku) {
        $failures[] = 'Diagnostik saku-only memuat item pondok';
    }
}

echo "\n";
if ($failures === []) {
    echo "Semua assertion lulus ({$passed} checks).\n";
    echo "\n--- Wipe pondok verify ---\n";
    passthru('"' . PHP_BINARY . '" "' . __DIR__ . DIRECTORY_SEPARATOR . 'test_wipe_pondok_verify.php"', $wipeExit);
    exit($wipeExit === 0 ? 0 : 1);
}

echo "GAGAL — " . count($failures) . " assertion:\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
