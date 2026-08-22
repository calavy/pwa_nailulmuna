/**
 * Login staf offline: simpan verifikasi password (bukan teks polos) setelah login online,
 * lalu buka dashboard dari cache saat tanpa internet.
 */
(function (global) {
    'use strict';

    if (global.PondokOfflineAuth) {
        return;
    }

    var DB_NAME = 'pondok-offline-auth';
    var DB_VERSION = 1;
    var STORE = 'creds';
    var RECORD_KEY = 'staff';
    var PENDING_KEY = 'pondok_offline_auth_pending';
    var PBKDF2_ITERS = 120000;
    var FALLBACK_ITERS = 8000;
    var ERR_MSG = 'Identitas atau password salah.';

    function appBase() {
        var b = global.PONDOK_APP_BASE != null ? String(global.PONDOK_APP_BASE) : '';
        return b.replace(/\/$/, '');
    }

    function appHref(relative) {
        relative = String(relative || '').replace(/^\//, '');
        var base = appBase();
        return (base === '' ? '' : base) + '/' + relative;
    }

    function bytesToB64(buf) {
        var bytes = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin);
    }

    function b64ToBytes(s) {
        var bin = atob(String(s || ''));
        var out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) {
            out[i] = bin.charCodeAt(i);
        }
        return out;
    }

    function randomSalt() {
        var buf = new Uint8Array(16);
        if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
            global.crypto.getRandomValues(buf);
            return buf;
        }
        var seed = Date.now();
        for (var i = 0; i < buf.length; i++) {
            seed = (seed * 9301 + 49297) % 233280;
            buf[i] = seed & 255;
        }
        return buf;
    }

    function rotr(n, x) {
        return (x >>> n) | (x << (32 - n));
    }

    function sha256sync(bytes) {
        var K = [
            0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
            0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
            0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
            0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
            0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
            0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
            0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
            0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
        ];
        var h0 = 0x6a09e667;
        var h1 = 0xbb67ae85;
        var h2 = 0x3c6ef372;
        var h3 = 0xa54ff53a;
        var h4 = 0x510e527f;
        var h5 = 0x9b05688c;
        var h6 = 0x1f83d9ab;
        var h7 = 0x5be0cd19;
        var bitLen = bytes.length * 8;
        var withPad = bytes.length + 1 + 8;
        var padLen = (64 - (withPad % 64)) % 64;
        var total = bytes.length + 1 + padLen + 8;
        var buf = new Uint8Array(total);
        buf.set(bytes, 0);
        buf[bytes.length] = 0x80;
        var view = new DataView(buf.buffer);
        view.setUint32(total - 4, bitLen >>> 0, false);
        view.setUint32(total - 8, Math.floor(bitLen / 0x100000000), false);
        var w = new Uint32Array(64);
        for (var off = 0; off < total; off += 64) {
            var i;
            for (i = 0; i < 16; i++) {
                w[i] = view.getUint32(off + i * 4, false);
            }
            for (i = 16; i < 64; i++) {
                var s0 = rotr(7, w[i - 15]) ^ rotr(18, w[i - 15]) ^ (w[i - 15] >>> 3);
                var s1 = rotr(17, w[i - 2]) ^ rotr(19, w[i - 2]) ^ (w[i - 2] >>> 10);
                w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
            }
            var a = h0;
            var b = h1;
            var c = h2;
            var d = h3;
            var e = h4;
            var f = h5;
            var g = h6;
            var h = h7;
            for (i = 0; i < 64; i++) {
                var S1 = rotr(6, e) ^ rotr(11, e) ^ rotr(25, e);
                var ch = (e & f) ^ ((~e) & g);
                var temp1 = (h + S1 + ch + K[i] + w[i]) >>> 0;
                var S0 = rotr(2, a) ^ rotr(13, a) ^ rotr(22, a);
                var maj = (a & b) ^ (a & c) ^ (b & c);
                var temp2 = (S0 + maj) >>> 0;
                h = g;
                g = f;
                f = e;
                e = (d + temp1) >>> 0;
                d = c;
                c = b;
                b = a;
                a = (temp1 + temp2) >>> 0;
            }
            h0 = (h0 + a) >>> 0;
            h1 = (h1 + b) >>> 0;
            h2 = (h2 + c) >>> 0;
            h3 = (h3 + d) >>> 0;
            h4 = (h4 + e) >>> 0;
            h5 = (h5 + f) >>> 0;
            h6 = (h6 + g) >>> 0;
            h7 = (h7 + h) >>> 0;
        }
        var out = new Uint8Array(32);
        var dv = new DataView(out.buffer);
        dv.setUint32(0, h0, false);
        dv.setUint32(4, h1, false);
        dv.setUint32(8, h2, false);
        dv.setUint32(12, h3, false);
        dv.setUint32(16, h4, false);
        dv.setUint32(20, h5, false);
        dv.setUint32(24, h6, false);
        dv.setUint32(28, h7, false);
        return out;
    }

    function concatBytes(a, b) {
        var out = new Uint8Array(a.length + b.length);
        out.set(a, 0);
        out.set(b, a.length);
        return out;
    }

    function fallbackDerive(password, saltBytes) {
        var pass = new TextEncoder().encode(String(password));
        var block = concatBytes(saltBytes, pass);
        var out = sha256sync(block);
        for (var i = 0; i < FALLBACK_ITERS; i++) {
            out = sha256sync(concatBytes(out, saltBytes));
        }
        return bytesToB64(out);
    }

    async function deriveHash(password, saltBytes, algo) {
        var usePbkdf2 = algo !== 'sha256-iter-8k'
            && global.crypto
            && global.crypto.subtle
            && typeof global.crypto.subtle.importKey === 'function';
        if (usePbkdf2) {
            try {
                var key = await global.crypto.subtle.importKey(
                    'raw',
                    new TextEncoder().encode(String(password)),
                    'PBKDF2',
                    false,
                    ['deriveBits']
                );
                var bits = await global.crypto.subtle.deriveBits({
                    name: 'PBKDF2',
                    salt: saltBytes,
                    iterations: PBKDF2_ITERS,
                    hash: 'SHA-256'
                }, key, 256);
                return { hash: bytesToB64(bits), algo: 'pbkdf2-120k' };
            } catch (e) { /* LAN HTTP: SubtleCrypto sering tidak tersedia */ }
        }
        return { hash: fallbackDerive(password, saltBytes), algo: 'sha256-iter-8k' };
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            if (!('indexedDB' in global)) {
                reject(new Error('indexedDB'));
                return;
            }
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE);
                }
            };
            req.onsuccess = function () {
                resolve(req.result);
            };
            req.onerror = function () {
                reject(req.error || new Error('idb'));
            };
        });
    }

    function getRecord() {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE, 'readonly');
                var req = tx.objectStore(STORE).get(RECORD_KEY);
                req.onsuccess = function () {
                    resolve(req.result || null);
                };
                req.onerror = function () {
                    reject(req.error);
                };
            });
        }).catch(function () {
            return null;
        });
    }

    function putRecord(rec) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).put(rec, RECORD_KEY);
                tx.oncomplete = function () {
                    resolve();
                };
                tx.onerror = function () {
                    reject(tx.error);
                };
            });
        });
    }

    function normalizeUsername(u) {
        return String(u || '').trim().toLowerCase();
    }

    function timingSafeEqual(a, b) {
        a = String(a || '');
        b = String(b || '');
        if (a.length !== b.length) {
            return false;
        }
        var x = 0;
        for (var i = 0; i < a.length; i++) {
            x |= a.charCodeAt(i) ^ b.charCodeAt(i);
        }
        return x === 0;
    }

    function lastPathFromLocation() {
        return String(global.location.pathname || '');
    }

    function isDashboardPath(p) {
        return String(p || '').indexOf('/dashboard.php') >= 0;
    }

    function dashHref(rec) {
        if (rec && isDashboardPath(rec.lastPath)) {
            return rec.lastPath;
        }
        return appHref('/dashboard.php');
    }

    function savePending(username, password) {
        var salt = randomSalt();
        return deriveHash(password, salt).then(function (derived) {
            try {
                sessionStorage.setItem(PENDING_KEY, JSON.stringify({
                    username: normalizeUsername(username),
                    salt: bytesToB64(salt),
                    hash: derived.hash,
                    algo: derived.algo,
                    at: Date.now()
                }));
            } catch (e) { /* abaikan kuota */ }
        });
    }

    function commitPending() {
        var raw = '';
        try {
            raw = sessionStorage.getItem(PENDING_KEY) || '';
        } catch (e) {
            raw = '';
        }
        var lastPath = lastPathFromLocation();
        if (raw) {
            try {
                sessionStorage.removeItem(PENDING_KEY);
            } catch (e) { /* abaikan */ }
            try {
                var pending = JSON.parse(raw);
                if (pending && pending.username && pending.hash && pending.salt) {
                    return putRecord({
                        username: pending.username,
                        salt: pending.salt,
                        hash: pending.hash,
                        algo: pending.algo || 'pbkdf2-120k',
                        lastPath: lastPath,
                        updatedAt: Date.now()
                    });
                }
            } catch (e) { /* pending rusak */ }
        }
        return rememberLastPage();
    }

    function rememberLastPage() {
        var lastPath = lastPathFromLocation();
        if (lastPath.indexOf('/login.php') >= 0) {
            return Promise.resolve();
        }
        return getRecord().then(function (rec) {
            if (!rec) {
                return;
            }
            rec.lastPath = lastPath;
            rec.updatedAt = Date.now();
            return putRecord(rec);
        }).catch(function () {});
    }

    function verify(username, password) {
        return getRecord().then(function (rec) {
            if (!rec || !rec.hash || !rec.salt) {
                return false;
            }
            if (normalizeUsername(username) !== String(rec.username || '')) {
                return false;
            }
            return deriveHash(password, b64ToBytes(rec.salt), rec.algo).then(function (derived) {
                return timingSafeEqual(derived.hash, rec.hash);
            });
        }).catch(function () {
            return false;
        });
    }

    function showLoginError(form, msg) {
        var host = form.parentNode;
        if (!host) {
            return;
        }
        var existing = host.querySelector('[data-offline-login-error]');
        if (!existing) {
            existing = document.createElement('div');
            existing.className = 'alert alert-danger py-2 small';
            existing.setAttribute('role', 'alert');
            existing.setAttribute('data-offline-login-error', '1');
            host.insertBefore(existing, form);
        }
        existing.textContent = msg;
    }

    function bindLoginForm() {
        var form = document.getElementById('login-staff-form');
        var userEl = document.getElementById('login-username');
        var passEl = document.getElementById('login-password');
        if (!form || !userEl || !passEl) {
            return false;
        }

        form.addEventListener('submit', function (ev) {
            var username = userEl.value;
            var password = passEl.value;
            if (!navigator.onLine) {
                ev.preventDefault();
                verify(username, password).then(function (ok) {
                    if (!ok) {
                        showLoginError(form, ERR_MSG);
                        return;
                    }
                    return getRecord().then(function (rec) {
                        global.location.href = dashHref(rec);
                    });
                }).catch(function () {
                    showLoginError(form, ERR_MSG);
                });
                return;
            }
            savePending(username, password).catch(function () {});
        });
        return true;
    }

    function boot() {
        if (bindLoginForm()) {
            return;
        }
        if (document.body && document.body.classList.contains('auth-portal-page')) {
            return;
        }
        commitPending();
    }

    global.PondokOfflineAuth = {
        commitPending: commitPending,
        verify: verify
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
