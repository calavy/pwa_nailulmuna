/**
 * Scan Kegiatan — kamera inline di login.php?scan=1 (routing otomatis via smart API).
 */
(function () {
    'use strict';

    if (typeof window.PresensiScanCamera === 'undefined') {
        return;
    }

    var smartUrlEl = document.getElementById('login-scan-smart-url');
    var loginDestEl = document.getElementById('login-scan-login-dest');
    var formOffline = document.getElementById('login-scan-form-offline');
    var inputOffline = document.getElementById('login-scan-kode-offline');
    var statusEl = document.getElementById('login-scan-status');
    var munawibPick = document.getElementById('login-scan-munawib-pick');
    var munawibTitle = document.getElementById('login-scan-munawib-title');
    var munawibSelect = document.getElementById('login-scan-munawib-select');
    var munawibConfirm = document.getElementById('login-scan-munawib-confirm');

    if (!smartUrlEl || !formOffline || !inputOffline) {
        return;
    }

    var smartUrl = smartUrlEl.value || '';
    var loginDest = loginDestEl ? loginDestEl.value || '' : '';
    var submitted = false;
    var scanner = null;
    var pendingQrCode = '';
    var pendingMunawibId = 0;

    function stampClientAt(body) {
        body.scan_client_at = new Date().toISOString();
        if (loginDest) {
            body.login_dest = loginDest;
        }
        return body;
    }

    function showFeedback(type, msg) {
        if (window.PresensiScanFeedback && typeof window.PresensiScanFeedback.show === 'function') {
            window.PresensiScanFeedback.show(type, msg);
        }
    }

    function resetMunawibPick() {
        pendingMunawibId = 0;
        if (munawibPick) {
            munawibPick.classList.add('d-none');
        }
        if (munawibSelect) {
            munawibSelect.innerHTML = '<option value="">Pilih jadwal aktif</option>';
        }
    }

    function showMunawibPick(data, qrCode) {
        if (!munawibPick || !munawibSelect) {
            submitted = false;
            return;
        }
        pendingQrCode = qrCode;
        pendingMunawibId = parseInt(data.munawib_id, 10) || 0;
        var slots = Array.isArray(data.munawib_slots) ? data.munawib_slots : [];
        munawibSelect.innerHTML = '<option value="">Pilih jadwal aktif</option>';
        slots.forEach(function (slot) {
            var opt = document.createElement('option');
            opt.value = String(slot.kegiatan_id || '');
            var label = slot.label || slot.nama_kegiatan || ('Kegiatan #' + opt.value);
            opt.textContent = label;
            munawibSelect.appendChild(opt);
        });
        if (munawibTitle) {
            var nama = data.munawib_nama || 'Munawib';
            munawibTitle.textContent = nama + ' — pilih jadwal yang diwakili';
        }
        munawibPick.classList.remove('d-none');
        showFeedback(data.type || 'info', data.message || 'Pilih jadwal munawib.');
        submitted = false;
    }

    function handleRedirect(redirect, presensiMsg) {
        if (presensiMsg) {
            showFeedback('success', presensiMsg);
        }
        setTimeout(function () {
            window.location.href = redirect;
        }, presensiMsg ? 500 : 0);
    }

    function postSmart(body) {
        if (window.crypto && crypto.randomUUID) {
            body.client_uuid = crypto.randomUUID();
        }
        return fetch(smartUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-PWA-Offline-Sync': '1',
            },
            body: JSON.stringify(stampClientAt(body)),
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('invalid_json');
            });
        });
    }

    function continueScanning() {
        submitted = false;
        if (scanner && typeof scanner.resetScanState === 'function') {
            scanner.resetScanState();
        }
        if (scanner && !scanner.scanning && typeof scanner.resumeScanning === 'function') {
            scanner.resumeScanning().catch(function () {});
        }
    }

    function handleSmartResponse(data, qrCode) {
        if (data.munawib_pending) {
            showMunawibPick(data, qrCode);
            continueScanning();
            return;
        }

        if (data.redirect) {
            var presMsg = data.presensi_message || (data.presensi_type === 'duplicate' ? data.message : '');
            handleRedirect(data.redirect, presMsg);
            return;
        }

        var type = data.type || (data.ok ? 'success' : 'warning');
        var msg = data.message || '';
        showFeedback(type, msg);
        resetMunawibPick();
        continueScanning();
    }

    function submitOffline(code) {
        inputOffline.value = code;
        var at = formOffline.querySelector('input[name="scan_client_at"]');
        if (!at) {
            at = document.createElement('input');
            at.type = 'hidden';
            at.name = 'scan_client_at';
            formOffline.appendChild(at);
        }
        at.value = new Date().toISOString();

        if (window.PondokOfflineSync && PondokOfflineSync.enqueuePresensiScan) {
            PondokOfflineSync.enqueuePresensiScan(formOffline, {
                label: 'Scan: ' + String(code).slice(0, 24),
                url: formOffline.getAttribute('action') || '',
            }).then(function () {
                showFeedback('info', 'Absensi disimpan offline. Masuk portal membutuhkan koneksi internet.');
                continueScanning();
            }).catch(function () {
                continueScanning();
            });
            return;
        }

        showFeedback('warning', 'Tidak ada koneksi. Masuk portal membutuhkan internet.');
        continueScanning();
    }

    function submitSmart(body, qrCode) {
        postSmart(body).then(function (data) {
            handleSmartResponse(data, qrCode);
        }).catch(function () {
            continueScanning();
            showFeedback('danger', 'Gagal memproses scan. Periksa koneksi lalu coba lagi.');
        });
    }

    function submitScan(code) {
        if (submitted || !code) {
            return;
        }
        submitted = true;
        resetMunawibPick();
        pendingQrCode = code;

        if (!navigator.onLine) {
            submitOffline(code);
            return;
        }

        showFeedback('success', 'Kartu terbaca, memproses…');
        setTimeout(function () {
            submitSmart({
                qr_code: code,
                scan_source: 'camera',
            }, code);
        }, 550);
    }

    function submitMunawibPick() {
        if (submitted || !pendingQrCode || pendingMunawibId <= 0 || !munawibSelect) {
            return;
        }
        var kegiatanId = parseInt(munawibSelect.value, 10);
        if (!kegiatanId) {
            showFeedback('warning', 'Pilih jadwal terlebih dahulu.');
            return;
        }
        submitted = true;
        submitSmart({
            action: 'munawib_pick_schedule',
            qr_code: pendingQrCode,
            kode_qr: pendingQrCode,
            munawib_id: pendingMunawibId,
            kegiatan_id: kegiatanId,
            scan_source: 'camera',
        }, pendingQrCode);
    }

    if (munawibConfirm) {
        munawibConfirm.addEventListener('click', submitMunawibPick);
    }

    scanner = new window.PresensiScanCamera({
        readerId: 'login-scan-reader',
        statusEl: statusEl,
        errorPanel: document.getElementById('login-scan-error'),
        errorText: document.getElementById('login-scan-error-text'),
        btnFlip: document.getElementById('login-scan-flip'),
        btnRestart: document.getElementById('login-scan-restart'),
        btnRetry: document.getElementById('login-scan-retry'),
        btnTorch: document.getElementById('login-scan-torch'),
        onSubmit: submitScan,
    });
    scanner.init();
})();
