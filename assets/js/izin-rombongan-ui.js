(function () {
    var cari = document.getElementById('izin-rombongan-cari-santri');
    var pickWrap = document.getElementById('rombongan-input-wrap');
    var terpilihWrap = document.getElementById('izin-rombongan-santri-terpilih');
    if (!cari || !pickWrap) {
        return;
    }

    function rowSearchHaystack(row) {
        var ds = (row.getAttribute('data-search') || '').toLowerCase();
        if (ds) {
            return ds;
        }
        var label = row.querySelector('.form-check-label');
        return (label ? label.textContent : row.textContent || '').toLowerCase();
    }

    function syncSantriTerpilih() {
        if (!terpilihWrap) {
            return;
        }
        terpilihWrap.innerHTML = '';
        pickWrap.querySelectorAll('.rombongan-santri-cb:checked').forEach(function (cb) {
            var row = cb.closest('.rombongan-santri-picker__row');
            var nis = row ? (row.getAttribute('data-nis') || '').trim() : '';
            var nama = row ? (row.getAttribute('data-nama') || '').trim() : '';
            var badge = document.createElement('span');
            badge.className = 'badge text-bg-primary';
            if (nis !== '' && nama !== '') {
                badge.textContent = nis + ' — ' + nama;
            } else if (nis !== '') {
                badge.textContent = nis;
            } else if (nama !== '') {
                badge.textContent = nama;
            } else {
                badge.textContent = '#' + cb.value;
            }
            terpilihWrap.appendChild(badge);
        });
    }

    function filterSantriPicker() {
        var q = (cari.value || '').trim().toLowerCase();
        pickWrap.hidden = q.length < 1;
        if (q.length < 1) {
            return;
        }
        pickWrap.querySelectorAll('.rombongan-santri-picker__row').forEach(function (row) {
            row.style.display = rowSearchHaystack(row).indexOf(q) !== -1 ? '' : 'none';
        });
        pickWrap.querySelectorAll('.rombongan-santri-picker__group').forEach(function (grp) {
            var visible = Array.prototype.some.call(
                grp.querySelectorAll('.rombongan-santri-picker__row'),
                function (r) { return r.style.display !== 'none'; }
            );
            grp.style.display = visible ? '' : 'none';
        });
    }

    cari.addEventListener('input', filterSantriPicker);
    pickWrap.addEventListener('change', syncSantriTerpilih);
    syncSantriTerpilih();
})();
