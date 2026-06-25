/**
 * Jadwal — edit cepat via modal, hapus tunggal, atur waktu jamaah.
 */
(function () {
    'use strict';

    function parseTime24(str) {
        var m = String(str || '').trim().match(/^(\d{1,2}):(\d{2})$/);
        if (!m) {
            return null;
        }
        var h = parseInt(m[1], 10);
        var min = parseInt(m[2], 10);
        if (h < 0 || h > 23 || min < 0 || min > 59) {
            return null;
        }
        return h * 60 + min;
    }

    function formatTime24(totalMin) {
        var t = ((totalMin % (24 * 60)) + (24 * 60)) % (24 * 60);
        var h = Math.floor(t / 60);
        var m = t % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function nudgeTimeInput(input, deltaMin) {
        if (!input) {
            return;
        }
        var cur = parseTime24(input.value);
        if (cur === null) {
            cur = 6 * 60;
        }
        input.value = formatTime24(cur + deltaMin);
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.addEventListener('click', function (e) {
        var nudgeBtn = e.target.closest('.jadwal-time-nudge');
        if (nudgeBtn) {
            var scope = nudgeBtn.closest('.jadwal-jamaah-kelompok, .jadwal-jamaah-card, .modal-body, form');
            if (!scope) {
                return;
            }
            var target = nudgeBtn.getAttribute('data-target');
            var delta = parseInt(nudgeBtn.getAttribute('data-delta') || '0', 10);
            var sel = target === 'js' ? '.jadwal-jamaah-js, #jq-jam-selesai' : '.jadwal-jamaah-jm, #jq-jam-mulai';
            var input = scope.querySelector(sel);
            nudgeTimeInput(input, delta);
            return;
        }

        var saranBtn = e.target.closest('.jadwal-jamaah-isi-saran');
        if (saranBtn) {
            var form = saranBtn.closest('form');
            if (!form) {
                return;
            }
            var jm = form.querySelector('.jadwal-jamaah-jm');
            var js = form.querySelector('.jadwal-jamaah-js');
            if (jm) {
                jm.value = saranBtn.getAttribute('data-jm') || '';
            }
            if (js) {
                js.value = saranBtn.getAttribute('data-js') || '';
            }
        }
    });

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
