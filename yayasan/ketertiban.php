<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);
$tab = trim((string) ($_GET['tab'] ?? 'izin'));
if (!in_array($tab, ['izin', 'sakit', 'alpa'], true)) {
    $tab = 'izin';
}
$pageTitle = 'Menu Ketertiban';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <?php $yayasanCrumbTail = 'Ketertiban'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
        <h1 class="h3 mb-1">Menu Ketertiban</h1>
        <p class="text-muted mb-0">Pemantauan disiplin santri — per <?= htmlspecialchars(date('d F Y')) ?></p>
    </header>

    <div id="yp-ketertiban-mount">
        <div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat data ketertiban…</div>
    </div>

    <div class="mt-3 small text-muted">
        <a href="<?= htmlspecialchars(app_href('/rekap/perizinan.php')) ?>">Rekap perizinan lengkap</a>
        · <a href="<?= htmlspecialchars(app_href('/poin/rekap.php')) ?>">Rekap poin</a>
    </div>
</div>

<script>
window.__ypKetertibanBoot = <?= json_encode([
    'api' => app_href('/api/yayasan/ketertiban_content.php'),
    'tab' => $tab,
], JSON_UNESCAPED_UNICODE) ?>;
(function () {
    function load() {
        var mount = document.getElementById('yp-ketertiban-mount');
        var boot = window.__ypKetertibanBoot || {};
        if (!mount || !boot.api) return;
        var tab = new URL(window.location.href).searchParams.get('tab') || boot.tab || 'izin';
        mount.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat…</div>';
        fetch(boot.api + '?tab=' + encodeURIComponent(tab), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok && typeof d.html === 'string') mount.innerHTML = d.html;
            })
            .catch(function () {
                mount.innerHTML = '<div class="alert alert-warning mb-0">Gagal memuat ketertiban.</div>';
            });
    }
    document.addEventListener('yp:navigated', load);
    load();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
