/**
 * Unduh & baca database keuangan offline (IndexedDB, baca-saja).
 * Bergantung pada store keuangan_meta / keuangan_rows (DB v2).
 */
(function (global) {
    'use strict';

    var DB_NAME = 'pondok-offline-v1';
    var DB_VERSION = 2;
    var STORE_META = 'keuangan_meta';
    var STORE_ROWS = 'keuangan_rows';
    var downloading = false;

    function appBase() {
        var b = global.PONDOK_APP_BASE != null ? String(global.PONDOK_APP_BASE) : '';
        return b.replace(/\/$/, '');
    }

    function appPath(relative) {
        relative = String(relative || '').replace(/^\//, '');
        var base = appBase();
        return (base === '' ? '' : base) + '/' + relative;
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onerror = function () { reject(req.error); };
            req.onsuccess = function () { resolve(req.result); };
            req.onupgradeneeded = function (ev) {
                var db = ev.target.result;
                if (!db.objectStoreNames.contains('action_queue')) {
                    var q = db.createObjectStore('action_queue', { keyPath: 'id', autoIncrement: true });
                    q.createIndex('status', 'status', { unique: false });
                    q.createIndex('createdAt', 'createdAt', { unique: false });
                }
                if (!db.objectStoreNames.contains('rekap_cache')) {
                    db.createObjectStore('rekap_cache', { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(STORE_META)) {
                    db.createObjectStore(STORE_META, { keyPath: 'key' });
                }
                if (!db.objectStoreNames.contains(STORE_ROWS)) {
                    var kr = db.createObjectStore(STORE_ROWS, { keyPath: 'key' });
                    kr.createIndex('table', 'table', { unique: false });
                    kr.createIndex('chunk', 'chunk', { unique: false });
                }
            };
        });
    }

    function metaGet(key) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_META, 'readonly');
                var req = tx.objectStore(STORE_META).get(key);
                req.onsuccess = function () { resolve(req.result || null); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function metaPut(record) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_META, 'readwrite');
                tx.objectStore(STORE_META).put(record);
                tx.oncomplete = function () { resolve(record); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function rowKey(table, pk, id) {
        return String(table) + '|' + String(pk) + '|' + String(id);
    }

    function putRows(chunkId, table, pk, rows) {
        if (!rows || !rows.length) {
            return Promise.resolve(0);
        }
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_ROWS, 'readwrite');
                var store = tx.objectStore(STORE_ROWS);
                rows.forEach(function (row) {
                    var idVal = row[pk];
                    if (idVal == null && table === 'app_settings') {
                        idVal = row.setting_key;
                    }
                    if (idVal == null) {
                        return;
                    }
                    store.put({
                        key: rowKey(table, pk, idVal),
                        table: table,
                        chunk: chunkId,
                        pk: pk,
                        id: idVal,
                        data: row,
                        updatedAt: Date.now(),
                    });
                });
                tx.oncomplete = function () { resolve(rows.length); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function clearTable(table) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_ROWS, 'readwrite');
                var store = tx.objectStore(STORE_ROWS);
                var idx = store.index('table');
                var req = idx.openCursor(IDBKeyRange.only(table));
                req.onsuccess = function (ev) {
                    var cursor = ev.target.result;
                    if (cursor) {
                        cursor.delete();
                        cursor.continue();
                    }
                };
                tx.oncomplete = function () { resolve(); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function getOfflineTable(table) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE_ROWS, 'readonly');
                var store = tx.objectStore(STORE_ROWS);
                var idx = store.index('table');
                var req = idx.getAll(IDBKeyRange.only(table));
                req.onsuccess = function () {
                    var list = (req.result || []).map(function (r) { return r.data; });
                    resolve(list);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function getSnapshot(kind) {
        return getOfflineTable('_snapshots').then(function (rows) {
            for (var i = 0; i < rows.length; i++) {
                if (String(rows[i].kind || '') === kind) {
                    return rows[i].payload || null;
                }
            }
            return null;
        });
    }

    function fetchJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || (body && body.ok === false)) {
                    var msg = (body && body.message) ? body.message : ('HTTP ' + res.status);
                    throw new Error(msg);
                }
                return body;
            });
        });
    }

    function formatBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    /**
     * @param {{years?:number, all?:boolean, onProgress?:function, force?:boolean}} opts
     */
    function downloadKeuanganPack(opts) {
        opts = opts || {};
        if (downloading) {
            return Promise.reject(new Error('Unduhan sedang berjalan.'));
        }
        if (!global.navigator.onLine) {
            return Promise.reject(new Error('Tidak ada koneksi internet.'));
        }
        downloading = true;
        var years = opts.years != null ? opts.years : 2;
        var all = !!opts.all;
        var onProgress = typeof opts.onProgress === 'function' ? opts.onProgress : function () {};
        var qs = 'years=' + encodeURIComponent(String(years)) + (all ? '&all=1' : '');

        return fetchJson(appPath('api/offline/keuangan_meta.php?' + qs))
            .then(function (meta) {
                return metaGet('pack').then(function (local) {
                    if (!opts.force && local && local.pack_version === meta.pack_version) {
                        onProgress({ phase: 'skip', message: 'Data sudah terbaru.', percent: 100, meta: meta });
                        return { skipped: true, meta: meta, local: local };
                    }
                    var chunks = meta.chunks || [];
                    var totalRows = 0;
                    chunks.forEach(function (c) { totalRows += Number(c.count) || 0; });
                    var doneRows = 0;
                    var i = 0;

                    function nextChunk() {
                        if (i >= chunks.length) {
                            var saved = {
                                key: 'pack',
                                pack_version: meta.pack_version,
                                schema_version: meta.schema_version,
                                generated_at: meta.generated_at,
                                downloaded_at: new Date().toISOString(),
                                years: meta.years,
                                all_time: !!meta.all_time,
                                since_date: meta.since_date,
                                table_counts: meta.table_counts || {},
                                approx_bytes: meta.approx_bytes || 0,
                                chunks: chunks,
                            };
                            return metaPut(saved).then(function () {
                                onProgress({ phase: 'done', message: 'Selesai.', percent: 100, meta: meta, local: saved });
                                return { skipped: false, meta: meta, local: saved };
                            });
                        }
                        var chunk = chunks[i++];
                        var chunkId = chunk.id;
                        var table = chunk.table;
                        onProgress({
                            phase: 'chunk',
                            chunk: chunkId,
                            label: chunk.label,
                            message: 'Mengunduh ' + (chunk.label || chunkId) + '…',
                            percent: totalRows > 0 ? Math.min(99, Math.round((doneRows / totalRows) * 100)) : Math.round((i / chunks.length) * 100),
                        });

                        var clearPromise = (table && table !== '_snapshots')
                            ? clearTable(table)
                            : (table === '_snapshots' ? clearTable('_snapshots') : Promise.resolve());

                        return clearPromise.then(function () {
                            var afterId = 0;
                            var afterKey = null;

                            function nextPage() {
                                var url = appPath('api/offline/keuangan_pack.php?chunk=' + encodeURIComponent(chunkId)
                                    + '&' + qs
                                    + '&after_id=' + encodeURIComponent(String(afterId)));
                                if (afterKey) {
                                    url += '&after_key=' + encodeURIComponent(afterKey);
                                }
                                return fetchJson(url).then(function (page) {
                                    var rows = page.rows || [];
                                    var pk = page.pk || chunk.pk || 'id';
                                    return putRows(chunkId, page.table || table, pk, rows).then(function () {
                                        doneRows += rows.length;
                                        onProgress({
                                            phase: 'chunk',
                                            chunk: chunkId,
                                            label: chunk.label,
                                            message: 'Mengunduh ' + (chunk.label || chunkId) + ' (' + doneRows + ' baris)…',
                                            percent: totalRows > 0 ? Math.min(99, Math.round((doneRows / totalRows) * 100)) : Math.round((i / chunks.length) * 100),
                                        });
                                        if (page.has_more) {
                                            afterId = Number(page.next_after_id) || afterId;
                                            afterKey = page.next_after_key || null;
                                            return nextPage();
                                        }
                                        return nextChunk();
                                    });
                                });
                            }

                            return nextPage();
                        });
                    }

                    return nextChunk();
                });
            })
            .finally(function () {
                downloading = false;
            });
    }

    function getLocalPackMeta() {
        return metaGet('pack');
    }

    function isKeuanganPackFresh(serverPackVersion) {
        return getLocalPackMeta().then(function (local) {
            if (!local || !local.pack_version) {
                return false;
            }
            if (!serverPackVersion) {
                return true;
            }
            return local.pack_version === serverPackVersion;
        });
    }

    function autoDownloadIfStale(opts) {
        opts = opts || {};
        if (!global.navigator.onLine || downloading) {
            return Promise.resolve({ skipped: true, reason: 'offline_or_busy' });
        }
        var years = opts.years != null ? opts.years : 2;
        var qs = 'years=' + encodeURIComponent(String(years));
        return fetchJson(appPath('api/offline/keuangan_meta.php?' + qs))
            .then(function (meta) {
                return getLocalPackMeta().then(function (local) {
                    if (local && local.pack_version === meta.pack_version) {
                        return { skipped: true, reason: 'fresh', meta: meta, local: local };
                    }
                    return downloadKeuanganPack({
                        years: years,
                        force: true,
                        onProgress: opts.onProgress,
                    });
                });
            })
            .catch(function (err) {
                return { skipped: true, reason: 'error', error: String(err && err.message ? err.message : err) };
            });
    }

    function renderOfflineBanner(container, text) {
        if (!container) return;
        var el = document.createElement('div');
        el.className = 'alert alert-warning py-2 small mb-3';
        el.setAttribute('role', 'status');
        el.innerHTML = '<i class="fa-solid fa-wifi me-1"></i> ' + (text || 'Mode offline — menampilkan data dari unduhan lokal (baca-saja).');
        container.insertBefore(el, container.firstChild);
    }

    function formatRp(n) {
        n = Number(n) || 0;
        var neg = n < 0;
        var s = Math.abs(n).toLocaleString('id-ID');
        return (neg ? '-Rp ' : 'Rp ') + s;
    }

    /**
     * Render snapshot ke elemen halaman offline-reader.
     */
    function mountOfflineReaders() {
        var root = document.getElementById('keuangan-offline-reader');
        if (!root) {
            return;
        }
        var kind = root.getAttribute('data-kind') || '';
        var body = root.querySelector('[data-offline-body]');
        var online = document.getElementById('keuangan-online-content');
        if (!kind || !body) {
            return;
        }
        if (global.navigator.onLine) {
            root.hidden = true;
            if (online) {
                online.hidden = false;
            }
            autoDownloadIfStale({ years: 2 });
            return;
        }
        if (online) {
            online.hidden = true;
        }
        root.hidden = false;
        getSnapshot(kind).then(function (payload) {
            if (!payload) {
                body.innerHTML = '<div class="alert alert-danger">Data offline belum diunduh. Hubungkan internet lalu buka <a href="' + appPath('keuangan/offline-data.php') + '">Data Offline Keuangan</a>.</div>';
                return;
            }
            renderOfflineBanner(root, 'Mode offline — snapshot «' + kind + '» dari unduhan lokal (baca-saja).');
            body.innerHTML = renderSnapshotHtml(kind, payload);
        }).catch(function (err) {
            body.innerHTML = '<div class="alert alert-danger">Gagal membaca data lokal: ' + String(err && err.message ? err.message : err) + '</div>';
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderSnapshotHtml(kind, payload) {
        if (payload && payload.error) {
            return '<div class="alert alert-warning">Snapshot error: ' + escapeHtml(payload.error) + '</div>';
        }
        if (kind === 'neraca') {
            return ''
                + '<div class="card shadow-sm mb-3"><div class="card-body">'
                + '<h2 class="h6">Neraca per ' + escapeHtml(payload.as_of_label || payload.as_of || '') + '</h2>'
                + '<p class="mb-1 text-muted small">' + escapeHtml(payload.nama_lembaga || '') + '</p>'
                + '<div class="row g-2">'
                + '<div class="col-md-4"><div class="app-mini-stat"><div class="app-mini-stat-label">Total aktiva</div><div class="app-mini-stat-value">' + escapeHtml(formatRp(payload.total_aset)) + '</div></div></div>'
                + '<div class="col-md-4"><div class="app-mini-stat"><div class="app-mini-stat-label">Total pasiva</div><div class="app-mini-stat-value">' + escapeHtml(formatRp(payload.total_pasiva)) + '</div></div></div>'
                + '<div class="col-md-4"><div class="app-mini-stat"><div class="app-mini-stat-label">Selisih</div><div class="app-mini-stat-value">' + escapeHtml(formatRp(payload.selisih)) + '</div></div></div>'
                + '</div></div></div>'
                + '<p class="small text-muted">Ini ringkasan snapshot. Detail lengkap tersedia saat online.</p>';
        }
        if (kind === 'arus_kas') {
            return ''
                + '<div class="card shadow-sm mb-3"><div class="card-body">'
                + '<h2 class="h6">Arus kas ' + escapeHtml(payload.date_from || '') + ' s/d ' + escapeHtml(payload.date_to || '') + '</h2>'
                + '<div class="row g-2">'
                + '<div class="col-md-3"><div class="app-mini-stat"><div class="app-mini-stat-label">Kas awal</div><div class="app-mini-stat-value">' + escapeHtml(formatRp(payload.kas_awal)) + '</div></div></div>'
                + '<div class="col-md-3"><div class="app-mini-stat"><div class="app-mini-stat-label">Masuk</div><div class="app-mini-stat-value text-success">' + escapeHtml(formatRp(payload.total_masuk)) + '</div></div></div>'
                + '<div class="col-md-3"><div class="app-mini-stat"><div class="app-mini-stat-label">Keluar</div><div class="app-mini-stat-value text-danger">' + escapeHtml(formatRp(payload.total_keluar)) + '</div></div></div>'
                + '<div class="col-md-3"><div class="app-mini-stat"><div class="app-mini-stat-label">Kas akhir</div><div class="app-mini-stat-value">' + escapeHtml(formatRp(payload.kas_akhir)) + '</div></div></div>'
                + '</div></div></div>';
        }
        if (kind === 'riwayat_ringkas') {
            var rows = payload.rows || [];
            var html = '<div class="card shadow-sm"><div class="card-header fw-semibold">Riwayat ' + escapeHtml(payload.dari || '') + ' — ' + escapeHtml(payload.sampai || '')
                + ' <span class="text-muted small">(' + rows.length + ' dari ' + (payload.total_rows || rows.length) + ')</span></div>'
                + '<div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Tanggal</th><th>Arah</th><th>Keterangan</th><th class="text-end">Nominal</th></tr></thead><tbody>';
            rows.forEach(function (r) {
                html += '<tr><td>' + escapeHtml(r.tanggal || r.tanggal_bayar || '') + '</td>'
                    + '<td>' + escapeHtml(r.arah || r.tipe || '') + '</td>'
                    + '<td>' + escapeHtml(r.keterangan || r.pos || r.nama_santri || r.subjek || '') + '</td>'
                    + '<td class="text-end">' + escapeHtml(formatRp(r.nominal || r.total_nominal || 0)) + '</td></tr>';
            });
            if (!rows.length) {
                html += '<tr><td colspan="4" class="text-muted text-center">Tidak ada data</td></tr>';
            }
            html += '</tbody></table></div>';
            if (payload.total_masuk != null || payload.total_keluar != null) {
                html += '<div class="card-footer small">Masuk: <strong>' + escapeHtml(formatRp(payload.total_masuk || 0))
                    + '</strong> · Keluar: <strong>' + escapeHtml(formatRp(payload.total_keluar || 0)) + '</strong></div>';
            }
            html += '</div>';
            return html;
        }
        if (kind === 'cashless_ringkas') {
            var per = payload.per_koperasi || [];
            var html2 = '<div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Cashless ' + escapeHtml(payload.dari || '') + ' — ' + escapeHtml(payload.sampai || '') + '</div><div class="card-body">';
            if (Array.isArray(per) && per.length) {
                html2 += '<ul class="mb-0">';
                per.forEach(function (k) {
                    html2 += '<li>' + escapeHtml(k.nama || k.nama_koperasi || ('Koperasi #' + (k.id || ''))) + ': '
                        + escapeHtml(formatRp(k.total_debit || k.nominal || k.total || 0)) + '</li>';
                });
                html2 += '</ul>';
            } else {
                html2 += '<p class="text-muted mb-0 small">Ringkasan tersimpan. Detail transaksi tersedia saat online.</p>';
                if (payload.ringkas) {
                    html2 += '<pre class="small mt-2 mb-0" style="white-space:pre-wrap">' + escapeHtml(JSON.stringify(payload.ringkas, null, 2).slice(0, 2000)) + '</pre>';
                }
            }
            html2 += '</div></div>';
            return html2;
        }
        return '<pre class="small">' + escapeHtml(JSON.stringify(payload, null, 2).slice(0, 4000)) + '</pre>';
    }

    function initStatusPage() {
        var page = document.getElementById('keuangan-offline-data-page');
        if (!page) {
            return;
        }
        var statusEl = document.getElementById('keu-offline-status');
        var progressEl = document.getElementById('keu-offline-progress');
        var barEl = document.getElementById('keu-offline-progress-bar');
        var msgEl = document.getElementById('keu-offline-progress-msg');
        var btnDownload = document.getElementById('keu-offline-download');
        var btnAll = document.getElementById('keu-offline-download-all');
        var btnRefreshMeta = document.getElementById('keu-offline-refresh-meta');

        function setProgress(p) {
            if (!progressEl) return;
            progressEl.hidden = false;
            if (barEl) {
                barEl.style.width = (p.percent || 0) + '%';
                barEl.textContent = (p.percent || 0) + '%';
            }
            if (msgEl) {
                msgEl.textContent = p.message || '';
            }
            if (p.phase === 'done' || p.phase === 'skip') {
                setTimeout(function () {
                    if (progressEl) progressEl.hidden = true;
                    refreshStatus();
                }, 800);
            }
        }

        function refreshStatus() {
            return Promise.all([
                getLocalPackMeta(),
                global.navigator.onLine
                    ? fetchJson(appPath('api/offline/keuangan_meta.php?years=2')).catch(function () { return null; })
                    : Promise.resolve(null),
            ]).then(function (pair) {
                var local = pair[0];
                var server = pair[1];
                if (!statusEl) return;
                if (!local) {
                    statusEl.innerHTML = '<div class="alert alert-warning mb-0">Belum ada data offline di perangkat ini.</div>';
                } else {
                    var fresh = server && server.pack_version === local.pack_version;
                    statusEl.innerHTML = ''
                        + '<dl class="row mb-0 small">'
                        + '<dt class="col-sm-4">Diunduh</dt><dd class="col-sm-8">' + escapeHtml(local.downloaded_at || '—') + '</dd>'
                        + '<dt class="col-sm-4">Versi pack</dt><dd class="col-sm-8"><code>' + escapeHtml(local.pack_version || '') + '</code>'
                        + (fresh ? ' <span class="badge bg-success">Terbaru</span>' : (server ? ' <span class="badge bg-warning text-dark">Perlu perbarui</span>' : '')) + '</dd>'
                        + '<dt class="col-sm-4">Periode</dt><dd class="col-sm-8">' + (local.all_time ? 'Semua waktu' : ('±' + (local.years || 2) + ' tahun (sejak ' + escapeHtml(local.since_date || '') + ')')) + '</dd>'
                        + '<dt class="col-sm-4">Estimasi ukuran</dt><dd class="col-sm-8">' + escapeHtml(formatBytes(local.approx_bytes)) + '</dd>'
                        + '</dl>';
                }
                if (server && statusEl) {
                    var info = document.createElement('p');
                    info.className = 'small text-muted mt-2 mb-0';
                    info.textContent = 'Server: ~' + formatBytes(server.approx_bytes) + ' · ' + Object.keys(server.table_counts || {}).length + ' chunk';
                    statusEl.appendChild(info);
                }
            });
        }

        function runDownload(all) {
            if (btnDownload) btnDownload.disabled = true;
            if (btnAll) btnAll.disabled = true;
            downloadKeuanganPack({
                years: 2,
                all: !!all,
                force: true,
                onProgress: setProgress,
            }).then(function () {
                refreshStatus();
            }).catch(function (err) {
                setProgress({ phase: 'error', percent: 0, message: String(err && err.message ? err.message : err) });
                if (progressEl) progressEl.hidden = false;
            }).finally(function () {
                if (btnDownload) btnDownload.disabled = false;
                if (btnAll) btnAll.disabled = false;
            });
        }

        if (btnDownload) {
            btnDownload.addEventListener('click', function () { runDownload(false); });
        }
        if (btnAll) {
            btnAll.addEventListener('click', function () {
                if (global.confirm('Unduh semua data keuangan (bisa besar & lama). Lanjutkan?')) {
                    runDownload(true);
                }
            });
        }
        if (btnRefreshMeta) {
            btnRefreshMeta.addEventListener('click', function () { refreshStatus(); });
        }
        refreshStatus();
        if (global.navigator.onLine) {
            autoDownloadIfStale({
                years: 2,
                onProgress: setProgress,
            }).then(function (r) {
                if (r && !r.skipped) {
                    refreshStatus();
                }
            });
        }
    }

    function init() {
        if (!('indexedDB' in global)) {
            return;
        }
        initStatusPage();
        mountOfflineReaders();
        // Di halaman keuangan lain (mis. index): unduh/perbarui pack di background saat online.
        if (
            global.navigator.onLine
            && !document.getElementById('keuangan-offline-data-page')
            && !document.getElementById('keuangan-offline-reader')
        ) {
            autoDownloadIfStale({ years: 2 });
        }
    }

    global.PondokKeuanganOffline = {
        downloadKeuanganPack: downloadKeuanganPack,
        getOfflineTable: getOfflineTable,
        getSnapshot: getSnapshot,
        getLocalPackMeta: getLocalPackMeta,
        isKeuanganPackFresh: isKeuanganPackFresh,
        autoDownloadIfStale: autoDownloadIfStale,
        formatBytes: formatBytes,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
