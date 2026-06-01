<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/push_fcm.php';

if (!function_exists('pondok_pdo')) {
    require_once __DIR__ . '/../../config/database.php';
}
$pdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : pondok_pdo();
$pushCfgKey = 'push_fcm_cfg_cache_v1';
if (!empty($_SESSION[$pushCfgKey]) && is_array($_SESSION[$pushCfgKey])) {
    $pushCfg = $_SESSION[$pushCfgKey];
} else {
    $pushCfg = push_fcm_web_config($pdo);
    $_SESSION[$pushCfgKey] = $pushCfg;
}
$pushAudience = 'staff';
$pushCategories = push_default_categories_for_audience('staff');
$pushSubscribeKiai = false;

if (isset($_SESSION['wali']) && is_array($_SESSION['wali'])) {
    $pushAudience = 'wali';
    $pushCategories = push_default_categories_for_audience('wali');
} elseif (isset($_SESSION['santri_portal']) && is_array($_SESSION['santri_portal'])) {
    $pushAudience = 'wali';
    $pushCategories = push_default_categories_for_audience('wali');
} elseif (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'kiai') {
        $pushAudience = 'kiai';
        $pushCategories = push_default_categories_for_audience('kiai');
        $pushSubscribeKiai = true;
    }
}
?>
<div id="fcm-toast-host" class="position-fixed bottom-0 end-0 p-3" style="z-index:1080;max-width:320px;"></div>
<script>
window.PONDOK_FCM_CONFIG = <?= json_encode($pushCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.PONDOK_FCM_OPTS = {
    audienceType: <?= json_encode($pushAudience) ?>,
    categories: <?= json_encode($pushCategories, JSON_UNESCAPED_UNICODE) ?>,
    subscribeKiai: <?= $pushSubscribeKiai ? 'true' : 'false' ?>,
    prompt: false
};
</script>
<script>window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= htmlspecialchars(app_url('assets/js/fcm-push.js')) ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.PondokFcm || !window.PONDOK_FCM_CONFIG || window.PONDOK_FCM_CONFIG.enabled !== '1') return;

    var storageKey = 'pondok_fcm_subscribed_' + (window.PONDOK_FCM_OPTS.audienceType || 'staff');
    if (!document.querySelector('.js-fcm-subscribe')) return;

    function markSubscribed(active) {
        document.querySelectorAll('.js-fcm-subscribe').forEach(function (btn) {
            if (active) {
                btn.classList.remove('text-muted');
                btn.classList.add('text-success');
                btn.title = 'Notifikasi push aktif';
                if (btn.classList.contains('dropdown-item')) {
                    btn.innerHTML = '<i class="fa-solid fa-bell me-2"></i> Notifikasi aktif';
                } else {
                    btn.innerHTML = '<i class="fa-solid fa-bell me-1"></i> Notifikasi aktif';
                }
            } else {
                btn.classList.remove('text-success');
                btn.title = 'Aktifkan notifikasi push';
                if (btn.classList.contains('dropdown-item')) {
                    btn.innerHTML = '<i class="fa-regular fa-bell me-2 opacity-75" aria-hidden="true"></i> Aktifkan notifikasi';
                } else {
                    btn.innerHTML = '<i class="fa-regular fa-bell me-1"></i> Aktifkan notifikasi';
                }
            }
        });
    }

    if (localStorage.getItem(storageKey) === '1') {
        markSubscribed(true);
    }

    document.querySelectorAll('.js-fcm-subscribe').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            PondokFcm.init(Object.assign({}, window.PONDOK_FCM_OPTS, { prompt: true })).then(function (r) {
                document.querySelectorAll('.js-fcm-subscribe').forEach(function (b) { b.disabled = false; });
                if (r.ok) {
                    localStorage.setItem(storageKey, '1');
                    markSubscribed(true);
                    alert('Notifikasi push aktif di perangkat ini.');
                } else if (r.reason === 'denied') {
                    alert('Izin notifikasi ditolak. Aktifkan di pengaturan browser / HP.');
                } else if (r.reason === 'not_configured') {
                    alert('FCM belum dikonfigurasi. Buka Settings → Push FCM.');
                } else {
                    alert('Gagal mengaktifkan push. Periksa Settings → Push FCM.');
                }
            }).catch(function () {
                document.querySelectorAll('.js-fcm-subscribe').forEach(function (b) { b.disabled = false; });
                alert('Gagal menghubungkan ke Firebase.');
            });
        });
    });
});
</script>
