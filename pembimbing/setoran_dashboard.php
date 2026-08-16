<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';

if (!isset($_SESSION['user'])) {
    app_redirect('login.php?dest=setoran');
}

require_once __DIR__ . '/../helpers/akademik_setoran.php';
require_once __DIR__ . '/partials/setoran_portal_bootstrap.php';

$setoranNavActive = 'home';

$pageTitle = 'Dashboard Setoran Hafalan';
$bodyClass = 'setoran-portal-page st-portal-page pb-dash-bg-putih dash-page st-portal-is-home st-portal-mobile-fit';
$pageStylesheets = [
    app_asset_href('/assets/css/pembimbing-dashboard.css'),
    app_asset_href('/assets/css/setoran-portal.css'),
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-page">
<div class="container py-3 py-md-4" style="max-width:640px">
    <?php require __DIR__ . '/partials/setoran_portal_head.php'; ?>

    <?php if (($setoranPortalWarning ?? '') !== ''): ?>
    <div class="alert alert-warning small py-2 mb-3"><?= htmlspecialchars($setoranPortalWarning) ?></div>
    <?php endif; ?>

    <div class="alert alert-light border small py-2 mb-3">
        <i class="fa-solid fa-circle-info me-1 text-primary"></i>
        <strong>Setoran harian</strong> — tidak mengikuti jadwal kegiatan. Setiap hari santri wajib setor;
        jika tidak setor dan tidak ada izin/sakit (dari presensi), tercatat <strong>alpa</strong>.
        Anda hanya melihat santri pada tingkatan yang ditugaskan menerima setoran.
    </div>

    <?php require __DIR__ . '/partials/setoran_portal_menu_cards.php'; ?>

    <?php require __DIR__ . '/partials/setoran_santri_list.php'; ?>
</div>
</div>

<script>
(function () {
    function tick() {
        var now = new Date();
        var clock = document.getElementById('st-portal-live-clock');
        var date = document.getElementById('st-portal-live-date');
        if (clock) clock.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if (date) date.textContent = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
