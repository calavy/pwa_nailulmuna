<?php

declare(strict_types=1);

require_once __DIR__ . '/../../inc_portal.php';
require_once __DIR__ . '/../../../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../../../helpers/akademik_pkpps_tugas.php';
require_once __DIR__ . '/../../../helpers/app_path.php';

santri_portal_pkpps_tugas_guard($pdo);
ensure_akademik_ikhtibar_tables($pdo);

$santriId = (int) ($santriPortalRow['id'] ?? 0);
$tingkatan = (string) ($santriPortalRow['tingkatan'] ?? '');
$base = pkpps_tugas_santri_base_path();
$tugasList = pkpps_tugas_tersedia_santri($pdo, $santriId, $tingkatan);

require_once __DIR__ . '/../../includes/layout.php';
santri_portal_layout_head('Tugas PKPPS — Portal Santri', 'tugas_pkpps');
?>
<h1 class="h5 fw-bold mb-1">Tugas PKPPS</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) ($santriPortalRow['nama_santri'] ?? '')) ?></p>

<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">
<nav class="ikhtibar-portal-tabs" aria-label="Menu tugas PKPPS">
    <a href="<?= htmlspecialchars(app_href($base . '/index.php')) ?>" class="active">Kerjakan</a>
    <a href="<?= htmlspecialchars(app_href($base . '/hasil.php')) ?>">Hasil saya</a>
</nav>

<?php if ($tugasList === []): ?>
    <div class="text-muted small">
        <p class="text-center mb-2">Belum ada tugas PKPPS untuk Anda.</p>
        <ul class="mb-0 ps-3">
            <li>Pembimbing PKPPS belum mempublikasikan tugas</li>
            <li>Tanggal tugas belum tiba</li>
            <li>Anda belum terdaftar di tingkatan PKPPS jadwal tugas tersebut</li>
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
            <a href="<?= htmlspecialchars(app_href($base . '/kerjakan.php?id=' . $tid)) ?>" class="btn <?= $btnClass ?> text-start py-3">
                <strong><?= htmlspecialchars((string) $t['judul']) ?></strong>
                <span class="d-block small opacity-75"><?= htmlspecialchars((string) ($t['mapel_label'] ?? '')) ?></span>
                <span class="d-block small opacity-75"><?= htmlspecialchars(ikhtibar_hari_label((int) ($t['hari_ke'] ?? 0))) ?> · <?= htmlspecialchars((string) $t['tanggal']) ?></span>
                <span class="badge bg-light text-dark mt-1"><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php santri_portal_layout_foot('tugas_pkpps'); ?>
