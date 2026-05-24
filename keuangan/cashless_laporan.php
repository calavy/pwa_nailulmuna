<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$koperasiList = cashless_koperasi_list($pdo);
$filterKoperasiId = (int) ($_GET['koperasi_id'] ?? 0);
if ($filterKoperasiId < 1 || $filterKoperasiId > 3) {
    $filterKoperasiId = 0;
}

$dari = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
$sampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
    $dari = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    $sampai = date('Y-m-d');
}
if ($dari > $sampai) {
    [$dari, $sampai] = [$sampai, $dari];
}

$ringkasPerKop = cashless_koperasi_laporan_per_koperasi($pdo, $dari, $sampai);
$laporan = cashless_koperasi_laporan_ringkas(
    $pdo,
    $filterKoperasiId > 0 ? $filterKoperasiId : null,
    $dari,
    $sampai
);

$filterNama = $filterKoperasiId > 0
    ? (cashless_koperasi_by_id($pdo, $filterKoperasiId)['nama'] ?? ('Koperasi ' . $filterKoperasiId))
    : 'Semua koperasi';

$pageTitle = 'Laporan Cashless Koperasi';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Laporan Cashless Koperasi</h1>
    <a href="<?= htmlspecialchars(app_href('/koperasi/index.php')) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Portal petugas koperasi</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small">Koperasi</label>
        <select name="koperasi_id" class="form-select">
            <option value="0" <?= $filterKoperasiId === 0 ? 'selected' : '' ?>>Semua koperasi</option>
            <?php foreach ($koperasiList as $kop): ?>
                <option value="<?= (int) $kop['id'] ?>" <?= (int) $kop['id'] === $filterKoperasiId ? 'selected' : '' ?>><?= htmlspecialchars((string) $kop['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small">Dari</label>
        <input type="date" name="dari" class="form-control" value="<?= htmlspecialchars($dari) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label small">Sampai</label>
        <input type="date" name="sampai" class="form-control" value="<?= htmlspecialchars($sampai) ?>">
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-primary w-100">Tampilkan</button>
    </div>
</form>

<?php if ($ringkasPerKop !== []): ?>
<div class="row g-2 mb-3">
    <?php foreach ($ringkasPerKop as $rk): ?>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <div class="fw-semibold"><?= htmlspecialchars((string) $rk['nama']) ?></div>
                    <div class="small text-muted mt-1">Rp <?= number_format((int) $rk['total_debit'], 0, ',', '.') ?> · <?= (int) $rk['total_transaksi'] ?> trx · <?= (int) $rk['jumlah_santri'] ?> santri</div>
                    <a class="small" href="<?= htmlspecialchars(app_href('/keuangan/cashless_laporan.php?koperasi_id=' . (int) $rk['koperasi_id'] . '&dari=' . urlencode($dari) . '&sampai=' . urlencode($sampai))) ?>">Detail</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Total debit (<?= htmlspecialchars($filterNama) ?>)</div>
                <div class="h5 mb-0">Rp <?= number_format((int) $laporan['total_debit'], 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Jumlah transaksi</div>
                <div class="h5 mb-0"><?= (int) $laporan['total_transaksi'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Santri unik</div>
                <div class="h5 mb-0"><?= (int) $laporan['jumlah_santri'] ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold small">Rincian transaksi — <?= htmlspecialchars($filterNama) ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Waktu</th><?php if ($filterKoperasiId === 0): ?><th>Koperasi</th><?php endif; ?><th>NIS</th><th>Nama</th><th>Tingkatan</th><th>Keterangan</th><th class="text-end">Nominal</th></tr></thead>
                <tbody>
                <?php if ($laporan['rows'] !== []): foreach ($laporan['rows'] as $r): ?>
                    <?php
                    $kopLabel = '-';
                    if (isset($r['koperasi_id']) && (int) $r['koperasi_id'] > 0) {
                        $kopLabel = cashless_koperasi_by_id($pdo, (int) $r['koperasi_id'])['nama'] ?? ('Koperasi ' . (int) $r['koperasi_id']);
                    }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                        <?php if ($filterKoperasiId === 0): ?><td><?= htmlspecialchars($kopLabel) ?></td><?php endif; ?>
                        <td><?= htmlspecialchars((string) $r['nis']) ?></td>
                        <td><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
                        <td><?= htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['keterangan'] ?: '-')) ?></td>
                        <td class="text-end">Rp <?= number_format((int) round((float) ($r['nominal'] ?? 0)), 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="<?= $filterKoperasiId === 0 ? 7 : 6 ?>" class="text-center text-muted py-4">Tidak ada transaksi pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
