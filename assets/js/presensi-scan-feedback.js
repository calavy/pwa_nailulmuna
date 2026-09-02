/**
 * Umpan balik scan presensi: suara + text-to-speech + overlay notifikasi.
 */
(function (global) {
    'use strict';

    var audioCtx = null;
    var voicesReady = false;

    function getAudioContext() {
        if (!audioCtx) {
            var Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) {
                return null;
            }
            audioCtx = new Ctx();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume().catch(function () {});
        }
        return audioCtx;
    }

    function tone(freq, start, duration, volume, type) {
        var ctx = getAudioContext();
        if (!ctx) {
            return;
        }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = type || 'sine';
        osc.frequency.setValueAtTime(freq, start);
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(volume, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(start);
        osc.stop(start + duration + 0.05);
    }

    function vibrate(pattern) {
        if (global.navigator && global.navigator.vibrate) {
            global.navigator.vibrate(pattern);
        }
    }

    function hasSpeech() {
        return 'speechSynthesis' in global && 'SpeechSynthesisUtterance' in global;
    }

    function pickIndonesianVoice() {
        if (!hasSpeech()) {
            return null;
        }
        var voices = global.speechSynthesis.getVoices() || [];
        if (!voices.length) {
            return null;
        }
        var id = voices.find(function (v) {
            return /^id(-|_)/i.test(v.lang || '');
        });
        if (id) {
            return id;
        }
        return voices.find(function (v) {
            return /indonesia|indonesian|bahasa/i.test(v.name || '');
        }) || voices[0];
    }

    function loadVoices(callback) {
        if (!hasSpeech()) {
            callback();
            return;
        }
        if (voicesReady && global.speechSynthesis.getVoices().length) {
            callback();
            return;
        }
        var done = false;
        function finish() {
            if (done) {
                return;
            }
            done = true;
            voicesReady = true;
            callback();
        }
        global.speechSynthesis.addEventListener('voiceschanged', finish, { once: true });
        setTimeout(finish, 400);
        if (global.speechSynthesis.getVoices().length) {
            finish();
        }
    }

    /**
     * Text-to-speech — suara orang membacakan teks (bahasa Indonesia jika tersedia).
     * @returns {boolean} true jika TTS dipanggil
     */
    function speakText(text) {
        if (!hasSpeech() || !text || !String(text).trim()) {
            return false;
        }
        try {
            global.speechSynthesis.cancel();
            var u = new global.SpeechSynthesisUtterance(String(text).trim());
            u.lang = 'id-ID';
            u.rate = 1.02;
            u.pitch = 1;
            u.volume = 1;
            var voice = pickIndonesianVoice();
            if (voice) {
                u.voice = voice;
            }
            global.speechSynthesis.speak(u);
            return true;
        } catch (e) {
            return false;
        }
    }

    /** Ringkas pesan server agar natural dibacakan TTS. */
    function textForSpeech(raw, type) {
        var s = String(raw || '')
            .replace(/[—–]/g, ', ')
            .replace(/\s+/g, ' ')
            .trim();
        if (!s) {
            return '';
        }

        if (type === 'success') {
            var santri = s.match(/Santri hadir:\s*([^(.,]+)/i);
            if (santri) {
                return 'Berhasil. Santri hadir, ' + santri[1].trim();
            }
            var pemb = s.match(/Pembimbing hadir:\s*([^.,]+)/i);
            if (pemb) {
                return 'Berhasil. Pembimbing hadir, ' + pemb[1].trim();
            }
            return 'Berhasil. ' + s.slice(0, 100);
        }

        if (type === 'warning') {
            if (/sudah tercatat|sudah scan|Scan ditolak|sudah diwakili/i.test(s)) {
                return 'Perhatian. Presensi sudah tercatat sebelumnya.';
            }
            if (/tidak terdaftar/i.test(s)) {
                return 'Gagal. Kode QR tidak terdaftar.';
            }
            if (/tidak aktif|sudah keluar/i.test(s)) {
                return 'Gagal. Santri tidak aktif, presensi tidak dicatat.';
            }
            if (/hari libur/i.test(s)) {
                return 'Gagal. Hari libur, presensi tidak dicatat.';
            }
            if (/luar jadwal|tidak ada kegiatan/i.test(s)) {
                return 'Gagal. Scan di luar jadwal kegiatan.';
            }
            return 'Perhatian. ' + s.slice(0, 110);
        }

        if (type === 'danger') {
            if (/tidak terdaftar/i.test(s)) {
                return 'Gagal. Kode QR tidak terdaftar.';
            }
            if (/luar jadwal|tidak ada kegiatan/i.test(s)) {
                return 'Gagal. Scan di luar jadwal atau tidak ada kegiatan aktif.';
            }
            if (/hari libur/i.test(s)) {
                return 'Gagal. Hari libur, presensi tidak dicatat.';
            }
            return 'Gagal. ' + s.slice(0, 110);
        }

        if (type === 'duplicate') {
            return 'Sudah tercatat. ' + s.slice(0, 100);
        }

        if (type === 'info') {
            if (/Munawib terdeteksi/i.test(s)) {
                return 'Munawib terdeteksi. Silakan pilih jadwal kegiatan.';
            }
            if (/Pilih jadwal/i.test(s)) {
                return 'Silakan pilih jadwal kegiatan.';
            }
            return s.slice(0, 110);
        }

        return s.slice(0, 120);
    }

    function speakSoon(speechText) {
        if (!speechText) {
            return;
        }
        setTimeout(function () {
            loadVoices(function () {
                speakText(speechText);
            });
        }, 60);
    }

    function runWhenUnlocked(fn) {
        var ctx = getAudioContext();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().then(fn).catch(function () {
                waitForTouch(fn);
            });
            return;
        }
        if (hasSpeech()) {
            fn();
            return;
        }
        if (ctx) {
            fn();
            return;
        }
        waitForTouch(fn);
    }

    function waitForTouch(fn) {
        var once = function () {
            fn();
            document.removeEventListener('touchstart', once);
            document.removeEventListener('click', once);
        };
        document.addEventListener('touchstart', once, { once: true, passive: true });
        document.addEventListener('click', once, { once: true });
    }

    /** Diputar saat QR terbaca (sebelum kirim ke server) — bip singkat saja. */
    function playScanTick() {
        var ctx = getAudioContext();
        if (ctx) {
            tone(1200, ctx.currentTime, 0.06, 0.12, 'sine');
        }
    }

    function playBeepSuccess() {
        var ctx = getAudioContext();
        if (!ctx) {
            return;
        }
        var t = ctx.currentTime;
        tone(523.25, t, 0.12, 0.2, 'sine');
        tone(659.25, t + 0.1, 0.12, 0.2, 'sine');
        vibrate([40, 30, 60]);
    }

    function playBeepWarning() {
        var ctx = getAudioContext();
        if (!ctx) {
            return;
        }
        var t = ctx.currentTime;
        tone(440, t, 0.14, 0.16, 'triangle');
        tone(330, t + 0.16, 0.18, 0.16, 'triangle');
        vibrate([80, 40, 80]);
    }

    function playBeepDanger() {
        var ctx = getAudioContext();
        if (!ctx) {
            return;
        }
        var t = ctx.currentTime;
        tone(220, t, 0.2, 0.22, 'sawtooth');
        tone(180, t + 0.22, 0.24, 0.2, 'sawtooth');
        vibrate([120, 60, 120, 60, 120]);
    }

    function playBeepDuplicate() {
        var ctx = getAudioContext();
        if (!ctx) {
            return;
        }
        var t = ctx.currentTime;
        tone(587.33, t, 0.1, 0.14, 'sine');
        tone(523.25, t + 0.12, 0.12, 0.14, 'sine');
        vibrate([50, 30, 50]);
    }

    function playBeepInfo() {
        var ctx = getAudioContext();
        if (!ctx) {
            return;
        }
        var t = ctx.currentTime;
        tone(392, t, 0.1, 0.14, 'sine');
        tone(494, t + 0.11, 0.12, 0.14, 'sine');
        vibrate([35, 25, 35]);
    }

    /** Suara sukses: TTS pesan hasil, fallback bip jika TTS tidak ada. */
    function playSuccess(message) {
        playBeepSuccess();
        var speech = textForSpeech(message, 'success');
        speakSoon(speech);
    }

    function playWarning(message) {
        playBeepWarning();
        var speech = textForSpeech(message, 'warning');
        speakSoon(speech);
    }

    function playDanger(message) {
        playBeepDanger();
        var speech = textForSpeech(message, 'danger');
        speakSoon(speech);
    }

    function playDuplicate(message) {
        playBeepDuplicate();
        speakSoon('Stop. Anda sudah scan.');
    }

    function playInfo(message) {
        playBeepInfo();
        var speech = textForSpeech(message, 'info');
        speakSoon(speech);
    }

    function normalizeType(type, message) {
        var t = String(type || 'success');
        if (t === 'warning') {
            if (/sudah tercatat|sudah scan|Scan ditolak|sudah diwakili|pembimbing asli sudah|Kegiatan ini sudah|sudah scan pada jadwal/i.test(message || '')) {
                return 'duplicate';
            }
            if (/Munawib terdeteksi|Pilih jadwal/i.test(message || '')) {
                return 'info';
            }
            return 'danger';
        }
        if (t === 'error') {
            return 'danger';
        }
        if (t === 'info') {
            return 'info';
        }
        return t;
    }

    function flashClassForType(type) {
        if (type === 'success') {
            return 'presensi-scan-flash-success';
        }
        if (type === 'duplicate') {
            return 'presensi-scan-flash-duplicate';
        }
        if (type === 'danger') {
            return 'presensi-scan-flash-danger';
        }
        if (type === 'info') {
            return 'presensi-scan-flash-info';
        }
        return 'presensi-scan-flash-warning';
    }

    function playFeedback(type, message) {
        type = normalizeType(type, message);
        if (type === 'success') {
            playSuccess(message);
        } else if (type === 'duplicate') {
            playDuplicate(message);
        } else if (type === 'danger') {
            playDanger(message);
        } else if (type === 'info') {
            playInfo(message);
        } else {
            playWarning(message);
        }
        flashViewport(flashClassForType(type));
    }

    function flashViewport(className) {
        var vp = document.querySelector('.presensi-scan-viewport:not(.d-none)') ||
            document.querySelector('.cashless-viewport') ||
            document.querySelector('.login-pb-qr__viewport');
        if (!vp) {
            return;
        }
        vp.classList.remove(
            'presensi-scan-flash-success',
            'presensi-scan-flash-warning',
            'presensi-scan-flash-danger',
            'presensi-scan-flash-duplicate',
            'presensi-scan-flash-info'
        );
        void vp.offsetWidth;
        vp.classList.add(className);
        setTimeout(function () {
            vp.classList.remove(className);
        }, 700);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setFlashMessage(message, type) {
        var el = document.getElementById('presensi-scan-flash');
        if (!el) {
            return;
        }
        global.clearTimeout(el._hideTimer);
        var text = String(message || '').trim();
        if (!text) {
            el.textContent = '';
            el.className = 'presensi-scan-flash is-empty';
            return;
        }
        type = normalizeType(type, text);
        var tone = type;
        if (tone === 'danger') {
            tone = 'error';
        }
        el.textContent = text;
        el.className = 'presensi-scan-flash is-' + tone;
        var duration = type === 'success' ? 5500 : (type === 'info' ? 6000 : 6500);
        el._hideTimer = global.setTimeout(function () {
            setFlashMessage('', '');
        }, duration);
    }

    function handleSessionFlash() {
        var el = document.getElementById('presensi-scan-flash');
        if (!el || el.classList.contains('is-empty')) {
            return;
        }
        var msg = (el.textContent || '').trim();
        if (!msg) {
            return;
        }
        var type = 'info';
        if (el.classList.contains('is-success')) {
            type = 'success';
        } else if (el.classList.contains('is-error') || el.classList.contains('is-danger')) {
            type = 'error';
        } else if (el.classList.contains('is-warning')) {
            type = 'warning';
        }
        setFlashMessage(msg, type);
    }

    function handlePageResult() {
        var el = document.getElementById('presensi-scan-result');
        if (!el) {
            return;
        }
        var type = el.getAttribute('data-type') || 'success';
        var speakAttr = el.getAttribute('data-speak');
        var textEl = el.querySelector('.presensi-scan-result-text');
        var message = speakAttr || (textEl ? textEl.textContent : '');

        var normalized = normalizeType(type, message);
        var extra = {
            photoUrl: (el.getAttribute('data-foto') || '').trim(),
            nama: (el.getAttribute('data-nama') || '').trim(),
        };
        runWhenUnlocked(function () {
            playFeedback(type, message);
        });
        showOverlayResult(normalized, message, extra);
        setBanner(normalized, message);
        setFlashMessage(message, normalized);
    }

    function overlayMeta(type) {
        var toneClass = 'presensi-scan-result--' + type;
        var icon = 'fa-circle-info';
        if (type === 'success') {
            icon = 'fa-circle-check';
        } else if (type === 'duplicate') {
            icon = 'fa-ban';
        } else if (type === 'danger') {
            icon = 'fa-circle-xmark';
        } else if (type === 'info') {
            icon = 'fa-list-check';
        } else if (type === 'warning') {
            icon = 'fa-triangle-exclamation';
        }
        return { toneClass: toneClass, icon: icon };
    }

    function shortDisplayMessage(type, message) {
        type = normalizeType(type, message);
        var s = String(message || '').trim();
        if (type === 'success') {
            return 'Berhasil';
        }
        if (type === 'duplicate') {
            return 'Anda sudah scan';
        }
        if (type === 'danger') {
            if (/luar jadwal|tidak ada kegiatan/i.test(s)) {
                return 'Di luar jadwal';
            }
            if (/hari libur/i.test(s)) {
                return 'Hari libur';
            }
            if (/tidak terdaftar/i.test(s)) {
                return 'QR tidak terdaftar';
            }
            if (/tidak aktif|sudah keluar/i.test(s)) {
                return 'Santri tidak aktif';
            }
            return 'Scan ditolak';
        }
        if (type === 'info') {
            if (/Munawib/i.test(s)) {
                return 'Pilih jadwal munawib';
            }
        }
        return s.slice(0, 120);
    }

    function bannerIconForType(type) {
        if (type === 'success') {
            return 'fa-circle-check';
        }
        if (type === 'duplicate') {
            return 'fa-ban';
        }
        if (type === 'danger') {
            return 'fa-circle-xmark';
        }
        if (type === 'info') {
            return 'fa-circle-info';
        }
        return 'fa-triangle-exclamation';
    }

    function setBanner(type, message) {
        type = normalizeType(type, message);
        var host = document.getElementById('presensi-scan-banner-host');
        if (!host) {
            host = document.createElement('div');
            host.id = 'presensi-scan-banner-host';
            var header = document.querySelector('.presensi-scan-top') ||
                document.querySelector('.cashless-scan-top');
            if (header && header.parentNode) {
                header.parentNode.insertBefore(host, header.nextSibling);
            } else {
                document.body.appendChild(host);
            }
        }
        host.hidden = false;
        host.innerHTML = ''
            + '<div class="presensi-scan-banner presensi-scan-banner--' + type + '" role="alert" aria-live="assertive">'
            + '  <i class="fa-solid ' + bannerIconForType(type) + '" aria-hidden="true"></i>'
            + '  <span>' + escapeHtml(shortDisplayMessage(type, message)) + '</span>'
            + '</div>';
        global.clearTimeout(host._hideTimer);
        host._hideTimer = global.setTimeout(function () {
            host.innerHTML = '';
            host.hidden = true;
        }, type === 'success' ? 5500 : 6500);
    }

    function overlayExtra(extra) {
        extra = extra && typeof extra === 'object' ? extra : {};
        return {
            photoUrl: String(extra.photoUrl || extra.foto_url || '').trim(),
            nama: String(extra.nama || extra.nama_santri || '').trim(),
        };
    }

    function overlayVisualHtml(type, extra, meta) {
        var showPhoto = extra.photoUrl && (type === 'success' || type === 'duplicate');
        if (showPhoto) {
            return '<img class="presensi-scan-result-photo" src="' + escapeHtml(extra.photoUrl)
                + '" alt="" width="96" height="96" onerror="this.style.display=\'none\'">';
        }
        return '<span class="presensi-scan-result-icon"><i class="fa-solid ' + meta.icon + '"></i></span>';
    }

    function showOverlayResult(type, message, extra) {
        extra = overlayExtra(extra);
        type = normalizeType(type, message);
        var old = document.getElementById('presensi-result-overlay');
        if (old && old.parentNode) {
            old.parentNode.removeChild(old);
        }
        var meta = overlayMeta(type);
        var wrap = document.createElement('div');
        wrap.id = 'presensi-result-overlay';
        wrap.className = 'presensi-scan-result is-visible';
        wrap.setAttribute('aria-live', 'assertive');
        wrap.style.pointerEvents = 'none';
        var duration = type === 'success' ? 3500 : (type === 'duplicate' ? 3500 : (type === 'info' ? 3800 : 4200));
        var displayMessage = shortDisplayMessage(type, message);
        var cardClass = 'presensi-scan-result-card ' + meta.toneClass + (extra.photoUrl && (type === 'success' || type === 'duplicate') ? ' has-photo' : '');
        var textHtml = '<div class="presensi-scan-result-text">' + escapeHtml(displayMessage);
        if (extra.nama && (type === 'success' || type === 'duplicate')) {
            textHtml += '<div class="presensi-scan-result-nama">' + escapeHtml(extra.nama) + '</div>';
        }
        textHtml += '</div>';
        wrap.innerHTML = ''
            + '<div class="' + cardClass + '">'
            + overlayVisualHtml(type, extra, meta)
            + textHtml
            + '</div>';
        document.body.appendChild(wrap);
        setTimeout(function () {
            wrap.classList.remove('is-visible');
            setTimeout(function () {
                if (wrap.parentNode) {
                    wrap.parentNode.removeChild(wrap);
                }
            }, 260);
        }, duration);
    }

    function showResult(type, message, extra) {
        playFeedback(type, message);
        showOverlayResult(type, message, extra);
        setBanner(type, message);
        setFlashMessage(message, type);
    }

    document.addEventListener('touchstart', function () {
        var ctx = getAudioContext();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().catch(function () {});
        }
        if (hasSpeech() && global.speechSynthesis.getVoices().length === 0) {
            loadVoices(function () {});
        }
    }, { once: true, passive: true });

    global.PresensiScanFeedback = {
        scanTick: playScanTick,
        success: playSuccess,
        warning: playWarning,
        danger: playDanger,
        duplicate: playDuplicate,
        info: playInfo,
        speak: speakText,
        show: showResult,
        setBanner: setBanner,
        setFlash: setFlashMessage,
        onPageLoad: handlePageResult,
    };

    function bootFeedback() {
        handleSessionFlash();
        handlePageResult();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootFeedback);
    } else {
        bootFeedback();
    }
})(window);
