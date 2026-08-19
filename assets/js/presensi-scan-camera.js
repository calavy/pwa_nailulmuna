/**
 * Scanner QR presensi — kamera otomatis, ganti kamera, ingat pilihan.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'presensi_scan_camera_id';
    var LIB_WAIT_MS = 12000;

    function labelKamera(device, index, total) {
        var raw = (device && device.label) ? device.label.trim() : '';
        if (/back|rear|environment|belakang|world|wide/i.test(raw)) {
            return 'Kamera belakang';
        }
        if (/front|user|face|selfie|depan/i.test(raw)) {
            return 'Kamera depan';
        }
        if (raw.length > 0) {
            return raw.length > 42 ? raw.slice(0, 40) + '…' : raw;
        }
        if (total <= 1) {
            return 'Kamera';
        }
        return 'Kamera ' + (index + 1);
    }

    function pickPreferredCamera(cameras, savedId) {
        if (!cameras || cameras.length === 0) {
            return null;
        }
        if (savedId) {
            var saved = cameras.find(function (c) { return c.id === savedId; });
            if (saved) {
                return saved;
            }
        }
        var back = cameras.find(function (c) {
            return /back|rear|environment|belakang|world|wide/i.test(c.label || '');
        });
        if (back) {
            return back;
        }
        var notFront = cameras.find(function (c) {
            return !/front|user|face|selfie|depan/i.test(c.label || '');
        });
        return notFront || cameras[0];
    }

    function beepSuccess() {
        try {
            var Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) {
                return;
            }
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.18);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.2);
        } catch (e) {
            /* abaikan */
        }
    }

    function vibrateOk() {
        if (global.navigator && global.navigator.vibrate) {
            global.navigator.vibrate(80);
        }
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            global.setTimeout(resolve, ms);
        });
    }

    function isMobileScanDevice() {
        try {
            return /iPhone|iPad|iPod|Android/i.test(global.navigator.userAgent || '');
        } catch (e) {
            return false;
        }
    }

    function nextPaint() {
        return new Promise(function (resolve) {
            global.requestAnimationFrame(function () {
                global.requestAnimationFrame(resolve);
            });
        });
    }

    async function waitForHtml5Qrcode(timeoutMs) {
        var deadline = Date.now() + timeoutMs;
        while (typeof global.Html5Qrcode === 'undefined') {
            if (Date.now() >= deadline) {
                return false;
            }
            await sleep(40);
        }
        return true;
    }

    async function primeCameraPermission() {
        if (!global.navigator.mediaDevices || !global.navigator.mediaDevices.getUserMedia) {
            return false;
        }
        var constraints = [
            { video: { facingMode: { ideal: 'environment' } }, audio: false },
            { video: true, audio: false },
        ];
        for (var i = 0; i < constraints.length; i++) {
            try {
                var stream = await global.navigator.mediaDevices.getUserMedia(constraints[i]);
                stream.getTracks().forEach(function (track) {
                    track.stop();
                });
                return true;
            } catch (e) {
                /* coba constraint berikutnya */
            }
        }
        return false;
    }

    function secureContextMessage() {
        var host = (global.location && global.location.hostname) ? global.location.hostname : '';
        var msg = 'Kamera hanya bisa dipakai lewat HTTPS atau localhost.';
        if (host && host !== 'localhost' && host !== '127.0.0.1' && host !== '[::1]') {
            msg += ' Alamat saat ini (' + host + ') memakai HTTP — buka lewat https:// atau http://localhost.';
        }
        return msg;
    }

    function formatCameraError(err) {
        var name = err && err.name ? String(err.name) : '';
        var message = err && err.message ? String(err.message) : '';
        var combined = name + ' ' + message;
        if (/overlay|balon|bubble|float(?:ing)?|draw.?over|tapjack|tidak dapat meminta izin|cannot request/i.test(combined)) {
            return 'Kamera diblokir overlay aplikasi lain. Tutup balon chat, filter layar, atau perekam layar, lalu ketuk Coba lagi.';
        }
        if (/NotAllowedError|PermissionDeniedError/i.test(combined)) {
            return 'Akses kamera ditolak. Tutup balon chat, filter layar, atau perekam layar jika ada, izinkan kamera di browser, lalu ketuk Coba lagi.';
        }
        if (/NotFoundError|DevicesNotFoundError/i.test(combined)) {
            return 'Kamera tidak ditemukan di perangkat ini.';
        }
        if (/NotReadableError|TrackStartError|AbortError/i.test(combined)) {
            return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi lain, lalu ketuk Ulangi.';
        }
        if (/OverconstrainedError/i.test(combined)) {
            return 'Pengaturan kamera tidak didukung. Coba ganti kamera atau matikan Super Fokus.';
        }
        return 'Gagal membuka kamera. Izinkan akses kamera di browser, lalu ketuk Ulangi.';
    }

    function buildScanConfig(options) {
        options = options || {};
        var qrbox = options.qrbox;
        if (!qrbox) {
            qrbox = function (vw, vh) {
                var s = Math.min(vw, vh) * 0.78;
                return { width: Math.floor(s), height: Math.floor(s) };
            };
        }
        return {
            fps: options.fps || 12,
            qrbox: qrbox,
            aspectRatio: options.aspectRatio || 1.333334,
            disableFlip: false,
            videoConstraints: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: false,
            },
        };
    }

    async function waitReaderVisibleById(elementId) {
        for (var i = 0; i < 16; i++) {
            var el = document.getElementById(elementId);
            if (el && el.offsetParent !== null && el.offsetHeight > 40) {
                return;
            }
            await nextPaint();
        }
        await sleep(120);
    }

    async function loadCameraList() {
        var list = [];
        try {
            list = await global.Html5Qrcode.getCameras();
        } catch (e) {
            list = [];
        }
        if (!list || list.length === 0) {
            await primeCameraPermission();
            try {
                list = await global.Html5Qrcode.getCameras();
            } catch (e) {
                list = [];
            }
        }
        return list || [];
    }

    async function startScannerOnDevice(qrInstance, config, onSuccess, onError, cameras, preferredCameraId) {
        onError = onError || function () {};
        cameras = cameras || [];

        if (preferredCameraId && preferredCameraId !== 'environment' && preferredCameraId !== 'user') {
            try {
                await qrInstance.start(preferredCameraId, config, onSuccess, onError);
                return preferredCameraId;
            } catch (e) {
                /* lanjut fallback */
            }
        }

        try {
            await qrInstance.start({ facingMode: 'environment' }, config, onSuccess, onError);
            return 'environment';
        } catch (e) {
            /* lanjut fallback */
        }

        try {
            await qrInstance.start({ facingMode: 'user' }, config, onSuccess, onError);
            return 'user';
        } catch (e) {
            /* lanjut fallback */
        }

        for (var i = 0; i < cameras.length; i++) {
            var cam = cameras[i];
            if (!cam || !cam.id) {
                continue;
            }
            if (preferredCameraId && cam.id === preferredCameraId) {
                continue;
            }
            try {
                await qrInstance.start(cam.id, config, onSuccess, onError);
                return cam.id;
            } catch (e) {
                /* coba kamera berikutnya */
            }
        }

        throw new Error('Semua kamera gagal dibuka');
    }

    function PresensiScanCamera(options) {
        this.readerId = options.readerId || 'qr-reader';
        this.onSubmit = options.onSubmit || function () {};
        this.statusEl = options.statusEl || null;
        this.errorPanel = options.errorPanel || null;
        this.errorText = options.errorText || null;
        this.cameraSelect = options.cameraSelect || null;
        this.settingsPanel = options.settingsPanel || null;
        this.btnFlip = options.btnFlip || null;
        this.btnRestart = options.btnRestart || null;
        this.btnSettings = options.btnSettings || null;
        this.btnRetry = options.btnRetry || null;
        this.btnTorch = options.btnTorch || null;
        this.btnSuperFocus = options.btnSuperFocus || null;
        this.startWrap = options.startWrap || null;
        this.startBtn = options.startBtn || null;
        this.deferStartOnMobile = options.deferStartOnMobile !== false;
        this.continuousScan = options.continuousScan !== false;
        this.onCameraReady = options.onCameraReady || null;

        this.qr = null;
        this.scanning = false;
        this.cameras = [];
        this.selectedId = null;
        this.currentIndex = 0;
        this.lastCode = '';
        this.lastSubmittedCode = '';
        this.lastTime = 0;
        this.hitCount = 0;
        this.torchOn = false;
        this.superFocusOn = false;
        this.focusInterval = null;
    }

    PresensiScanCamera.prototype.setStatus = function (state, text) {
        if (!this.statusEl) {
            return;
        }
        this.statusEl.textContent = text;
        this.statusEl.className = 'presensi-scan-status' + (state ? ' ' + state : '');
    };

    PresensiScanCamera.prototype.showError = function (msg) {
        if (this.errorText) {
            this.errorText.textContent = msg;
        }
        if (this.errorPanel) {
            this.errorPanel.classList.remove('d-none');
        }
        this.setStatus('is-error', 'Kamera bermasalah');
    };

    PresensiScanCamera.prototype.hideError = function () {
        if (this.errorPanel) {
            this.errorPanel.classList.add('d-none');
        }
    };

    PresensiScanCamera.prototype.hideStartWrap = function () {
        if (this.startWrap) {
            this.startWrap.classList.add('is-hidden');
        }
    };

    PresensiScanCamera.prototype.showStartWrap = function () {
        if (this.startWrap) {
            this.startWrap.classList.remove('is-hidden');
        }
    };

    PresensiScanCamera.prototype.runStart = async function (cameraId) {
        this.hideStartWrap();
        try {
            await this.start(cameraId);
        } catch (e) {
            if (this.startBtn && this.deferStartOnMobile) {
                this.showStartWrap();
            }
            throw e;
        }
    };

    PresensiScanCamera.prototype.scanConfig = function () {
        return buildScanConfig({});
    };

    PresensiScanCamera.prototype.onScanSuccess = function (decodedText) {
        var now = Date.now();
        if (decodedText === this.lastCode) {
            this.hitCount += 1;
        } else {
            this.hitCount = 1;
            this.lastCode = decodedText;
        }
        if (this.hitCount < 2) {
            return;
        }
        if (decodedText === this.lastSubmittedCode && now - this.lastTime < 2500) {
            return;
        }
        this.lastTime = now;
        this.lastSubmittedCode = decodedText;
        if (global.PresensiScanFeedback && global.PresensiScanFeedback.scanTick) {
            global.PresensiScanFeedback.scanTick();
        } else {
            beepSuccess();
        }
        vibrateOk();
        var self = this;
        var result;
        try {
            result = this.onSubmit(decodedText);
        } catch (e) {
            result = null;
        }
        function afterSubmit() {
            if (!self.continuousScan) {
                return;
            }
            self.resetScanState();
        }
        if (result && typeof result.then === 'function') {
            result.then(afterSubmit).catch(afterSubmit);
        } else {
            afterSubmit();
        }
    };

    PresensiScanCamera.prototype.resetScanState = function () {
        this.lastCode = '';
        this.hitCount = 0;
    };

    PresensiScanCamera.prototype.resumeScanning = async function () {
        this.resetScanState();
        if (this.scanning) {
            return;
        }
        this.hideError();
        await this.runStart(this.selectedId);
    };

    PresensiScanCamera.prototype.detectTorch = async function () {
        if (!this.btnTorch) {
            return;
        }
        try {
            var video = document.querySelector('#' + this.readerId + ' video');
            if (!video || !video.srcObject) {
                return;
            }
            var track = video.srcObject.getVideoTracks()[0];
            if (!track) {
                return;
            }
            var caps = track.getCapabilities ? track.getCapabilities() : {};
            if (caps.torch) {
                this.btnTorch.style.display = '';
                this.btnTorch.disabled = false;
                if (this.torchOn) {
                    await track.applyConstraints({ advanced: [{ torch: true }] });
                }
            } else {
                this.btnTorch.style.display = 'none';
                this.torchOn = false;
                this.btnTorch.classList.remove('is-active');
            }
        } catch (e) {
            /* biarkan tombol flash tetap ada; perangkat tanpa torch akan disembunyikan di percobaan berikutnya */
        }
        if (typeof this.onCameraReady === 'function') {
            try {
                this.onCameraReady();
            } catch (err) { /* abaikan */ }
        }
    };

    PresensiScanCamera.prototype.waitReaderVisible = async function () {
        return waitReaderVisibleById(this.readerId);
    };

    PresensiScanCamera.prototype.ensureQrInstance = function () {
        if (!this.qr) {
            this.qr = new global.Html5Qrcode(this.readerId);
        }
    };

    PresensiScanCamera.prototype.stop = async function () {
        if (this.focusInterval) {
            global.clearInterval(this.focusInterval);
            this.focusInterval = null;
        }
        this.setTorch(false);
        if (!this.qr) {
            this.scanning = false;
            return;
        }
        if (this.scanning) {
            try {
                await this.qr.stop();
            } catch (e) {
                /* abaikan */
            }
        }
        try {
            await this.qr.clear();
        } catch (e) {
            /* abaikan */
        }
        this.scanning = false;
    };

    PresensiScanCamera.prototype.resetQrInstance = async function () {
        await this.stop();
        this.qr = null;
    };

    PresensiScanCamera.prototype.applyFocusConstraints = async function () {
        try {
            var video = document.querySelector('#' + this.readerId + ' video');
            if (!video || !video.srcObject) { return; }
            var track = video.srcObject.getVideoTracks()[0];
            if (!track || !track.getCapabilities || !this.superFocusOn) { return; }
            var caps = track.getCapabilities() || {};
            var adv = {};
            if (Array.isArray(caps.focusMode) && caps.focusMode.indexOf('continuous') !== -1) {
                adv.focusMode = 'continuous';
            }
            if (Object.keys(adv).length > 0) {
                await track.applyConstraints({ advanced: [adv] });
            }
        } catch (e) {
            /* abaikan */
        }
    };

    PresensiScanCamera.prototype.toggleSuperFocus = function () {
        this.superFocusOn = !this.superFocusOn;
        if (this.btnSuperFocus) {
            this.btnSuperFocus.classList.toggle('is-active', this.superFocusOn);
        }
        if (this.superFocusOn) {
            this.applyFocusConstraints();
        }
    };

    PresensiScanCamera.prototype.rememberCameraId = function (cameraId) {
        if (typeof cameraId !== 'string' || cameraId === '' || cameraId === 'environment' || cameraId === 'user') {
            return;
        }
        try {
            global.localStorage.setItem(STORAGE_KEY, cameraId);
        } catch (e) {
            /* abaikan */
        }
    };

    PresensiScanCamera.prototype.startScannerDevice = async function (preferredCameraId) {
        var self = this;
        return startScannerOnDevice(
            self.qr,
            self.scanConfig(),
            function (text) { self.onScanSuccess(text); },
            function () {},
            self.cameras,
            preferredCameraId
        );
    };

    PresensiScanCamera.prototype.start = async function (cameraId) {
        var self = this;
        if (!global.isSecureContext) {
            self.showError(secureContextMessage());
            return;
        }
        if (typeof global.Html5Qrcode === 'undefined') {
            self.showError('Pustaka scanner belum siap. Muat ulang halaman.');
            return;
        }

        await self.stop();
        await self.waitReaderVisible();
        self.ensureQrInstance();
        self.setStatus('is-waiting', 'Menyiapkan kamera…');

        var preferredId = cameraId || self.selectedId || null;
        try {
            var usedId = await self.startScannerDevice(preferredId);
            self.scanning = true;
            self.hideError();
            self.setStatus('', 'Memindai QR…');
            if (typeof usedId === 'string' && usedId !== 'environment' && usedId !== 'user') {
                self.selectedId = usedId;
                self.currentIndex = Math.max(0, self.cameras.findIndex(function (c) { return c.id === usedId; }));
                self.rememberCameraId(usedId);
                if (self.cameraSelect) {
                    self.cameraSelect.value = usedId;
                }
            }
            if (self.btnSuperFocus) {
                self.btnSuperFocus.classList.toggle('is-active', self.superFocusOn);
            }
            await self.applyFocusConstraints();
            if (self.focusInterval) {
                global.clearInterval(self.focusInterval);
            }
            if (self.superFocusOn) {
                self.focusInterval = global.setInterval(function () {
                    self.applyFocusConstraints();
                }, 4500);
            }
        } catch (err) {
            try {
                await self.resetQrInstance();
                await self.waitReaderVisible();
                self.ensureQrInstance();
                var usedIdRetry = await self.startScannerDevice(null);
                self.scanning = true;
                self.hideError();
                self.setStatus('', 'Memindai QR…');
                if (typeof usedIdRetry === 'string' && usedIdRetry !== 'environment' && usedIdRetry !== 'user') {
                    self.selectedId = usedIdRetry;
                    self.rememberCameraId(usedIdRetry);
                }
            } catch (err2) {
                self.showError(formatCameraError(err2 || err));
                self.setStatus('is-error', 'Gagal');
                throw err2 || err;
            }
        }
        if (self.scanning) {
            await self.detectTorch();
            global.setTimeout(function () {
                self.detectTorch();
            }, 700);
        }
    };

    PresensiScanCamera.prototype.restart = async function () {
        this.hideError();
        try {
            await this.prepareCameras();
            await this.runStart(this.selectedId);
        } catch (e) {
            if (this.startBtn && this.deferStartOnMobile) {
                this.showStartWrap();
            }
        }
    };

    PresensiScanCamera.prototype.fillCameraSelect = function () {
        var self = this;
        if (!self.cameraSelect) {
            return;
        }
        self.cameraSelect.innerHTML = '';
        self.cameras.forEach(function (cam, i) {
            var opt = document.createElement('option');
            opt.value = cam.id;
            opt.textContent = labelKamera(cam, i, self.cameras.length);
            if (cam.id === self.selectedId) {
                opt.selected = true;
            }
            self.cameraSelect.appendChild(opt);
        });
        self.cameraSelect.disabled = self.cameras.length < 2;
    };

    PresensiScanCamera.prototype.switchToIndex = async function (index) {
        if (!this.cameras.length) {
            return;
        }
        this.currentIndex = ((index % this.cameras.length) + this.cameras.length) % this.cameras.length;
        this.selectedId = this.cameras[this.currentIndex].id;
        if (this.cameraSelect) {
            this.cameraSelect.value = this.selectedId;
        }
        await this.start(this.selectedId);
    };

    PresensiScanCamera.prototype.flipCamera = async function () {
        if (this.cameras.length < 2) {
            return;
        }
        await this.switchToIndex(this.currentIndex + 1);
    };

    PresensiScanCamera.prototype.setTorch = async function (on) {
        this.torchOn = !!on;
        if (!this.btnTorch) {
            return;
        }
        this.btnTorch.classList.toggle('is-active', this.torchOn);
        try {
            var video = document.querySelector('#' + this.readerId + ' video');
            if (!video || !video.srcObject) {
                return;
            }
            var track = video.srcObject.getVideoTracks()[0];
            if (!track) {
                return;
            }
            var caps = track.getCapabilities ? track.getCapabilities() : {};
            if (!caps.torch) {
                this.btnTorch.style.display = 'none';
                return;
            }
            this.btnTorch.style.display = '';
            await track.applyConstraints({ advanced: [{ torch: this.torchOn }] });
        } catch (e) {
            this.btnTorch.style.display = 'none';
        }
    };

    PresensiScanCamera.prototype.toggleTorch = function () {
        this.setTorch(!this.torchOn);
    };

    PresensiScanCamera.prototype.toggleSettings = function () {
        if (!this.settingsPanel) {
            return;
        }
        this.settingsPanel.classList.toggle('is-open');
        if (this.btnSettings) {
            this.btnSettings.classList.toggle('is-active', this.settingsPanel.classList.contains('is-open'));
        }
    };

    PresensiScanCamera.prototype.loadCameras = async function () {
        this.cameras = await loadCameraList();
    };

    /**
     * Izin + daftar kamera. Di HP dipanggil setelah ketuk Mulai scan, bukan saat halaman terbuka.
     *
     * @param {boolean} [force]
     */
    PresensiScanCamera.prototype.prepareCameras = async function (force) {
        var self = this;
        if (!force && self.cameras && self.cameras.length > 0 && self.selectedId) {
            return;
        }

        await primeCameraPermission();
        await self.loadCameras();

        if (!self.cameras || self.cameras.length === 0) {
            self.showError('Tidak ada kamera terdeteksi. Pastikan perangkat punya kamera dan izin sudah diizinkan.');
            throw new Error('Tidak ada kamera terdeteksi');
        }

        var savedId = null;
        try {
            savedId = global.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            /* abaikan */
        }
        if (savedId && !self.cameras.some(function (c) { return c.id === savedId; })) {
            savedId = null;
            try {
                global.localStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                /* abaikan */
            }
        }

        var preferred = pickPreferredCamera(self.cameras, savedId);
        self.selectedId = preferred ? preferred.id : self.cameras[0].id;
        self.currentIndex = Math.max(0, self.cameras.findIndex(function (c) { return c.id === self.selectedId; }));

        self.fillCameraSelect();

        if (self.btnFlip) {
            self.btnFlip.disabled = self.cameras.length < 2;
            self.btnFlip.style.opacity = self.cameras.length < 2 ? '0.45' : '1';
        }
    };

    PresensiScanCamera.prototype.bindStartBtn = function () {
        var self = this;
        if (!self.startBtn || self._startBtnBound) {
            return;
        }
        self._startBtnBound = true;
        self.startBtn.addEventListener('click', function () {
            self.prepareCameras().then(function () {
                return self.runStart(null);
            }).catch(function () {
                if (self.startBtn && self.deferStartOnMobile) {
                    self.showStartWrap();
                }
            });
        });
    };

    PresensiScanCamera.prototype.init = async function () {
        var self = this;

        if (self.btnFlip) {
            self.btnFlip.addEventListener('click', function () {
                self.flipCamera();
            });
        }
        if (self.btnRestart) {
            self.btnRestart.addEventListener('click', function () {
                self.restart();
            });
        }
        if (self.btnRetry) {
            self.btnRetry.addEventListener('click', function () {
                self.restart();
            });
        }
        if (self.btnSettings) {
            self.btnSettings.addEventListener('click', function () {
                self.toggleSettings();
            });
        }
        if (self.cameraSelect) {
            self.cameraSelect.addEventListener('change', function () {
                var id = self.cameraSelect.value;
                var idx = self.cameras.findIndex(function (c) { return c.id === id; });
                if (idx >= 0) {
                    self.switchToIndex(idx);
                }
            });
        }
        if (self.btnTorch) {
            self.btnTorch.addEventListener('click', function () {
                self.toggleTorch();
            });
        }
        if (self.btnSuperFocus) {
            self.btnSuperFocus.addEventListener('click', function () {
                self.toggleSuperFocus();
            });
            self.btnSuperFocus.classList.toggle('is-active', self.superFocusOn);
        }

        self.setStatus('is-waiting', 'Menyiapkan kamera…');

        var libReady = await waitForHtml5Qrcode(LIB_WAIT_MS);
        if (!libReady || typeof global.Html5Qrcode === 'undefined') {
            self.showError('Pustaka scanner gagal dimuat. Periksa koneksi atau muat ulang halaman.');
            return;
        }

        if (!global.isSecureContext) {
            self.showError(secureContextMessage());
            return;
        }

        await self.waitReaderVisible();

        global.addEventListener('beforeunload', function () {
            if (self.qr) {
                self.qr.stop().catch(function () {});
            }
        });

        if (self.deferStartOnMobile && isMobileScanDevice() && self.startBtn) {
            self.showStartWrap();
            self.setStatus('is-waiting', 'Ketuk Mulai scan');
            self.bindStartBtn();
            return;
        }

        try {
            await self.prepareCameras();
            await self.runStart(null);
        } catch (e) {
            /* error sudah ditampilkan */
        }
    };

    /**
     * Jalankan boot scanner; di HP menunggu tap tombol mulai (izin kamera lebih lancar).
     *
     * @param {{startWrap?:HTMLElement,startBtn?:HTMLElement,run:Function}} options
     */
    function runWithMobileStartGate(options) {
        options = options || {};
        var wrap = options.startWrap || null;
        var btn = options.startBtn || null;
        var run = options.run;
        if (typeof run !== 'function') {
            return Promise.resolve();
        }
        function hideWrap() {
            if (wrap) {
                wrap.classList.add('is-hidden');
            }
        }
        function showWrap() {
            if (wrap) {
                wrap.classList.remove('is-hidden');
            }
        }
        if (isMobileScanDevice() && btn) {
            showWrap();
            return new Promise(function (resolve, reject) {
                function onClick() {
                    btn.removeEventListener('click', onClick);
                    hideWrap();
                    Promise.resolve(run()).then(resolve).catch(function (err) {
                        showWrap();
                        reject(err);
                    });
                }
                btn.addEventListener('click', onClick);
            });
        }
        hideWrap();
        return Promise.resolve(run());
    }

    global.PresensiScanCamera = PresensiScanCamera;
    global.PresensiScanCamera.isMobileDevice = isMobileScanDevice;
    global.PresensiScanCamera.runWithMobileStartGate = runWithMobileStartGate;
    global.PresensiScanCamera.pickPreferredCamera = pickPreferredCamera;
    global.PresensiScanCamera.labelKamera = labelKamera;
    global.PresensiScanCamera.waitForLibrary = function () {
        return waitForHtml5Qrcode(LIB_WAIT_MS);
    };
    global.PresensiScanCamera.primePermission = primeCameraPermission;
    global.PresensiScanCamera.secureContextMsg = secureContextMessage;
    global.PresensiScanCamera.formatError = formatCameraError;
    global.PresensiScanCamera.buildScanConfig = buildScanConfig;
    global.PresensiScanCamera.waitReaderVisible = waitReaderVisibleById;
    global.PresensiScanCamera.loadCameraList = loadCameraList;
    global.PresensiScanCamera.startDevice = startScannerOnDevice;
})(window);
