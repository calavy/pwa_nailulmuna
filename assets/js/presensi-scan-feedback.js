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
            if (/sudah tercatat/i.test(s)) {
                return 'Perhatian. Presensi sudah tercatat sebelumnya.';
            }
            if (/tidak terdaftar/i.test(s)) {
                return 'Perhatian. Kode QR tidak terdaftar.';
            }
            if (/tidak aktif|sudah keluar/i.test(s)) {
                return 'Perhatian. Santri tidak aktif, presensi tidak dicatat.';
            }
            if (/hari libur/i.test(s)) {
                return 'Perhatian. Hari libur, presensi tidak dicatat.';
            }
            if (/luar jadwal/i.test(s)) {
                return 'Perhatian. Scan di luar jadwal kegiatan.';
            }
            if (/tidak ada kegiatan aktif/i.test(s)) {
                return 'Perhatian. Tidak ada kegiatan aktif saat ini.';
            }
            return 'Perhatian. ' + s.slice(0, 110);
        }

        return s.slice(0, 120);
    }

    function speakOrBeep(speechText, beepFn) {
        loadVoices(function () {
            if (speakText(speechText)) {
                return;
            }
            if (typeof beepFn === 'function') {
                beepFn();
            }
        });
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

    /** Suara sukses: TTS pesan hasil, fallback bip jika TTS tidak ada. */
    function playSuccess(message) {
        vibrate([40, 30, 60]);
        var speech = textForSpeech(message, 'success');
        speakOrBeep(speech, playBeepSuccess);
    }

    function playWarning(message) {
        vibrate([80, 40, 80]);
        var speech = textForSpeech(message, 'warning');
        speakOrBeep(speech, playBeepWarning);
    }

    function flashViewport(className) {
        var vp = document.querySelector('.presensi-scan-viewport');
        if (!vp) {
            return;
        }
        vp.classList.remove('presensi-scan-flash-success', 'presensi-scan-flash-warning');
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

    function handlePageResult() {
        var el = document.getElementById('presensi-scan-result');
        if (!el) {
            return;
        }
        var type = el.getAttribute('data-type') || 'success';
        var speakAttr = el.getAttribute('data-speak');
        var textEl = el.querySelector('.presensi-scan-result-text');
        var message = speakAttr || (textEl ? textEl.textContent : '');

        runWhenUnlocked(function () {
            if (type === 'success') {
                playSuccess(message);
                flashViewport('presensi-scan-flash-success');
            } else if (type === 'warning') {
                playWarning(message);
                flashViewport('presensi-scan-flash-warning');
            }
        });

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
        speak: speakText,
        onPageLoad: handlePageResult,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', handlePageResult);
    } else {
        handlePageResult();
    }
})(window);
