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

$setoranNavActive = 'keaktivan';
$tahun = (int) ($_GET['tahun'] ?? (int) date('Y'));
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = (int) date('Y');
}

$filterTk = trim((string) ($_GET['tingkatan'] ?? ''));
$tingkatanFilter = $tingkatanList;
if ($filterTk !== '' && ($tingkatanFilter === [] || in_array($filterTk, $tingkatanFilter, true))) {
    $tingkatanFilter = [$filterTk];
}

$rows = akademik_setoran_keaktivan_tahun($pdo, array_merge($ctx, ['tingkatan_allowed' => $tingkatanFilter]), $tahun);
$ringkas = ['bagus' => 0, 'sedang' => 0, 'buruk' => 0, 'belum' => 0];
foreach ($rows as $r) {
    $kat = strtoupper((string) ($r['kategori'] ?? ''));
    if (in_array($kat, ['BAIK', 'BAGUS'], true)) {
        $ringkas['bagus']++;
    } elseif ($kat === 'SEDANG') {
        $ringkas['sedang']++;
    } elseif (in_array($kat, ['BURUK', 'JELEK'], true)) {
        $ringkas['buruk']++;
    } else {
        $ringkas['belum']++;
    }
}

$pageTitle = 'Rekap Keaktivan Setoran';
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

    <div class="alert alert-info py-2 small mb-3">
        Keaktivan berdasarkan <strong>presensi setoran hafalan</strong> saja (bukan kegiatan jadwal).
        Kategori mengikuti jumlah alpa setoran tahun <?= (int) $tahun ?>.
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-0">Tahun</label>
                    <input type="number" name="tahun" class="form-control form-control-sm" value="<?= (int) $tahun ?>" min="2000" max="2100">
                </div>
                <?php if ($tingkatanList !== []): ?>
                <div class="col-md-4">
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
        <div class="col-3"><div class="card h-100 border-success-subtle"><div class="card-body py-2 text-center"><div class="small text-muted">Bagus</div><div class="h5 mb-0 text-success"><?= (int) $ringkas['bagus'] ?></div></div></div></div>
        <div class="col-3"><div class="card h-100 border-warning-subtle"><div class="card-body py-2 text-center"><div class="small text-muted">Sedang</div><div class="h5 mb-0 text-warning"><?= (int) $ringkas['sedang'] ?></div></div></div></div>
        <div class="col-3"><div class="card h-100 border-danger-subtle"><div class="card-body py-2 text-center"><div class="small text-muted">Buruk</div><div class="h5 mb-0 text-danger"><?= (int) $ringkas['buruk'] ?></div></div></div></div>
        <div class="col-3"><div class="card h-100"><div class="card-body py-2 text-center"><div class="small text-muted">Belum data</div><div class="h5 mb-0 text-secondary"><?= (int) $ringkas['belum'] ?></div></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Santri</th>
                            <th>Tingkatan</th>
                            <th>Kategori</th>
                            <th class="text-end">Setor</th>
                            <th class="text-end">Izin</th>
                            <th class="text-end">Alpa</th>
                            <th class="text-end">%</th>
                            <th class="text-center">Lancar</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Belum ada data keaktivan setoran.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $kat = (string) ($r['kategori'] ?? '');
                            $badgeKat = match ($kat) {
                                'bagus' => 'success',
                                'sedang' => 'warning',
                                'buruk' => 'danger',
                                default => 'secondary',
                            };
                            ?>
                            <tr>
                                <td class="small">
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                                    <div class="text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                                </td>
                                <td class="small"><?= htmlspecialchars((string) ($r['tingkatan'] ?? '')) ?></td>
                                <td><span class="badge text-bg-<?= $badgeKat ?>"><?= htmlspecialchars((string) ($r['label'] ?? '')) ?></span></td>
                                <td class="text-end small"><?= (int) ($r['setor'] ?? 0) ?></td>
                                <td class="text-end small"><?= (int) ($r['izin'] ?? 0) ?></td>
                                <td class="text-end small text-danger fw-semibold"><?= (int) ($r['alpa'] ?? 0) ?></td>
                                <td class="text-end small"><?= (float) ($r['persen_setor'] ?? 0) ?>%</td>
                                <td class="text-center">
                                    <?php if (!empty($r['lancar'])): ?>
                                        <span class="badge text-bg-success">Ya</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">Tidak</span>
                                    <?php endif; ?>
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
