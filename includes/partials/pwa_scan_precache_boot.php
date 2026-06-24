<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/pwa_offline.php';
require_once __DIR__ . '/../../helpers/app_path.php';

$scanPrecachePaths = [];
foreach (pwa_scan_precache_relative_paths() as $rel) {
    $scanPrecachePaths[] = $rel . '?v=' . app_asset_version($rel);
}
if ($scanPrecachePaths === []) {
    return;
}
?>
<script>
(function () {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    var paths = <?= json_encode($scanPrecachePaths, JSON_UNESCAPED_SLASHES) ?>;
    function send() {
        if (!navigator.serviceWorker.controller) {
            return;
        }
        navigator.serviceWorker.controller.postMessage({ type: 'PRECACHE_SCAN', paths: paths });
    }
    function scheduleSend() {
        if ('requestIdleCallback' in window) {
            requestIdleCallback(send, { timeout: 15000 });
        } else {
            setTimeout(send, 12000);
        }
    }
    if (navigator.serviceWorker.controller) {
        scheduleSend();
    } else {
        navigator.serviceWorker.ready.then(scheduleSend).catch(function () {});
    }
})();
</script>
