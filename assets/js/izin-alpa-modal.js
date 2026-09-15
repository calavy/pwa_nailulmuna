(function (global) {
    'use strict';

    function esc(text) {
        var el = document.createElement('div');
        el.textContent = text == null ? '' : String(text);
        return el.innerHTML;
    }

    function parsePayload(source) {
        if (!source) {
            return null;
        }
        if (typeof source === 'object') {
            return source;
        }
        try {
            return JSON.parse(String(source));
        } catch (e) {
            return null;
        }
    }

    function payloadFromElement(el) {
        if (!el) {
            return null;
        }
        var raw = el.getAttribute('data-alpa-modal');
        if (raw) {
            return parsePayload(raw);
        }
        var subject = el.getAttribute('data-alpa-subject') === '1';
        if (!subject) {
            return { subject: false, na_message: 'Syarat ALPA tidak berlaku untuk permohonan ini.' };
        }
        return {
            subject: true,
            allowed: el.getAttribute('data-alpa-allowed') === '1',
            status_label: el.getAttribute('data-alpa-status-label') || '',
            stat_line: (el.getAttribute('data-alpa-jumlah') || el.getAttribute('data-alpa-count') || '0') + ' ALPA · ' + (el.getAttribute('data-alpa-periode') || (el.getAttribute('data-alpa-hari') || '0') + ' hari'),
            syarat_line: [el.getAttribute('data-alpa-aturan'), el.getAttribute('data-alpa-blokir')].filter(Boolean).join(' · '),
            progress_pct: 0,
            progress_label: el.getAttribute('data-alpa-progress') || '',
            note: el.getAttribute('data-alpa-catatan') || '',
            glossary: 'ALPA = tidak hadir ke kegiatan wajib tanpa izin/sakit resmi.',
            na_message: ''
        };
    }

    /**
     * @param {HTMLElement|null} panelEl
     * @param {object|null} payload
     * @param {{ rombonganNote?: string }} opts
     */
    function renderIzinAlpaModal(panelEl, payload, opts) {
        opts = opts || {};
        if (!panelEl) {
            return;
        }

        payload = parsePayload(payload) || payload;
        if (!payload || !payload.subject) {
            panelEl.className = 'alert alert-secondary py-2 small mb-3 izin-alpa-panel-modal';
            panelEl.innerHTML = '<div class="izin-alpa-modal izin-alpa-modal--na">' +
                esc(payload && payload.na_message ? payload.na_message : 'Syarat ALPA tidak berlaku untuk permohonan ini.') +
                '</div>';
            return;
        }

        var allowed = !!payload.allowed;
        var statusClass = allowed ? 'success' : 'danger';
        var statusIcon = allowed ? '✓' : '✗';
        var rombonganNote = opts.rombonganNote ? String(opts.rombonganNote) : '';
        var progressPct = parseInt(payload.progress_pct, 10);
        if (isNaN(progressPct)) {
            progressPct = 0;
        }
        progressPct = Math.max(0, Math.min(100, progressPct));
        var barWidth = payload.progress_label ? Math.max(4, progressPct) : 0;

        var html = '<div class="izin-alpa-modal izin-alpa-modal--' + (allowed ? 'ok' : 'blocked') + '">';
        if (rombonganNote) {
            html += '<div class="izin-alpa-modal__rombongan fw-semibold mb-2">' + esc(rombonganNote) + '</div>';
        }
        html += '<div class="izin-alpa-modal__status badge text-bg-' + statusClass + ' mb-2">' +
            esc(statusIcon + ' ' + (payload.status_label || (allowed ? 'Masih boleh disetujui' : 'Terhalang syarat ALPA'))) +
            '</div>';
        if (payload.stat_line) {
            html += '<div class="izin-alpa-modal__stat">' + esc(payload.stat_line) + '</div>';
        }
        if (payload.progress_label) {
            html += '<div class="izin-alpa-modal__progress mt-2" role="presentation">' +
                '<div class="progress izin-alpa-progress" style="height:6px">' +
                '<div class="progress-bar bg-' + statusClass + '" style="width:' + barWidth + '%"></div>' +
                '</div>' +
                '<div class="izin-alpa-modal__progress-label text-muted">' + esc(payload.progress_label) + '</div>' +
                '</div>';
        }
        if (payload.syarat_line) {
            html += '<div class="izin-alpa-modal__syarat text-muted mt-2">' + esc(payload.syarat_line) + '</div>';
        }
        if (payload.note) {
            html += '<div class="izin-alpa-modal__note mt-2 ' + (allowed ? 'text-warning-emphasis' : 'text-danger') + '">' +
                esc(payload.note) + '</div>';
        }
        if (payload.glossary) {
            html += '<div class="izin-alpa-modal__glossary mt-3">' + esc(payload.glossary) + '</div>';
        }
        html += '</div>';

        panelEl.className = 'alert py-2 small mb-3 izin-alpa-panel-modal alert-' + statusClass;
        panelEl.innerHTML = html;
    }

    global.renderIzinAlpaModal = renderIzinAlpaModal;
    global.parseIzinAlpaModalPayload = payloadFromElement;
})(typeof window !== 'undefined' ? window : this);
