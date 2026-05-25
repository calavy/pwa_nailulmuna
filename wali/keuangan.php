<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

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
$tagihanPerBulan = tagihan_wajib_total_expected($pdo, $kelasKat);

$totalBayarTa = 0;
if (table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail')) {
    $slugsIn = "'" . implode("','", keuangan_tagihan_wajib_slugs()) . "'";
    $sum = $pdo->prepare("
        SELECT COALESCE(SUM(d.nominal), 0) FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid AND p.jenis_periode = 'BULANAN'
          AND p.tahun_ajaran_mulai = :tm AND p.tahun_ajaran_selesai = :ts
          AND d.pos_slug IN ({$slugsIn})
    ");
    $sum->execute(['sid' => $waliSantriId, 'tm' => $periodeMulai, 'ts' => $periodeSelesai]);
    $totalBayarTa = (int) ((float) ($sum->fetchColumn() ?: 0));
}

$totalTagihanTahun = $tagihanPerBulan * 12;
$kurang = max(0, $totalTagihanTahun - $totalBayarTa);
$ringkasanPosTa = wali_portal_ringkasan_pos($pdo, $waliSantriId, $periodeMulai, $periodeSelesai);

$cashlessSaldo = null;
if (table_exists($pdo, 'cashless_accounts')) {
    $cs = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $cs->execute(['id' => $waliSantriId]);
    $rowC = $cs->fetch(PDO::FETCH_ASSOC);
    $cashlessSaldo = $rowC ? (float) ($rowC['balance'] ?? 0) : 0.0;
}

$rowsTagihan = keuangan_tagihan_bulanan_rows($pdo, $waliSantriId, $kelasKat);

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Ringkasan keuangan — Portal Wali', true, 'keuangan');
require __DIR__ . '/partials/greeting.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h5 mb-0 wali-brand fw-bold">Keuangan &amp; tabungan</h1>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>
        <p class="small text-muted">Cari nama atau NIS untuk memilih santri lain. <a href="/wali/pembayaran.php">Riwayat Keuangan</a> · <a href="/wali/tagihan.php">Tagihan bulanan</a>.</p>

        <form method="get" class="input-group input-group-sm mb-3">
            <input type="text" name="q" class="form-control" placeholder="NIS atau nama" value="<?= htmlspecialchars($q) ?>">
            <button class="btn btn-outline-secondary" type="submit">Cari</button>
        </form>

        <?php
        $keuRedir = '/wali/keuangan.php' . ($q !== '' ? ('?q=' . rawurlencode($q)) : '');
        ?>
        <?php if (count($waliAnakRows) > 1 && $anakRows !== []): ?>
            <div class="list-group mb-3 small shadow-sm">
                <?php foreach ($anakRows as $a): ?>
                    <form method="post" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                        <input type="hidden" name="wali_pilih_anak" value="1">
                        <input type="hidden" name="santri_id" value="<?= (int) $a['id'] ?>">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($keuRedir) ?>">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($a['nama_tampil'] ?? $a['nama_santri'] ?? '')) ?></div>
                            <div class="text-muted font-monospace"><?= htmlspecialchars((string) ($a['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($a['tingkatan'] ?? '')) ?></div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary"><?= (int) $a['id'] === $waliSantriId ? 'Aktif' : 'Pilih' ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php elseif ($q !== ''): ?>
            <div class="alert alert-light border small mb-3">Tidak ada santri yang cocok dalam data anak Anda.</div>
        <?php endif; ?>

        <div class="card shadow-sm wali-card mb-3 border-primary border-opacity-25">
            <div class="card-body">
                <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;">Ringkasan keuangan</div>
                <div class="fw-bold"><?= htmlspecialchars((string) ($detail['nama_tampil'] ?? '')) ?></div>
                <div class="font-monospace small text-muted mb-3">NIS <?= htmlspecialchars((string) ($detail['nis'] ?? '')) ?></div>

                <div class="row g-2 text-center">
                    <div class="col-4">
                        <div class="rounded-3 bg-light py-2 px-1 h-100">
                            <div class="small text-muted">Tagihan/bulan</div>
                            <div class="font-monospace fw-semibold" style="font-size:0.85rem;">Rp <?= number_format($tagihanPerBulan, 0, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded-3 bg-success bg-opacity-10 py-2 px-1 h-100">
                            <div class="small text-muted">Sudah dibayar (TA)</div>
                            <div class="font-monospace fw-semibold text-success" style="font-size:0.85rem;">Rp <?= number_format($totalBayarTa, 0, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="rounded-3 bg-danger bg-opacity-10 py-2 px-1 h-100">
                            <div class="small text-muted">Kurang (est.)</div>
                            <div class="font-monospace fw-semibold text-danger" style="font-size:0.85rem;">Rp <?= number_format($kurang, 0, ',', '.') ?></div>
                        </div>
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0">Estimasi 12 × tagihan wajib (Syahriyah) TA <?= (int) $periodeMulai ?>/<?= (int) $periodeSelesai ?>. Makan &amp; Saku opsional.</p>
                <a class="btn btn-sm btn-teal w-100 mt-2" href="/wali/pembayaran.php"><i class="fa-solid fa-receipt me-1"></i> Riwayat Keuangan &amp; bukti</a>
            </div>
        </div>

        <?php if ($ringkasanPosTa !== []): ?>
        <div class="card shadow-sm wali-card mb-3">
            <div class="card-body">
                <div class="wali-kicker mb-2">Komponen terbayar (TA ini)</div>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($ringkasanPosTa as $rp): ?>
                        <li class="d-flex justify-content-between py-1 border-bottom">
                            <span><?= htmlspecialchars((string) ($rp['pos_nama'] ?? '')) ?></span>
                            <span class="font-monospace"><?= htmlspecialchars(wali_portal_format_rupiah((int) round((float) ($rp['total'] ?? 0)))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($cashlessSaldo !== null): ?>
        <div class="card shadow-sm wali-card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center py-3">
                <div>
                    <div class="small text-muted">Saldo cashless (tabungan digital)</div>
                    <div class="small">Top-up dari pembayaran Saku · batas belanja harian di pondok</div>
                </div>
                <span class="font-monospace fw-bold fs-5">Rp <?= number_format((int) round($cashlessSaldo), 0, ',', '.') ?></span>
            </div>
            <a class="btn btn-sm btn-outline-primary w-100 border-top rounded-0" href="/wali/cashless.php">
                <i class="fa-solid fa-list me-1"></i> Lihat log jajan &amp; top-up
            </a>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm wali-card">
            <div class="card-header bg-white small fw-semibold text-muted d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span>Tagihan bulanan (wajib: Syahriyah)</span>
                <span class="badge text-bg-primary">Bulan ini: <?= htmlspecialchars((string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'])) ?></span>
            </div>
            <div class="card-body p-0">
                <?php $mode = 'staff'; require __DIR__ . '/../includes/partials/tagihan_bulanan_tabel.php'; ?>
            </div>
        </div>

<?php
wali_layout_foot(true, 'keuangan');
