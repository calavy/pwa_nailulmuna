<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

$bulanMap = keuangan_bulan_map();
$berjalan = keuangan_periode_berjalan($pdo);
$tahunAjaranMulai = max(2000, min(2100, (int) ($_GET['tm'] ?? $berjalan['mulai'])));
$tahunAjaranSelesai = max($tahunAjaranMulai, min(2105, (int) ($_GET['ts'] ?? $berjalan['selesai'])));

$tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');

$sqlSantri = 'SELECT id, tingkatan, kategori_kelas, is_aktif FROM santri';
if (column_exists($pdo, 'santri', 'is_aktif')) {
    $sqlSantri .= ' WHERE COALESCE(is_aktif, 1) = 1';
}
$santriRows = $tablesOk ? $pdo->query($sqlSantri)->fetchAll() : [];

$expectedPerSantri = [];
$totalExpectedOneMonth = 0;
foreach ($santriRows as $s) {
    $kelasKategori = trim((string) ($s['kategori_kelas'] ?? ''));
    if ($kelasKategori === '' && !empty($s['tingkatan'])) {
        $kelasKategori = (string) $s['tingkatan'];
    }
    $exp = tagihan_wajib_total_expected($pdo, $kelasKategori);
    $expectedPerSantri[(int) $s['id']] = $exp;
    $totalExpectedOneMonth += $exp;
}

$paidByMonth = array_fill(1, 12, 0);
if ($tablesOk) {
    $st = $pdo->prepare('
        SELECT p.bulan_tagihan AS b, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.jenis_periode = \'BULANAN\'
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND d.pos_slug IN (\'syahriyah\', \'makan\')
          AND p.bulan_tagihan BETWEEN 1 AND 12
        GROUP BY p.bulan_tagihan
    ');
    $st->execute(['tm' => $tahunAjaranMulai, 'ts' => $tahunAjaranSelesai]);
    foreach ($st->fetchAll() as $row) {
        $b = (int) ($row['b'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $paidByMonth[$b] = (int) ((float) ($row['total'] ?? 0));
        }
    }
}

$rowsLaporan = [];
$bulanBerjalan = $berjalan['bulan'];
for ($b = 1; $b <= 12; $b++) {
    $tagihan = $totalExpectedOneMonth;
    $terbayar = $paidByMonth[$b];
    $rowsLaporan[] = [
        'bulan' => $b,
        'label' => $bulanMap[$b],
        'tagihan' => $tagihan,
        'terbayar' => $terbayar,
        'sisa' => max(0, $tagihan - $terbayar),
        'is_bulan_ini' => $b === $bulanBerjalan,
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
        <a href="/pwa_nailulmuna/keuangan/index.php">Keuangan</a>
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($iconLaporan) ?>"></i></span>
        Laporan Syahriyah per bulan
    </h1>
    <p class="text-muted mb-0">
        Total tagihan per bulan = jumlah santri aktif × nominal Syahriyah masing-masing (tier).
        Terbayar = akumulasi detail pos <code>syahriyah</code> pada pembayaran bulanan dengan bulan tagihan sama.
    </p>
</div>

<?php if (!$tablesOk): ?>
    <div class="alert alert-warning">Tabel keuangan belum tersedia. Buka <a href="/pwa_nailulmuna/keuangan/index.php">Keuangan</a> terlebih dahulu.</div>
<?php endif; ?>

<form class="row g-2 align-items-end mb-3 bendahara-toolbar" method="get" action="">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Th. ajaran mulai</label>
        <input class="form-control form-control-sm" type="number" name="tm" value="<?= (int) $tahunAjaranMulai ?>" min="2000" max="2100">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Th. ajaran selesai</label>
        <input class="form-control form-control-sm" type="number" name="ts" value="<?= (int) $tahunAjaranSelesai ?>" min="2000" max="2105">
    </div>
    <div class="col-6 col-md-2">
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
        <span class="small text-muted">Santri aktif: <strong><?= count($santriRows) ?></strong> · TA <?= htmlspecialchars($berjalan['ta_label']) ?> · <span class="badge text-bg-primary">Bulan berjalan: <?= htmlspecialchars($berjalan['bulan_label']) ?></span></span>
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
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rowsLaporan as $r): ?>
                    <tr class="<?= !empty($r['is_bulan_ini']) ? 'table-primary' : '' ?>">
                        <td>
                            <?= htmlspecialchars($r['label']) ?>
                            <?php if (!empty($r['is_bulan_ini'])): ?>
                                <span class="badge text-bg-primary ms-1" style="font-size:.65rem">Bulan ini</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['tagihan'], 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['terbayar'], 0, ',', '.') ?></td>
                        <td class="text-end font-monospace small">Rp <?= number_format($r['sisa'], 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
