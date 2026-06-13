(function () {
    var TUJUAN_JENIS = ['KELUAR', 'TUGAS', 'SYARI'];

    function perluTujuan(kode) {
        return TUJUAN_JENIS.indexOf(String(kode || '').toUpperCase()) >= 0;
    }

    function syncWrap(wrap) {
        if (!wrap || wrap.getAttribute('data-always-visible') === '1') {
            return;
        }
        var selectId = wrap.getAttribute('data-jenis-select') || '';
        var sel = selectId ? document.getElementById(selectId) : null;
        var input = wrap.querySelector('.perizinan-tujuan-input');
        if (!sel || !input) {
            return;
        }
        var show = perluTujuan(sel.value);
        wrap.classList.toggle('d-none', !show);
        input.required = show;
        if (!show) {
            input.value = '';
        }
    }

    function init() {
        document.querySelectorAll('.perizinan-tujuan-wrap').forEach(function (wrap) {
            syncWrap(wrap);
            var selectId = wrap.getAttribute('data-jenis-select') || '';
            var sel = selectId ? document.getElementById(selectId) : null;
            if (sel) {
                sel.addEventListener('change', function () {
                    syncWrap(wrap);
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
