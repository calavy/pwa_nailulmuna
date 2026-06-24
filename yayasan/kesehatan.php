<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$periode = yayasan_periode_berjalan($pdo);
$modulReady = table_exists($pdo, 'perizinan');

$pageTitle = 'Laporan Kesehatan Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
$pageScripts = [app_asset_href('/assets/js/yayasan-period.js')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <?php $yayasanCrumbTail = 'Kesehatan'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
            <div>
                <h1 class="h3 mb-1">Laporan Kesehatan</h1>
                <p class="text-muted mb-0" id="yp-kes-subtitle">Rekap izin sakit disetujui &amp; catatan E-Health — <?= htmlspecialchars($periode['label']) ?></p>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">
                <i class="fa-solid fa-notes-medical me-1"></i>Input izin sakit
            </a>
        </div>
    </header>

    <?php if (!$modulReady): ?>
        <div class="alert alert-warning">Modul perizinan belum tersedia.</div>
    <?php else: ?>

    <?php
    $periodeLabel = $periode['label'];
    $ypRekapLabel = 'Rekap izin per periode';
    $ypRekapHref = app_href('/perizinan/rekap_aktif.php');
    $ypRekapNote = 'Portal Yayasan menampilkan ringkasan bulan berjalan saja. Untuk arsip periode lain, buka modul Perizinan.';
    require __DIR__ . '/../includes/partials/yayasan_periode_rekap_link.php';
    ?>

    <div id="yp-kes-mount">
        <div class="text-center py-5 text-muted">
            <i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat laporan…
        </div>
    </div>

    <script>
    window.__ypPeriodBoot = <?= json_encode([
        'type' => 'kesehatan',
        'mount' => 'yp-kes-mount',
        'api' => app_href('/api/yayasan/kesehatan_content.php'),
        'params' => [],
        'lockPeriode' => true,
    ], JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
