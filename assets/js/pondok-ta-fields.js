(function () {
    function syncTaSelesai(wrap) {
        const mulai = wrap.querySelector('.pondok-ta-mulai');
        const selesai = wrap.querySelector('.pondok-ta-selesai');
        if (!mulai || !selesai || wrap.getAttribute('data-ta-hijri') !== '1') {
            return;
        }
        const m = parseInt(mulai.value, 10) || 0;
        if (m > 0) {
            selesai.value = String(m + 1);
        }
    }

    document.querySelectorAll('.pondok-ta-field[data-ta-hijri="1"]').forEach(function (wrap) {
        const mulai = wrap.querySelector('.pondok-ta-mulai');
        if (!mulai) {
            return;
        }
        mulai.addEventListener('input', function () {
            syncTaSelesai(wrap);
        });
        mulai.addEventListener('change', function () {
            syncTaSelesai(wrap);
        });
        syncTaSelesai(wrap);
    });
})();
