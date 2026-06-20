<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc_portal.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/app_path.php';

ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($santriPortalRow['id'] ?? 0);
$riwayat = ikhtibar_riwayat_hasil_santri($pdo, $santriId, IKHTIBAR_TUGAS_SUMBER);

require_once __DIR__ . '/../includes/layout.php';
santri_portal_layout_head('Hasil Tugas — Portal Santri', 'tugas');
?>
<h1 class="h5 fw-bold mb-1">Nilai &amp; hasil ujian</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) ($santriPortalRow['nama_santri'] ?? '')) ?></p>

<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<nav class="ikhtibar-portal-tabs" aria-label="Menu tugas">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>">Kerjakan</a>
    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/hasil.php')) ?>" class="active">Hasil saya</a>
</nav>

<?php if ($riwayat === []): ?>
    <div class="text-center text-muted py-4">
        <i class="fa-solid fa-clipboard-list fa-2x mb-2 opacity-50"></i>
        <p class="mb-1">Belum ada riwayat pengerjaan tugas.</p>
        <p class="small mb-0">Setelah menyelesaikan tugas dari pembimbing, nilai akan muncul di sini.</p>
        <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="btn btn-auth-primary btn-sm mt-3">Lihat tugas aktif</a>
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
            <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/hasil_detail.php?sesi_id=' . $sesiId)) ?>" class="card ikhtibar-result-card text-decoration-none text-body">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <h2 class="h6 mb-1 fw-bold"><?= htmlspecialchars((string) ($r['judul'] ?? '')) ?></h2>
                            <p class="small text-muted mb-0">
                                <?= htmlspecialchars(ikhtibar_hari_label((int) ($r['hari_ke'] ?? 0))) ?>
                                · <?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?>
                                <?php if (!empty($r['mapel_label'])): ?>
                                    <br><?= htmlspecialchars((string) $r['mapel_label']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if ($st === 'selesai' && !$pending && $nilai !== null): ?>
                            <div class="text-end">
                                <div class="fs-4 fw-bold text-success"><?= htmlspecialchars(number_format($nilai, 1)) ?></div>
                                <span class="badge text-bg-<?= htmlspecialchars($predClass) ?> ikhtibar-pill-status"><?= htmlspecialchars((string) ($r['predikat'] ?? '')) ?></span>
                            </div>
                        <?php elseif ($pending): ?>
                            <span class="badge text-bg-warning ikhtibar-pill-status">Menunggu koreksi esai</span>
                        <?php elseif ($st === 'berjalan'): ?>
                            <span class="badge text-bg-primary ikhtibar-pill-status">Sedang dikerjakan</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary ikhtibar-pill-status"><?= htmlspecialchars($st) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ikhtibar-score-grid">
                        <div class="ikhtibar-score-tile">
                            <div class="ikhtibar-score-tile__val"><?= $r['skor_pg'] !== null ? htmlspecialchars((string) $r['skor_pg']) . '%' : '—' ?></div>
                            <div class="ikhtibar-score-tile__lbl">Pilihan ganda</div>
                        </div>
                        <div class="ikhtibar-score-tile">
                            <div class="ikhtibar-score-tile__val"><?= $r['skor_esai'] !== null ? htmlspecialchars((string) $r['skor_esai']) : ($pending ? '…' : '—') ?></div>
                            <div class="ikhtibar-score-tile__lbl">Esai</div>
                        </div>
                    </div>
                    <p class="small text-primary mb-0 mt-2"><i class="fa-solid fa-arrow-right me-1"></i> Lihat detail jawaban</p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
santri_portal_layout_foot('tugas');
