/**
 * Scanner QR presensi — kamera otomatis, ganti kamera, ingat pilihan.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'presensi_scan_camera_id';

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

        this.qr = null;
        this.scanning = false;
        this.cameras = [];
        this.selectedId = null;
        this.currentIndex = 0;
        this.lastCode = '';
        this.lastTime = 0;
        this.hitCount = 0;
        this.torchOn = false;
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

    PresensiScanCamera.prototype.scanConfig = function () {
        return {
            fps: 15,
            qrbox: function (vw, vh) {
                var s = Math.min(vw, vh) * 0.78;
                return { width: Math.floor(s), height: Math.floor(s) };
            },
            aspectRatio: 1.0,
            disableFlip: false,
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true,
            },
        };
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
        if (decodedText === this.lastCode && now - this.lastTime < 2500) {
            return;
        }
        this.lastTime = now;
        if (global.PresensiScanFeedback && global.PresensiScanFeedback.scanTick) {
            global.PresensiScanFeedback.scanTick();
        } else {
            beepSuccess();
        }
        vibrateOk();
        this.onSubmit(decodedText);
    };

    PresensiScanCamera.prototype.stop = async function () {
        if (!this.scanning || !this.qr) {
            return;
        }
        try {
            await this.qr.stop();
        } catch (e) {
            /* abaikan */
        }
        this.scanning = false;
        this.setTorch(false);
    };

    PresensiScanCamera.prototype.start = async function (cameraId) {
        var self = this;
        if (self.scanning) {
            await self.stop();
        }
        if (!global.isSecureContext) {
            self.showError('Kamera butuh HTTPS atau localhost.');
            return;
        }
        if (!self.qr) {
            self.qr = new global.Html5Qrcode(self.readerId);
        }
        var deviceId = cameraId || self.selectedId || { facingMode: 'environment' };
        try {
            await self.qr.start(
                deviceId,
                self.scanConfig(),
                function (text) { self.onScanSuccess(text); }
            );
            self.scanning = true;
            self.hideError();
            self.setStatus('', 'Memindai QR…');
            if (typeof deviceId === 'string' && deviceId.indexOf('facingMode') === -1) {
                try {
                    global.localStorage.setItem(STORAGE_KEY, deviceId);
                } catch (e) {
                    /* abaikan */
                }
            }
        } catch (err) {
            if (!cameraId && self.cameras.length > 0) {
                var cam = pickPreferredCamera(self.cameras, null);
                if (cam) {
                    self.selectedId = cam.id;
                    return self.start(cam.id);
                }
            }
            self.showError('Gagal membuka kamera. Izinkan akses kamera di browser, lalu ketuk Ulangi.');
            self.setStatus('is-error', 'Gagal');
            throw err;
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

    PresensiScanCamera.prototype.init = async function () {
        var self = this;
        if (typeof global.Html5Qrcode === 'undefined') {
            self.showError('Pustaka scanner gagal dimuat. Periksa koneksi internet.');
            return;
        }

        self.setStatus('is-waiting', 'Menyiapkan kamera…');

        if (self.btnFlip) {
            self.btnFlip.addEventListener('click', function () {
                self.flipCamera();
            });
        }
        if (self.btnRestart) {
            self.btnRestart.addEventListener('click', function () {
                self.hideError();
                self.start(self.selectedId);
            });
        }
        if (self.btnRetry) {
            self.btnRetry.addEventListener('click', function () {
                self.hideError();
                self.start(self.selectedId);
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

        try {
            self.cameras = await global.Html5Qrcode.getCameras();
        } catch (e) {
            self.showError('Tidak bisa mengakses daftar kamera. Izinkan kamera di pengaturan browser.');
            return;
        }

        if (!self.cameras || self.cameras.length === 0) {
            self.showError('Tidak ada kamera di perangkat ini.');
            return;
        }

        var savedId = null;
        try {
            savedId = global.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            /* abaikan */
        }

        var preferred = pickPreferredCamera(self.cameras, savedId);
        self.selectedId = preferred.id;
        self.currentIndex = Math.max(0, self.cameras.findIndex(function (c) { return c.id === self.selectedId; }));

        self.fillCameraSelect();

        if (self.btnFlip) {
            self.btnFlip.disabled = self.cameras.length < 2;
            self.btnFlip.style.opacity = self.cameras.length < 2 ? '0.45' : '1';
        }

        await self.start(self.selectedId);

        global.addEventListener('beforeunload', function () {
            if (self.scanning && self.qr) {
                self.qr.stop().catch(function () {});
            }
        });
    };

    global.PresensiScanCamera = PresensiScanCamera;
    global.PresensiScanCamera.pickPreferredCamera = pickPreferredCamera;
    global.PresensiScanCamera.labelKamera = labelKamera;
})(window);
