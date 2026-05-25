(function () {
    var submitTimer = null;

    function submitFormDebounced(form, delayMs) {
        if (!form) {
            return;
        }
        if (submitTimer) {
            clearTimeout(submitTimer);
        }
        submitTimer = setTimeout(function () {
            submitTimer = null;
            if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }, delayMs);
    }

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

    function syncTaFromSelect(select) {
        const wrap = select.closest('.pondok-ta-field--dropdown, .pondok-ta-toolbar-form, .keuangan-ta-toolbar-form');
        if (!wrap) {
            return;
        }
        const opt = select.options[select.selectedIndex];
        const ts = opt ? parseInt(opt.getAttribute('data-ts') || '0', 10) : 0;
        const hidden = wrap.querySelector('.pondok-ta-selesai-hidden, .keuangan-ta-ts-hidden');
        if (hidden && ts > 0) {
            hidden.value = String(ts);
        }
        if (select.getAttribute('data-auto-submit') === '1') {
            const form = select.closest('form');
            submitFormDebounced(form, 350);
        }
    }

    function onBulanSelectChange(select) {
        if (select.getAttribute('data-auto-submit') !== '1') {
            return;
        }
        const form = select.closest('form');
        submitFormDebounced(form, 200);
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

    document.querySelectorAll('.pondok-ta-select, .keuangan-ta-select').forEach(function (select) {
        select.addEventListener('change', function () {
            syncTaFromSelect(select);
        });
    });

    document.querySelectorAll('.pondok-bulan-select, select[name="bulan"], select[name="bulan_tagihan"], select[name="rekap_bulan"]').forEach(function (select) {
        if (select.getAttribute('data-auto-submit') === '1' || select.classList.contains('pondok-bulan-select')) {
            select.setAttribute('data-auto-submit', '1');
            select.addEventListener('change', function () {
                onBulanSelectChange(select);
            });
        }
    });
})();
