<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_setoran.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';

akademik_setoran_require_access();
ensure_akademik_setoran_extended_schema($pdo);

$ctx = akademik_setoran_petugas_context($pdo);
$defaultMulai = date('Y-m-01');
$defaultSelesai = date('Y-m-d');
$mulai = trim((string) ($_GET['mulai'] ?? $defaultMulai));
$selesai = trim((string) ($_GET['selesai'] ?? $defaultSelesai));
$tab = trim((string) ($_GET['tab'] ?? 'kehadiran'));
if (!in_array($tab, ['kehadiran', 'perolehan'], true)) {
    $tab = 'kehadiran';
}

$tingkatanFilter = $ctx['tingkatan_allowed'];
$filterTk = trim((string) ($_GET['tingkatan'] ?? ''));
if ($filterTk !== '' && ($tingkatanFilter === [] || in_array($filterTk, $tingkatanFilter, true))) {
    $tingkatanFilter = [$filterTk];
} elseif ($filterTk !== '' && !in_array($filterTk, $tingkatanFilter, true) && $tingkatanFilter !== []) {
    $filterTk = '';
}

$kehadiranRows = [];
$perolehanRows = [];
$summary = ['setor' => 0, 'alpa' => 0, 'izin' => 0];

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
    $kehadiranRows = akademik_setoran_rekap_kehadiran($pdo, $mulai, $selesai, $tingkatanFilter);
    foreach ($kehadiranRows as $kr) {
        $st = (string) ($kr['status'] ?? '');
        if ($st === 'SETOR') {
            $summary['setor']++;
        } elseif ($st === 'IZIN') {
            $summary['izin']++;
        } elseif ($st === 'ALPA') {
            $summary['alpa']++;
        }
    }
    if ($tab === 'perolehan') {
        $perolehanRows = akademik_setoran_rekap_perolehan($pdo, $mulai, $selesai, $tingkatanFilter);
    }
}

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehAdmin = in_array($role, ['admin', 'pengurus'], true) || is_super_admin();

$pageTitle = 'Rekap Setoran Hafalan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Akademik · Setoran</p>
    <h1 class="h4 mb-1">Rekap setoran hafalan</h1>
    <p class="text-muted small mb-0">
        Kehadiran setoran (setor / izin / alpa) dan perolehan baris bait.
        Alpa = tidak setor tanpa izin/sakit dari presensi/perizinan.
    </p>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/setoran_dashboard.php')) ?>"><i class="fa-solid fa-gauge me-1"></i> Dashboard setoran</a>
    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>"><i class="fa-solid fa-qrcode me-1"></i> Portal scan</a>
    <?php if ($bolehAdmin): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php')) ?>">Penerima setoran</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/akademik/bait_kitab.php')) ?>">Pengaturan bait</a>
    <?php endif; ?>
</div>

<form method="get" class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Dari</label>
                <input type="date" name="mulai" class="form-control form-control-sm" value="<?= htmlspecialchars($mulai) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Sampai</label>
                <input type="date" name="selesai" class="form-control form-control-sm" value="<?= htmlspecialchars($selesai) ?>">
            </div>
            <?php if ($ctx['tingkatan_allowed'] !== []): ?>
            <div class="col-md-3">
                <label class="form-label small mb-0">Tingkatan</label>
                <select name="tingkatan" class="form-select form-select-sm">
                    <option value="">Semua cakupan</option>
                    <?php foreach ($ctx['tingkatan_allowed'] as $tk): ?>
                        <option value="<?= htmlspecialchars($tk) ?>" <?= $filterTk === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tk) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label small mb-0">Tab</label>
                <select name="tab" class="form-select form-select-sm">
                    <option value="kehadiran" <?= $tab === 'kehadiran' ? 'selected' : '' ?>>Kehadiran</option>
                    <option value="perolehan" <?= $tab === 'perolehan' ? 'selected' : '' ?>>Perolehan bait</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Tampilkan</button>
            </div>
        </div>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-4">
        <div class="card border-success-subtle h-100"><div class="card-body py-2"><div class="small text-muted">Setor</div><div class="h5 mb-0 text-success"><?= number_format($summary['setor'], 0, ',', '.') ?></div></div></div>
    </div>
    <div class="col-4">
        <div class="card border-warning-subtle h-100"><div class="card-body py-2"><div class="small text-muted">Izin / sakit</div><div class="h5 mb-0 text-warning"><?= number_format($summary['izin'], 0, ',', '.') ?></div></div></div>
    </div>
    <div class="col-4">
        <div class="card border-danger-subtle h-100"><div class="card-body py-2"><div class="small text-muted">Alpa</div><div class="h5 mb-0 text-danger"><?= number_format($summary['alpa'], 0, ',', '.') ?></div></div></div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <?php if ($tab === 'perolehan'): ?>
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr><th>Santri</th><th>Tingkatan</th><th>Kitab</th><th class="text-end">Total baris</th><th class="text-end">Frek.</th></tr>
                </thead>
                <tbody>
                <?php if ($perolehanRows === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada data perolehan.</td></tr>
                <?php else: ?>
                    <?php foreach ($perolehanRows as $pr): ?>
                        <tr>
                            <td class="small">
                                <div class="fw-semibold"><?= htmlspecialchars((string) $pr['nama_santri']) ?></div>
                                <div class="text-muted"><?= htmlspecialchars((string) $pr['nis']) ?></div>
                            </td>
                            <td class="small"><?= htmlspecialchars((string) $pr['tingkatan']) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($pr['nama_kitab'] ?? '—')) ?></td>
                            <td class="text-end font-monospace"><?= number_format((int) ($pr['total_baris'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-end"><?= (int) ($pr['jumlah_setoran'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php else: ?>
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr><th>Tanggal</th><th>Santri</th><th>Tingkatan</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php if ($kehadiranRows === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                <?php else: ?>
                    <?php foreach ($kehadiranRows as $kr): ?>
                        <?php
                        $st = (string) ($kr['status'] ?? '');
                        $badge = match ($st) {
                            'SETOR' => 'success',
                            'IZIN' => 'warning',
                            default => 'danger',
                        };
                        ?>
                        <tr>
                            <td class="small text-nowrap"><?= htmlspecialchars((string) $kr['tanggal']) ?></td>
                            <td class="small"><?= htmlspecialchars((string) $kr['nama_santri']) ?></td>
                            <td class="small"><?= htmlspecialchars((string) $kr['tingkatan']) ?></td>
                            <td><span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($st) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
