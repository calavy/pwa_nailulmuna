<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus', 'kiai']);

$pageTitle = 'Pusat Rekap';
require_once __DIR__ . '/../includes/header.php';

$cards = [
    ['href' => '/rekap/index.php', 'icon' => 'fa-calendar-check', 'title' => 'Rekap Presensi', 'desc' => 'Rekap harian/bulanan masehi & hijriyah', 'perm' => 'rekap'],
    ['href' => '/rekap/keaktifan_hari.php', 'icon' => 'fa-bolt', 'title' => 'Keaktifan Hari Ini', 'desc' => 'Status santri per kegiatan hari ini', 'perm' => 'rekap_keaktifan'],
    ['href' => '/rekap/santri_bagus.php', 'icon' => 'fa-star', 'title' => 'Rekap Keaktifan Santri', 'desc' => 'Kategori bagus/sedang/buruk', 'perm' => 'rekap_keaktifan'],
    ['href' => '/rekap/izin_telat.php', 'icon' => 'fa-clock', 'title' => 'Rekap Keterlambatan', 'desc' => 'Telat & izin terkait presensi', 'perm' => 'rekap_telat'],
    ['href' => '/rekap/perizinan.php', 'icon' => 'fa-person-walking-luggage', 'title' => 'Rekap Perizinan', 'desc' => 'Ringkasan izin santri bulanan', 'perm' => 'rekap'],
    ['href' => '/rekap/pembimbing.php', 'icon' => 'fa-coins', 'title' => 'Payroll Pembimbing', 'desc' => 'Gaji & presensi pembimbing', 'perm' => 'rekap_pembimbing'],
    ['href' => '/rekap/munawib.php', 'icon' => 'fa-user-clock', 'title' => 'Laporan Munawib', 'desc' => 'Kehadiran pengganti pembimbing', 'perm' => 'rekap'],
    ['href' => '/rekap/keaktivan_sdm.php', 'icon' => 'fa-people-group', 'title' => 'Keaktivan SDM', 'desc' => 'Dashboard pembimbing & munawib', 'perm' => 'rekap_hub'],
    ['href' => '/poin/rekap.php', 'icon' => 'fa-scale-balanced', 'title' => 'Rekap Poin', 'desc' => 'Kedisiplinan & sanksi', 'perm' => 'poin_rekap'],
];
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Kajian · Pusat data</p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-folder-tree me-1 text-primary"></i>Pusat Rekap</h1>
    <p class="text-muted mb-0 small">Semua laporan rekap terkumpul di satu tempat.</p>
</div>

<div class="row g-3">
    <?php foreach ($cards as $c):
        if (!user_can_access_permission_key($c['perm'])) {
            continue;
        }
    ?>
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="<?= htmlspecialchars(app_href($c['href'])) ?>" class="card shadow-sm h-100 text-decoration-none border-0">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width:2.5rem;height:2.5rem">
                        <i class="fa-solid <?= htmlspecialchars($c['icon']) ?>"></i>
                    </span>
                    <div>
                        <h2 class="h6 mb-1 text-dark"><?= htmlspecialchars($c['title']) ?></h2>
                        <p class="small text-muted mb-0"><?= htmlspecialchars($c['desc']) ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
