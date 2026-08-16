<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';

if (!isset($_SESSION['user'])) {
    app_redirect('login.php?dest=setoran');
}

require_once __DIR__ . '/../helpers/akademik_setoran.php';
require_once __DIR__ . '/partials/setoran_portal_bootstrap.php';

$setoranNavActive = 'perolehan';
$defaultMulai = date('Y-m-01');
$defaultSelesai = date('Y-m-d');
$mulai = trim((string) ($_GET['mulai'] ?? $defaultMulai));
$selesai = trim((string) ($_GET['selesai'] ?? $defaultSelesai));

$tingkatanFilter = $tingkatanList;
$filterTk = trim((string) ($_GET['tingkatan'] ?? ''));
if ($filterTk !== '' && ($tingkatanFilter === [] || in_array($filterTk, $tingkatanFilter, true))) {
    $tingkatanFilter = [$filterTk];
}

$rows = [];
$summary = ['lancar' => 0, 'tidak' => 0, 'total_baris' => 0];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
    $rows = akademik_setoran_rekap_perolehan_dengan_lancar($pdo, $mulai, $selesai, $tingkatanFilter);
    foreach ($rows as $r) {
        if (!empty($r['lancar'])) {
            $summary['lancar']++;
        } else {
            $summary['tidak']++;
        }
        $summary['total_baris'] += (int) ($r['total_baris'] ?? 0);
    }
}

$pageTitle = 'Rekap Perolehan Setoran';
$bodyClass = 'setoran-portal-page st-portal-page pb-dash-bg-putih dash-page';
$pageStylesheets = [
    app_asset_href('/assets/css/pembimbing-dashboard.css'),
    app_asset_href('/assets/css/setoran-portal.css'),
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-page">
<div class="container py-3" style="max-width:640px">
    <?php require __DIR__ . '/partials/setoran_portal_head.php'; ?>
    <?php require __DIR__ . '/partials/setoran_portal_subnav.php'; ?>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-0">Dari</label>
                    <input type="date" name="mulai" class="form-control form-control-sm" value="<?= htmlspecialchars($mulai) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-0">Sampai</label>
                    <input type="date" name="selesai" class="form-control form-control-sm" value="<?= htmlspecialchars($selesai) ?>">
                </div>
                <?php if ($tingkatanList !== []): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Tingkatan</label>
                    <select name="tingkatan" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        <?php foreach ($tingkatanList as $tk): ?>
                            <option value="<?= htmlspecialchars($tk) ?>" <?= $filterTk === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tk) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="card border-success-subtle h-100"><div class="card-body py-2 text-center">
                <div class="small text-muted">Lancar</div>
                <div class="h5 mb-0 text-success"><?= (int) $summary['lancar'] ?></div>
            </div></div>
        </div>
        <div class="col-4">
            <div class="card border-danger-subtle h-100"><div class="card-body py-2 text-center">
                <div class="small text-muted">Tidak lancar</div>
                <div class="h5 mb-0 text-danger"><?= (int) $summary['tidak'] ?></div>
            </div></div>
        </div>
        <div class="col-4">
            <div class="card border-primary-subtle h-100"><div class="card-body py-2 text-center">
                <div class="small text-muted">Total baris</div>
                <div class="h5 mb-0 text-primary"><?= number_format($summary['total_baris'], 0, ',', '.') ?></div>
            </div></div>
        </div>
    </div>

    <p class="small text-muted mb-2">
        <strong>Lancar</strong> = kehadiran setoran ≥ 80% hari wajib (setor / izin / alpa) dalam periode.
    </p>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Santri</th>
                            <th>Tingkatan</th>
                            <th class="text-center">Lancar</th>
                            <th class="text-end">Baris</th>
                            <th class="text-end">Setor</th>
                            <th class="text-end">Alpa</th>
                            <th>Kitab</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data perolehan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $stats = is_array($r['stats'] ?? null) ? $r['stats'] : []; ?>
                            <tr>
                                <td class="small">
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                                    <div class="text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                                </td>
                                <td class="small"><?= htmlspecialchars((string) ($r['tingkatan'] ?? '')) ?></td>
                                <td class="text-center">
                                    <?php if (!empty($r['lancar'])): ?>
                                        <span class="badge text-bg-success">Lancar</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">Tidak lancar</span>
                                    <?php endif; ?>
                                    <div class="text-muted" style="font-size:0.65rem"><?= (float) ($stats['persen_setor'] ?? 0) ?>%</div>
                                </td>
                                <td class="text-end font-monospace small"><?= number_format((int) ($r['total_baris'] ?? 0), 0, ',', '.') ?></td>
                                <td class="text-end small"><?= (int) ($stats['setor'] ?? 0) ?>/<?= (int) ($stats['wajib'] ?? 0) ?></td>
                                <td class="text-end small text-danger"><?= (int) ($stats['alpa'] ?? 0) ?></td>
                                <td class="small">
                                    <?php foreach (($r['kitab'] ?? []) as $kb): ?>
                                        <div><?= htmlspecialchars((string) ($kb['nama_kitab'] ?? '')) ?> · <?= (int) ($kb['total_baris'] ?? 0) ?> baris</div>
                                    <?php endforeach; ?>
                                    <?php if (($r['kitab'] ?? []) === []): ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<script>
(function () {
    function tick() {
        var now = new Date();
        var clock = document.getElementById('st-portal-live-clock');
        var date = document.getElementById('st-portal-live-date');
        if (clock) clock.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if (date) date.textContent = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    tick(); setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
