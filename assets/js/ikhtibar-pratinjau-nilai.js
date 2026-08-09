(function () {
    'use strict';

    function readPayload() {
        var el = document.getElementById('ikhtibar-pratinjau-skor-data');
        if (!el) {
            return [];
        }
        try {
            return JSON.parse(el.textContent || '[]');
        } catch (e) {
            return [];
        }
    }

    function hitung() {
        var payload = readPayload();
        var form = document.getElementById('form-pratinjau-nilai');
        var out = document.getElementById('ikhtibar-pratinjau-hasil');
        if (!form || !out) {
            return;
        }

        var pgTotal = 0;
        var pgBenar = 0;
        var esaiCount = 0;

        payload.forEach(function (soal) {
            if (soal.jenis === 'PG') {
                pgTotal++;
                var sel = form.querySelector('input[name="preview_jawaban_' + soal.id + '"]:checked');
                var jawab = sel ? String(sel.value || '').toUpperCase() : '';
                if (jawab !== '' && jawab === String(soal.kunci || '').toUpperCase()) {
                    pgBenar++;
                }
            } else if (soal.jenis === 'ESAI') {
                esaiCount++;
            }
        });

        var skorPg = pgTotal > 0 ? Math.round((100 * pgBenar / pgTotal) * 100) / 100 : null;
        var html = '<strong>Hasil simulasi:</strong> PG benar <strong>' + pgBenar + '/' + pgTotal + '</strong>';
        if (skorPg !== null) {
            html += ' · Skor PG <strong>' + skorPg + '</strong>';
        }
        if (esaiCount > 0) {
            html += ' · Esai <strong>' + esaiCount + '</strong> (nilai esai dikoreksi pembimbing)';
        }
        html += '. <span class="text-muted">Kunci jawaban tidak ditampilkan — sama seperti portal santri.</span>';

        out.innerHTML = html;
        out.classList.remove('d-none');
        out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function init() {
        var btn = document.getElementById('btn-hitung-pratinjau');
        if (btn) {
            btn.addEventListener('click', hitung);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
