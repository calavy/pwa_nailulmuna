<?php
$footerRequestPath = $requestPath ?? app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
$isScanKioskPage = app_request_path_is_scan_kiosk($footerRequestPath);
$loadOfflineSyncJs = ($loadOfflineSyncJs ?? true) && app_should_load_offline_sync_js($footerRequestPath);
$loadSdmModalsJs = ($loadSdmModalsJs ?? true) && app_should_load_sdm_modals($footerRequestPath);
$loadAppShellJs = ($loadAppShellJs ?? true) && app_should_load_app_shell_js($footerRequestPath);
$loadDateTimeJs = ($loadDateTimeJs ?? true) && !$isScanKioskPage;
$loadPwaMediaCacheJs = ($loadPwaMediaCacheJs ?? true) && !$isScanKioskPage;
$deferPwaRegisterJs = $isScanKioskPage;
?>
<?php if (isset($_SESSION['user']) && $loadSdmModalsJs): ?>
<?php require_once __DIR__ . '/partials/sdm_modals.php'; ?>
<?php endif; ?>
    </main>
        </div>
    </div>
</div>
<script>
    (function () {
        const cards = document.querySelectorAll('.app-mini-stat');
        if (!cards.length) return;
        const detectIcon = function (label) {
            const t = (label || '').toLowerCase();
            if (t.includes('saldo') || t.includes('bayar') || t.includes('keuangan') || t.includes('gaji')) return '$';
            if (t.includes('santri') || t.includes('wali') || t.includes('pembimbing') || t.includes('user')) return 'U';
            if (t.includes('izin') || t.includes('sakit') || t.includes('alpa')) return '!';
            if (t.includes('waktu') || t.includes('jam') || t.includes('jadwal') || t.includes('bulan')) return 'T';
            if (t.includes('rekap') || t.includes('laporan')) return '#';
            return '*';
        };
        cards.forEach(function (card, idx) {
            const labelEl = card.querySelector('.app-mini-stat-label');
            const label = labelEl ? labelEl.textContent || '' : '';
            card.setAttribute('data-icon', detectIcon(label));
            if (!card.classList.contains('stat-tone-2') && !card.classList.contains('bendahara-stat-icon')) {
                card.classList.add('stat-tone-' + ((idx % 6) + 1));
            }
        });
    })();
</script>
    <?php require_once __DIR__ . '/../helpers/app_vendor.php'; ?>
    <script src="<?= htmlspecialchars(app_vendor_bootstrap_js_href()) ?>" defer crossorigin="anonymous"></script>
    <script>window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/theme-mode.js')) ?>" defer></script>
    <?php if (isset($_SESSION['user']) && $loadOfflineSyncJs): ?>
    <?php if ($loadPwaMediaCacheJs): ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-media-cache.js')) ?>" defer></script>
    <?php endif; ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/offline-sync.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if ($deferPwaRegisterJs): ?>
    <script>
    (function () {
        function loadPwaRegister() {
            if (document.getElementById('pondok-pwa-register-loader')) return;
            var s = document.createElement('script');
            s.id = 'pondok-pwa-register-loader';
            s.src = <?= json_encode(app_asset_href('/assets/js/pwa-register.js'), JSON_UNESCAPED_SLASHES) ?>;
            s.defer = true;
            document.body.appendChild(s);
        }
        if ('requestIdleCallback' in window) {
            requestIdleCallback(loadPwaRegister, { timeout: 12000 });
        } else {
            setTimeout(loadPwaRegister, 4000);
        }
    })();
    </script>
    <?php else: ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-register.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if ($loadAppShellJs): ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/app-shell.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if ($loadDateTimeJs): ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/app-datetime-24h.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if (isset($_SESSION['user']) && $loadSdmModalsJs): ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/sdm-modals.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if (isset($_SESSION['user'])): ?>
    <?php if (!empty($loadSantriSelectJs)): ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/santri-select.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if (!empty($loadPushFcm)): ?>
    <?php require_once __DIR__ . '/partials/push_fcm_bootstrap.php'; ?>
    <?php endif; ?>
    <?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
        <?php foreach ($pageScripts as $pageScriptHref): ?>
    <script src="<?= htmlspecialchars((string) $pageScriptHref) ?>" defer></script>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html>
