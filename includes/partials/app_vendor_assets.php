<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/app_vendor.php';

$faCssLocal = app_href('/api/vendor/fontawesome.css.php');
$faCssCdn = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
$bsCss = app_vendor_bootstrap_css_href();
$faSolidFont = app_vendor_static_href('fontawesome/6.5.2/webfonts/fa-solid-900.woff2');
$faRegularFont = app_vendor_static_href('fontawesome/6.5.2/webfonts/fa-regular-400.woff2');
$faBrandsFont = app_vendor_static_href('fontawesome/6.5.2/webfonts/fa-brands-400.woff2');
$faLocalOk = app_vendor_file_exists('fontawesome/6.5.2/all.min.css');
?>
<?php if ($faLocalOk): ?>
<link rel="preload" href="<?= htmlspecialchars($faSolidFont) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= htmlspecialchars($faRegularFont) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= htmlspecialchars($faBrandsFont) ?>" as="font" type="font/woff2" crossorigin>
<?php endif; ?>
<link href="<?= htmlspecialchars($bsCss) ?>" rel="stylesheet">
<link id="pondok-fontawesome-css" href="<?= htmlspecialchars($faCssCdn) ?>" rel="stylesheet">
<?php if ($faLocalOk): ?>
<script>
(function () {
    var link = document.getElementById('pondok-fontawesome-css');
    if (!link) {
        return;
    }
    var localHref = <?= json_encode($faCssLocal, JSON_UNESCAPED_SLASHES) ?>;
    var cdnHref = <?= json_encode($faCssCdn, JSON_UNESCAPED_SLASHES) ?>;
    function applyFa() {
        link.href = navigator.onLine ? cdnHref : localHref;
    }
    applyFa();
    window.addEventListener('online', applyFa);
    window.addEventListener('offline', applyFa);
})();
</script>
<?php endif; ?>
<link rel="preload" href="<?= htmlspecialchars($bsCss) ?>" as="style">
