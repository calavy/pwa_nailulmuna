(function () {
    var container = document.getElementById('izin-tetap-slot-bloks');
    var tpl = document.getElementById('tpl-izin-tetap-slot-blok');
    var addBtn = document.getElementById('btn-tambah-blok-slot');
    if (!container || !tpl) {
        return;
    }

    function reindexBloks() {
        container.querySelectorAll('.izin-tetap-slot-blok').forEach(function (blok, idx) {
            blok.setAttribute('data-blok-index', String(idx));
            var label = blok.querySelector('.izin-tetap-blok-label');
            if (label) {
                label.textContent = 'Blok waktu ' + (idx + 1);
            }
            blok.querySelectorAll('[name^="slot_hari["]').forEach(function (el) {
                el.name = 'slot_hari[' + idx + '][]';
            });
            blok.querySelectorAll('.izin-tetap-jam-mulai').forEach(function (el) {
                el.name = 'slot_jam_mulai[' + idx + ']';
            });
            blok.querySelectorAll('.izin-tetap-jam-selesai').forEach(function (el) {
                el.name = 'slot_jam_selesai[' + idx + ']';
            });
            blok.querySelectorAll('.js-izin-tetap-hari-semua, .js-izin-tetap-hari-bersih').forEach(function (btn) {
                btn.setAttribute('data-blok', String(idx));
            });
            blok.querySelectorAll('.izin-tetap-hari-cb').forEach(function (cb) {
                var hk = cb.value;
                var newId = 'slot-hari-' + idx + '-' + hk;
                cb.id = newId;
                var row = cb.closest('.form-check');
                var lbl = row ? row.querySelector('label') : null;
                if (lbl) {
                    lbl.setAttribute('for', newId);
                }
            });
            var hapusBtn = blok.querySelector('.js-izin-tetap-hapus-blok');
            if (hapusBtn) {
                hapusBtn.style.display = idx === 0 && container.querySelectorAll('.izin-tetap-slot-blok').length === 1
                    ? 'none'
                    : '';
            }
        });
    }

    function blokByIndex(idx) {
        return container.querySelector('.izin-tetap-slot-blok[data-blok-index="' + idx + '"]');
    }

    addBtn?.addEventListener('click', function () {
        var idx = container.querySelectorAll('.izin-tetap-slot-blok').length;
        var html = tpl.innerHTML.replace(/__IDX__/g, String(idx)).replace(/__NUM__/g, String(idx + 1));
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        container.appendChild(wrap.firstElementChild);
        reindexBloks();
        container.dispatchEvent(new CustomEvent('izin-tetap-slots-changed'));
    });

    container.addEventListener('click', function (ev) {
        var semua = ev.target.closest('.js-izin-tetap-hari-semua');
        if (semua) {
            var b = blokByIndex(semua.getAttribute('data-blok'));
            b?.querySelectorAll('.izin-tetap-hari-cb').forEach(function (cb) { cb.checked = true; });
            container.dispatchEvent(new CustomEvent('izin-tetap-slots-changed'));
            return;
        }
        var bersih = ev.target.closest('.js-izin-tetap-hari-bersih');
        if (bersih) {
            var b2 = blokByIndex(bersih.getAttribute('data-blok'));
            b2?.querySelectorAll('.izin-tetap-hari-cb').forEach(function (cb) { cb.checked = false; });
            container.dispatchEvent(new CustomEvent('izin-tetap-slots-changed'));
            return;
        }
        var hapus = ev.target.closest('.js-izin-tetap-hapus-blok');
        if (hapus) {
            var rows = container.querySelectorAll('.izin-tetap-slot-blok');
            if (rows.length <= 1) {
                return;
            }
            hapus.closest('.izin-tetap-slot-blok')?.remove();
            reindexBloks();
            container.dispatchEvent(new CustomEvent('izin-tetap-slots-changed'));
        }
    });

    container.addEventListener('change', function (ev) {
        if (ev.target.matches('.izin-tetap-hari-cb, .izin-tetap-jam-mulai, .izin-tetap-jam-selesai')) {
            container.dispatchEvent(new CustomEvent('izin-tetap-slots-changed'));
        }
    });
    container.addEventListener('input', function (ev) {
        if (ev.target.matches('.izin-tetap-jam-mulai, .izin-tetap-jam-selesai')) {
            container.dispatchEvent(new CustomEvent('izin-tetap-slots-changed'));
        }
    });

    reindexBloks();
})();
