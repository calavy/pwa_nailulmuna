<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);
$pageTitle = 'Yayasan — To-Do & Agenda';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <?php $yayasanCrumbTail = 'To-Do & Agenda'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
        <h1 class="h3 mb-1">To-Do &amp; Agenda</h1>
        <p class="text-muted mb-0">To-do mendesak pengurus & kegiatan terdekat ke depan.</p>
    </header>

    <div id="yp-ringkasan-mount">
        <div class="row g-4 placeholder-glow">
            <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><span class="placeholder col-8 mb-3"></span><span class="placeholder col-12"></span><span class="placeholder col-10 mt-2"></span></div></div></div>
            <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><span class="placeholder col-8 mb-3"></span><span class="placeholder col-12"></span><span class="placeholder col-10 mt-2"></span></div></div></div>
        </div>
    </div>
</div>

<script>
window.__ypRingkasanBoot = <?= json_encode(['api' => app_href('/api/yayasan/ringkasan_content.php')], JSON_UNESCAPED_UNICODE) ?>;
(function () {
    function load() {
        var mount = document.getElementById('yp-ringkasan-mount');
        var api = (window.__ypRingkasanBoot || {}).api;
        if (!mount || !api) return;
        fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok && typeof d.html === 'string') mount.innerHTML = d.html;
            })
            .catch(function () {
                mount.innerHTML = '<div class="alert alert-warning mb-0">Gagal memuat agenda.</div>';
            });
    }
    document.addEventListener('yp:navigated', load);
    load();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
