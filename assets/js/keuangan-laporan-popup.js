(function () {
    'use strict';

    var dataEl = document.getElementById('laporan-popup-data');
    if (!dataEl) {
        return;
    }

    var payload;
    try {
        payload = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return;
    }

    function esc(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtRp(n) {
        return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    }

    function int(v) {
        return parseInt(v, 10) || 0;
    }

    function panelEl(key) {
        if (key === 'santri') {
            return document.querySelector('[data-laporan-popup-panel="santri"]');
        }
        return document.querySelector('[data-laporan-popup-panel="detail"]');
    }

    function closeAll() {
        document.querySelectorAll('[data-laporan-popup-panel]').forEach(function (p) {
            p.classList.add('d-none');
            p.innerHTML = '';
        });
        document.querySelectorAll('.bendahara-stat--clickable.is-open').forEach(function (b) {
            b.classList.remove('is-open');
            b.setAttribute('aria-expanded', 'false');
        });
    }

    function renderSantri() {
        var list = Array.isArray(payload.santri) ? payload.santri : [];
        if (!list.length) {
            return '<p class="text-muted small mb-0">Tidak ada santri aktif.</p>';
        }
        var html = '<ul class="bendahara-stat-popup__list">';
        list.forEach(function (s) {
            var sub = esc(s.tingkatan || '');
            if (s.nis) {
                sub += (sub ? ' · ' : '') + esc(s.nis);
            }
            html += '<li><span class="bendahara-stat-popup__name">' + esc(s.nama_santri || '') + '</span>';
            if (sub) {
                html += '<span class="bendahara-stat-popup__sub">' + sub + '</span>';
            }
            html += '</li>';
        });
        html += '</ul>';
        return html;
    }

    function renderAlokasi(filter) {
        var rows = Array.isArray(payload.alokasi) ? payload.alokasi : [];
        if (!rows.length) {
            return '<p class="text-muted small mb-0">Belum ada data alokasi.</p>';
        }
        var html = '<ul class="bendahara-stat-popup__list">';
        rows.forEach(function (r) {
            var harus = int(r.harus_masuk);
            var masuk = int(r.masuk);
            var saldo = int(r.saldo);
            if (filter === 'sisa' && saldo <= 0) {
                return;
            }
            var val = filter === 'masuk' ? masuk : (filter === 'harus' ? harus : saldo);
            html += '<li><span class="bendahara-stat-popup__name">' + esc(r.nama || '') + '</span>';
            html += '<span class="bendahara-stat-popup__sub font-monospace">' + fmtRp(val) + '</span></li>';
        });
        html += '</ul>';
        return html;
    }

    function renderPos() {
        var rows = Array.isArray(payload.pos) ? payload.pos : [];
        if (!rows.length) {
            return '<p class="text-muted small mb-0">Tidak ada komponen POS.</p>';
        }
        var html = '<ul class="bendahara-stat-popup__list">';
        rows.forEach(function (r) {
            var exp = int(r.expected);
            var paid = int(r.paid);
            var sisa = Math.max(0, exp - paid);
            html += '<li><span class="bendahara-stat-popup__name">' + esc(r.pos_nama || r.pos_slug || '') + '</span>';
            html += '<span class="bendahara-stat-popup__sub font-monospace">Target ' + fmtRp(exp) + ' · Masuk ' + fmtRp(paid);
            if (sisa > 0) {
                html += ' · Sisa ' + fmtRp(sisa);
            }
            html += '</span></li>';
        });
        html += '</ul>';
        return html;
    }

    var titles = {
        santri: 'Santri aktif',
        harus: 'Rincian harus masuk (alokasi)',
        masuk: 'Rincian masuk (alokasi)',
        sisa: 'Rincian saldo alokasi',
        pos: 'Komponen tagihan POS',
    };

    function bodyFor(key) {
        if (key === 'santri') {
            return renderSantri();
        }
        if (key === 'harus' || key === 'masuk' || key === 'sisa') {
            return renderAlokasi(key);
        }
        if (key === 'pos') {
            return renderPos();
        }
        return '';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-laporan-popup]');
        if (!btn) {
            if (!e.target.closest('[data-laporan-popup-panel]')) {
                closeAll();
            }
            return;
        }
        e.preventDefault();
        var key = btn.getAttribute('data-laporan-popup') || '';
        var panel = panelEl(key);
        if (!panel) {
            return;
        }
        var isOpen = btn.classList.contains('is-open');
        closeAll();
        if (isOpen) {
            return;
        }
        var title = titles[key] || 'Detail';
        if (payload.bulan_label && key !== 'santri') {
            title += ' · ' + payload.bulan_label;
        }
        panel.innerHTML =
            '<div class="bendahara-stat-popup__head"><strong>' + esc(title) + '</strong></div>' +
            bodyFor(key);
        panel.classList.remove('d-none');
        btn.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
    });
})();
