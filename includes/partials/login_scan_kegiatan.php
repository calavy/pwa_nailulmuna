<?php

declare(strict_types=1);

/**
 * UI scan kegiatan inline — kamera + routing otomatis (santri absensi, pb/mw portal).
 *
 * @var string $loginScanDest ''|'setoran' — dari ?dest=setoran
 */
$loginScanDest = ($loginScanDest ?? '') === 'setoran' ? 'setoran' : '';
?>
<a href="<?= htmlspecialchars(app_href('/login.php' . ($loginScanDest === 'setoran' ? '?dest=setoran' : ''))) ?>" class="auth-portal-back">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Masuk dengan password
</a>

<div class="login-scan-kegiatan presensi-scan-app mt-1">
    <div class="login-scan-kegiatan__head presensi-scan-top">
        <span class="login-scan-kegiatan__title">
            <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> Multi Scan
        </span>
        <span id="login-scan-status" class="presensi-scan-status is-waiting">Menyiapkan…</span>
    </div>

    <div class="presensi-scan-banner-host-login" id="presensi-scan-banner-host" hidden></div>

    <?php require __DIR__ . '/presensi_scan_timer_strip.php'; ?>

    <div class="login-scan-kegiatan__viewport presensi-scan-viewport" id="login-scan-camera-wrap">
        <div id="login-scan-reader" aria-label="Kamera scan kartu santri, pembimbing, atau munawib"></div>
        <div class="presensi-scan-frame" aria-hidden="true"><div class="presensi-scan-frame-box"></div></div>
        <div id="login-scan-error" class="presensi-scan-error d-none" role="alert">
            <div>
                <p class="fw-semibold mb-2" id="login-scan-error-text">Gagal membuka kamera</p>
                <button type="button" class="btn btn-light btn-sm" id="login-scan-retry">Coba lagi</button>
            </div>
        </div>
        <div id="login-scan-start-wrap" class="presensi-scan-start-wrap is-hidden">
            <button type="button" class="btn btn-success btn-lg px-4" id="btn-start-login-scan">
                <i class="fa-solid fa-camera me-2" aria-hidden="true"></i>Mulai scan kamera
            </button>
            <p class="small text-muted mt-2 mb-0">Tutup balon chat, filter layar, atau perekam layar, lalu ketuk untuk mengizinkan kamera.</p>
        </div>
    </div>

    <div class="presensi-scan-controls login-scan-kegiatan__controls" id="login-scan-controls">
        <button type="button" class="btn-scan-ctl btn-scan-ctl--flash" id="login-scan-torch" title="Nyalakan/matikan flash kamera">
            <i class="fa-solid fa-bolt"></i>
            <span>Flash</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="login-scan-flip" title="Ganti kamera">
            <i class="fa-solid fa-camera-rotate"></i>
            <span>Ganti kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="login-scan-super-focus" title="Optimalkan fokus kamera">
            <i class="fa-solid fa-crosshairs"></i>
            <span>Super Fokus</span>
        </button>
        <button type="button" class="btn-scan-ctl btn-scan-ctl--secondary" id="login-scan-restart" title="Nyalakan ulang kamera">
            <i class="fa-solid fa-rotate-right"></i>
            <span>Ulangi</span>
        </button>
    </div>

    <input type="hidden" id="login-scan-login-dest" value="<?= htmlspecialchars($loginScanDest) ?>">
    <input type="hidden" id="login-scan-smart-url" value="<?= htmlspecialchars(app_href('/api/scan/smart.php')) ?>">

    <div id="login-scan-munawib-pick" class="login-scan-munawib-pick d-none" role="region" aria-label="Pilih jadwal munawib">
        <p class="login-scan-munawib-pick__title small fw-semibold mb-2" id="login-scan-munawib-title"></p>
        <div class="login-scan-munawib-pick__row">
            <select id="login-scan-munawib-select" class="form-select form-select-sm" aria-label="Jadwal aktif munawib">
                <option value="">Pilih jadwal aktif</option>
            </select>
            <button type="button" class="btn btn-sm btn-warning" id="login-scan-munawib-confirm">
                <i class="fa-solid fa-check me-1" aria-hidden="true"></i> Konfirmasi
            </button>
        </div>
    </div>

    <p class="login-scan-kegiatan__hint small text-muted text-center mb-0">Arahkan kartu ke kotak hijau. Santri: absensi. Pembimbing/munawib: kehadiran dulu, scan lagi untuk portal. Tanpa jadwal, portal otomatis.</p>

    <form method="post" id="login-scan-form-offline" class="visually-hidden" action="<?= htmlspecialchars(app_href('/presensi/scan.php?portal=1')) ?>" autocomplete="off">
        <input type="hidden" name="scan_source" value="camera">
        <input type="hidden" name="pb_portal_scan" value="1">
        <input type="text" name="kode_qr" id="login-scan-kode-offline" readonly>
    </form>
</div>

<?php if (!empty($err)): ?>
<div id="presensi-scan-result" class="visually-hidden" data-type="danger" data-speak="<?= htmlspecialchars((string) $err) ?>" aria-hidden="true">
    <span class="presensi-scan-result-text"><?= htmlspecialchars((string) $err) ?></span>
</div>
<?php endif; ?>

<link href="<?= htmlspecialchars(app_url('assets/css/presensi-scan.css')) ?>" rel="stylesheet">
<link href="<?= htmlspecialchars(app_url('assets/css/offline-sync.css')) ?>" rel="stylesheet">
<?php require_once __DIR__ . '/../../helpers/app_vendor.php'; require __DIR__ . '/app_html5_qrcode_script.php'; ?>
<script>window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<?php if (function_exists('app_offline_queue_flush_script')) { app_offline_queue_flush_script(); } ?>
<script src="<?= htmlspecialchars(app_url('assets/js/pwa-register.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/offline-sync.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-timer.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-camera.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/login-scan-kegiatan.js')) ?>"></script>
