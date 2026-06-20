<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/app_vendor.php';

?>
<script src="<?= htmlspecialchars(app_vendor_html5_qrcode_js_href()) ?>" crossorigin="anonymous" defer></script>
<?php require __DIR__ . '/pwa_scan_precache_boot.php'; ?>
