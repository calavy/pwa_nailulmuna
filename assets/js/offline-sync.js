/**
 * Antrian offline: presensi scan, poin, penilaian, izin kembali + cache rekap.
 */
(function (global) {
    'use strict';

    if (global.PondokOfflineSync) {
        return;
    }

    var DB_NAME = 'pondok-offline-v1';
    var DB_VERSION = 3;
    var STORE_QUEUE = 'action_queue';
    var STORE_REKAP = 'rekap_cache';
    var STORE_KEU_META = 'keuangan_meta';
    var STORE_KEU_ROWS = 'keuangan_rows';
    var STORE_REF = 'reference_cache';
    var STORE_PENDING = 'pending_display';
    var SYNC_HEADER = 'X-PWA-Offline-Sync';
    var QUEUE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
    var FLUSH_STORAGE_KEY = 'pondok_flush_offline';
    var RETRY_INTERVAL_MS = 90000;
    var syncing = false;
    var retryTimer = null;

    var WRITE_ROUTES = [
        { test: /\/presensi\/scan\.php$/i, module: 'presensi_scan', label: 'Presensi scan' },
        { test: /\/poin\/input\.php$/i, module: 'poin_input', label: 'Input poin' },
        { test: /\/pembimbing\/nilai_manual\.php$/i, module: 'nilai_manual', label: 'Nilai manual' },
        { test: /\/pembimbing\/tugas\/nilai\.php$/i, module: 'ikhtibar_nilai', label: 'Nilai ikhtibar' },
        { test: /\/perizinan\/kembali\.php$/i, module: 'izin_kembali', label: 'Izin kembali' },
    ];

    var CASHLESS_ONLINE_ONLY = [
        /\/keuangan\/cashless_scan\.php$/i,
        /\/koperasi\/scan\.php$/i,
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

    function generateClientUuid() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        return 'c' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
    }

    function routeInfo(pathOverride) {
        var path = pathOverride || (global.location.pathname || '').replace(/\/+$/, '');
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

    function isCashlessOnlineOnlyPath() {
        var path = (global.location.pathname || '').replace(/\/+$/, '');
        var base = appBase();
        if (base && path.indexOf(base) === 0) {
            path = path.slice(base.length) || '/';
        }
        for (var i = 0; i < CASHLESS_ONLINE_ONLY.length; i++) {
            if (CASHLESS_ONLINE_ONLY[i].test(path)) {
                return true;
            }
        }
        return false;
    }

    function routeForModule(module) {
        for (var i = 0; i < WRITE_ROUTES.length; i++) {
            if (WRITE_ROUTES[i].module === module) {
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
            req.onupgradeneeded = function (ev) {
                var db = ev.target.result;
                var oldVer = ev.oldVersion || 0;
                if (!db.objectStoreNames.contains(STORE_QUEUE)) {
                    var q = db.createObjectStore(STORE_QUEUE, { keyPath: 'id', autoIncrement: true });
                    q.createIndex('status', 'status', { unique: false });
                    q.createIndex('createdAt', 'createdAt', { unique: false });
                    q.createIndex('clientUuid', 'clientUuid', { unique: false });
                } else if (oldVer < 3) {
                    var tx = ev.target.transaction;
                    var qStore = tx.objectStore(STORE_QUEUE);
                    if (!qStore.indexNames.contains('clientUuid')) {
                        qStore.createIndex('clientUuid', 'clientUuid', { unique: false });
                    }
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
                if (!db.objectStoreNames.contains(STORE_REF)) {
                    db.createObjectStore(STORE_REF, { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(STORE_PENDING)) {
                    db.createObjectStore(STORE_PENDING, { keyPath: 'id', autoIncrement: true });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
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
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function queueItemSortTime(r) {
        if (r && r.fields && r.fields.scan_client_at) {
            var parsed = Date.parse(String(r.fields.scan_client_at));
            if (!isNaN(parsed)) {
                return parsed;
            }
        }
        return (r && r.createdAt) ? Number(r.createdAt) : 0;
    }

    function queueListPending() {
        return queueListAll().then(function (all) {
            return all.filter(function (r) {
                return r.status === 'pending' || r.status === 'error';
            }).sort(function (a, b) { return queueItemSortTime(a) - queueItemSortTime(b); });
        });
    }

    function queueListToSync(includeErrors) {
        return queueListAll().then(function (all) {
            return all.filter(function (r) {
                return r.status === 'pending' || (includeErrors && r.status === 'error');
            }).sort(function (a, b) { return queueItemSortTime(a) - queueItemSortTime(b); });
        });
    }

    function queuePurgeDone() {
        return queueListAll().then(function (all) {
            var done = all.filter(function (r) {
                return r.status === 'done' || r.status === 'duplicate';
            });
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

    function pendingAdd(entry) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_PENDING, 'readwrite');
                var store = tx.objectStore(STORE_PENDING);
                var req = store.add(entry);
                req.onsuccess = function () { resolve(req.result); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function pendingListByModule(module) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_PENDING, 'readonly');
                var store = tx.objectStore(STORE_PENDING);
                var req = store.getAll();
                req.onsuccess = function () {
                    var rows = (req.result || []).filter(function (r) {
                        return r.module === module && r.status === 'local';
                    });
                    resolve(rows);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function pendingMarkSynced(clientUuid) {
        if (!clientUuid) {
            return Promise.resolve();
        }
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_PENDING, 'readwrite');
                var store = tx.objectStore(STORE_PENDING);
                var req = store.getAll();
                req.onsuccess = function () {
                    (req.result || []).forEach(function (row) {
                        if (row.clientUuid === clientUuid) {
                            row.status = 'synced';
                            store.put(row);
                        }
                    });
                    resolve();
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function refSave(key, payload) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_REF, 'readwrite');
                var store = tx.objectStore(STORE_REF);
                var req = store.put({ key: key, savedAt: Date.now(), payload: payload });
                req.onsuccess = function () { resolve(); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function refLoad(key) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_REF, 'readonly');
                var store = tx.objectStore(STORE_REF);
                var req = store.get(key);
                req.onsuccess = function () { resolve(req.result || null); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function rekapSave(key, payload) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_REKAP, 'readwrite');
                var store = tx.objectStore(STORE_REKAP);
                var req = store.put({ key: key, savedAt: Date.now(), payload: payload });
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
        if (fields.santri_id && (fields.point_custom || fields.rule_id)) {
            return 'Poin santri #' + fields.santri_id;
        }
        if (fields.action === 'simpan_nilai') {
            return 'Nilai santri #' + (fields.santri_id || '?');
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
        el._hideTimer = global.setTimeout(function () { el.hidden = true; }, 5200);
    }

    function updateQueueBadge(count) {
        var badge = document.getElementById('pondok-offline-queue-count');
        if (badge) {
            if (count > 0) {
                badge.textContent = String(count);
                badge.hidden = false;
            } else {
                badge.hidden = true;
            }
        }
        var statusQueue = document.getElementById('pondok-offline-status-queue');
        if (statusQueue) {
            if (count > 0) {
                statusQueue.textContent = count + ' belum terkirim';
                statusQueue.hidden = false;
            } else {
                statusQueue.hidden = true;
            }
        }
    }

    function updateOfflineBar() {
        var bar = document.getElementById('pondok-offline-status-bar');
        if (!bar) {
            return;
        }
        var offline = !navigator.onLine;
        bar.hidden = !offline;
        document.documentElement.classList.toggle('pondok-offline', offline);
    }

    function updateDashboardStatus(pendingCount) {
        var statusEl = document.getElementById('dash-system-status');
        var pillEl = document.getElementById('dash-system-pill');
        var syncText = document.getElementById('dash-sync-text');
        var syncFooter = document.getElementById('dash-sync-footer');
        var syncBadge = document.getElementById('dash-sync-badge');
        if (!statusEl || !pillEl) {
            return Promise.resolve();
        }

        function apply(count) {
            var offline = !navigator.onLine;
            pillEl.classList.remove('dash-status-pill--ok', 'dash-status-pill--offline', 'dash-status-pill--pending');
            if (syncFooter) {
                syncFooter.classList.remove('dash-sync-footer--offline', 'dash-sync-footer--pending');
            }
            if (offline) {
                pillEl.classList.add('dash-status-pill--offline');
                statusEl.textContent = 'Mode Offline';
                if (syncText) {
                    syncText.textContent = count > 0
                        ? 'Antrian tersimpan lokal (' + count + ') · akan terkirim saat online'
                        : 'Tidak ada koneksi · antrian akan tersimpan lokal';
                }
                if (syncFooter) {
                    syncFooter.classList.add('dash-sync-footer--offline');
                }
                if (syncBadge) {
                    syncBadge.innerHTML = '<i class="fa-solid fa-circle" aria-hidden="true"></i> Offline';
                }
            } else if (count > 0) {
                pillEl.classList.add('dash-status-pill--pending');
                statusEl.textContent = 'Online · ' + count + ' belum terkirim';
                if (syncText) {
                    syncText.textContent = count + ' antrian menunggu sinkronisasi otomatis';
                }
                if (syncFooter) {
                    syncFooter.classList.add('dash-sync-footer--pending');
                }
                if (syncBadge) {
                    syncBadge.innerHTML = '<i class="fa-solid fa-circle" aria-hidden="true"></i> Pending (' + count + ')';
                }
            } else {
                pillEl.classList.add('dash-status-pill--ok');
                statusEl.textContent = 'Normal Online';
                if (syncText) {
                    syncText.textContent = 'Sistem sinkronisasi otomatis aktif · data real-time';
                }
                if (syncBadge) {
                    syncBadge.innerHTML = '<i class="fa-solid fa-circle" aria-hidden="true"></i> Connected';
                }
            }
        }

        if (typeof pendingCount === 'number') {
            apply(pendingCount);
            return Promise.resolve();
        }
        return queueListPending().then(function (items) {
            apply(items.length);
        });
    }

    function escapeHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function statusLabel(status) {
        if (status === 'error') {
            return 'Gagal';
        }
        if (status === 'duplicate') {
            return 'Duplikat';
        }
        return 'Menunggu';
    }

    function refreshQueueUi() {
        return queueListPending().then(function (items) {
            updateQueueBadge(items.length);
            updateDashboardStatus(items.length);
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
                var ageWarn = (Date.now() - item.createdAt) > QUEUE_MAX_AGE_MS
                    ? '<span class="small text-warning d-block">Antrian &gt; 7 hari — segera sync</span>' : '';
                li.innerHTML = '<strong>' + escapeHtml(item.label) + '</strong>'
                    + '<span class="pondok-offline-queue-item__status">' + escapeHtml(statusLabel(item.status)) + '</span>'
                    + '<span class="small text-muted d-block">' + escapeHtml(dt.toLocaleString('id-ID')) + '</span>'
                    + (item.lastError ? '<span class="small text-danger d-block">' + escapeHtml(item.lastError) + '</span>' : '')
                    + ageWarn;
                list.appendChild(li);
            });
            return items;
        });
    }

    function playScanFeedback(type, message) {
        if (global.PresensiScanFeedback && typeof global.PresensiScanFeedback.show === 'function') {
            global.PresensiScanFeedback.show(type, message);
            return;
        }
        toast(message, type);
    }

    function appendPoinPendingRow(fields, label, santriName) {
        var tbody = document.getElementById('poin-recent-tbody');
        if (!tbody) {
            return;
        }
        var tr = document.createElement('tr');
        tr.className = 'is-local-pending';
        var poin = fields.point_custom || '?';
        var nama = santriName || ('Santri #' + String(fields.santri_id || '?'));
        tr.innerHTML = '<td>' + escapeHtml(fields.tanggal || '-') + '</td>'
            + '<td>' + escapeHtml(nama) + '</td>'
            + '<td>-</td>'
            + '<td>' + escapeHtml(fields.jenis_perubahan || '-') + '</td>'
            + '<td>' + escapeHtml(String(poin)) + '</td>'
            + '<td>' + escapeHtml(String(fields.keterangan || label)) + ' <span class="badge bg-warning text-dark">Lokal</span></td>';
        tbody.insertBefore(tr, tbody.firstChild);
    }

    function hydratePoinSelects(pack) {
        if (!pack) {
            return false;
        }
        var santriList = pack.santri || [];
        var ruleList = pack.point_rules || [];
        var santriSelect = document.querySelector('#form-poin-input select[name="santri_id"]');
        var ruleSelect = document.getElementById('poinRuleSelect');
        var changed = false;

        if (santriSelect && santriList.length) {
            var prev = santriSelect.value;
            var html = '<option value="">Pilih santri</option>';
            santriList.forEach(function (s) {
                var label = (s.nama_santri || '-') + ' - ' + (s.tingkatan || '-') + ' (' + (s.nis || '') + ')';
                html += '<option value="' + escapeHtml(String(s.id)) + '">' + escapeHtml(label) + '</option>';
            });
            santriSelect.innerHTML = html;
            if (prev) {
                santriSelect.value = prev;
            }
            changed = true;
        }

        if (ruleSelect && ruleList.length) {
            var prevRule = ruleSelect.value;
            var ruleHtml = '<option value="0">Pilih rule / kosongkan jika poin custom</option>';
            ruleList.forEach(function (r) {
                var jr = String(r.jenis_rule || 'PLUS').toUpperCase();
                var label = (r.kategori || '') + ' - ' + (r.nama_rule || '') + ' (' + (r.bobot_poin || 0) + ' poin)';
                ruleHtml += '<option value="' + escapeHtml(String(r.id)) + '" data-jenis="' + escapeHtml(jr)
                    + '" data-poin="' + escapeHtml(String(r.bobot_poin || 0)) + '">'
                    + escapeHtml(label) + '</option>';
            });
            ruleSelect.innerHTML = ruleHtml;
            if (prevRule) {
                ruleSelect.value = prevRule;
            }
            changed = true;
            var jenisEl = document.getElementById('poinJenisSelect');
            if (jenisEl) {
                jenisEl.dispatchEvent(new Event('change'));
            }
        }

        return changed;
    }

    function resolveSantriNameFromPack(pack, santriId) {
        var list = (pack && pack.santri) || [];
        var id = String(santriId || '');
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id) === id) {
                return list[i].nama_santri || ('Santri #' + id);
            }
        }
        return id ? ('Santri #' + id) : '-';
    }

    function bootstrapPoinPage() {
        if (!routeInfo() || routeInfo().module !== 'poin_input') {
            return;
        }
        var applyCached = function (cached) {
            var pack = cached && cached.payload ? cached.payload : null;
            if (!pack || !pack.ok) {
                if (!navigator.onLine) {
                    toast('Data referensi poin belum di-cache. Buka halaman ini sekali saat online dulu.', 'warning');
                }
                return null;
            }
            if (!navigator.onLine || (document.querySelectorAll('#form-poin-input select[name="santri_id"] option').length <= 1)) {
                hydratePoinSelects(pack);
            }
            return pack;
        };

        if (navigator.onLine) {
            fetchReferencePack()
                .then(function () { return refLoad('poin_reference'); })
                .then(applyCached)
                .catch(function () { /* abaikan */ });
        } else {
            refLoad('poin_reference').then(applyCached).catch(function () {
                toast('Data referensi poin belum di-cache. Buka halaman ini sekali saat online dulu.', 'warning');
            });
        }

        Promise.all([
            pendingListByModule('poin_input'),
            refLoad('poin_reference'),
        ]).then(function (parts) {
            var rows = parts[0] || [];
            var pack = parts[1] && parts[1].payload ? parts[1].payload : null;
            rows.forEach(function (row) {
                var fields = row.fields || {};
                appendPoinPendingRow(fields, row.label || 'Poin', resolveSantriNameFromPack(pack, fields.santri_id));
            });
        });
    }

    var syncBatchSummary = { ok: 0, err: 0, dup: 0 };

    function handleSyncResult(data, route, opts, item) {
        opts = opts || {};
        var type = data.type || (data.ok ? 'success' : 'warning');
        var msg = data.message || (data.ok ? 'Berhasil' : 'Gagal');
        if (item && item.clientUuid && (data.ok || type === 'duplicate')) {
            pendingMarkSynced(item.clientUuid);
        }
        var immediate = route && (route.module === 'presensi_scan' || route.module === 'cashless');
        if (opts.batch && !immediate) {
            if (data.ok) {
                syncBatchSummary.ok += 1;
            } else if (type === 'duplicate') {
                syncBatchSummary.dup += 1;
            } else {
                syncBatchSummary.err += 1;
            }
        } else if (route && route.module === 'presensi_scan') {
            playScanFeedback(type, msg);
            if (data.munawib_pending && Array.isArray(data.munawib_slots) && data.munawib_slots.length) {
                toast('Pilih jadwal munawib di layar scan.', 'warning');
                global.location.reload();
            }
        } else if (route && route.module === 'poin_input') {
            toast(msg, type);
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
        syncBatchSummary = { ok: 0, err: 0, dup: 0 };
        if (s.ok > 0 && s.err === 0 && s.dup === 0) {
            toast(s.ok + ' data offline berhasil disinkronkan.', 'success');
        } else if (s.ok > 0 || s.dup > 0) {
            var parts = [];
            if (s.ok) {
                parts.push(s.ok + ' berhasil');
            }
            if (s.dup) {
                parts.push(s.dup + ' duplikat');
            }
            if (s.err) {
                parts.push(s.err + ' gagal');
            }
            toast(parts.join(', ') + '.', s.err ? 'warning' : 'success');
        } else if (s.err > 0) {
            toast('Sinkronisasi gagal untuk ' + s.err + ' item.', 'danger');
        }
    }

    function resolvePostUrl(item) {
        if (item.module === 'poin_input') {
            return appPath('api/offline/poin_submit.php');
        }
        return item.url;
    }

    function postQueuedItem(item) {
        var fd = objectToFormData(item.fields);
        return fetch(resolvePostUrl(item), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: (function () {
                var h = {};
                h[SYNC_HEADER] = '1';
                return h;
            })(),
        }).then(function (res) {
            if (res.status === 401) {
                var authErr = new Error('Sesi habis — masuk lagi agar antrian terkirim otomatis.');
                authErr.authRequired = true;
                authErr.status = 401;
                throw authErr;
            }
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
        syncBatchSummary = { ok: 0, err: 0, dup: 0 };
        document.documentElement.classList.add('pondok-offline-syncing');

        return queueListToSync(!!options.includeErrors).then(function processNext(list) {
            if (!list.length) {
                syncing = false;
                document.documentElement.classList.remove('pondok-offline-syncing');
                flushSyncBatchToast();
                return queuePurgeDone().then(refreshQueueUi);
            }
            var item = list[0];
            if (item.module === 'cashless') {
                item.status = 'done';
                item.lastError = 'Cashless online-only';
                return queueUpdate(item).then(function () {
                    return queueListToSync(!!options.includeErrors).then(processNext);
                });
            }
            item.status = 'syncing';
            return queueUpdate(item).then(function () {
                return postQueuedItem(item);
            }).then(function (json) {
                var type = json.type || (json.ok ? 'success' : 'warning');
                if (type === 'duplicate') {
                    item.status = 'duplicate';
                    item.lastError = json.message || 'Duplikat';
                } else if (json.ok) {
                    item.status = 'done';
                    item.lastError = '';
                } else {
                    item.status = 'error';
                    item.lastError = json.message || 'Gagal';
                }
                item.syncedAt = Date.now();
                var route = routeForModule(item.module);
                handleSyncResult(json, route, { batch: true }, item);
                return queueUpdate(item).then(function () {
                    return { stop: false };
                });
            }).catch(function (err) {
                var authRequired = !!(err && err.authRequired);
                item.status = authRequired ? 'pending' : 'error';
                item.lastError = err && err.message ? err.message : 'Gagal kirim';
                if (!authRequired) {
                    syncBatchSummary.err += 1;
                }
                return queueUpdate(item).then(function () {
                    return { stop: authRequired, auth: authRequired };
                });
            }).then(function (ctrl) {
                if (ctrl && ctrl.stop) {
                    syncing = false;
                    document.documentElement.classList.remove('pondok-offline-syncing');
                    if (ctrl.auth) {
                        toast('Sesi habis — masuk lagi agar antrian terkirim otomatis.', 'warning');
                    }
                    flushSyncBatchToast();
                    return refreshQueueUi();
                }
                return queueListToSync(!!options.includeErrors).then(processNext);
            });
        }).catch(function () {
            syncing = false;
            document.documentElement.classList.remove('pondok-offline-syncing');
        });
    }

    function stampScanClientAt(fields, force) {
        if (!fields) {
            return fields;
        }
        if (!force && fields.scan_client_at) {
            return fields;
        }
        fields.scan_client_at = new Date().toISOString();
        return fields;
    }

    function enqueueFields(fields, options) {
        options = options || {};
        var module = options.module || 'generic';
        if (module === 'cashless') {
            toast('Cashless membutuhkan internet. Transaksi tidak disimpan lokal.', 'warning');
            return Promise.resolve();
        }
        var route = routeForModule(module);
        if (module === 'presensi_scan' || module === 'cashless') {
            // Selalu stamp ulang di momen masuk antrian (= waktu scan offline).
            fields = stampScanClientAt(fields, true);
            if (!fields.scan_source) {
                fields.scan_source = 'camera';
            }
        }
        if (module === 'poin_input' && !fields.client_created_at) {
            fields.client_created_at = new Date().toISOString();
        }
        if (!fields.client_uuid) {
            fields.client_uuid = generateClientUuid();
        }
        if (fields.action === 'process_scan_uang' && !fields.client_token) {
            fields.client_token = fields.client_uuid;
        }
        var label = options.label || describeAction(fields, route ? route.label : 'Data');
        var url = options.url || (route ? appPath('presensi/scan.php') : global.location.href);
        if (module === 'presensi_scan' && options.url) {
            url = options.url;
        }
        var item = {
            url: url,
            fields: fields,
            module: module,
            label: label,
            status: 'pending',
            createdAt: Date.now(),
            clientUuid: fields.client_uuid,
            lastError: '',
        };
        return queueAdd(item).then(function () {
            return pendingAdd({
                module: module,
                clientUuid: fields.client_uuid,
                label: label,
                fields: fields,
                status: 'local',
                createdAt: Date.now(),
            }).then(function () {
                if (module === 'presensi_scan') {
                    playScanFeedback('success', 'Scan tercatat offline (' + label + '). Akan dikirim saat online.');
                } else if (module === 'cashless') {
                    toast('Cashless tersimpan offline dengan waktu scan (' + label + '). Akan dikirim saat online.', 'warning');
                } else if (module === 'poin_input') {
                    refLoad('poin_reference').then(function (cached) {
                        var pack = cached && cached.payload ? cached.payload : null;
                        appendPoinPendingRow(fields, label, resolveSantriNameFromPack(pack, fields.santri_id));
                    }).catch(function () {
                        appendPoinPendingRow(fields, label);
                    });
                    toast('Poin disimpan lokal (' + label + '). Akan dikirim saat online.', 'warning');
                } else {
                    toast('Disimpan di antrian offline (' + label + ').', 'warning');
                }
                return refreshQueueUi();
            });
        });
    }

    function enqueueForm(form, options) {
        options = options || {};
        var route = routeInfo();
        var fields = formToObject(form);
        var module = options.module || (route ? route.module : 'generic');
        var url = options.url || form.getAttribute('action') || global.location.href;
        options.module = module;
        options.url = url;
        options.label = options.label || describeAction(fields, route ? route.label : 'Data');
        return enqueueFields(fields, options);
    }

    function handleFormSubmit(form, options) {
        if (navigator.onLine) {
            return false;
        }
        options = options || {};
        enqueueForm(form, options);
        return true;
    }

    function enqueuePresensiScan(form, options) {
        options = options || {};
        options.module = 'presensi_scan';
        options.url = options.url || form.getAttribute('action') || appPath('presensi/scan.php');
        return enqueueForm(form, options);
    }

    function bindWriteForms() {
        if (isCashlessOnlineOnlyPath()) {
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
                    toast('Cashless membutuhkan internet. Transaksi tidak disimpan lokal.', 'warning');
                });
            });
            return;
        }
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

    function fetchReferencePack() {
        return fetch(appPath('api/offline/reference_pack.php'), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (payload && payload.ok) {
                    return refSave('poin_reference', payload);
                }
                return null;
            });
    }

    function fetchRekapSnapshot(cfg) {
        var url = appPath('api/offline/rekap_data.php?page=' + encodeURIComponent(cfg.page));
        if (global.location.search) {
            url += '&' + global.location.search.slice(1);
        }
        return fetch(url, { credentials: 'same-origin' }).then(function (res) { return res.json(); });
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
        mount.innerHTML = '<strong>Mode offline — data rekap terakhir</strong> (' + escapeHtml(saved) + ')';
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
        }
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
            + '<p class="small text-muted">Presensi &amp; poin tersimpan lokal, belum terkirim. Buka halaman scan/poin sekali saat online agar siap offline.</p>'
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

    function consumeFlushOfflineFlag() {
        try {
            if (global.sessionStorage && sessionStorage.getItem(FLUSH_STORAGE_KEY) === '1') {
                sessionStorage.removeItem(FLUSH_STORAGE_KEY);
                return true;
            }
        } catch (e) { /* mode privat / storage diblokir */ }
        return false;
    }

    function tryProcessQueueIfOnline(options) {
        if (!navigator.onLine) {
            return;
        }
        processQueue(options || {});
    }

    function scheduleRetryLoop() {
        if (retryTimer) {
            return;
        }
        retryTimer = global.setInterval(function () {
            if (navigator.onLine && !syncing) {
                processQueue();
            }
        }, RETRY_INTERVAL_MS);
    }

    function init() {
        if (!('indexedDB' in global)) {
            return;
        }
        ensureQueuePanel();
        bindWriteForms();
        bootstrapRekap();
        bootstrapPoinPage();
        refreshQueueUi();
        updateOfflineBar();
        updateDashboardStatus();
        scheduleRetryLoop();

        var afterLogin = consumeFlushOfflineFlag();

        global.addEventListener('online', function () {
            updateOfflineBar();
            updateDashboardStatus();
            toast('Internet kembali — mengirim antrian…', 'info');
            processQueue();
            bootstrapRekap();
            fetchReferencePack().catch(function () { /* abaikan */ });
        });
        global.addEventListener('offline', function () {
            updateOfflineBar();
            updateDashboardStatus();
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                tryProcessQueueIfOnline();
            }
        });
        global.addEventListener('pageshow', function () {
            tryProcessQueueIfOnline();
        });

        tryProcessQueueIfOnline({ includeErrors: afterLogin });
    }

    global.PondokOfflineSync = {
        enqueueForm: enqueueForm,
        enqueueFields: enqueueFields,
        enqueuePresensiScan: enqueuePresensiScan,
        handleFormSubmit: handleFormSubmit,
        processQueue: processQueue,
        refreshQueueUi: refreshQueueUi,
        fetchReferencePack: fetchReferencePack,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
