<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_setoran.php';

akademik_setoran_require_access();
ensure_akademik_setoran_extended_schema($pdo);

$defaultMulai = date('Y-m-01');
$defaultSelesai = date('Y-m-d');
$mulai = trim((string) ($_GET['mulai'] ?? $defaultMulai));
$selesai = trim((string) ($_GET['selesai'] ?? $defaultSelesai));

$semuaTingkatan = akademik_setoran_semua_tingkatan($pdo);
$filterTk = trim((string) ($_GET['tingkatan'] ?? ''));
$tingkatanFilter = [];
if ($filterTk !== '' && in_array($filterTk, $semuaTingkatan, true)) {
    $tingkatanFilter = [$filterTk];
}

$rows = [];
$summary = ['kitab' => 0, 'santri' => 0, 'baris' => 0, 'setoran' => 0];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
    $rows = akademik_setoran_rekap_per_kitab($pdo, $mulai, $selesai, $tingkatanFilter);
    foreach ($rows as $r) {
        $summary['kitab']++;
        $summary['santri'] += (int) ($r['jumlah_santri'] ?? 0);
        $summary['baris'] += (int) ($r['total_baris'] ?? 0);
        $summary['setoran'] += (int) ($r['frekuensi_setor'] ?? 0);
    }
}

$pageTitle = 'Rekap Setoran per Kitab';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Kajian · Akademik · Setoran</p>
    <h1 class="h4 mb-1">Rekap setoran per kitab bait</h1>
    <p class="text-muted small mb-0">
        Agregat perolehan baris dan frekuensi setoran dikelompokkan menurut <strong>nama kitab</strong>.
        <a href="<?= htmlspecialchars(app_href('/akademik/setoran_dashboard.php')) ?>">Dashboard setoran</a> ·
        Input harian: <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>">Portal scan</a>.
    </p>
</div>

<form method="get" class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Dari</label>
                <input type="date" name="mulai" class="form-control form-control-sm" value="<?= htmlspecialchars($mulai) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Sampai</label>
                <input type="date" name="selesai" class="form-control form-control-sm" value="<?= htmlspecialchars($selesai) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Tingkatan</label>
                <select name="tingkatan" class="form-select form-select-sm">
                    <option value="">Semua tingkatan</option>
                    <?php foreach ($semuaTingkatan as $tk): ?>
                        <option value="<?= htmlspecialchars($tk) ?>" <?= $filterTk === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tk) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Tampilkan</button>
            </div>
        </div>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-3"><div class="card h-100"><div class="card-body py-2 text-center"><div class="small text-muted">Kitab</div><div class="h5 mb-0"><?= (int) $summary['kitab'] ?></div></div></div></div>
    <div class="col-3"><div class="card h-100"><div class="card-body py-2 text-center"><div class="small text-muted">Santri</div><div class="h5 mb-0"><?= (int) $summary['santri'] ?></div></div></div></div>
    <div class="col-3"><div class="card h-100 border-primary-subtle"><div class="card-body py-2 text-center"><div class="small text-muted">Total baris</div><div class="h5 mb-0 text-primary"><?= number_format($summary['baris'], 0, ',', '.') ?></div></div></div></div>
    <div class="col-3"><div class="card h-100"><div class="card-body py-2 text-center"><div class="small text-muted">Frek. setor</div><div class="h5 mb-0"><?= number_format($summary['setoran'], 0, ',', '.') ?></div></div></div></div>
</div>

<?php if ($rows === []): ?>
    <div class="alert alert-secondary">Tidak ada data setoran pada periode ini.</div>
<?php else: ?>
    <?php foreach ($rows as $r): ?>
        <?php $kid = (int) ($r['kitab_id'] ?? 0); ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                <div>
                    <strong><i class="fa-solid fa-book me-1 text-primary"></i><?= htmlspecialchars((string) ($r['nama_kitab'] ?? '—')) ?></strong>
                    <span class="text-muted small ms-1">· target <?= number_format((int) ($r['jumlah_baris'] ?? 0), 0, ',', '.') ?> baris</span>
                </div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge text-bg-light text-dark"><?= (int) ($r['jumlah_santri'] ?? 0) ?> santri</span>
                    <span class="badge text-bg-primary"><?= number_format((int) ($r['total_baris'] ?? 0), 0, ',', '.') ?> baris</span>
                    <span class="badge text-bg-secondary"><?= (int) ($r['frekuensi_setor'] ?? 0) ?>× setor</span>
                </div>
            </div>
            <?php if (($r['santri'] ?? []) !== []): ?>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Santri</th><th>Tingkatan</th><th class="text-end">Baris</th><th class="text-end">Frek.</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($r['santri'] as $sr): ?>
                            <tr>
                                <td class="small">
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($sr['nama_santri'] ?? '')) ?></div>
                                    <div class="text-muted"><?= htmlspecialchars((string) ($sr['nis'] ?? '')) ?></div>
                                </td>
                                <td class="small"><?= htmlspecialchars((string) ($sr['tingkatan'] ?? '')) ?></td>
                                <td class="text-end font-monospace small"><?= number_format((int) ($sr['total_baris'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end small"><?= (int) ($sr['frekuensi_setor'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
