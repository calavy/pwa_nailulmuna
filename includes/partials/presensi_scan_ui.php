<?php

declare(strict_types=1);

/**
 * UI scan kamera presensi (dipakai presensi utama & scan musyawarah yayasan).
 *
 * @var string $scanBackUrl
 * @var string $scanBackLabel
 * @var string $scanTopTitle
 * @var string|null $resultMessage
 * @var string $resultType
 * @var array<string, mixed>|null $pendingMusyawarahPick
 * @var array<string, mixed> $scanJadwalCtx
 * @var string $timerState
 * @var string $timerClass
 * @var string $timerClockInit
 * @var string $scanTimerNoneLabel
 * @var string $scanFormExtraHtml
 */
$scanTimerNoneLabel = $scanTimerNoneLabel ?? 'Belum ada jadwal';
$scanFormExtraHtml = $scanFormExtraHtml ?? '';
$pendingMusyawarahPick = $pendingMusyawarahPick ?? null;
?>

<div class="presensi-scan-app">
    <header class="presensi-scan-top">
        <div>
            <a href="<?= htmlspecialchars($scanBackUrl) ?>"><i class="fa-solid fa-arrow-left me-1"></i> <?= htmlspecialchars($scanBackLabel) ?></a>
        </div>
        <strong class="small"><?= htmlspecialchars($scanTopTitle) ?></strong>
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
            preg_match('/luar jadwal|tidak ada rapat|tidak ada kegiatan/i', (string) $resultMessage) ? 'Di luar jadwal'
            : (preg_match('/hari libur/i', (string) $resultMessage) ? 'Hari libur'
            : (preg_match('/tidak terdaftar/i', (string) $resultMessage) ? 'QR tidak terdaftar'
            : (preg_match('/tidak aktif|sudah keluar/i', (string) $resultMessage) ? 'Tidak aktif'
            : ($resultType === 'danger' ? 'Scan ditolak' : $resultMessage))))
        ));
    ?>
    <div class="presensi-scan-banner presensi-scan-banner--<?= htmlspecialchars($resultType) ?>" role="alert" aria-live="assertive">
        <i class="fa-solid <?= $bannerIcon ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars((string) $bannerText) ?></span>
    </div>
    <?php endif; ?>
    </div>

    <?php if (is_array($pendingMusyawarahPick) && !empty($pendingMusyawarahPick['rapats']) && (int) ($pendingMusyawarahPick['pengurus_id'] ?? 0) > 0): ?>
        <div class="alert alert-info mx-2 my-2 py-2">
            <div class="small fw-semibold mb-1">
                Musyawarah: <?= htmlspecialchars((string) ($pendingMusyawarahPick['pengurus_nama'] ?? '-')) ?> — pilih rapat
            </div>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="hidden" name="action" value="musyawarah_pick_rapat">
                <input type="hidden" name="pengurus_id" value="<?= (int) ($pendingMusyawarahPick['pengurus_id'] ?? 0) ?>">
                <?= $scanFormExtraHtml ?>
                <select name="rapat_id" class="form-select form-select-sm" style="max-width:280px" required>
                    <option value="">Pilih rapat aktif</option>
                    <?php foreach ((array) $pendingMusyawarahPick['rapats'] as $rapatPick): ?>
                        <option value="<?= (int) ($rapatPick['id'] ?? 0) ?>">
                            <?= htmlspecialchars((string) (($rapatPick['label'] ?? '') !== '' ? $rapatPick['label'] : ($rapatPick['judul'] ?? ('Rapat #' . (int) ($rapatPick['id'] ?? 0))))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-info text-white" type="submit">
                    <i class="fa-solid fa-check me-1"></i> Konfirmasi rapat
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php
    $scanTimerActiveFallback = $scanTimerActiveFallback ?? 'Rapat aktif';
    $scanTimerUpcomingFallback = $scanTimerUpcomingFallback ?? 'Menunggu rapat';
    $scanTimerEndedLabel = $scanTimerEndedLabel ?? 'Di luar jadwal rapat';
    $scanTimerAriaLabel = $scanTimerAriaLabel ?? 'Rapat berlangsung';
    $scanTimerShowWall = true;
    require __DIR__ . '/presensi_scan_timer_strip.php';
    ?>

    <?php
    $scanAgendaUraian = trim((string) ($scanJadwalCtx['agenda_ringkas'] ?? ''));
    if ($scanAgendaUraian !== ''):
        ?>
    <div class="mx-2 mb-2 px-3 py-2 small border rounded bg-white shadow-sm" style="max-height:8rem;overflow-y:auto">
        <div class="fw-semibold text-secondary mb-1"><i class="fa-solid fa-list-ul me-1"></i>Agenda / uraian</div>
        <div class="text-muted" style="white-space:pre-wrap"><?= htmlspecialchars($scanAgendaUraian) ?></div>
    </div>
    <?php endif; ?>

    <form method="post" id="form-scan-presensi" class="visually-hidden">
        <input type="text" id="kode_qr" name="kode_qr" required readonly>
        <input type="hidden" name="scan_source" id="scan_source" value="camera">
        <input type="hidden" name="scan_client_at" id="scan_client_at" value="">
        <?= $scanFormExtraHtml ?>
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

<?php require __DIR__ . '/app_html5_qrcode_script.php'; ?>
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
            if (scanner && typeof scanner.resetScanState === 'function') {
                scanner.resetScanState();
            }
            return;
        }
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
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            if (data.musyawarah_pending) {
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
