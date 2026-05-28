<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/munawib.php';

require_roles(['admin', 'pengurus', 'kiai']);
munawib_ensure_schema($pdo);

$dari = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
$sampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
    $dari = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    $sampai = date('Y-m-d');
}

$pembimbingRows = [];
if (table_exists($pdo, 'presensi_pembimbing')) {
    $st = $pdo->prepare('
        SELECT b.id, b.nama_pembimbing, b.nip,
               COUNT(pp.id) AS total_hadir,
               COUNT(DISTINCT pp.tanggal) AS hari_hadir
        FROM pembimbing b
        LEFT JOIN presensi_pembimbing pp
          ON pp.pembimbing_id = b.id
         AND pp.tanggal BETWEEN :dari AND :sampai
        GROUP BY b.id, b.nama_pembimbing, b.nip
        ORDER BY total_hadir DESC, b.nama_pembimbing ASC
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    $pembimbingRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$munawibRows = [];
if (table_exists($pdo, 'presensi_munawib')) {
    $st = $pdo->prepare('
        SELECT m.id, m.nama, m.nip,
               COUNT(pm.id) AS total_hadir,
               COUNT(DISTINCT pm.tanggal) AS hari_hadir,
               SUM(CASE WHEN pm.penugasan_id IS NULL THEN 1 ELSE 0 END) AS hadir_fleksibel
        FROM munawib m
        LEFT JOIN presensi_munawib pm
          ON pm.munawib_id = m.id
         AND pm.tanggal BETWEEN :dari AND :sampai
        WHERE COALESCE(m.is_aktif,1)=1
        GROUP BY m.id, m.nama, m.nip
        ORDER BY total_hadir DESC, m.nama ASC
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    $munawibRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$totalHadirPembimbing = 0;
$aktifPembimbing = 0;
foreach ($pembimbingRows as $r) {
    $totalHadirPembimbing += (int) ($r['total_hadir'] ?? 0);
    if ((int) ($r['total_hadir'] ?? 0) > 0) {
        $aktifPembimbing++;
    }
}

$totalHadirMunawib = 0;
$aktifMunawib = 0;
$fleksibelMunawib = 0;
foreach ($munawibRows as $r) {
    $totalHadirMunawib += (int) ($r['total_hadir'] ?? 0);
    $fleksibelMunawib += (int) ($r['hadir_fleksibel'] ?? 0);
    if ((int) ($r['total_hadir'] ?? 0) > 0) {
        $aktifMunawib++;
    }
}

$pageTitle = 'Rekap Keaktivan SDM';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/hub.php')) ?>">Pusat Rekap</a></p>
    <h1 class="h4 mb-1">Dashboard Keaktivan SDM</h1>
    <p class="text-muted mb-0 small">Ringkasan kehadiran pembimbing dan munawib dalam satu dashboard.</p>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Dari</label><input type="date" name="dari" class="form-control form-control-sm" value="<?= htmlspecialchars($dari) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Sampai</label><input type="date" name="sampai" class="form-control form-control-sm" value="<?= htmlspecialchars($sampai) ?>"></div>
    <div class="col-auto"><button class="btn btn-primary btn-sm">Terapkan</button></div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Hadir Pembimbing</div><div class="app-mini-stat-value text-primary"><?= $totalHadirPembimbing ?></div></div></div>
    <div class="col-6 col-md-3"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Pembimbing Aktif</div><div class="app-mini-stat-value text-success"><?= $aktifPembimbing ?></div></div></div>
    <div class="col-6 col-md-3"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Hadir Munawib</div><div class="app-mini-stat-value text-info"><?= $totalHadirMunawib ?></div></div></div>
    <div class="col-6 col-md-3"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Scan Fleksibel Munawib</div><div class="app-mini-stat-value text-warning"><?= $fleksibelMunawib ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-header bg-primary-subtle border-0 fw-semibold text-primary">Top Pembimbing</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Nama</th><th>NIP</th><th class="text-center">Hari Hadir</th><th class="text-center">Total Scan</th></tr></thead>
                    <tbody>
                    <?php if ($pembimbingRows === []): ?><tr><td colspan="4" class="text-center text-muted py-3">Belum ada data.</td></tr><?php endif; ?>
                    <?php foreach (array_slice($pembimbingRows, 0, 20) as $r): ?>
                        <tr>
                            <td class="small fw-semibold"><?= htmlspecialchars((string) ($r['nama_pembimbing'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) (($r['nip'] ?? '') !== '' ? $r['nip'] : '-')) ?></td>
                            <td class="small text-center"><?= (int) ($r['hari_hadir'] ?? 0) ?></td>
                            <td class="small text-center"><?= (int) ($r['total_hadir'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-header bg-info-subtle border-0 fw-semibold text-info">Top Munawib</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Nama</th><th>NIP</th><th class="text-center">Hari Hadir</th><th class="text-center">Total Scan</th><th class="text-center">Fleksibel</th></tr></thead>
                    <tbody>
                    <?php if ($munawibRows === []): ?><tr><td colspan="5" class="text-center text-muted py-3">Belum ada data.</td></tr><?php endif; ?>
                    <?php foreach (array_slice($munawibRows, 0, 20) as $r): ?>
                        <tr>
                            <td class="small fw-semibold"><?= htmlspecialchars((string) ($r['nama'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) (($r['nip'] ?? '') !== '' ? $r['nip'] : '-')) ?></td>
                            <td class="small text-center"><?= (int) ($r['hari_hadir'] ?? 0) ?></td>
                            <td class="small text-center"><?= (int) ($r['total_hadir'] ?? 0) ?></td>
                            <td class="small text-center"><?= (int) ($r['hadir_fleksibel'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
