<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

migrate_inventaris_barang_permission($pdo);
require_roles(['admin', 'pengurus']);

$cekHref = app_href('/inventaris/cek.php');
$pageTitle = 'Pindai Barang';
$bodyClass = 'scan-simple-page';
$pageStylesheets = [app_asset_href('/assets/css/presensi-scan.css')];
$hideAppSidebar = true;
$loadPushFcm = false;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="presensi-scan-app">
    <header class="presensi-scan-top">
        <div>
            <a href="<?= htmlspecialchars(app_href('/inventaris/index.php')) ?>"><i class="fa-solid fa-arrow-left me-1"></i> Kelengkapan barang</a>
        </div>
        <strong class="small">Pindai barang</strong>
        <span id="scan-status-badge" class="presensi-scan-status is-waiting">Menyiapkan…</span>
    </header>
    <p class="small text-center text-muted mb-0 px-2 py-1">Arahkan kamera ke kode QR pada barang.</p>

    <div id="presensi-scan-banner-host" hidden></div>

    <div class="presensi-scan-viewport">
        <div id="qr-reader" aria-label="Kamera scan QR barang"></div>
        <div class="presensi-scan-frame" aria-hidden="true">
            <div class="presensi-scan-frame-box"></div>
        </div>
        <div id="camera-error-panel" class="presensi-scan-error d-none" role="alert">
            <div>
                <p class="fw-semibold mb-2" id="camera-error-text">Gagal membuka kamera</p>
                <p class="small opacity-75 mb-2">Izinkan akses kamera saat browser meminta.</p>
                <button type="button" class="btn btn-light btn-sm" id="btn-retry-camera">Coba lagi</button>
            </div>
        </div>
        <div id="presensi-scan-start-wrap" class="presensi-scan-start-wrap is-hidden">
            <button type="button" class="btn btn-success btn-lg px-4" id="btn-start-presensi-scan">
                <i class="fa-solid fa-camera me-2" aria-hidden="true"></i>Mulai scan kamera
            </button>
            <p class="small text-muted mt-2 mb-0">Ketuk, lalu arahkan kode QR barang ke kotak.</p>
        </div>
    </div>

    <div id="presensi-scan-settings" class="presensi-scan-settings">
        <label class="form-label mb-1" for="camera-select">Pilih kamera</label>
        <select id="camera-select" class="form-select form-select-sm" aria-label="Pilih kamera"></select>
    </div>

    <div id="presensi-scan-flash" class="presensi-scan-flash is-empty" role="status" aria-live="polite"></div>

    <div class="presensi-scan-controls">
        <button type="button" class="btn-scan-ctl btn-scan-ctl--flash" id="btn-torch" title="Nyalakan/matikan flash kamera">
            <i class="fa-solid fa-bolt"></i>
            <span>Flash</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-flip-camera" title="Ganti kamera depan/belakang">
            <i class="fa-solid fa-camera-rotate"></i>
            <span>Ganti kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-super-focus" title="Optimalkan fokus kamera">
            <i class="fa-solid fa-crosshairs"></i>
            <span>Super Fokus</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-scan-settings" title="Pilih kamera">
            <i class="fa-solid fa-sliders"></i>
            <span>Kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl btn-scan-ctl--secondary" id="btn-restart-camera" title="Nyalakan ulang kamera">
            <i class="fa-solid fa-rotate-right"></i>
            <span>Ulangi</span>
        </button>
    </div>
</div>

<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
(function () {
    var cekHref = <?= json_encode($cekHref, JSON_UNESCAPED_UNICODE) ?>;

    function targetForCode(code) {
        var text = String(code || '').trim();
        if (/^https?:\/\//i.test(text) && /\/inventaris\/cek\.php/i.test(text)) {
            return text;
        }
        var fromQuery = text.match(/[?&]kode=([^&#]+)/i);
        var bare = fromQuery ? decodeURIComponent(fromQuery[1]) : text;
        bare = bare.trim().toUpperCase();
        if (/^[A-Z0-9][A-Z0-9_-]{0,63}$/.test(bare)) {
            return cekHref + '?kode=' + encodeURIComponent(bare);
        }
        return '';
    }

    function submitScan(code) {
        var target = targetForCode(code);
        if (!target) {
            if (window.PresensiScanFeedback) {
                PresensiScanFeedback.show('warning', 'QR ini bukan kode barang inventaris.');
            }
            if (scanner && typeof scanner.resetScanState === 'function') {
                scanner.resetScanState();
            }
            return;
        }
        window.location.href = target;
    }

    var scanner = new PresensiScanCamera({
        readerId: 'qr-reader',
        statusEl: document.getElementById('scan-status-badge'),
        errorPanel: document.getElementById('camera-error-panel'),
        errorText: document.getElementById('camera-error-text'),
        cameraSelect: document.getElementById('camera-select'),
        settingsPanel: document.getElementById('presensi-scan-settings'),
        btnFlip: document.getElementById('btn-flip-camera'),
        btnRestart: document.getElementById('btn-restart-camera'),
        btnSettings: document.getElementById('btn-scan-settings'),
        btnRetry: document.getElementById('btn-retry-camera'),
        btnTorch: document.getElementById('btn-torch'),
        btnSuperFocus: document.getElementById('btn-super-focus'),
        startWrap: document.getElementById('presensi-scan-start-wrap'),
        startBtn: document.getElementById('btn-start-presensi-scan'),
        onSubmit: submitScan
    });
    scanner.init();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
