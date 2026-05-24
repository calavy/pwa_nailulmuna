<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';

require_roles(['admin', 'pengurus']);
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_rekap.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/tagihan_bulanan.php';

keuangan_ensure_schema_deferred($pdo);

$berjalan = keuangan_periode_berjalan($pdo);
$kalenderMode = pondok_kalender_mode($pdo);
$keuanganTa = keuangan_ta_resolve($pdo, $_GET);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$rekapBulan = max(0, min(12, (int) ($_GET['rekap_bulan'] ?? 0)));

$tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');

$sqlSantri = 'SELECT id, tingkatan, kategori_kelas, is_aktif FROM santri';
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $sqlSantri .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
$santriRows = $tablesOk ? $pdo->query($sqlSantri)->fetchAll() : [];

$totalExpectedOneMonth = tagihan_wajib_total_expected_all_santri($pdo, $santriRows);

$paidByMonth = array_fill(1, 12, 0);
if ($tablesOk) {
    foreach ($bulanSlots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b < 1 || $b > 12) {
            continue;
        }
        $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $b, 'p');
        $st = $pdo->prepare('
            SELECT COALESCE(SUM(d.nominal), 0) AS total
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            WHERE p.jenis_periode = \'BULANAN\'
              AND p.tahun_ajaran_mulai = :tm
              AND p.tahun_ajaran_selesai = :ts
              AND d.pos_slug IN (\'syahriyah\', \'makan\')
              AND ' . $bulanMatch['sql'] . '
        ');
        $st->execute(array_merge(['tm' => $tahunAjaranMulai, 'ts' => $tahunAjaranSelesai], $bulanMatch['params']));
        $paidByMonth[$b] = (int) ((float) ($st->fetchColumn() ?: 0));
    }
}

$rowsLaporan = [];
$bulanBerjalan = $berjalan['bulan'];
foreach ($bulanSlots as $slot) {
    $b = (int) ($slot['bulan_tagihan'] ?? 0);
    if ($b < 1 || $b > 12) {
        continue;
    }
    $tagihan = $totalExpectedOneMonth;
    $terbayar = $paidByMonth[$b];
    $rowsLaporan[] = [
        'bulan' => $b,
        'label' => pondok_bulan_slot_label_tampilan($pdo, $slot),
        'rentang_masehi' => ($slot['masehi_awal'] ?? '') !== '' ? ($slot['masehi_awal'] . ' – ' . $slot['masehi_akhir']) : '',
        'tagihan' => $tagihan,
        'terbayar' => $terbayar,
        'sisa' => max(0, $tagihan - $terbayar),
        'is_bulan_ini' => $b === $bulanBerjalan,
    ];
}

$rekapPosRows = [];
$rekapAlokasiRows = [];
$rekapBulanLabel = '';
$rekapSyahriyahMasuk = 0;
if ($tablesOk && $rekapBulan >= 1 && $rekapBulan <= 12) {
    $biayaDefs = keuangan_biaya_definitions();
    $rekapPosRows = keuangan_rekap_pos_with_expected(
        $pdo,
        'BULANAN',
        $rekapBulan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefs
    );
    $rekapAlokasiRows = keuangan_rekap_alokasi_syahriyah_bulan($pdo, $rekapBulan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $rekapSyahriyahMasuk = keuangan_syahriyah_terbayar_bulan($pdo, $rekapBulan, $tahunAjaranMulai, $tahunAjaranSelesai);
    foreach ($bulanSlots as $slot) {
        if ((int) ($slot['bulan_tagihan'] ?? 0) === $rekapBulan) {
            $rekapBulanLabel = pondok_bulan_slot_label_tampilan($pdo, $slot);
            break;
        }
    }
    if ($rekapBulanLabel === '') {
        $rekapBulanLabel = pondok_bulan_label($pdo, $rekapBulan, $tahunAjaranMulai, $tahunAjaranSelesai);
    }
}

if (($_GET['export'] ?? '') === 'csv' && $tablesOk) {
    $fn = sprintf('laporan_syahriyah_%d_%d_%d.csv', $tahunAjaranMulai, $tahunAjaranSelesai, time());
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Bulan', 'Total tagihan (semua santri aktif)', 'Terbayar Syahriyah', 'Sisa'], ';');
    foreach ($rowsLaporan as $r) {
        fputcsv($out, [$r['label'], (string) $r['tagihan'], (string) $r['terbayar'], (string) $r['sisa']], ';');
    }
    fclose($out);
    exit;
}

$pageTitle = 'Laporan Syahriyah';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconLaporan = bendahara_page_icon('laporan');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>
        <a href="/keuangan/index.php">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($iconLaporan) ?>"></i></span>
        Laporan Syahriyah per bulan
    </h1>
    <p class="text-muted mb-0">
        Total tagihan per bulan = jumlah santri aktif × nominal Syahriyah masing-masing (tier).
        Terbayar = akumulasi pos syahriyah &amp; makan per bulan tagihan.
        Kalender aktif: <strong><?= $kalenderMode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?></strong> (sesuai pengaturan pondok &amp; kalender akademik).
    </p>
</div>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="/keuangan/index.php">Keuangan</a> terlebih dahulu.</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="">
    <input type="hidden" name="tm" value="<?= (int) $tahunAjaranMulai ?>">
    <input type="hidden" name="ts" value="<?= (int) $tahunAjaranSelesai ?>">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Rekap per bulan</label>
        <select class="form-select form-select-sm" name="rekap_bulan" onchange="this.form.submit()">
            <option value="0">— Pilih bulan —</option>
            <?php foreach ($bulanSlots as $slot): ?>
                <?php $bRekap = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                <option value="<?= $bRekap ?>" <?= $rekapBulan === $bRekap ? 'selected' : '' ?>><?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0 d-none d-md-block">&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
    </div>
    <div class="col-6 col-md-3">
        <?php if ($tablesOk): ?>
            <a class="btn btn-outline-success btn-sm w-100" href="?tm=<?= (int) $tahunAjaranMulai ?>&amp;ts=<?= (int) $tahunAjaranSelesai ?>&amp;export=csv"><i class="fa-solid fa-file-csv me-1"></i> Unduh CSV</a>
        <?php endif; ?>
    </div>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <span class="small text-muted">Santri aktif: <strong><?= count($santriRows) ?></strong> · TA <?= htmlspecialchars($berjalan['ta_label']) ?> · <span class="badge text-bg-primary">Bulan berjalan: <?= htmlspecialchars((string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'])) ?></span></span>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Bulan tagihan</th>
                        <th class="text-end">Total tagihan</th>
                        <th class="text-end">Terbayar (Syahriyah)</th>
                        <th class="text-end">Sisa</th>
                        <th class="text-center" style="width:8rem">Rekap</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rowsLaporan as $r): ?>
                    <?php
                    $bRow = (int) $r['bulan'];
                    $rekapUrl = '?tm=' . (int) $tahunAjaranMulai . '&ts=' . (int) $tahunAjaranSelesai . '&rekap_bulan=' . $bRow;
                    ?>
                    <tr class="<?= !empty($r['is_bulan_ini']) ? 'table-primary' : '' ?><?= $rekapBulan === $bRow ? ' table-info' : '' ?>">
                        <td>
                            <?= htmlspecialchars($r['label']) ?>
                            <?php if (!empty($r['rentang_masehi']) && $kalenderMode === 'hijriyah'): ?>
                                <div class="small text-muted"><?= htmlspecialchars($r['rentang_masehi']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($r['is_bulan_ini'])): ?>
                                <span class="badge text-bg-primary ms-1" style="font-size:.65rem">Bulan ini</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['tagihan'], 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['terbayar'], 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['sisa'], 0, ',', '.') ?></td>
                        <td class="text-center">
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($rekapUrl) ?>#rekap-pos-bulan">
                                <i class="fa-solid fa-chart-pie me-1"></i> POS
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($rekapBulan >= 1 && $tablesOk): ?>
<section id="rekap-pos-bulan" class="mt-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Rekap keluar masuk — <?= htmlspecialchars($rekapBulanLabel) ?></h2>
            <p class="small text-muted mb-0">
                TA <?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, ['mulai' => $tahunAjaranMulai, 'selesai' => $tahunAjaranSelesai])) ?>
                · Pembayaran syahriyah bulan ini: <strong>Rp <?= number_format($rekapSyahriyahMasuk, 0, ',', '.') ?></strong>
            </p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="/pembayaran/rekap_pos.php?bulan=<?= (int) $rekapBulan ?>&amp;tm=<?= (int) $tahunAjaranMulai ?>&amp;ts=<?= (int) $tahunAjaranSelesai ?>">
            <i class="fa-solid fa-up-right-from-square me-1"></i> Halaman rekap POS
        </a>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Komponen tagihan (POS pembayaran)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Komponen</th>
                                    <th class="text-end">Target</th>
                                    <th class="text-end">Masuk</th>
                                    <th class="text-end">Sisa</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($rekapPosRows === []): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada data.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rekapPosRows as $rp): ?>
                                    <?php
                                    $exp = (int) ($rp['expected'] ?? 0);
                                    $paid = (int) ($rp['paid'] ?? 0);
                                    $sisa = max(0, $exp - $paid);
                                    ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars((string) ($rp['pos_nama'] ?? $rp['pos_slug'] ?? '')) ?>
                                            <span class="text-muted small">(<?= htmlspecialchars((string) ($rp['pos_slug'] ?? '')) ?>)</span>
                                        </td>
                                        <td class="text-end font-monospace small">Rp <?= number_format($exp, 0, ',', '.') ?></td>
                                        <td class="text-end font-monospace small text-success">Rp <?= number_format($paid, 0, ',', '.') ?></td>
                                        <td class="text-end font-monospace small<?= $sisa > 0 ? ' text-danger' : '' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Alokasi dana syahriyah (masuk &amp; keluar)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Komponen alokasi</th>
                                    <th class="text-end">%</th>
                                    <th class="text-end">Masuk</th>
                                    <th class="text-end">Keluar</th>
                                    <th class="text-end">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($rekapAlokasiRows === []): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Belum ada alokasi syahriyah aktif. Atur di <a href="/keuangan/pengaturan.php?bagian=alokasi">pengaturan alokasi</a>.</td></tr>
                            <?php else: ?>
                                <?php
                                $sumMasuk = 0;
                                $sumKeluar = 0;
                                foreach ($rekapAlokasiRows as $ra):
                                    $sumMasuk += (int) $ra['masuk'];
                                    $sumKeluar += (int) $ra['keluar'];
                                ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars((string) $ra['nama']) ?>
                                            <?php if ((string) ($ra['kategori'] ?? '') !== ''): ?>
                                                <span class="text-muted small">· <?= htmlspecialchars((string) $ra['kategori']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= htmlspecialchars((string) $ra['persen']) ?>%</td>
                                        <td class="text-end font-monospace small text-success">Rp <?= number_format((int) $ra['masuk'], 0, ',', '.') ?></td>
                                        <td class="text-end font-monospace small text-danger">Rp <?= number_format((int) $ra['keluar'], 0, ',', '.') ?></td>
                                        <td class="text-end font-monospace small">Rp <?= number_format((int) $ra['saldo'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="table-light fw-semibold">
                                    <td colspan="2">Total</td>
                                    <td class="text-end font-monospace small">Rp <?= number_format($sumMasuk, 0, ',', '.') ?></td>
                                    <td class="text-end font-monospace small">Rp <?= number_format($sumKeluar, 0, ',', '.') ?></td>
                                    <td class="text-end font-monospace small">Rp <?= number_format($sumMasuk - $sumKeluar, 0, ',', '.') ?></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    <strong>Masuk</strong> = bagian pembayaran syahriyah menurut persen alokasi.
                    <strong>Keluar</strong> = pengeluaran yang memilih komponen alokasi pada rentang tanggal bulan tagihan.
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
