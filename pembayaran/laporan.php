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
require_once __DIR__ . '/../helpers/keuangan_dashboard.php';
if (!empty($_GET['refresh'])) {
    keuangan_dashboard_cache_invalidate();
}
if (table_exists($pdo, 'keuangan_pembayaran')) {
    keuangan_preload_laporan_caches($pdo);
}

$berjalan = keuangan_periode_berjalan($pdo);
$kalenderMode = pondok_kalender_mode($pdo);
$keuanganTa = keuangan_ta_resolve($pdo);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$rekapBulan = max(0, min(12, (int) ($_GET['rekap_bulan'] ?? 0)));
if ($rekapBulan === 0) {
    $rekapBulan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));
}

$laporan12 = tagihan_laporan_12bulan_cached($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanSlots);
$tablesOk = (bool) ($laporan12['tables_ok'] ?? false);
$expectedByMonth = $laporan12['expected_by_month'] ?? array_fill(1, 12, 0);
$paidByMonth = $laporan12['paid_by_month'] ?? array_fill(1, 12, 0);

$bulanBerjalan = $berjalan['bulan'];

$rowsLaporan = [];
$laporanTotalTagihan = 0;
$laporanTotalTerbayar = 0;
$laporanTotalSisa = 0;
foreach ($bulanSlots as $slot) {
    $b = (int) ($slot['bulan_tagihan'] ?? 0);
    if ($b < 1 || $b > 12) {
        continue;
    }
    $tagihan = (int) ($expectedByMonth[$b] ?? 0);
    $terbayar = (int) ($paidByMonth[$b] ?? 0);
    $sisa = max(0, $tagihan - $terbayar);
    $laporanTotalTagihan += $tagihan;
    $laporanTotalTerbayar += $terbayar;
    $laporanTotalSisa += $sisa;
    $rowsLaporan[] = [
        'bulan' => $b,
        'label' => pondok_bulan_slot_label_tampilan($pdo, $slot),
        'rentang_masehi' => ($slot['masehi_awal'] ?? '') !== '' ? ($slot['masehi_awal'] . ' – ' . $slot['masehi_akhir']) : '',
        'tagihan' => $tagihan,
        'terbayar' => $terbayar,
        'sisa' => $sisa,
        'is_bulan_ini' => $b === $bulanBerjalan,
    ];
}

$rekapPosRows = [];
$rekapAlokasiRows = [];
$rekapBulanLabel = '';
$rekapSyahriyahMasuk = 0;
$rekapSyahriyahHarusMasuk = 0;
$rekapSyahriyahSisa = 0;
$rekapCapaiPersen = 0.0;
$rekapPosTotalExpected = 0;
$rekapPosTotalPaid = 0;
$rekapPosTotalSisa = 0;
if ($tablesOk && $rekapBulan >= 1 && $rekapBulan <= 12) {
    $rekapSyahriyahHarusMasuk = (int) ($expectedByMonth[$rekapBulan] ?? 0);
    $rekapSyahriyahMasuk = (int) ($paidByMonth[$rekapBulan] ?? 0);
    $rekapSyahriyahSisa = max(0, $rekapSyahriyahHarusMasuk - $rekapSyahriyahMasuk);
    $rekapCapaiPersen = $rekapSyahriyahHarusMasuk > 0
        ? min(100.0, round($rekapSyahriyahMasuk / $rekapSyahriyahHarusMasuk * 100, 1))
        : 0.0;
    $rekapAlokasiRows = keuangan_rekap_alokasi_syahriyah_bulan(
        $pdo,
        $rekapBulan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $rekapSyahriyahHarusMasuk
    );

    $biayaDefs = keuangan_biaya_definitions();
    $rekapPosRows = keuangan_rekap_pos_with_expected_cached(
        $pdo,
        'BULANAN',
        $rekapBulan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefs
    );
    foreach ($rekapPosRows as $rpRow) {
        $rekapPosTotalExpected += (int) ($rpRow['expected'] ?? 0);
        $rekapPosTotalPaid += (int) ($rpRow['paid'] ?? 0);
        $rekapPosTotalSisa += max(0, (int) ($rpRow['expected'] ?? 0) - (int) ($rpRow['paid'] ?? 0));
    }
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

$santriRows = $tablesOk ? tagihan_santri_aktif_rows_cached($pdo, false) : [];

$laporanPopupSantri = [];
foreach ($santriRows as $sr) {
    $laporanPopupSantri[] = [
        'nama_santri' => (string) ($sr['nama_santri'] ?? ''),
        'nis' => (string) ($sr['nis'] ?? ''),
        'tingkatan' => (string) ($sr['tingkatan'] ?? ''),
    ];
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
        Laporan Syahriyah
    </h1>
    <p class="text-muted mb-0">
        Tampilan utama satu bulan: alokasi per persen (<strong>harus masuk</strong>, <strong>masuk</strong>, <strong>keluar</strong>, <strong>saldo</strong>).
        Pilih bulan lain lewat dropdown. Kalender: <strong><?= $kalenderMode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?></strong>.
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/kartu_syahriyah_santri.php')) ?>">Kartu syahriyah santri</a>
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_kopsa_per_santri.php')) ?>">KOPSA per santri</a>
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php')) ?>">Syahriyah PKPPS</a>
    </p>
</div>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="/keuangan/index.php">Keuangan</a> terlebih dahulu.</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="#rekap-pos-bulan">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select class="form-select form-select-sm pondok-bulan-select" name="rekap_bulan" data-auto-submit="1">
            <?php foreach ($bulanSlots as $slot): ?>
                <?php $bRekap = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                <option value="<?= $bRekap ?>" <?= $rekapBulan === $bRekap ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($slot['label'] ?? '')) ?>
                    <?php if ($bRekap === $bulanBerjalan): ?> (berjalan)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0 d-none d-md-block">&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
    </div>
    <div class="col-6 col-md-3">
        <?php if ($tablesOk): ?>
            <a class="btn btn-outline-success btn-sm w-100" href="?export=csv"><i class="fa-solid fa-file-csv me-1"></i> Unduh CSV tahunan</a>
        <?php endif; ?>
    </div>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none bendahara-stat--clickable" data-laporan-popup="santri" aria-expanded="false">
            <span class="small text-muted">
                Santri aktif: <strong><?= count($santriRows) ?></strong>
                · TA <?= htmlspecialchars($berjalan['ta_label']) ?>
            </span>
        </button>
        <a class="btn btn-sm btn-outline-secondary" href="/pembayaran/rekap_pos.php?bulan=<?= (int) $rekapBulan ?>">
            <i class="fa-solid fa-up-right-from-square me-1"></i> Rekap POS
        </a>
    </div>
    <div class="bendahara-stat-popup d-none" data-laporan-popup-panel="santri" role="region" aria-live="polite"></div>
</div>

<?php if ($rekapBulan >= 1 && $tablesOk): ?>
<section id="rekap-pos-bulan" class="mb-4">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1"><?= htmlspecialchars($rekapBulanLabel) ?></h2>
            <p class="small text-muted mb-0">
                TA <?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, ['mulai' => $tahunAjaranMulai, 'selesai' => $tahunAjaranSelesai])) ?>
                <?php if ($rekapBulan === $bulanBerjalan): ?>
                    <span class="badge text-bg-primary ms-1">Bulan berjalan</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="text-end">
            <div class="small text-muted">Capaian pembayaran syahriyah</div>
            <div class="h4 mb-0 text-primary"><?= number_format($rekapCapaiPersen, 1, ',', '.') ?>%</div>
        </div>
    </div>

    <div class="row g-2 mb-3" id="laporan-stat-row">
        <div class="col-6 col-lg-3">
            <button type="button" class="card shadow-sm border-primary-subtle h-100 w-100 text-start bendahara-stat--clickable" data-laporan-popup="harus">
                <div class="card-body py-2">
                    <div class="small text-muted">Harus masuk</div>
                    <div class="fw-semibold font-monospace">Rp <?= number_format($rekapSyahriyahHarusMasuk, 0, ',', '.') ?></div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="card shadow-sm border-success-subtle h-100 w-100 text-start bendahara-stat--clickable" data-laporan-popup="masuk">
                <div class="card-body py-2">
                    <div class="small text-muted">Masuk (terbayar)</div>
                    <div class="fw-semibold font-monospace text-success">Rp <?= number_format($rekapSyahriyahMasuk, 0, ',', '.') ?></div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="card shadow-sm border-danger-subtle h-100 w-100 text-start bendahara-stat--clickable" data-laporan-popup="sisa">
                <div class="card-body py-2">
                    <div class="small text-muted">Sisa tagihan</div>
                    <div class="fw-semibold font-monospace<?= $rekapSyahriyahSisa > 0 ? ' text-danger' : '' ?>">Rp <?= number_format($rekapSyahriyahSisa, 0, ',', '.') ?></div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="card shadow-sm h-100 w-100 text-start bendahara-stat--clickable" data-laporan-popup="pos">
                <div class="card-body py-2">
                    <div class="small text-muted mb-1">Progres · komponen POS</div>
                    <div class="progress mb-1" style="height:.55rem" role="progressbar" aria-valuenow="<?= (int) $rekapCapaiPersen ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-primary" style="width:<?= min(100, max(0, (int) $rekapCapaiPersen)) ?>%"></div>
                    </div>
                    <div class="fw-semibold text-primary"><?= number_format($rekapCapaiPersen, 1, ',', '.') ?>%</div>
                </div>
            </button>
        </div>
    </div>
    <div class="bendahara-stat-popup d-none mb-3" data-laporan-popup-panel="detail" role="region" aria-live="polite"></div>

    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Alokasi syahriyah per persen</span>
            <span class="small text-muted fw-normal">Harus masuk · Masuk · Keluar · Saldo</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Komponen</th>
                            <th class="text-end">%</th>
                            <th class="text-end">Harus masuk</th>
                            <th class="text-end">Masuk</th>
                            <th class="text-end">Keluar</th>
                            <th class="text-end">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rekapAlokasiRows === []): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada alokasi syahriyah aktif. Atur di <a href="/keuangan/pengaturan.php?bagian=alokasi">pengaturan alokasi</a>.</td></tr>
                    <?php else: ?>
                        <?php
                        $sumHarus = 0;
                        $sumMasuk = 0;
                        $sumKeluar = 0;
                        foreach ($rekapAlokasiRows as $ra):
                            $sumHarus += (int) ($ra['harus_masuk'] ?? 0);
                            $sumMasuk += (int) ($ra['masuk'] ?? 0);
                            $sumKeluar += (int) ($ra['pengeluaran'] ?? $ra['keluar'] ?? 0);
                            $saldoRow = (int) ($ra['saldo'] ?? 0);
                            $persenRow = (float) ($ra['persen'] ?? 0);
                            $capaiRow = (int) ($ra['harus_masuk'] ?? 0) > 0
                                ? min(100, (int) round((int) ($ra['masuk'] ?? 0) / (int) ($ra['harus_masuk'] ?? 1) * 100))
                                : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars((string) $ra['nama']) ?></div>
                                    <?php if ((string) ($ra['kategori'] ?? '') !== ''): ?>
                                        <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars((string) $ra['kategori']) ?></div>
                                    <?php endif; ?>
                                    <div class="progress mt-1" style="height:.35rem" title="Capaian masuk <?= $capaiRow ?>%">
                                        <div class="progress-bar bg-success" style="width:<?= $capaiRow ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <?php if (!empty($ra['is_pkpps_gaji'])): ?>
                                        <span class="badge text-bg-secondary"><?= htmlspecialchars((string) $persenRow) ?>%</span>
                                        <span class="badge text-bg-info ms-1">+PKPPS</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary"><?= htmlspecialchars((string) $persenRow) ?>%</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end font-monospace small">Rp <?= number_format((int) ($ra['harus_masuk'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end font-monospace small text-success">Rp <?= number_format((int) ($ra['masuk'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end font-monospace small text-danger">Rp <?= number_format((int) ($ra['pengeluaran'] ?? $ra['keluar'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end font-monospace small<?= $saldoRow < 0 ? ' text-danger' : '' ?>">Rp <?= number_format($saldoRow, 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light fw-semibold">
                            <td>Total</td>
                            <td class="text-end text-muted small">% + PKPPS</td>
                            <td class="text-end font-monospace small">Rp <?= number_format($sumHarus, 0, ',', '.') ?></td>
                            <td class="text-end font-monospace small text-success">Rp <?= number_format($sumMasuk, 0, ',', '.') ?></td>
                            <td class="text-end font-monospace small text-danger">Rp <?= number_format($sumKeluar, 0, ',', '.') ?></td>
                            <td class="text-end font-monospace small">Rp <?= number_format($sumMasuk - $sumKeluar, 0, ',', '.') ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer small text-muted">
            <strong>Harus masuk</strong> = target PKPPS (dana umum) atau dasar syahriyah × % komponen (selaras dengan
            <a href="<?= htmlspecialchars(app_href('/pembayaran/kartu_syahriyah_santri.php')) ?>">kartu syahriyah santri</a>).
            <strong>Masuk</strong> = cicilan dialokasikan PKPPS dulu, sisanya ke % dasar.
            <strong>Keluar</strong> = pengeluaran pada komponen alokasi di rentang bulan tagihan.
            <strong>Saldo</strong> = masuk − keluar.
            Setelah ubah pembayaran/pengaturan, muat ulang dengan <code>?refresh=1</code>.
        </div>
    </div>

    <details class="mb-3">
        <summary class="fw-semibold small text-primary" style="cursor:pointer">Komponen tagihan POS (detail)</summary>
        <div class="card shadow-sm mt-2">
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
                            <tr class="table-secondary fw-semibold">
                                <td>Total komponen POS</td>
                                <td class="text-end font-monospace small">Rp <?= number_format($rekapPosTotalExpected, 0, ',', '.') ?></td>
                                <td class="text-end font-monospace small text-success">Rp <?= number_format($rekapPosTotalPaid, 0, ',', '.') ?></td>
                                <td class="text-end font-monospace small<?= $rekapPosTotalSisa > 0 ? ' text-danger' : '' ?>">Rp <?= number_format($rekapPosTotalSisa, 0, ',', '.') ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </details>
</section>
<?php endif; ?>

<details class="card shadow-sm">
    <summary class="card-header fw-semibold" style="cursor:pointer">Ringkasan semua bulan (tahun ajaran)</summary>
    <div class="card-body p-0 border-top">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Bulan tagihan</th>
                        <th class="text-end">Total tagihan</th>
                        <th class="text-end">Terbayar</th>
                        <th class="text-end">Sisa</th>
                        <th class="text-center" style="width:6rem">Buka</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rowsLaporan as $r): ?>
                    <?php
                    $bRow = (int) $r['bulan'];
                    $rekapUrl = '?rekap_bulan=' . $bRow . '#rekap-pos-bulan';
                    $capaiBulan = (int) ($r['tagihan'] ?? 0) > 0
                        ? min(100, (int) round((int) ($r['terbayar'] ?? 0) / (int) ($r['tagihan'] ?? 1) * 100))
                        : 0;
                    ?>
                    <tr class="<?= !empty($r['is_bulan_ini']) ? 'table-primary' : '' ?><?= $rekapBulan === $bRow ? ' table-info' : '' ?>">
                        <td>
                            <?= htmlspecialchars($r['label']) ?>
                            <?php if (!empty($r['rentang_masehi']) && $kalenderMode === 'hijriyah'): ?>
                                <div class="small text-muted"><?= htmlspecialchars($r['rentang_masehi']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['tagihan'], 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['terbayar'], 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['sisa'], 0, ',', '.') ?> <span class="text-muted">(<?= $capaiBulan ?>%)</span></td>
                        <td class="text-center">
                            <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= htmlspecialchars($rekapUrl) ?>">Lihat</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rowsLaporan !== []): ?>
                    <?php
                    $laporanCapaiTahun = $laporanTotalTagihan > 0
                        ? min(100, (int) round($laporanTotalTerbayar / $laporanTotalTagihan * 100))
                        : 0;
                    ?>
                    <tr class="table-light fw-bold border-top border-2">
                        <td>
                            <span class="text-uppercase small">Jumlah total tahun ajaran</span>
                            <div class="small text-muted fw-normal"><?= count($rowsLaporan) ?> bulan tagihan</div>
                        </td>
                        <td class="text-end font-monospace">Rp <?= number_format($laporanTotalTagihan, 0, ',', '.') ?></td>
                        <td class="text-end font-monospace text-success">Rp <?= number_format($laporanTotalTerbayar, 0, ',', '.') ?></td>
                        <td class="text-end font-monospace<?= $laporanTotalSisa > 0 ? ' text-danger' : '' ?>">
                            Rp <?= number_format($laporanTotalSisa, 0, ',', '.') ?>
                            <span class="text-muted fw-semibold">(<?= $laporanCapaiTahun ?>%)</span>
                        </td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</details>

<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<script type="application/json" id="laporan-popup-data"><?= json_encode([
    'santri' => $laporanPopupSantri,
    'alokasi' => $rekapAlokasiRows,
    'pos' => $rekapPosRows,
    'bulan_label' => $rekapBulanLabel,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(app_href('/assets/js/keuangan-laporan-popup.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
