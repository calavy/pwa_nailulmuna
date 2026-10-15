<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_data_quality.php';
require_once __DIR__ . '/../helpers/keuangan_tanggal_validasi.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$bounds = keuangan_tanggal_ta_bounds($pdo);
$badDates = keuangan_data_quality_bad_dates($pdo, 50);
$dupSantri = keuangan_data_quality_santri_duplicates($pdo);
$noBukti = keuangan_data_quality_pengeluaran_tanpa_bukti($pdo, 20);
$posOut = keuangan_data_quality_pos_outliers($pdo, 30);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Perbaikan Data Keuangan';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a>
    · Perbaikan data
</div>

<h1 class="h4 mb-3">Kualitas data keuangan</h1>
<p class="text-muted small">
    Rentang tanggal TA: <strong><?= htmlspecialchars($bounds['min']) ?></strong> s/d
    <strong><?= htmlspecialchars($bounds['max']) ?></strong>.
    POS salah klasifikasi: edit manual di
    <a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pengeluaran.php')) ?>">riwayat pengeluaran</a>
    (<code>pos</code> = kategori dana; <code>alokasi_nama</code> = komponen).
</p>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Tanggal bermasalah</div><div class="fs-4"><?= count($badDates) ?></div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Santri NIS duplikat/shadow</div><div class="fs-4"><?= count($dupSantri) ?></div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Pengeluaran tanpa bukti</div><div class="fs-4"><?= count($noBukti) ?></div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Outlier POS</div><div class="fs-4"><?= count($posOut) ?></div></div></div></div>
</div>

<p class="small text-muted mb-4">
    CLI hosting: <code>php scripts/_diag_keuangan_data_quality.php</code> ·
    <code>php scripts/_repair_keuangan_data_quality.php --apply --only=tanggal,santri,bukti</code>
    (backup DB dulu).
</p>

<?php if ($posOut !== []): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Outlier POS (review manual)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>ID</th><th>Tanggal</th><th>Pos</th><th>Alokasi</th><th>Nominal</th><th>Flag</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($posOut as $r): ?>
                <tr>
                    <td><?= (int) ($r['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['pos'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['alokasi_nama'] ?? '')) ?></td>
                    <td><?= $fmt((int) ($r['nominal'] ?? 0)) ?></td>
                    <td><span class="badge text-bg-warning"><?= htmlspecialchars((string) ($r['flag'] ?? '')) ?></span></td>
                    <td><a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pengeluaran.php?q=' . (int) ($r['id'] ?? 0))) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($badDates !== []): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Sample tanggal bermasalah</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Jenis</th><th>ID</th><th>Tanggal</th><th>Alasan</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($badDates, 0, 20) as $r): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($r['kind'] ?? '')) ?></td>
                    <td><?= (int) ($r['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['reason'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
