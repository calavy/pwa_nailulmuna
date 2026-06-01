<?php



declare(strict_types=1);



require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../helpers/app.php';

require_once __DIR__ . '/../helpers/pkpps.php';



require_roles(['admin', 'pengurus']);

pkpps_ensure_schema($pdo);



$stats = [

    'santri_aktif' => 0,

    'tingkatan_aktif' => 0,

    'jadwal_aktif' => 0,

    'pembimbing_jadwal' => 0,

];

if (table_exists($pdo, 'pkpps_santri')) {

    $stats['santri_aktif'] = (int) $pdo->query('SELECT COUNT(*) FROM pkpps_santri WHERE is_aktif = 1')->fetchColumn();

}

if (table_exists($pdo, 'pkpps_tingkatan')) {

    $stats['tingkatan_aktif'] = (int) $pdo->query('SELECT COUNT(*) FROM pkpps_tingkatan WHERE is_aktif = 1')->fetchColumn();

}

if (table_exists($pdo, 'pkpps_jadwal')) {

    $stats['jadwal_aktif'] = (int) $pdo->query('SELECT COUNT(*) FROM pkpps_jadwal WHERE is_aktif = 1')->fetchColumn();

    $stats['pembimbing_jadwal'] = (int) $pdo->query('SELECT COUNT(DISTINCT pembimbing_id) FROM pkpps_jadwal WHERE is_aktif = 1 AND pembimbing_id IS NOT NULL AND pembimbing_id > 0')->fetchColumn();

}



$hubLinks = [

    ['path' => '/pkpps/santri.php', 'icon' => 'fa-solid fa-user-graduate', 'label' => 'Santri PKPPS', 'desc' => 'Kelola keanggotaan santri per tingkatan'],

    ['path' => '/pkpps/jadwal.php', 'icon' => 'fa-solid fa-calendar-days', 'label' => 'Jadwal PKPPS', 'desc' => 'Jadwal kegiatan dan pembimbing'],

    ['path' => '/rekap/pkpps_keaktivan.php', 'icon' => 'fa-solid fa-chart-line', 'label' => 'Rekap keaktivan', 'desc' => 'Kehadiran santri & pembimbing PKPPS'],

    ['path' => '/pembayaran/laporan_pkpps_syahriyah.php', 'icon' => 'fa-solid fa-coins', 'label' => 'Syahriyah PKPPS', 'desc' => 'Laporan pembayaran syahriyah PKPPS'],

    ['path' => '/pembimbing/pkpps_santri.php', 'icon' => 'fa-solid fa-chalkboard-user', 'label' => 'Portal pembimbing', 'desc' => 'Akses santri untuk pembimbing'],

    ['path' => '/settings/tingkatan.php#pkpps', 'icon' => 'fa-solid fa-layer-group', 'label' => 'Tingkatan PKPPS', 'desc' => 'Master tingkatan program'],

];



$pageTitle = 'Dashboard PKPPS';

require_once __DIR__ . '/../includes/header.php';

?>



<div class="page-intro mb-3">

    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/menu/menu_hub.php?id=menu-grp-kajian')) ?>">Kajian</a> · PKPPS</p>

    <h1 class="h4 mb-1">Dashboard PKPPS</h1>

    <p class="text-muted mb-0 small">Ringkasan data santri, jadwal, dan keaktivan — kelola detail lewat kartu di bawah.</p>

</div>



<div class="row g-2 mb-3">

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Santri aktif</div>

            <div class="app-mini-stat-value text-primary"><?= (int) $stats['santri_aktif'] ?></div>

        </div>

    </div>

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Tingkatan</div>

            <div class="app-mini-stat-value"><?= (int) $stats['tingkatan_aktif'] ?></div>

        </div>

    </div>

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Jadwal aktif</div>

            <div class="app-mini-stat-value text-success"><?= (int) $stats['jadwal_aktif'] ?></div>

        </div>

    </div>

    <div class="col-6 col-md-3">

        <div class="app-mini-stat h-100">

            <div class="app-mini-stat-label">Pembimbing di jadwal</div>

            <div class="app-mini-stat-value"><?= (int) $stats['pembimbing_jadwal'] ?></div>

        </div>

    </div>

</div>



<div class="alert alert-info py-2 small mb-3">

    <strong>Tarif syahriyah PKPPS</strong> di

    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-pkpps')) ?>">Keuangan → Pengaturan → Syahriyah</a>.

    Import data: <a href="<?= htmlspecialchars(app_href('/pkpps/import_santri.php')) ?>">santri</a> ·

    <a href="<?= htmlspecialchars(app_href('/pkpps/import.php')) ?>">jadwal</a>.

</div>



<div class="row g-3">

    <?php foreach ($hubLinks as $link): ?>

    <div class="col-sm-6 col-lg-4">

        <a href="<?= htmlspecialchars(app_href((string) $link['path'])) ?>" class="card h-100 text-decoration-none hub-link-card">

            <div class="card-body">

                <div class="d-flex align-items-start gap-3">

                    <span class="hub-link-card__icon" aria-hidden="true"><i class="<?= htmlspecialchars((string) $link['icon']) ?>"></i></span>

                    <div class="min-w-0">

                        <h2 class="h6 mb-1 text-dark"><?= htmlspecialchars((string) $link['label']) ?></h2>

                        <p class="small text-muted mb-0"><?= htmlspecialchars((string) $link['desc']) ?></p>

                    </div>

                </div>

            </div>

        </a>

    </div>

    <?php endforeach; ?>

</div>



<style>

.hub-link-card { border: 1px solid #e2e8f0; transition: border-color .15s, box-shadow .15s; }

.hub-link-card:hover { border-color: #0f766e; box-shadow: 0 4px 14px rgba(15,118,110,.12); }

.hub-link-card__icon { flex-shrink: 0; width: 2.5rem; height: 2.5rem; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: rgba(15,118,110,.1); color: #0f766e; font-size: 1.1rem; }

</style>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>


