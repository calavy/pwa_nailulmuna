<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc_portal.php';
require_once __DIR__ . '/../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../helpers/app_path.php';

ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($santriPortalRow['id'] ?? 0);
$tingkatan = (string) ($santriPortalRow['tingkatan'] ?? '');
$tugasList = ikhtibar_tugas_tersedia_santri($pdo, $santriId, $tingkatan, IKHTIBAR_TUGAS_SUMBER);

require_once __DIR__ . '/../includes/layout.php';
santri_portal_layout_head('Tugas Ikhtibar — Portal Santri', 'tugas');
?>
<h1 class="h5 fw-bold mb-1">Tugas kajian (Ikhtibar)</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) ($santriPortalRow['nama_santri'] ?? '')) ?></p>

<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">
<nav class="ikhtibar-portal-tabs" aria-label="Menu tugas kajian">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="active">Kerjakan</a>
    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/hasil.php')) ?>">Hasil saya</a>
</nav>

<?php if (!empty($santriPortalPkppsAktif)): ?>
    <p class="small mb-3"><a href="<?= htmlspecialchars(app_href('/santri_portal/pkpps/tugas/index.php')) ?>"><i class="fa-solid fa-book-open me-1"></i> Tugas PKPPS (program PKPPS)</a></p>
<?php endif; ?>

<?php if ($tugasList === []): ?>
    <div class="text-muted small">
        <p class="text-center mb-2">Belum ada tugas yang tampil untuk Anda.</p>
        <p class="mb-1"><strong>Penyebab umum:</strong></p>
        <ul class="mb-0 ps-3">
            <li>Pembimbing belum menekan <em>Publikasikan tugas</em></li>
            <li>Tanggal tugas belum tiba (hanya tampil mulai hari H)</li>
            <li>Filter tingkatan tidak sesuai profil Anda</li>
            <li>Soal belum diisi saat tugas dibuat</li>
        </ul>
    </div>
<?php else:
    $sections = ikhtibar_tugas_kelompok_sections($tugasList);
    foreach ($sections as $section):
        if ($section['label'] !== ''): ?>
            <h2 class="h6 text-muted text-uppercase small fw-semibold mt-3 mb-2"><?= htmlspecialchars($section['label']) ?></h2>
        <?php endif; ?>
        <div class="d-grid gap-2 mb-2">
        <?php foreach ($section['items'] as $t):
            $tid = (int) $t['id'];
            $st = (string) ($t['sesi_status'] ?? 'menunggu');
            $label = match ($st) {
                'selesai' => 'Selesai',
                'berjalan' => 'Lanjutkan',
                default => 'Mulai',
            };
            $btnClass = $st === 'selesai' ? 'btn-outline-secondary' : 'btn-auth-primary';
            ?>
            <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/kerjakan.php?id=' . $tid)) ?>" class="btn <?= $btnClass ?> text-start py-3">
                <strong><?= htmlspecialchars((string) $t['judul']) ?></strong>
                <span class="d-block small opacity-75"><?= htmlspecialchars(ikhtibar_hari_label((int) ($t['hari_ke'] ?? 0))) ?> · <?= htmlspecialchars((string) $t['tanggal']) ?> · <?= (int) ($t['durasi_menit'] ?? 0) ?> menit</span>
                <?php if (trim((string) ($t['filter_tingkatan'] ?? '')) !== ''): ?>
                    <span class="d-block small opacity-75">Tingkatan: <?= htmlspecialchars((string) $t['filter_tingkatan']) ?></span>
                <?php endif; ?>
                <span class="badge bg-light text-dark mt-1"><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
santri_portal_layout_foot('tugas');
