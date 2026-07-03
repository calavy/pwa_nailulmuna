<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../../helpers/keuangan_rekap.php';
require_once __DIR__ . '/../../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../../helpers/keuangan_pkpps_syahriyah.php';

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
$syBreakdown = null;
foreach ($breakdown as $row) {
    if (!empty($row['is_wajib'])) {
        $expectedWajib += (int) ($row['expected'] ?? 0);
        $sisaWajib += (int) ($row['sisa'] ?? 0);
    }
}
if (isset($breakdown['syahriyah']) && is_array($breakdown['syahriyah'])) {
    $sy = $breakdown['syahriyah'];
    $pkpps = (int) ($sy['pkpps_tambahan'] ?? 0);
    $total = (int) ($sy['expected'] ?? 0);
    $dasar = (int) ($sy['expected_setelah_potongan'] ?? max(0, $total - $pkpps));
    $syBreakdown = [
        'tier_label' => (string) ($sy['tier_label'] ?? ''),
        'dasar' => $dasar,
        'pkpps' => $pkpps,
        'kelas_syahriyah' => 0,
        'total' => $total,
        'sisa' => (int) ($sy['sisa'] ?? 0),
    ];
}

$pkppsAktif = keuangan_pkpps_syahriyah_berlaku_untuk_santri($pdo, $santriId);
$kelasTagihan = function_exists('keuangan_santri_kelas_tagihan')
    ? keuangan_santri_kelas_tagihan($pdo, $santriId, $tahunMulai, $tahunSelesai)
    : '';
$pkppsKelasKode = pkpps_kelas_keuangan_kode_for_santri($pdo, $santriId, $tahunMulai, $tahunSelesai);

$nominalFill = [];
foreach ($breakdown as $slug => $row) {
    if (!is_string($slug) || $slug === '' || !is_array($row)) {
        continue;
    }
    $sisa = (int) ($row['sisa'] ?? 0);
    $expected = (int) ($row['expected'] ?? 0);
    $status = (string) ($row['status'] ?? '');
    if ($sisa > 0) {
        $nominalFill[$slug] = $sisa;
    } elseif ($expected > 0 && $status !== 'Lunas') {
        $nominalFill[$slug] = $expected;
    } else {
        $nominalFill[$slug] = 0;
    }
}

$bulanUrutan = $jenisPeriode === 'BULANAN'
    ? keuangan_pembayaran_bulan_urutan_map($pdo, $santriId, $tahunMulai, $tahunSelesai)
    : [];
$bulanUrutanEntry = $bulanUrutan[$bulanTagihan] ?? null;
$bulanDiblokir = is_array($bulanUrutanEntry) && !empty($bulanUrutanEntry['dibebankan']) && empty($bulanUrutanEntry['allowed']);

$jenisSantri = 'semua';
$tagihanMasukInfo = null;
if ($jenisPeriode === 'AWAL_TAHUN') {
    require_once __DIR__ . '/../../helpers/tagihan_santri_masuk.php';
    $jenisSantri = tagihan_santri_jenis_ta($pdo, $santriId, $tahunMulai);
} elseif ($jenisPeriode === 'BULANAN') {
    require_once __DIR__ . '/../../helpers/tagihan_santri_masuk.php';
    $tagihanMasukInfo = tagihan_santri_masuk_info_for_ta($pdo, $santriId, $tahunMulai, $tahunSelesai);
    $jenisSantri = (string) ($tagihanMasukInfo['jenis_santri'] ?? 'semua');
}

echo json_encode([
    'ok' => true,
    'pos' => $breakdown,
    'nominal_fill' => $nominalFill,
    'bulan_urutan' => $bulanUrutan,
    'bulan_diblokir' => $bulanDiblokir,
    'bulan_blokir_pesan' => $bulanDiblokir ? (string) ($bulanUrutanEntry['message'] ?? '') : '',
    'summary' => [
        'expected_wajib' => $expectedWajib,
        'sisa_wajib' => $sisaWajib,
        'status' => $sisaWajib <= 0 && $expectedWajib > 0 ? 'Lunas' : ($sisaWajib < $expectedWajib ? 'Sebagian' : 'Belum'),
        'syahriyah_breakdown' => $syBreakdown,
        'pkpps_aktif' => $pkppsAktif,
        'kelas_tagihan' => $kelasTagihan,
        'pkpps_kelas_kode' => $pkppsKelasKode,
        'jenis_santri' => $jenisSantri,
        'tagihan_masuk' => $tagihanMasukInfo,
    ],
], JSON_UNESCAPED_UNICODE);
