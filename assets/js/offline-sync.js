/**
 * Antrian offline: presensi scan, penilaian, izin kembali + cache rekap.
 */
(function (global) {
    'use strict';

    var DB_NAME = 'pondok-offline-v1';
    var DB_VERSION = 2;
    var STORE_QUEUE = 'action_queue';
    var STORE_REKAP = 'rekap_cache';
    var STORE_KEU_META = 'keuangan_meta';
    var STORE_KEU_ROWS = 'keuangan_rows';
    var SYNC_HEADER = 'X-PWA-Offline-Sync';
    var syncing = false;

    var WRITE_ROUTES = [
        { test: /\/presensi\/scan\.php$/i, module: 'presensi_scan', label: 'Presensi scan' },
        { test: /\/pembimbing\/nilai_manual\.php$/i, module: 'nilai_manual', label: 'Nilai manual' },
        { test: /\/pembimbing\/tugas\/nilai\.php$/i, module: 'ikhtibar_nilai', label: 'Nilai ikhtibar' },
        { test: /\/perizinan\/kembali\.php$/i, module: 'izin_kembali', label: 'Izin kembali' },
        { test: /\/keuangan\/cashless_scan\.php$/i, module: 'cashless', label: 'Cashless' },
        { test: /\/koperasi\/scan\.php$/i, module: 'cashless', label: 'Cashless koperasi' },
    ];

    var REKAP_PAGES = {
        '/rekap/keaktifan_hari.php': { page: 'keaktifan_hari', title: 'Keaktifan Hari Ini' },
        '/pembimbing/nilai_manual_rekap.php': { page: 'nilai_manual_rekap', title: 'Rekap Nilai Manual' },
        '/pembimbing/tugas/rekap.php': { page: 'tugas_ikhtibar_rekap', title: 'Rekap Tugas Ikhtibar' },
        '/akademik/ikhtibar_rekap.php': { page: 'tugas_ikhtibar_rekap', title: 'Rekap Ikhtibar' },
    };

    function appBase() {
        var b = global.PONDOK_APP_BASE != null ? String(global.PONDOK_APP_BASE) : '';
        return b.replace(/\/$/, '');
    }

    function appPath(relative) {
        relative = String(relative || '').replace(/^\//, '');
        var base = appBase();
        return (base === '' ? '' : base) + '/' + relative;
    }

    function routeInfo() {
        var path = (global.location.pathname || '').replace(/\/+$/, '');
        var base = appBase();
        if (base && path.indexOf(base) === 0) {
            path = path.slice(base.length) || '/';
        }
        for (var i = 0; i < WRITE_ROUTES.length; i++) {
            if (WRITE_ROUTES[i].test.test(path)) {
                return WRITE_ROUTES[i];
            }
        }
        return null;
    }

    function rekapPageConfig() {
        var path = (global.location.pathname || '').replace(/\/+$/, '');
        var base = appBase();
        if (base && path.indexOf(base) === 0) {
            path = path.slice(base.length) || '/';
        }
        return REKAP_PAGES[path] || null;
    }

    function cacheKey() {
        return (global.location.pathname || '') + (global.location.search || '');
    }

    function cacheKeyHtml() {
        return cacheKey() + ':html';
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onerror = function () { reject(req.error); };
            req.onsuccess = function () { resolve(req.result); };
            req.onupgradeneeded = function (ev) {
                var db = ev.target.result;
                if (!db.objectStoreNames.contains(STORE_QUEUE)) {
                    var q = db.createObjectStore(STORE_QUEUE, { keyPath: 'id', autoIncrement: true });
                    q.createIndex('status', 'status', { unique: false });
                    q.createIndex('createdAt', 'createdAt', { unique: false });
                }
                if (!db.objectStoreNames.contains(STORE_REKAP)) {
                    db.createObjectStore(STORE_REKAP, { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(STORE_KEU_META)) {
                    db.createObjectStore(STORE_KEU_META, { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(STORE_KEU_ROWS)) {
                    var kr = db.createObjectStore(STORE_KEU_ROWS, { keyPath: 'key' });
                    kr.createIndex('table', 'table', { unique: false });
                    kr.createIndex('chunk', 'chunk', { unique: false });
                }
            };
        });
    }

    function queueAdd(item) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_QUEUE, 'readwrite');
                var store = tx.objectStore(STORE_QUEUE);
                var req = store.add(item);
                req.onsuccess = function () { resolve(req.result); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function queueListAll() {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_QUEUE, 'readonly');
                var store = tx.objectStore(STORE_QUEUE);
                var req = store.getAll();
                req.onsuccess = function () {
                    resolve(req.result || []);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function queueListPending() {
        return queueListAll().then(function (all) {
            return all.filter(function (r) {
                return r.status === 'pending' || r.status === 'error';
            }).sort(function (a, b) { return a.createdAt - b.createdAt; });
        });
    }

    function queueListToSync(includeErrors) {
        return queueListAll().then(function (all) {
            return all.filter(function (r) {
                return r.status === 'pending' || (includeErrors && r.status === 'error');
            }).sort(function (a, b) { return a.createdAt - b.createdAt; });
        });
    }

    function queuePurgeDone() {
        return queueListAll().then(function (all) {
            var done = all.filter(function (r) { return r.status === 'done'; });
            if (!done.length) {
                return;
            }
            return openDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_QUEUE, 'readwrite');
                    var store = tx.objectStore(STORE_QUEUE);
                    done.forEach(function (row) { store.delete(row.id); });
                    tx.oncomplete = function () { resolve(); };
                    tx.onerror = function () { reject(tx.error); };
                });
            });
        });
    }

    function queueUpdate(record) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_QUEUE, 'readwrite');
                var store = tx.objectStore(STORE_QUEUE);
                var req = store.put(record);
                req.onsuccess = function () { resolve(record); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function rekapSave(key, payload) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_REKAP, 'readwrite');
                var store = tx.objectStore(STORE_REKAP);
                var req = store.put({
                    key: key,
                    savedAt: Date.now(),
                    payload: payload,
                });
                req.onsuccess = function () { resolve(); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function rekapLoad(key) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_REKAP, 'readonly');
                var store = tx.objectStore(STORE_REKAP);
                var req = store.get(key);
                req.onsuccess = function () { resolve(req.result || null); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function formToObject(form) {
        var data = {};
        var fd = new FormData(form);
        fd.forEach(function (value, key) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                if (!Array.isArray(data[key])) {
                    data[key] = [data[key]];
                }
                data[key].push(value);
            } else {
                data[key] = value;
            }
        });
        return data;
    }

    function objectToFormData(obj) {
        var fd = new FormData();
        Object.keys(obj).forEach(function (key) {
            var val = obj[key];
            if (Array.isArray(val)) {
                val.forEach(function (v) { fd.append(key, v); });
            } else if (val != null) {
                fd.append(key, val);
            }
        });
        return fd;
    }

    function describeAction(fields, fallback) {
        if (fields.kode_qr) {
            return 'Scan: ' + String(fields.kode_qr).slice(0, 24);
        }
        if (fields.action === 'simpan_nilai') {
            return 'Nilai santri #' + (fields.santri_id || '?');
        }
        if (fields.action === 'buat_target') {
            return 'Target: ' + (fields.judul || 'baru');
        }
        if (fields.action === 'nilai_esai') {
            return 'Nilai esai sesi #' + (fields.sesi_id || '?');
        }
        if (fields.action === 'munawib_pick_schedule') {
            return 'Jadwal munawib';
        }
        if (fields.action === 'verify_cashless_pin') {
            return 'Cashless: verifikasi PIN';
        }
        if (fields.action === 'process_scan_uang') {
            return 'Cashless: Rp ' + (fields.nominal_scan || '?');
        }
        return fallback || 'Kirim data';
    }

    function toast(message, type) {
        var el = document.getElementById('pondok-offline-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pondok-offline-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.className = 'pondok-offline-toast pondok-offline-toast--' + (type || 'info');
        el.textContent = message;
        el.hidden = false;
        global.clearTimeout(el._hideTimer);
        el._hideTimer = global.setTimeout(function () {
            el.hidden = true;
        }, 5200);
    }

    function updateQueueBadge(count) {
        var badge = document.getElementById('pondok-offline-queue-count');
        if (!badge) {
            return;
        }
        if (count > 0) {
            badge.textContent = String(count);
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }
    }

    function refreshQueueUi() {
        return queueListPending().then(function (items) {
            updateQueueBadge(items.length);
            var list = document.getElementById('pondok-offline-queue-list');
            if (!list) {
                return items;
            }
            list.innerHTML = '';
            if (!items.length) {
                list.innerHTML = '<li class="pondok-offline-queue-empty">Tidak ada antrian.</li>';
                return items;
            }
            items.forEach(function (item) {
                var li = document.createElement('li');
                li.className = 'pondok-offline-queue-item';
                var dt = new Date(item.createdAt);
                li.innerHTML = '<strong>' + escapeHtml(item.label) + '</strong>'
                    + '<span class="small text-muted d-block">' + escapeHtml(dt.toLocaleString('id-ID')) + '</span>'
                    + (item.lastError ? '<span class="small text-danger d-block">' + escapeHtml(item.lastError) + '</span>' : '');
                list.appendChild(li);
            });
            return items;
        });
    }

    function escapeHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function playScanFeedback(type, message) {
        if (global.PresensiScanFeedback && typeof global.PresensiScanFeedback.show === 'function') {
            global.PresensiScanFeedback.show(type, message);
            return;
        }
        toast(message, type);
    }

    var syncBatchSummary = { ok: 0, err: 0 };

    function handleSyncResult(data, route, opts) {
        opts = opts || {};
        var type = data.type || (data.ok ? 'success' : 'warning');
        var msg = data.message || (data.ok ? 'Berhasil' : 'Gagal');
        var immediate = route && (route.module === 'presensi_scan' || route.module === 'cashless');
        if (opts.batch && !immediate) {
            if (data.ok) {
                syncBatchSummary.ok += 1;
            } else {
                syncBatchSummary.err += 1;
            }
        } else if (route && route.module === 'presensi_scan') {
            playScanFeedback(type, msg);
            if (data.munawib_pending && Array.isArray(data.munawib_slots) && data.munawib_slots.length) {
                toast('Pilih jadwal munawib di layar scan.', 'warning');
                global.location.reload();
            }
        } else if (route && route.module === 'cashless') {
            toast(msg, type);
            if (data.auto_nominal) {
                global.location.reload();
            }
        } else if (!opts.batch) {
            toast(msg, type);
        }
        if (data.redirect) {
            global.location.href = data.redirect;
        }
    }

    function flushSyncBatchToast() {
        var s = syncBatchSummary;
        syncBatchSummary = { ok: 0, err: 0 };
        if (s.ok > 0 && s.err === 0) {
            toast(s.ok + ' data offline berhasil disinkronkan.', 'success');
        } else if (s.ok > 0 && s.err > 0) {
            toast(s.ok + ' berhasil, ' + s.err + ' gagal. Periksa antrian offline.', 'warning');
        } else if (s.err > 0) {
            toast('Sinkronisasi gagal untuk ' + s.err + ' item. Periksa antrian offline.', 'danger');
        }
    }

    function postQueuedItem(item) {
        var fd = objectToFormData(item.fields);
        return fetch(item.url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: (function () {
                var h = {};
                h[SYNC_HEADER] = '1';
                return h;
            })(),
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('Respons server tidak valid (HTTP ' + res.status + ').');
            }).then(function (json) {
                if (!res.ok && !json.message) {
                    throw new Error('HTTP ' + res.status);
                }
                return json;
            });
        });
    }

    function processQueue(options) {
        options = options || {};
        if (syncing || !navigator.onLine) {
            return Promise.resolve();
        }
        syncing = true;
        syncBatchSummary = { ok: 0, err: 0 };
        document.documentElement.classList.add('pondok-offline-syncing');

        return queueListToSync(!!options.includeErrors).then(function processNext(list) {
            if (!list.length) {
                syncing = false;
                document.documentElement.classList.remove('pondok-offline-syncing');
                flushSyncBatchToast();
                return queuePurgeDone().then(refreshQueueUi);
            }
            var item = list[0];
            item.status = 'syncing';
            return queueUpdate(item).then(function () {
                return postQueuedItem(item);
            }).then(function (json) {
                item.status = json.ok ? 'done' : 'error';
                item.lastError = json.ok ? '' : (json.message || 'Gagal');
                item.syncedAt = Date.now();
                var route = WRITE_ROUTES.filter(function (r) { return r.module === item.module; })[0];
                handleSyncResult(json, route, { batch: true });
                return queueUpdate(item);
            }).catch(function (err) {
                item.status = 'error';
                item.lastError = err && err.message ? err.message : 'Gagal kirim';
                syncBatchSummary.err += 1;
                return queueUpdate(item);
            }).then(function () {
                return queueListToSync();
            }).then(processNext);
        }).catch(function () {
            syncing = false;
            document.documentElement.classList.remove('pondok-offline-syncing');
        });
    }

    function stampScanClientAt(fields) {
        if (!fields || fields.scan_client_at) {
            return fields;
        }
        fields.scan_client_at = new Date().toISOString();
        return fields;
    }

    function enqueueForm(form, options) {
        options = options || {};
        var route = routeInfo();
        var fields = formToObject(form);
        if (route && route.module === 'presensi_scan') {
            fields = stampScanClientAt(fields);
        }
        var label = options.label || describeAction(fields, route ? route.label : 'Data');
        var item = {
            url: form.getAttribute('action') || global.location.href,
            fields: fields,
            module: route ? route.module : 'generic',
            label: label,
            status: 'pending',
            createdAt: Date.now(),
            lastError: '',
        };
        return queueAdd(item).then(function () {
            if (route && route.module === 'presensi_scan') {
                playScanFeedback('success', 'Scan tercatat offline (' + label + '). Waktu scan disimpan — akan dihitung saat antrian terkirim.');
            } else {
                toast('Disimpan di antrian offline (' + label + '). Akan dikirim saat online.', 'warning');
            }
            return refreshQueueUi();
        });
    }

    function handleFormSubmit(form, options) {
        if (navigator.onLine) {
            return false;
        }
        options = options || {};
        if (options.forceQueue || !navigator.onLine) {
            enqueueForm(form, options);
            return true;
        }
        return false;
    }

    function bindWriteForms() {
        var route = routeInfo();
        if (!route) {
            return;
        }
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
            if (form.dataset.offlineBound === '1') {
                return;
            }
            form.dataset.offlineBound = '1';
            form.addEventListener('submit', function (ev) {
                if (navigator.onLine) {
                    return;
                }
                ev.preventDefault();
                enqueueForm(form);
            });
        });
    }

    function fetchRekapSnapshot(cfg) {
        var url = appPath('api/offline/rekap_data.php?page=' + encodeURIComponent(cfg.page));
        if (global.location.search) {
            url += '&' + global.location.search.slice(1);
        }
        return fetch(url, { credentials: 'same-origin' }).then(function (res) {
            return res.json();
        });
    }

    function renderRekapOffline(cached) {
        var mount = document.getElementById('pondok-offline-rekap-panel');
        if (!mount) {
            mount = document.createElement('div');
            mount.id = 'pondok-offline-rekap-panel';
            mount.className = 'alert alert-warning pondok-offline-rekap-panel';
            var main = document.querySelector('.app-main');
            if (main && main.firstChild) {
                main.insertBefore(mount, main.firstChild);
            } else {
                document.body.prepend(mount);
            }
        }
        var p = cached.payload || {};
        var saved = new Date(cached.savedAt).toLocaleString('id-ID');
        var html = '<strong>Mode offline — data rekap terakhir</strong> (' + escapeHtml(saved) + ')<br>';
        html += '<span class="small">' + escapeHtml(p.title || '') + '</span>';

        if (p.page === 'keaktifan_hari' && Array.isArray(p.detail_kegiatan)) {
            html += '<div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Kegiatan</th><th>Hadir</th><th>Belum</th></tr></thead><tbody>';
            p.detail_kegiatan.forEach(function (row) {
                html += '<tr><td>' + escapeHtml(row.nama_kegiatan || '-') + '</td><td>' + escapeHtml(String(row.hadir ?? row.jumlah_hadir ?? '-')) + '</td><td>' + escapeHtml(String(row.belum ?? row.jumlah_belum ?? '-')) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        } else if (Array.isArray(p.rows) && p.rows.length) {
            html += '<div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-0"><tbody>';
            p.rows.slice(0, 12).forEach(function (row) {
                var label = row.judul || row.nama_kegiatan || row.nama_santri || row.nama_pembimbing || row.id || '-';
                html += '<tr><td>' + escapeHtml(String(label)) + '</td></tr>';
            });
            if (p.rows.length > 12) {
                html += '<tr><td class="small text-muted">… dan ' + (p.rows.length - 12) + ' baris lainnya</td></tr>';
            }
            html += '</tbody></table></div>';
            html += '<p class="small mb-0">Total ' + p.rows.length + ' baris. Sambungkan internet untuk data terbaru.</p>';
        } else {
            html += '<p class="small mb-0 mt-2">Buka halaman ini saat online untuk memperbarui cache.</p>';
        }
        mount.innerHTML = html;
    }

    function saveRekapHtmlSnapshot() {
        var main = document.querySelector('.app-main');
        if (!main) {
            return Promise.resolve();
        }
        return rekapSave(cacheKeyHtml(), {
            page: 'html_snapshot',
            title: document.title,
            html: main.innerHTML,
        });
    }

    function attachRekapHtmlViewer(cachedHtml) {
        if (!cachedHtml || !cachedHtml.payload || !cachedHtml.payload.html) {
            return;
        }
        var mount = document.getElementById('pondok-offline-rekap-panel');
        if (!mount) {
            return;
        }
        if (document.getElementById('pondok-offline-rekap-html-view')) {
            return;
        }
        var saved = new Date(cachedHtml.savedAt).toLocaleString('id-ID');
        var wrap = document.createElement('details');
        wrap.id = 'pondok-offline-rekap-html-view';
        wrap.className = 'mt-2';
        wrap.innerHTML = ''
            + '<summary class="small" style="cursor:pointer">Lihat salinan tampilan lengkap (' + escapeHtml(saved) + ')</summary>'
            + '<div class="pondok-offline-rekap-html-sandbox border rounded mt-2 p-2 overflow-auto" style="max-height:min(60vh,480px)"></div>';
        var sandbox = wrap.querySelector('.pondok-offline-rekap-html-sandbox');
        sandbox.innerHTML = cachedHtml.payload.html;
        mount.appendChild(wrap);
    }

    function bootstrapRekap() {
        var cfg = rekapPageConfig();
        if (!cfg) {
            return;
        }
        var key = cacheKey();

        if (navigator.onLine) {
            fetchRekapSnapshot(cfg).then(function (payload) {
                if (payload && payload.ok) {
                    return rekapSave(key, payload);
                }
            }).catch(function () { /* abaikan */ });
            global.setTimeout(saveRekapHtmlSnapshot, 1500);
            return;
        }

        Promise.all([rekapLoad(key), rekapLoad(cacheKeyHtml())]).then(function (results) {
            var cached = results[0];
            var cachedHtml = results[1];
            if (!cached || !cached.payload || cached.payload.page === 'html_snapshot') {
                if (cachedHtml && cachedHtml.payload && cachedHtml.payload.html) {
                    var mountOnly = document.getElementById('pondok-offline-rekap-panel');
                    if (!mountOnly) {
                        mountOnly = document.createElement('div');
                        mountOnly.id = 'pondok-offline-rekap-panel';
                        mountOnly.className = 'alert alert-warning pondok-offline-rekap-panel';
                        var mainEl = document.querySelector('.app-main');
                        if (mainEl && mainEl.firstChild) {
                            mainEl.insertBefore(mountOnly, mainEl.firstChild);
                        } else {
                            document.body.prepend(mountOnly);
                        }
                        mountOnly.innerHTML = '<strong>Mode offline — salinan tampilan</strong><br>'
                            + '<span class="small">Data ringkas belum ada; gunakan salinan HTML terakhir di bawah.</span>';
                    }
                    attachRekapHtmlViewer(cachedHtml);
                    return;
                }
                toast('Rekap belum pernah disimpan di perangkat ini. Buka halaman ini saat online dulu.', 'warning');
                return;
            }
            renderRekapOffline(cached);
            attachRekapHtmlViewer(cachedHtml);
        });
    }

    function ensureQueuePanel() {
        if (document.getElementById('pondok-offline-queue-panel')) {
            return;
        }
        var panel = document.createElement('div');
        panel.id = 'pondok-offline-queue-panel';
        panel.className = 'pondok-offline-queue-panel';
        panel.innerHTML = ''
            + '<button type="button" class="pondok-offline-queue-btn" id="pondok-offline-queue-toggle" title="Antrian offline">'
            + '<i class="fa-solid fa-cloud-arrow-up"></i>'
            + '<span class="pondok-offline-queue-badge" id="pondok-offline-queue-count" hidden>0</span>'
            + '</button>'
            + '<div class="pondok-offline-queue-drawer" id="pondok-offline-queue-drawer" hidden>'
            + '<p class="fw-semibold mb-1">Antrian offline</p>'
            + '<p class="small text-muted">Scan &amp; penilaian yang dikirim tanpa internet.</p>'
            + '<ul class="pondok-offline-queue-list" id="pondok-offline-queue-list"></ul>'
            + '<button type="button" class="btn btn-sm btn-primary w-100" id="pondok-offline-sync-now">Kirim sekarang</button>'
            + '</div>';
        document.body.appendChild(panel);

        document.getElementById('pondok-offline-queue-toggle').addEventListener('click', function () {
            var drawer = document.getElementById('pondok-offline-queue-drawer');
            drawer.hidden = !drawer.hidden;
            refreshQueueUi();
        });
        document.getElementById('pondok-offline-sync-now').addEventListener('click', function () {
            processQueue({ includeErrors: true });
        });
    }

    function init() {
        if (!('indexedDB' in global)) {
            return;
        }
        ensureQueuePanel();
        bindWriteForms();
        bootstrapRekap();
        refreshQueueUi();

        global.addEventListener('online', function () {
            toast('Internet kembali — mengirim antrian…', 'info');
            processQueue();
            bootstrapRekap();
        });
    }

    global.PondokOfflineSync = {
        enqueueForm: enqueueForm,
        handleFormSubmit: handleFormSubmit,
        processQueue: processQueue,
        refreshQueueUi: refreshQueueUi,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
