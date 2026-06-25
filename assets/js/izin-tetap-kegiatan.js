(function () {
    var form = document.getElementById('form-izin-tetap');
    var wrap = document.getElementById('izin-tetap-kegiatan-wrap');
    if (!form || !wrap) {
        return;
    }

    var ajaxUrl = form.getAttribute('data-kegiatan-url') || '';
    var timer = null;
    var lastChecked = {};

    function collectChecked() {
        wrap.querySelectorAll('.izin-tetap-kg-cb:checked').forEach(function (cb) {
            var v = (cb.value || '').trim();
            if (v) {
                lastChecked[v] = true;
            }
        });
    }

    function readSlots() {
        var fd = new FormData();
        var bloks = form.querySelectorAll('#izin-tetap-slot-bloks .izin-tetap-slot-blok');
        if (bloks.length) {
            bloks.forEach(function (blok, idx) {
                blok.querySelectorAll('.izin-tetap-hari-cb:checked').forEach(function (cb) {
                    fd.append('slot_hari[' + idx + '][]', cb.value);
                });
                var jm = blok.querySelector('.izin-tetap-jam-mulai');
                var js = blok.querySelector('.izin-tetap-jam-selesai');
                if (jm) {
                    fd.append('slot_jam_mulai[' + idx + ']', jm.value);
                }
                if (js) {
                    fd.append('slot_jam_selesai[' + idx + ']', js.value);
                }
            });
            return fd;
        }
        var rows = form.querySelectorAll('#slot-rows .slot-row');
        rows.forEach(function (row) {
            var hari = row.querySelector('[name="hari_ke[]"]');
            var jm = row.querySelector('[name="jam_mulai[]"]');
            var js = row.querySelector('[name="jam_selesai[]"]');
            if (hari) {
                fd.append('hari_ke[]', hari.value);
            }
            if (jm) {
                fd.append('jam_mulai[]', jm.value);
            }
            if (js) {
                fd.append('jam_selesai[]', js.value);
            }
        });
        return fd;
    }

    function appendSantri(fd) {
        var sid = form.querySelector('[name="santri_id"]');
        if (sid && sid.value) {
            fd.append('santri_id', sid.value);
        }
        form.querySelectorAll('.rombongan-santri-cb:checked, input[name="santri_ids[]"]:checked').forEach(function (cb) {
            fd.append('santri_ids[]', cb.value);
        });
        var jenis = form.querySelector('[name="jenis"]');
        if (jenis) {
            fd.append('jenis', jenis.value);
        }
    }

    function renderItems(items) {
        collectChecked();
        if (!items || !items.length) {
            wrap.innerHTML = '<p class="text-muted small mb-0 py-1" id="izin-tetap-kegiatan-kosong">Tidak ada kegiatan pada jadwal yang bertabrakan dengan durasi jam hidmah. Periksa jadwal pondok atau isi kegiatan lain secara manual.</p>';
            return;
        }

        var html = '<div class="form-text mb-1">Kegiatan otomatis dari jadwal &amp; durasi — centang yang ditinggalkan:</div>';
        var sidEl = form.querySelector('[name="santri_id"]');
        var pickedSantri = form.querySelectorAll('.rombongan-santri-cb:checked, input[name="santri_ids[]"]:checked').length;
        if (sidEl && sidEl.value) {
            pickedSantri = Math.max(pickedSantri, 1);
        }
        var multiHint = document.getElementById('izin-tetap-kegiatan-hint-multi');
        if (multiHint) {
            multiHint.hidden = pickedSantri <= 1;
        }
        items.forEach(function (item) {
            var nama = (item.nama || '').trim();
            if (!nama) {
                return;
            }
            var id = 'kg-ditinggalkan-' + nama.toLowerCase().replace(/[^a-z0-9]+/g, '-');
            var label = (item.label || '').trim();
            var checked = Object.prototype.hasOwnProperty.call(lastChecked, nama)
                ? lastChecked[nama]
                : true;
            html += '<div class="form-check izin-tetap-kg-row" data-nama="' + escapeHtml(nama) + '">';
            html += '<input class="form-check-input izin-tetap-kg-cb" type="checkbox" name="kegiatan_ditinggalkan_items[]" id="' + escapeHtml(id) + '" value="' + escapeHtml(nama) + '"' + (checked ? ' checked' : '') + '>';
            html += '<label class="form-check-label small" for="' + escapeHtml(id) + '">';
            html += '<span class="fw-semibold">' + escapeHtml(nama) + '</span>';
            if (label) {
                html += ' <span class="text-muted">(' + escapeHtml(label) + ')</span>';
            }
            html += '</label></div>';
        });
        wrap.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function refreshKegiatan() {
        if (!ajaxUrl) {
            return;
        }
        var fd = readSlots();
        appendSantri(fd);
        var sid = form.querySelector('[name="santri_id"]');
        var pickedCount = form.querySelectorAll('.rombongan-santri-cb:checked, input[name="santri_ids[]"]:checked').length;
        if (sid && sid.value) {
            pickedCount = Math.max(pickedCount, 1);
        }
        var multiHint = document.getElementById('izin-tetap-kegiatan-hint-multi');
        if (multiHint) {
            multiHint.hidden = pickedCount <= 1;
        }
        fetch(ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                renderItems(data.items || []);
            })
            .catch(function () { /* abaikan */ });
    }

    function scheduleRefresh() {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(refreshKegiatan, 350);
    }

    form.querySelector('#izin-tetap-slot-bloks')?.addEventListener('izin-tetap-slots-changed', scheduleRefresh);
    form.querySelector('#slot-rows')?.addEventListener('change', scheduleRefresh);
    form.querySelector('#izin-tetap-slot-bloks')?.addEventListener('input', scheduleRefresh);
    form.querySelector('#slot-rows')?.addEventListener('input', scheduleRefresh);
    form.addEventListener('change', function (ev) {
        var t = ev.target;
        if (!t) {
            return;
        }
        if (t.matches('[name="santri_id"], [name="jenis"], .rombongan-santri-cb, input[name="santri_ids[]"]')) {
            scheduleRefresh();
        }
    });

    document.getElementById('btn-tambah-blok-slot')?.addEventListener('click', function () {
        setTimeout(scheduleRefresh, 80);
    });
    document.getElementById('btn-tambah-slot')?.addEventListener('click', function () {
        setTimeout(scheduleRefresh, 50);
    });
    form.querySelector('#slot-rows')?.addEventListener('click', function (ev) {
        if (ev.target.closest('.btn-hapus-slot')) {
            setTimeout(scheduleRefresh, 50);
        }
    });

    var blok = document.getElementById('blok-kegiatan-ditinggalkan');
    var jenisSel = document.getElementById('izin-tetap-jenis');
    function syncBlok() {
        if (!blok) {
            return;
        }
        blok.style.display = '';
    }
    jenisSel?.addEventListener('change', function () {
        scheduleRefresh();
    });
    syncBlok();

    if (!wrap.querySelector('.izin-tetap-kg-cb')) {
        scheduleRefresh();
    }
})();
