<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/pwa_offline.php';

$scanPrecachePaths = pwa_scan_precache_relative_paths();
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
    if (navigator.serviceWorker.controller) {
        send();
    } else {
        navigator.serviceWorker.ready.then(send).catch(function () {});
    }
})();
</script>
