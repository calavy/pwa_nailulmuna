(function () {
    'use strict';

    var cfg = window.SKBT_PREVIEW || {};
    var form = document.getElementById('skbt-meta-form');
    var iframe = document.getElementById('skbt-preview-iframe');
    var btnPrint = document.getElementById('skbt-btn-print');
    if (!form || !iframe || !cfg.previewUrl) {
        return;
    }

    var storageKey = 'skbt_meta_' + String((cfg.baseParams || {}).santri_id || '0');
    var debounceTimer = null;

    function loadStored() {
        try {
            var raw = sessionStorage.getItem(storageKey);
            if (!raw) return;
            var data = JSON.parse(raw);
            Object.keys(data).forEach(function (key) {
                var el = form.elements.namedItem(key);
                if (el && 'value' in el) {
                    el.value = data[key];
                }
            });
        } catch (e) { /* ignore */ }
    }

    function saveStored() {
        try {
            var data = {};
            Array.prototype.forEach.call(form.elements, function (el) {
                if (el.name) {
                    data[el.name] = el.value;
                }
            });
            sessionStorage.setItem(storageKey, JSON.stringify(data));
        } catch (e) { /* ignore */ }
    }

    function buildParams() {
        var params = Object.assign({}, cfg.baseParams || {});
        Array.prototype.forEach.call(form.elements, function (el) {
            if (el.name && el.value !== '') {
                params[el.name] = el.value;
            }
        });
        return params;
    }

    function refreshPreview() {
        var params = buildParams();
        params.preview = '1';
        params.embed = '1';
        var qs = new URLSearchParams(params).toString();
        iframe.src = cfg.previewUrl + (cfg.previewUrl.indexOf('?') >= 0 ? '&' : '?') + qs;
        saveStored();
    }

    function scheduleRefresh() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(refreshPreview, 300);
    }

    form.addEventListener('input', scheduleRefresh);
    form.addEventListener('change', scheduleRefresh);

    if (btnPrint) {
        btnPrint.addEventListener('click', function () {
            var params = buildParams();
            var qs = new URLSearchParams(params).toString();
            window.open(cfg.previewUrl + (cfg.previewUrl.indexOf('?') >= 0 ? '&' : '?') + qs, '_blank');
        });
    }

    loadStored();
    refreshPreview();
})();
