<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../../helpers/keuangan_rekap.php';
require_once __DIR__ . '/../../helpers/tagihan_bulanan.php';

require_login();
require_roles(['admin', 'pengurus']);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$jenisPeriode = strtoupper(trim((string) ($_GET['jenis_periode'] ?? 'BULANAN')));
$bulanTagihan = (int) ($_GET['bulan_tagihan'] ?? 0);
$periode = keuangan_tahun_ajaran_aktif($pdo);
$taNorm = pondok_normalisasi_tahun_ajaran_input(
    $pdo,
    (int) ($_GET['tahun_ajaran_mulai'] ?? $periode['mulai']),
    (int) ($_GET['tahun_ajaran_selesai'] ?? $periode['selesai'])
);
$tahunMulai = $taNorm['mulai'];
$tahunSelesai = $taNorm['selesai'];

if (!in_array($jenisPeriode, ['BULANAN', 'AWAL_TAHUN'], true)) {
    $jenisPeriode = 'BULANAN';
}
if ($jenisPeriode !== 'BULANAN') {
    $bulanTagihan = 0;
} elseif ($bulanTagihan < 1 || $bulanTagihan > 12) {
    $bulanTagihan = keuangan_bulan_berjalan(null, $pdo);
}

if ($santriId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Santri wajib dipilih.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_keuangan_transaksi_tables($pdo);
$breakdown = keuangan_tagihan_breakdown_for_santri(
    $pdo,
    $santriId,
    $jenisPeriode,
    $bulanTagihan,
    $tahunMulai,
    $tahunSelesai,
    keuangan_biaya_definitions()
);

$sisaWajib = 0;
$expectedWajib = 0;
foreach ($breakdown as $row) {
    if (!empty($row['is_wajib'])) {
        $expectedWajib += (int) ($row['expected'] ?? 0);
        $sisaWajib += (int) ($row['sisa'] ?? 0);
    }
}

echo json_encode([
    'ok' => true,
    'pos' => $breakdown,
    'summary' => [
        'expected_wajib' => $expectedWajib,
        'sisa_wajib' => $sisaWajib,
        'status' => $sisaWajib <= 0 && $expectedWajib > 0 ? 'Lunas' : ($sisaWajib < $expectedWajib ? 'Sebagian' : 'Belum'),
    ],
], JSON_UNESCAPED_UNICODE);
