<?php

declare(strict_types=1);

require_once __DIR__ . '/../../inc_portal.php';
require_once __DIR__ . '/../../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../../helpers/akademik_pkpps_tugas.php';
require_once __DIR__ . '/../../../helpers/app_path.php';

santri_portal_pkpps_tugas_guard($pdo);
ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($santriPortalRow['id'] ?? 0);
$base = pkpps_tugas_santri_base_path();
$riwayat = pkpps_tugas_riwayat_santri($pdo, $santriId);

require_once __DIR__ . '/../../includes/layout.php';
santri_portal_layout_head('Hasil Tugas PKPPS — Portal Santri', 'tugas_pkpps');
?>
<h1 class="h5 fw-bold mb-1">Hasil tugas PKPPS</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) ($santriPortalRow['nama_santri'] ?? '')) ?></p>

<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<nav class="ikhtibar-portal-tabs" aria-label="Menu tugas PKPPS">
    <a href="<?= htmlspecialchars(app_href($base . '/index.php')) ?>">Kerjakan</a>
    <a href="<?= htmlspecialchars(app_href($base . '/hasil.php')) ?>" class="active">Hasil saya</a>
</nav>

<?php if ($riwayat === []): ?>
    <div class="text-center text-muted py-4">
        <p class="mb-1">Belum ada riwayat tugas PKPPS.</p>
        <a href="<?= htmlspecialchars(app_href($base . '/index.php')) ?>" class="btn btn-auth-primary btn-sm mt-3">Lihat tugas aktif</a>
    </div>
<?php else: ?>
    <div class="d-grid gap-3">
        <?php foreach ($riwayat as $r):
            $sesiId = (int) ($r['sesi_id'] ?? 0);
            $st = (string) ($r['sesi_status'] ?? '');
            $nilai = $r['nilai_total'] !== null ? (float) $r['nilai_total'] : null;
            $pending = (int) ($r['esai_pending'] ?? 0) > 0;
            $predClass = (string) ($r['predikat_class'] ?? 'secondary');
            ?>
            <a href="<?= htmlspecialchars(app_href($base . '/hasil_detail.php?sesi_id=' . $sesiId)) ?>" class="card ikhtibar-result-card text-decoration-none text-body">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <h2 class="h6 mb-1 fw-bold"><?= htmlspecialchars((string) ($r['judul'] ?? '')) ?></h2>
                            <p class="small text-muted mb-0"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?><?php if (!empty($r['mapel_label'])): ?> · <?= htmlspecialchars((string) $r['mapel_label']) ?><?php endif; ?></p>
                        </div>
                        <?php if ($st === 'selesai' && !$pending && $nilai !== null): ?>
                            <div class="text-end">
                                <div class="fs-4 fw-bold text-success"><?= htmlspecialchars(number_format($nilai, 1)) ?></div>
                                <span class="badge text-bg-<?= htmlspecialchars($predClass) ?>"><?= htmlspecialchars((string) ($r['predikat'] ?? '')) ?></span>
                            </div>
                        <?php elseif ($pending): ?>
                            <span class="badge text-bg-warning">Menunggu koreksi esai</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php santri_portal_layout_foot('tugas_pkpps'); ?>
