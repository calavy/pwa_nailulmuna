<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';

ikhtibar_require_pembimbing_access();
ensure_akademik_ikhtibar_tables($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$rows = ikhtibar_rekap_tugas_pembimbing($pdo, $userId);

$totalTugas = count($rows);
$totalSelesai = array_sum(array_map(static fn ($r) => (int) ($r['jumlah_selesai'] ?? 0), $rows));
$totalPending = array_sum(array_map(static fn ($r) => (int) ($r['esai_belum_koreksi'] ?? 0), $rows));

$pageTitle = 'Rekap Tugas Ikhtibar';
$bodyClass = 'ikhtibar-rekap-page';
require_once __DIR__ . '/../../includes/header.php';
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>">Tugas Ikhtibar</a> · Rekap
    </p>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h4 mb-1"><i class="fa-solid fa-chart-pie text-primary me-1"></i> Rekap Semua Tugas</h1>
            <p class="text-muted mb-0">Ringkasan pengerjaan santri, nilai rata-rata, dan esai yang belum dikoreksi.</p>
        </div>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php')) ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i> Buat tugas</a>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-4">
        <div class="ikhtibar-rekap-stat ikhtibar-rekap-stat--info">
            <div class="ikhtibar-rekap-stat__num"><?= $totalTugas ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Total tugas</div>
        </div>
    </div>
    <div class="col-4">
        <div class="ikhtibar-rekap-stat ikhtibar-rekap-stat--primary">
            <div class="ikhtibar-rekap-stat__num"><?= $totalSelesai ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Sesi selesai</div>
        </div>
    </div>
    <div class="col-4">
        <div class="ikhtibar-rekap-stat ikhtibar-rekap-stat--warn">
            <div class="ikhtibar-rekap-stat__num"><?= $totalPending ?></div>
            <div class="ikhtibar-rekap-stat__lbl">Esai belum koreksi</div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Judul</th>
                        <th>Mapel</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="text-center">Selesai</th>
                        <th class="text-center">Rata nilai</th>
                        <th class="text-center">Esai pending</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada tugas.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $id = (int) $r['id'];
                    $st = (string) ($r['status'] ?? 'draft');
                    $badge = match ($st) {
                        'published' => 'success',
                        'closed' => 'secondary',
                        default => 'warning',
                    };
                    $pending = (int) ($r['esai_belum_koreksi'] ?? 0);
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['judul']) ?></td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($r['mapel_label'] ?? '')) ?: '—') ?></td>
                        <td class="small text-nowrap"><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                        <td><span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($st) ?></span></td>
                        <td class="text-center"><?= (int) ($r['jumlah_selesai'] ?? 0) ?> / <?= (int) ($r['total_peserta'] ?? 0) ?></td>
                        <td class="text-center fw-semibold"><?= $r['rata_nilai'] !== null ? htmlspecialchars((string) $r['rata_nilai']) : '—' ?></td>
                        <td class="text-center">
                            <?php if ($pending > 0): ?>
                                <span class="badge text-bg-warning text-dark"><?= $pending ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-success" href="<?= htmlspecialchars(app_href('/pembimbing/tugas/nilai.php?tugas_id=' . $id)) ?>"><i class="fa-solid fa-star me-1"></i> Nilai</a>
                            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php?id=' . $id)) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
