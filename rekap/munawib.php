<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/munawib.php';

require_roles(['admin', 'pengurus']);

munawib_ensure_schema($pdo);

$dari = trim((string) ($_GET['dari'] ?? date('Y-m-01')));
$sampai = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
$munawibId = (int) ($_GET['munawib_id'] ?? 0);
$rows = munawib_laporan_kehadiran($pdo, $dari, $sampai, $munawibId > 0 ? $munawibId : 0);
$munawibList = munawib_list_aktif($pdo);

$pageTitle = 'Laporan Munawib';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/hub.php')) ?>">Pusat Rekap</a></p>
    <h1 class="h4 mb-1">Laporan kehadiran munawib</h1>
    <p class="text-muted mb-0 small">Pengganti pembimbing saat pembimbing berizin — pembimbing asli tetap tercatat izin/alpa.</p>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Dari</label><input type="date" name="dari" class="form-control form-control-sm" value="<?= htmlspecialchars($dari) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Sampai</label><input type="date" name="sampai" class="form-control form-control-sm" value="<?= htmlspecialchars($sampai) ?>"></div>
    <div class="col-12 col-md-4">
        <label class="form-label small mb-0">Munawib</label>
        <select name="munawib_id" class="form-select form-select-sm">
            <option value="0">Semua</option>
            <?php foreach ($munawibList as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= $munawibId === (int) $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $m['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-primary btn-sm">Filter</button></div>
    <div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pembimbing/munawib.php')) ?>">Kelola munawib</a></div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>Tanggal</th><th>Jam</th><th>Munawib</th><th>Mengganti</th><th>Kegiatan</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="5" class="text-muted text-center py-4">Belum ada presensi munawib.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                    <td class="small font-monospace"><?= htmlspecialchars(substr((string) ($r['jam'] ?? ''), 0, 5)) ?></td>
                    <td><?= htmlspecialchars((string) ($r['munawib_nama'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['pembimbing_diganti'] ?? '—')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['nama_kegiatan'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
