<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/presensi_admin.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/presensi_scan_jadwal.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';
require_once __DIR__ . '/../helpers/presensi_notif.php';
require_once __DIR__ . '/../helpers/munawib.php';
require_once __DIR__ . '/../helpers/kegiatan_khusus.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/presensi_scan_client.php';
require_once __DIR__ . '/../helpers/perizinan_aktif.php';
require_once __DIR__ . '/../helpers/offline_sync_dedup.php';

app_scan_page_no_cache_headers();

$pbPortalScan = trim((string) ($_GET['portal'] ?? '')) === '1'
    || trim((string) ($_POST['pb_portal_scan'] ?? '')) === '1';

if (!$pbPortalScan) {
    require_roles(['admin', 'pengurus', 'petugas_absensi', 'pembimbing']);
}

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada. Jalankan schema_presensi.sql di phpMyAdmin.');
    header('Location: ' . app_href($pbPortalScan ? '/login.php?scan=1' : '/dashboard.php'));
    exit;
}

$resultMessage = null;
$resultType = 'success';
$scanRedirect = null;
$izinSelesaiMsgPreset = '';
$today = date('Y-m-d');
$nowTime = date('H:i:s');
$createdBy = $pbPortalScan ? 0 : (int) ($_SESSION['user']['id'] ?? 1);
$scanBackUrl = $pbPortalScan
    ? app_href('/login.php?scan=1')
    : app_href((string) ($_SESSION['user']['role'] ?? '') === 'petugas_absensi' ? '/logout.php' : '/dashboard.php');
$scanBackLabel = $pbPortalScan ? 'Scan Kegiatan' : ((string) ($_SESSION['user']['role'] ?? '') === 'petugas_absensi' ? 'Keluar' : 'Dashboard');
$pendingMunawibPick = $_SESSION['munawib_scan_pending'] ?? null;

presensi_scan_ensure_schema_deferred($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/../helpers/presensi_scan_post.inc.php';

    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json()) {
        $pending = $_SESSION['munawib_scan_pending'] ?? null;
        $extra = [];
        if (is_array($pending)) {
            $extra['munawib_pending'] = true;
            $extra['munawib_id'] = (int) ($pending['munawib_id'] ?? 0);
            $extra['munawib_slots'] = $pending['slots'] ?? [];
            $extra['munawib_nama'] = (string) ($pending['munawib_nama'] ?? '');
        } else {
            $extra['munawib_pending'] = false;
        }
        if ($scanRedirect !== null && $scanRedirect !== '') {
            $extra['redirect'] = $scanRedirect;
        }
        offline_sync_json_response(
            $resultType ?: 'success',
            $resultMessage ?: 'OK',
            $extra
        );
    }
}
$pendingMunawibPick = $_SESSION['munawib_scan_pending'] ?? null;

$pageTitle = $pbPortalScan ? 'Scan Presensi Pembimbing' : 'Scan Presensi';
$bodyClass = 'scan-simple-page' . ($pbPortalScan ? ' scan-portal-pembimbing' : '');
$pageStylesheets = [app_asset_href('/assets/css/presensi-scan.css')];
$hideAppSidebar = true;
$loadPushFcm = false;
$scanJadwalCtx = presensi_scan_jadwal_context_cached($pdo);
$scanTimerPrep = presensi_scan_timer_prepare($scanJadwalCtx);
$timerState = $scanTimerPrep['state'];
$timerClass = $scanTimerPrep['class'];
$timerClockInit = $scanTimerPrep['clock'];
$scanFlashSuccess = get_flash('success');
$scanFlashError = get_flash('error');
$scanFlashMessage = $scanFlashSuccess ?: $scanFlashError ?: '';
$scanFlashType = $scanFlashSuccess !== null ? 'success' : ($scanFlashError !== null ? 'error' : '');
require_once __DIR__ . '/../includes/header.php';
$canBersihkanPresensi = !$pbPortalScan && user_can_hapus_presensi_admin();
?>


<div class="presensi-scan-app">
    <header class="presensi-scan-top">
        <div>
            <a href="<?= htmlspecialchars($scanBackUrl) ?>"><i class="fa-solid fa-arrow-left me-1"></i> <?= htmlspecialchars($scanBackLabel) ?></a>
        </div>
        <strong class="small"><?= $pbPortalScan ? 'Presensi · Portal Pembimbing' : 'Scan Presensi' ?></strong>
        <span id="scan-status-badge" class="presensi-scan-status is-waiting">Menyiapkan…</span>
    </header>

    <div id="presensi-scan-banner-host"<?= $resultMessage ? '' : ' hidden' ?>>
    <?php if ($resultMessage): ?>
    <?php
    $bannerIcon = match ($resultType) {
        'success' => 'fa-circle-check',
        'duplicate' => 'fa-ban',
        'danger' => 'fa-circle-xmark',
        'info' => 'fa-circle-info',
        default => 'fa-triangle-exclamation',
    };
    $bannerText = $resultType === 'success'
        ? 'Berhasil'
        : ($resultType === 'duplicate' ? 'Anda sudah scan' : (
            preg_match('/luar jadwal|tidak ada kegiatan/i', (string) $resultMessage) ? 'Di luar jadwal'
            : (preg_match('/hari libur/i', (string) $resultMessage) ? 'Hari libur'
            : (preg_match('/tidak terdaftar/i', (string) $resultMessage) ? 'QR tidak terdaftar'
            : (preg_match('/tidak aktif|sudah keluar/i', (string) $resultMessage) ? 'Santri tidak aktif'
            : ($resultType === 'danger' ? 'Scan ditolak' : $resultMessage))))
        ));
    ?>
    <div class="presensi-scan-banner presensi-scan-banner--<?= htmlspecialchars($resultType) ?>" role="alert" aria-live="assertive">
        <i class="fa-solid <?= $bannerIcon ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars((string) $bannerText) ?></span>
    </div>
    <?php endif; ?>
    </div>

    <?php if (is_array($pendingMunawibPick) && !empty($pendingMunawibPick['slots']) && (int) ($pendingMunawibPick['munawib_id'] ?? 0) > 0): ?>
        <div class="alert alert-warning mx-2 my-2 py-2">
            <div class="small fw-semibold mb-1">
                Munawib: <?= htmlspecialchars((string) ($pendingMunawibPick['munawib_nama'] ?? '-')) ?> — pilih jadwal yang diwakili
            </div>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="hidden" name="action" value="munawib_pick_schedule">
                <?php if ($pbPortalScan): ?>
                    <input type="hidden" name="pb_portal_scan" value="1">
                <?php endif; ?>
                <input type="hidden" name="munawib_id" value="<?= (int) ($pendingMunawibPick['munawib_id'] ?? 0) ?>">
                <select name="kegiatan_id" class="form-select form-select-sm" style="max-width:280px" required>
                    <option value="">Pilih jadwal aktif</option>
                    <?php foreach ((array) $pendingMunawibPick['slots'] as $slot): ?>
                        <option value="<?= (int) ($slot['kegiatan_id'] ?? 0) ?>">
                            <?= htmlspecialchars((string) (($slot['label'] ?? '') !== '' ? $slot['label'] : ($slot['nama_kegiatan'] ?? ('Kegiatan #' . (int) ($slot['kegiatan_id'] ?? 0))))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-warning" type="submit">
                    <i class="fa-solid fa-check me-1"></i> Konfirmasi jadwal
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/../includes/partials/presensi_scan_timer_strip.php'; ?>

    <form method="post" id="form-scan-presensi" class="visually-hidden">
        <input type="text" id="kode_qr" name="kode_qr" required readonly>
        <input type="hidden" name="scan_source" id="scan_source" value="camera">
        <input type="hidden" name="scan_client_at" id="scan_client_at" value="">
        <?php if ($pbPortalScan): ?>
            <input type="hidden" name="pb_portal_scan" value="1">
        <?php endif; ?>
    </form>

    <div class="presensi-scan-viewport">
        <div id="qr-reader" aria-label="Kamera scan QR"></div>
        <div class="presensi-scan-frame" aria-hidden="true">
            <div class="presensi-scan-frame-box"></div>
        </div>
        <div id="camera-error-panel" class="presensi-scan-error d-none" role="alert">
            <div>
                <p class="fw-semibold mb-2" id="camera-error-text">Gagal membuka kamera</p>
                <p class="small opacity-75 mb-2">Izinkan akses kamera saat browser meminta. Jika tidak muncul:</p>
                <ul class="small text-start opacity-90 mb-3 ps-3">
                    <li>Ketuk ikon gembok / info di bilah alamat</li>
                    <li>Pilih <strong>Kamera → Izinkan</strong></li>
                    <li>Ketuk <strong>Ulangi</strong> di bawah</li>
                </ul>
                <button type="button" class="btn btn-light btn-sm" id="btn-retry-camera">Coba lagi</button>
            </div>
        </div>
        <div id="presensi-scan-start-wrap" class="presensi-scan-start-wrap is-hidden">
            <button type="button" class="btn btn-success btn-lg px-4" id="btn-start-presensi-scan">
                <i class="fa-solid fa-camera me-2" aria-hidden="true"></i>Mulai scan kamera
            </button>
            <p class="small text-muted mt-2 mb-0">Ketuk untuk mengizinkan kamera, lalu arahkan QR ke kotak.</p>
        </div>
    </div>

    <div id="presensi-scan-settings" class="presensi-scan-settings">
        <label class="form-label mb-1" for="camera-select">Pilih kamera</label>
        <select id="camera-select" class="form-select form-select-sm" aria-label="Pilih kamera"></select>
    </div>

    <div id="presensi-scan-flash" class="presensi-scan-flash<?= $scanFlashMessage === '' ? ' is-empty' : (' is-' . htmlspecialchars($scanFlashType)) ?>" role="status" aria-live="polite"><?= $scanFlashMessage !== '' ? htmlspecialchars($scanFlashMessage) : '' ?></div>

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

<?php if ($resultMessage): ?>
<div id="presensi-scan-result" class="visually-hidden" data-type="<?= htmlspecialchars($resultType) ?>" data-speak="<?= htmlspecialchars($resultMessage) ?>" aria-hidden="true">
    <span class="presensi-scan-result-text"><?= htmlspecialchars($resultMessage) ?></span>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-timer.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
(function () {
    var form = document.getElementById('form-scan-presensi');
    var input = document.getElementById('kode_qr');
    var submitting = false;

    function stampScanClientTime() {
        var el = document.getElementById('scan_client_at');
        if (el) {
            el.value = new Date().toISOString();
        }
    }

    function submitScan(code) {
        if (submitting) {
            return;
        }
        input.value = code;
        document.getElementById('scan_source').value = 'camera';
        stampScanClientTime();
        if (window.PondokOfflineSync && PondokOfflineSync.handleFormSubmit(form, { label: 'Scan: ' + code })) {
            submitting = false;
            if (scanner && typeof scanner.resetScanState === 'function') {
                scanner.resetScanState();
            }
            return;
        }
        submitting = true;
        var fd = new FormData(form);
        if (window.crypto && crypto.randomUUID) {
            fd.append('client_uuid', crypto.randomUUID());
        }
        var url = form.getAttribute('action') || window.location.href;
        fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-PWA-Offline-Sync': '1' },
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('invalid json');
            });
        }).then(function (data) {
            submitting = false;
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            if (data.munawib_pending) {
                window.location.reload();
                return;
            }
            var type = data.type || (data.ok ? 'success' : 'warning');
            var msg = data.message || '';
            if (window.PresensiScanFeedback) {
                PresensiScanFeedback.show(type, msg);
            }
            if (scanner && typeof scanner.resetScanState === 'function') {
                scanner.resetScanState();
            }
        }).catch(function () {
            submitting = false;
            form.submit();
        });
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
        onSubmit: submitScan,
    });

    scanner.init();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
