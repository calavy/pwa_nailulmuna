/**
 * Jadwal — edit cepat via modal & hapus tunggal.
 */
(function () {
    'use strict';

    var deleteForm = document.getElementById('form-jadwal-delete-one');
    if (deleteForm) {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.jadwal-delete-one');
            if (!btn) {
                return;
            }
            var msg = btn.getAttribute('data-confirm') || 'Hapus slot jadwal ini? Presensi terkait ikut dihapus.';
            if (!window.confirm(msg)) {
                return;
            }
            var raw = btn.getAttribute('data-delete-ids') || '';
            var ids = raw.split(',').map(function (s) {
                return parseInt(s.trim(), 10);
            }).filter(function (n) {
                return n > 0;
            });
            deleteForm.querySelectorAll('input[name="ids[]"]').forEach(function (el) {
                el.remove();
            });
            ids.forEach(function (id) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'ids[]';
                inp.value = String(id);
                deleteForm.appendChild(inp);
            });
            deleteForm.submit();
        });
    }

    var modalEl = document.getElementById('jadwalQuickEditModal');
    var form = document.getElementById('jadwalQuickEditForm');
    if (!modalEl || !form || typeof bootstrap === 'undefined') {
        return;
    }
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function setTingkatanChecked(list) {
        var names = Array.isArray(list) ? list : [];
        form.querySelectorAll('input[name="tingkatan[]"]').forEach(function (cb) {
            cb.checked = names.indexOf(cb.value) !== -1;
        });
    }

    function setHariChecked(list) {
        var ids = Array.isArray(list) ? list.map(Number) : [];
        form.querySelectorAll('.jq-hari-check').forEach(function (cb) {
            cb.checked = ids.indexOf(Number(cb.value)) !== -1;
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.jadwal-quick-edit');
        if (!btn) {
            return;
        }
        var id = btn.getAttribute('data-edit-id') || '';
        if (!id) {
            return;
        }
        var base = form.getAttribute('data-edit-base') || ((window.PONDOK_APP_BASE || '') + '/jadwal/edit.php');
        form.action = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);

        var keg = document.getElementById('jq-kegiatan');
        if (keg) {
            keg.value = btn.getAttribute('data-kegiatan-id') || '';
        }
        var pb = document.getElementById('jq-pembimbing');
        if (pb) {
            pb.value = btn.getAttribute('data-pembimbing-id') || '0';
        }
        var jm = document.getElementById('jq-jam-mulai');
        if (jm) {
            jm.value = btn.getAttribute('data-jam-mulai') || '';
        }
        var js = document.getElementById('jq-jam-selesai');
        if (js) {
            js.value = btn.getAttribute('data-jam-selesai') || '';
        }
        var tp = document.getElementById('jq-tempat');
        if (tp) {
            tp.value = btn.getAttribute('data-tempat') || '';
        }

        try {
            setTingkatanChecked(JSON.parse(btn.getAttribute('data-tingkatan') || '[]'));
        } catch (err) {
            setTingkatanChecked([]);
        }
        try {
            setHariChecked(JSON.parse(btn.getAttribute('data-hari') || '[]'));
        } catch (err2) {
            setHariChecked([]);
        }

        modal.show();
    });
})();
