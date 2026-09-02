<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/kedatangan_libur.php';
require_once __DIR__ . '/../helpers/offline_sync_http.php';

app_scan_page_no_cache_headers();
require_roles(['admin', 'pengurus', 'petugas_absensi']);
kedatangan_libur_ensure_schema($pdo);

$sesiId = (int) ($_GET['sesi'] ?? ($_POST['sesi_id'] ?? ($_SESSION['kedatangan_libur_sesi_id'] ?? 0)));
$sesi = $sesiId > 0 ? kedatangan_libur_sesi_by_id($pdo, $sesiId) : null;
if ($sesi !== null) {
    $_SESSION['kedatangan_libur_sesi_id'] = (int) $sesi['id'];
    $sesiId = (int) $sesi['id'];
}

$resultMessage = null;
$resultType = 'success';
$resultFotoUrl = '';
$resultNama = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = '';
    if (($_POST['scan_source'] ?? '') === 'camera') {
        $code = trim((string) ($_POST['kode_qr'] ?? ''));
    }
    if ($sesi === null) {
        $resultType = 'warning';
        $resultMessage = 'Sesi kedatangan belum dipilih. Buka Absen kedatangan dulu.';
    } elseif (($_POST['scan_source'] ?? '') !== 'camera') {
        $resultType = 'warning';
        $resultMessage = 'Input manual dinonaktifkan. Silakan gunakan scan kamera.';
    } else {
        $res = kedatangan_libur_catat_scan($pdo, $sesiId, $code, (int) ($_SESSION['user']['id'] ?? 0));
        $resultType = (string) ($res['type'] ?? 'warning');
        $resultMessage = (string) ($res['message'] ?? '');
        $santriRow = is_array($res['santri'] ?? null) ? $res['santri'] : null;
        $resultFotoUrl = kedatangan_libur_santri_foto_url($santriRow);
        $resultNama = kedatangan_libur_santri_nama($santriRow);
    }

    if (offline_sync_wants_json()) {
        offline_sync_json_response($resultType ?: 'success', $resultMessage ?: 'OK', [
            'foto_url' => $resultFotoUrl,
            'nama_santri' => $resultNama,
        ]);
    }
}

if ($sesi === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Pilih atau buka sesi kedatangan terlebih dahulu.');
    header('Location: ' . app_href('/presensi/kedatangan.php'));
    exit;
}

$scanBackUrl = app_href('/presensi/kedatangan.php' . ($sesiId > 0 ? ('?sesi=' . $sesiId) : ''));
$pageTitle = 'Scan kedatangan';
$bodyClass = 'scan-simple-page';
$pageStylesheets = [app_asset_href('/assets/css/presensi-scan.css')];
$hideAppSidebar = true;
$loadPushFcm = false;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="presensi-scan-app">
    <header class="presensi-scan-top">
        <div>
            <a href="<?= htmlspecialchars($scanBackUrl) ?>"><i class="fa-solid fa-arrow-left me-1"></i> Absen kedatangan</a>
        </div>
        <strong class="small">Scan kedatangan</strong>
        <span id="scan-status-badge" class="presensi-scan-status is-waiting">Menyiapkan…</span>
    </header>

    <?php if ($sesi !== null): ?>
        <p class="small text-center text-muted mb-0 px-2 py-1">
            <?= htmlspecialchars((string) ($sesi['nama_libur'] ?? $sesi['nama'] ?? '')) ?>
            · <?= htmlspecialchars(app_format_tanggal_id((string) $sesi['tanggal'])) ?>
            · <?= htmlspecialchars(app_format_jam_rentang((string) $sesi['jam_mulai'], (string) $sesi['jam_selesai'])) ?>
        </p>
    <?php endif; ?>

    <div id="presensi-scan-banner-host"<?= $resultMessage ? '' : ' hidden' ?>>
    <?php if ($resultMessage): ?>
    <?php
    $bannerIcon = match ($resultType) {
        'success' => 'fa-circle-check',
        'duplicate' => 'fa-ban',
        'danger' => 'fa-circle-xmark',
        default => 'fa-triangle-exclamation',
    };
    $bannerText = $resultType === 'success'
        ? 'Berhasil'
        : ($resultType === 'duplicate' ? 'Sudah dicatat' : $resultMessage);
    ?>
    <div class="presensi-scan-banner presensi-scan-banner--<?= htmlspecialchars($resultType) ?>" role="alert" aria-live="assertive">
        <i class="fa-solid <?= $bannerIcon ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars((string) $bannerText) ?></span>
    </div>
    <?php endif; ?>
    </div>

    <form method="post" id="form-scan-presensi" class="visually-hidden">
        <input type="text" id="kode_qr" name="kode_qr" required readonly>
        <input type="hidden" name="scan_source" id="scan_source" value="camera">
        <input type="hidden" name="sesi_id" value="<?= $sesiId ?>">
    </form>

    <div class="presensi-scan-viewport">
        <div id="qr-reader" aria-label="Kamera scan QR"></div>
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
            <p class="small text-muted mt-2 mb-0">Ketuk, lalu arahkan QR kartu santri ke kotak.</p>
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

<?php if ($resultMessage): ?>
<div id="presensi-scan-result" class="visually-hidden" data-type="<?= htmlspecialchars($resultType) ?>" data-speak="<?= htmlspecialchars($resultMessage) ?>" data-foto="<?= htmlspecialchars($resultFotoUrl) ?>" data-nama="<?= htmlspecialchars($resultNama) ?>" aria-hidden="true">
    <span class="presensi-scan-result-text"><?= htmlspecialchars($resultMessage) ?></span>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
(function () {
    var form = document.getElementById('form-scan-presensi');
    var input = document.getElementById('kode_qr');
    var submitting = false;

    function submitScan(code) {
        if (submitting) {
            return;
        }
        input.value = code;
        document.getElementById('scan_source').value = 'camera';
        submitting = true;
        var fd = new FormData(form);
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
            var type = data.type || (data.ok ? 'success' : 'warning');
            var msg = data.message || '';
            if (window.PresensiScanFeedback) {
                PresensiScanFeedback.show(type, msg, {
                    photoUrl: data.foto_url || '',
                    nama: data.nama_santri || '',
                });
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
