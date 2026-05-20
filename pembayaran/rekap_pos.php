<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';

require_roles(['admin', 'pengurus']);

ensure_keuangan_transaksi_tables($pdo);
ensure_santri_identity_columns($pdo);

$biayaDefinitions = keuangan_biaya_definitions();
$bulanMap = keuangan_bulan_map();
$periode = keuangan_tahun_ajaran_aktif($pdo);
$berjalan = keuangan_periode_berjalan($pdo);
$wajibSlugs = array_flip(keuangan_tagihan_wajib_slugs());

$jenisPeriode = strtoupper(trim((string) ($_GET['jenis'] ?? 'BULANAN')));
if (!in_array($jenisPeriode, ['BULANAN', 'AWAL_TAHUN'], true)) {
    $jenisPeriode = 'BULANAN';
}
$tahunAjaranMulai = max(2000, min(2100, (int) ($_GET['tm'] ?? $berjalan['mulai'] ?? $periode['mulai'])));
$tahunAjaranSelesai = max($tahunAjaranMulai, min(2105, (int) ($_GET['ts'] ?? $berjalan['selesai'] ?? $periode['selesai'])));
$bulanTagihan = max(1, min(12, (int) ($_GET['bulan'] ?? $berjalan['bulan'])));

$tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');
$rows = [];
$sumExpected = 0;
$sumPaid = 0;
$jumlahSantriAktif = 0;

if ($tablesOk) {
    $rows = keuangan_rekap_pos_with_expected(
        $pdo,
        $jenisPeriode,
        $bulanTagihan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefinitions
    );
    foreach ($rows as $r) {
        $sumExpected += (int) ($r['expected'] ?? 0);
        $sumPaid += (int) ($r['paid'] ?? 0);
    }
    if (table_exists($pdo, 'santri')) {
        $aktifSql = santri_sql_aktif_only('s');
        $jumlahSantriAktif = (int) $pdo->query('SELECT COUNT(*) FROM santri s WHERE ' . $aktifSql)->fetchColumn();
    }
}

$sumSisa = max(0, $sumExpected - $sumPaid);
$pctCapai = $sumExpected > 0 ? min(100, (int) round(($sumPaid / $sumExpected) * 100)) : 0;

$periodeLabel = $jenisPeriode === 'BULANAN'
    ? ($bulanMap[$bulanTagihan] ?? (string) $bulanTagihan) . ' · TA ' . $tahunAjaranMulai . '/' . $tahunAjaranSelesai
    : 'Awal tahun · TA ' . $tahunAjaranMulai . '/' . $tahunAjaranSelesai;

$queryBase = http_build_query([
    'jenis' => $jenisPeriode,
    'tm' => $tahunAjaranMulai,
    'ts' => $tahunAjaranSelesai,
    'bulan' => $bulanTagihan,
]);

if (($_GET['export'] ?? '') === 'csv' && $tablesOk) {
    $fn = sprintf(
        'rekap_pos_%s_%d_%d_%s.csv',
        strtolower($jenisPeriode),
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $jenisPeriode === 'BULANAN' ? 'b' . $bulanTagihan : 'awal'
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['POS', 'Slug', 'Tagihan wajib', 'Target (santri aktif × tarif)', 'Terbayar', 'Sisa', 'Capai %'], ';');
    foreach ($rows as $r) {
        $slug = (string) ($r['pos_slug'] ?? '');
        $exp = (int) ($r['expected'] ?? 0);
        $paid = (int) ($r['paid'] ?? 0);
        $sisa = max(0, $exp - $paid);
        $pct = $exp > 0 ? (string) (int) round(($paid / $exp) * 100) : '—';
        fputcsv($out, [
            (string) ($r['pos_nama'] ?? ''),
            $slug,
            isset($wajibSlugs[$slug]) ? 'Ya' : 'Tidak',
            (string) $exp,
            (string) $paid,
            (string) $sisa,
            $pct,
        ], ';');
    }
    fputcsv($out, ['TOTAL', '', '', (string) $sumExpected, (string) $sumPaid, (string) $sumSisa, (string) $pctCapai], ';');
    fclose($out);
    exit;
}

$pageTitle = 'Rekap per POS';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconPage = bendahara_page_icon('rekap_pos');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>
        <a href="/pwa_nailulmuna/keuangan/index.php">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($iconPage) ?>"></i></span>
        Rekap per POS
    </h1>
    <p class="text-muted mb-0">
        <strong>Target</strong> = jumlah santri aktif × tarif pengaturan per komponen.
        <strong>Terbayar</strong> = akumulasi rincian pembayaran pada periode terpilih.
        Tagihan wajib bulanan: <strong>Syahriyah</strong> dan <strong>Makan</strong> — lihat juga
        <a href="/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php">Tagihan Bulanan</a>.
    </p>
</div>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="/pwa_nailulmuna/keuangan/pembayaran.php">Input pembayaran</a> sekali untuk inisialisasi skema.</div>
<?php endif; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Jenis periode</label>
        <select class="form-select form-select-sm" name="jenis" id="rekap-pos-jenis">
            <option value="BULANAN" <?= $jenisPeriode === 'BULANAN' ? 'selected' : '' ?>>Bulanan</option>
            <option value="AWAL_TAHUN" <?= $jenisPeriode === 'AWAL_TAHUN' ? 'selected' : '' ?>>Awal tahun</option>
        </select>
    </div>
    <div class="col-6 col-md-2" id="rekap-pos-bulan-wrap">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select class="form-select form-select-sm" name="bulan">
            <?php foreach ($bulanMap as $b => $label): ?>
                <option value="<?= $b ?>" <?= $b === $bulanTagihan ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Th. ajaran mulai</label>
        <input class="form-control form-control-sm" type="number" name="tm" value="<?= (int) $tahunAjaranMulai ?>" min="2000" max="2100">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Th. ajaran selesai</label>
        <input class="form-control form-control-sm" type="number" name="ts" value="<?= (int) $tahunAjaranSelesai ?>" min="2000" max="2105">
    </div>
    <div class="col-12 col-md-4 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
        <?php if ($tablesOk && $rows !== []): ?>
            <a class="btn btn-outline-secondary btn-sm" href="?<?= htmlspecialchars($queryBase) ?>&amp;export=csv"><i class="fa-solid fa-file-csv me-1"></i> CSV</a>
        <?php endif; ?>
    </div>
</form>

<p class="small text-muted mb-3">
    Periode: <strong><?= htmlspecialchars($periodeLabel) ?></strong>
    · Santri aktif: <strong><?= number_format($jumlahSantriAktif, 0, ',', '.') ?></strong>
</p>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-tagihan">
            <div class="app-mini-stat-label">Total target</div>
            <div class="app-mini-stat-value">Rp <?= number_format($sumExpected, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-bayar">
            <div class="app-mini-stat-label">Terbayar</div>
            <div class="app-mini-stat-value text-success">Rp <?= number_format($sumPaid, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
            <div class="app-mini-stat-label">Sisa</div>
            <div class="app-mini-stat-value text-danger">Rp <?= number_format($sumSisa, 0, ',', '.') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-lunas">
            <div class="app-mini-stat-label">Capai target</div>
            <div class="app-mini-stat-value"><?= $pctCapai ?>%</div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Komponen (POS)</th>
                        <th class="text-center">Wajib</th>
                        <th class="text-end">Target</th>
                        <th class="text-end">Terbayar</th>
                        <th class="text-end">Sisa</th>
                        <th class="text-end" style="min-width:7rem;">Capai</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada data untuk filter ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $slug = (string) ($r['pos_slug'] ?? '');
                        $exp = (int) ($r['expected'] ?? 0);
                        $paid = (int) ($r['paid'] ?? 0);
                        $sisa = max(0, $exp - $paid);
                        $pct = $exp > 0 ? min(100, (int) round(($paid / $exp) * 100)) : null;
                        $isWajib = isset($wajibSlugs[$slug]);
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars((string) ($r['pos_nama'] ?? $slug)) ?>
                                <span class="text-muted small">(<?= htmlspecialchars($slug) ?>)</span>
                            </td>
                            <td class="text-center">
                                <?php if ($isWajib && $jenisPeriode === 'BULANAN'): ?>
                                    <span class="badge text-bg-primary">Wajib</span>
                                <?php elseif ($slug === 'saku'): ?>
                                    <span class="badge text-bg-secondary">Opsional</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace">Rp <?= number_format($exp, 0, ',', '.') ?></td>
                            <td class="text-end font-monospace text-success">Rp <?= number_format($paid, 0, ',', '.') ?></td>
                            <td class="text-end font-monospace<?= $sisa > 0 ? ' text-danger' : '' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></td>
                            <td class="text-end">
                                <?php if ($pct === null): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <div class="progress" style="height:1.25rem;">
                                        <div class="progress-bar<?= $pct >= 100 ? ' bg-success' : ($pct >= 50 ? ' bg-warning' : ' bg-danger') ?>" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end font-monospace">Rp <?= number_format($sumExpected, 0, ',', '.') ?></td>
                        <td class="text-end font-monospace text-success">Rp <?= number_format($sumPaid, 0, ',', '.') ?></td>
                        <td class="text-end font-monospace">Rp <?= number_format($sumSisa, 0, ',', '.') ?></td>
                        <td class="text-end"><?= $pctCapai ?>%</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mt-3">
    <a class="btn btn-outline-primary btn-sm" href="/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php?bulan=<?= (int) $bulanTagihan ?>&amp;tm=<?= (int) $tahunAjaranMulai ?>&amp;ts=<?= (int) $tahunAjaranSelesai ?>"><i class="fa-solid fa-receipt me-1"></i> Tagihan per santri</a>
    <a class="btn btn-outline-secondary btn-sm" href="/pwa_nailulmuna/pembayaran/riwayat.php"><i class="fa-solid fa-clock-rotate-left me-1"></i> Riwayat pembayaran</a>
</div>

<script>
(function () {
    const jenis = document.getElementById('rekap-pos-jenis');
    const bulanWrap = document.getElementById('rekap-pos-bulan-wrap');
    if (!jenis || !bulanWrap) return;
    function sync() {
        bulanWrap.style.display = jenis.value === 'BULANAN' ? '' : 'none';
    }
    jenis.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
