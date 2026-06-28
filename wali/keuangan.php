<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

$tab = trim((string) ($_GET['tab'] ?? 'ringkasan'));
if (!in_array($tab, ['ringkasan', 'tagihan', 'tagihan_lain', 'bayar'], true)) {
    $tab = 'ringkasan';
}

$q = trim((string) ($_GET['q'] ?? ''));
$anakRows = $waliAnakRows;
if ($q !== '') {
    $ql = mb_strtolower($q);
    $anakRows = array_values(array_filter($waliAnakRows, static function (array $a) use ($ql): bool {
        $nis = mb_strtolower((string) ($a['nis'] ?? ''));
        $nama = mb_strtolower((string) ($a['nama_tampil'] ?? ''));

        return str_contains($nis, $ql) || str_contains($nama, $ql);
    }));
}

$berjalan = keuangan_periode_berjalan($pdo);
$periodeMulai = $berjalan['mulai'];
$periodeSelesai = $berjalan['selesai'];

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$detailStmt = $pdo->prepare('SELECT id, nis, ' . $nameCol . ' AS nama_tampil, tingkatan, kategori_kelas FROM santri WHERE id = :id LIMIT 1');
$detailStmt->execute(['id' => $waliSantriId]);
$detail = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$kelasKat = trim((string) ($detail['kategori_kelas'] ?? ''));
if ($kelasKat === '' && !empty($detail['tingkatan'])) {
    $kelasKat = (string) $detail['tingkatan'];
}

$tagihanKumulatif = wali_portal_tagihan_sampai_bulan_berjalan($pdo, $waliSantriId, $kelasKat);
$totalTagihanTa = (int) ($tagihanKumulatif['expected_total'] ?? 0);
$totalBayarTa = (int) ($tagihanKumulatif['paid_total'] ?? 0);
$kurang = (int) ($tagihanKumulatif['sisa_total'] ?? 0);
$ringkasanPosTa = wali_portal_ringkasan_pos($pdo, $waliSantriId, $periodeMulai, $periodeSelesai);

$cashlessSaldo = null;
if (table_exists($pdo, 'cashless_accounts') || table_exists($pdo, 'cashless_transactions')) {
    $cashlessSaldo = (float) (wali_portal_cashless_saldo($pdo, $waliSantriId) ?? 0);
}

$rowsTagihan = (array) ($tagihanKumulatif['rows'] ?? []);
$totalsRow = [
    'tagihan' => (int) ($tagihanKumulatif['expected_total'] ?? 0),
    'bayar' => (int) ($tagihanKumulatif['paid_total'] ?? 0),
    'sisa' => (int) ($tagihanKumulatif['sisa_total'] ?? 0),
    'sy_expected' => (int) ($tagihanKumulatif['sy_expected'] ?? 0),
    'sy_paid' => (int) ($tagihanKumulatif['sy_paid'] ?? 0),
    'mk_expected' => (int) ($tagihanKumulatif['mk_expected'] ?? 0),
    'mk_paid' => (int) ($tagihanKumulatif['mk_paid'] ?? 0),
];

$list = wali_portal_fetch_pembayaran_list($pdo, $waliSantriId, 80);
$ringkasanPos = wali_portal_ringkasan_pos($pdo, $waliSantriId, (int) ($tagihanKumulatif['berjalan']['mulai'] ?? $periodeMulai), (int) ($tagihanKumulatif['berjalan']['selesai'] ?? $periodeSelesai));
$tablesOk = table_exists($pdo, 'keuangan_pembayaran');

$keuQuerySuffix = $q !== '' ? ('&q=' . rawurlencode($q)) : '';

require_once __DIR__ . '/includes/layout.php';

$tabTitles = [
    'ringkasan' => 'Ringkasan keuangan',
    'tagihan' => 'Tagihan bulanan',
    'tagihan_lain' => 'Tagihan lain',
    'bayar' => 'Riwayat pembayaran',
];
wali_layout_head(($tabTitles[$tab] ?? 'Keuangan') . ' — Portal Wali', true, 'keuangan');
require __DIR__ . '/partials/greeting.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h1 class="h5 mb-0 wali-brand fw-bold">Keuangan &amp; tabungan</h1>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>

        <?php
        wali_portal_render_hub_tabs(wali_keuangan_hub_tabs($tab, ltrim($keuQuerySuffix, '&')), $tab);
        ?>

        <?php if ($tab === 'ringkasan'): ?>
            <?php require __DIR__ . '/partials/keuangan_tab_ringkasan.php'; ?>
        <?php elseif ($tab === 'tagihan'): ?>
            <?php require __DIR__ . '/partials/keuangan_tab_tagihan.php'; ?>
        <?php elseif ($tab === 'tagihan_lain'): ?>
            <?php require __DIR__ . '/partials/keuangan_tab_tagihan_lain.php'; ?>
        <?php else: ?>
            <p class="small text-muted mb-3">Pembayaran tagihan, bukti kuitansi, dan ringkasan POS.</p>
            <?php require __DIR__ . '/partials/keuangan_tab_bayar.php'; ?>
        <?php endif; ?>

<?php
wali_layout_foot(true, 'keuangan');
