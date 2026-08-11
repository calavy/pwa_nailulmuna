/* Wizard buat/edit tugas ikhtibar — navigasi, form PG/esai, import preview */

(function () {
    'use strict';

    var cfg = window.IKHTIBAR_BUAT || {};
    var pgData = cfg.pgData || {};
    var esaiData = cfg.esaiData || {};
    var perluPin = !!cfg.perluPin;

    var OPSI_LABELS = { 2: 'A–B', 3: 'A–C', 4: 'A–D', 5: 'A–E' };
    var OPSI_COLS = ['a', 'b', 'c', 'd', 'e'];
    var OPSI_LETTERS = ['A', 'B', 'C', 'D', 'E'];

    var form = document.getElementById('form-ikhtibar');
    if (!form) return;

    var wrapPg = document.getElementById('wrap-pg');
    var wrapEsai = document.getElementById('wrap-esai');
    var wrapPgGlobal = document.getElementById('wrap-pg-global');
    var wrapImportTabs = document.getElementById('wrap-import-tabs');
    var selPg = document.getElementById('jumlah_pg');
    var selEsai = document.getElementById('jumlah_esai');
    var selOpsiGlobal = document.getElementById('pg_opsi_global');
    var inputMetode = document.getElementById('input_metode');
    var btnBack = document.getElementById('btn-wizard-back');
    var btnNext = document.getElementById('btn-wizard-next');
    var navSteps = document.querySelector('.ikhtibar-wizard-nav-steps');
    var navSubmit = document.querySelector('.ikhtibar-wizard-nav-submit');
    var panels = form.querySelectorAll('.ikhtibar-wizard-panel');
    var stepIndicators = document.querySelectorAll('.ikhtibar-wizard-step');

    var currentStep = parseInt(cfg.initialStep, 10) || 1;
    if (currentStep < 1 || currentStep > 3) currentStep = 1;

    var globalOpsi = 4;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function snippet(text, max) {
        var t = (text || '').replace(/\s+/g, ' ').trim();
        if (t.length <= max) return t || '(belum diisi)';
        return t.substring(0, max) + '…';
    }

    function getPgJumlahOpsi(row) {
        var j = parseInt(row.pg_jumlah_opsi || row.jumlah_opsi || 0, 10);
        if (j >= 2 && j <= 5) return j;
        if ((row.opsi_e || '').trim() !== '' || (row.kunci_jawaban || '') === 'E') return 5;
        return globalOpsi;
    }

    function detectGlobalOpsi() {
        var max = 4;
        Object.keys(pgData).forEach(function (nom) {
            var j = getPgJumlahOpsi(pgData[nom]);
            if (j > max) max = j;
        });
        return max;
    }

    function syncGlobalOpsiFromData() {
        globalOpsi = detectGlobalOpsi();
        if (selOpsiGlobal) selOpsiGlobal.value = String(globalOpsi);
    }

    function syncPgOpsiUi(soalIdx, jOpsi) {
        if (!wrapPg) return;
        var hidden = wrapPg.querySelector('.pg-jumlah-opsi-hidden[data-soal="' + soalIdx + '"]');
        if (hidden) hidden.value = String(jOpsi);

        OPSI_COLS.forEach(function (L, idx) {
            var cell = wrapPg.querySelector('.pg-opsi-cell-' + L + '-' + soalIdx);
            if (cell) cell.classList.toggle('d-none', idx >= jOpsi);
        });

        var kunciHidden = wrapPg.querySelector('.pg-kunci-hidden[data-soal="' + soalIdx + '"]');
        var curKunci = kunciHidden ? kunciHidden.value : '';
        wrapPg.querySelectorAll('.pg-kunci-pill[data-soal="' + soalIdx + '"]').forEach(function (pill) {
            var letter = pill.getAttribute('data-kunci');
            var idx = OPSI_LETTERS.indexOf(letter);
            pill.classList.toggle('d-none', idx >= jOpsi);
            pill.classList.toggle('is-active', curKunci === letter);
        });

        if (curKunci && OPSI_LETTERS.indexOf(curKunci) >= jOpsi) {
            if (kunciHidden) kunciHidden.value = '';
            wrapPg.querySelectorAll('.pg-kunci-pill[data-soal="' + soalIdx + '"]').forEach(function (pill) {
                pill.classList.remove('is-active');
            });
        }
    }

    function syncAllPgOpsi(jOpsi) {
        globalOpsi = jOpsi;
        if (!wrapPg) return;
        wrapPg.querySelectorAll('.pg-jumlah-opsi-hidden').forEach(function (hidden) {
            hidden.value = String(jOpsi);
            syncPgOpsiUi(hidden.getAttribute('data-soal'), jOpsi);
        });
    }

    function bindPgKunciPills() {
        if (!wrapPg) return;
        wrapPg.querySelectorAll('.pg-kunci-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                var soalIdx = pill.getAttribute('data-soal');
                var letter = pill.getAttribute('data-kunci');
                var hidden = wrapPg.querySelector('.pg-kunci-hidden[data-soal="' + soalIdx + '"]');
                wrapPg.querySelectorAll('.pg-kunci-pill[data-soal="' + soalIdx + '"]').forEach(function (p) {
                    p.classList.remove('is-active');
                });
                pill.classList.add('is-active');
                if (hidden) hidden.value = letter;
            });
        });
    }

    function bindSnippetUpdate() {
        if (!wrapPg) return;
        wrapPg.querySelectorAll('.pg-teks-input').forEach(function (ta) {
            ta.addEventListener('blur', function () {
                var soalIdx = ta.getAttribute('data-soal');
                var snippetEl = wrapPg.querySelector('.soal-snippet[data-soal="' + soalIdx + '"]');
                if (snippetEl) snippetEl.textContent = snippet(ta.value, 40);
            });
        });
        if (!wrapEsai) return;
        wrapEsai.querySelectorAll('.esai-teks-input').forEach(function (ta) {
            ta.addEventListener('blur', function () {
                var soalIdx = ta.getAttribute('data-esai');
                var snippetEl = wrapEsai.querySelector('.soal-snippet[data-esai="' + soalIdx + '"]');
                if (snippetEl) snippetEl.textContent = snippet(ta.value, 40);
            });
        });
    }

    function renderPg(n) {
        if (!wrapPg) return;
        if (n < 1) {
            wrapPg.innerHTML = '';
            if (wrapPgGlobal) wrapPgGlobal.classList.add('d-none');
            return;
        }
        if (wrapPgGlobal) wrapPgGlobal.classList.remove('d-none');

        var html = '<div class="accordion ikhtibar-soal-accordion" id="accordion-pg">';
        for (var i = 1; i <= n; i++) {
            var row = pgData[i] || {};
            var jOpsi = getPgJumlahOpsi(row);
            var kunci = row.kunci_jawaban || '';
            var teks = row.teks_soal || '';
            var collapseId = 'collapse-pg-' + i;
            var expanded = i === 1;

            html += '<div class="accordion-item soal-accordion-item">';
            html += '<h3 class="accordion-header">';
            html += '<button class="accordion-button soal-accordion-header' + (expanded ? '' : ' collapsed') + '" type="button" data-bs-toggle="collapse" data-bs-target="#' + collapseId + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">';
            html += '<span class="soal-accordion-num">Soal ' + i + '</span>';
            html += '<span class="soal-snippet text-muted" data-soal="' + i + '">' + esc(snippet(teks, 40)) + '</span>';
            html += '</button></h3>';
            html += '<div id="' + collapseId + '" class="accordion-collapse collapse' + (expanded ? ' show' : '') + '" data-bs-parent="#accordion-pg">';
            html += '<div class="accordion-body pt-2">';

            html += '<input type="hidden" name="pg_jumlah_opsi[' + i + ']" class="pg-jumlah-opsi-hidden" data-soal="' + i + '" value="' + jOpsi + '">';
            html += '<input type="hidden" name="pg_kunci[' + i + ']" class="pg-kunci-hidden" data-soal="' + i + '" value="' + esc(kunci) + '">';

            html += '<label class="form-label small">Pertanyaan</label>';
            html += '<textarea name="pg_teks[' + i + ']" class="form-control form-control-sm mb-2 ikhtibar-soal-input pg-teks-input" data-soal="' + i + '" dir="auto" rows="2">' + esc(teks) + '</textarea>';

            html += '<label class="form-label small">Opsi jawaban <span class="text-muted">(klik ✓ untuk kunci)</span></label>';
            html += '<div class="pg-opsi-grid">';
            OPSI_COLS.forEach(function (L, idx) {
                var U = OPSI_LETTERS[idx];
                var hidden = idx >= jOpsi ? ' d-none' : '';
                var active = kunci === U ? ' is-active' : '';
                html += '<div class="pg-opsi-cell pg-opsi-cell-' + L + '-' + i + hidden + '">';
                html += '<div class="pg-opsi-row">';
                html += '<span class="pg-opsi-label">' + U + '</span>';
                html += '<input name="pg_' + L + '[' + i + ']" class="form-control form-control-sm ikhtibar-soal-input pg-opsi-input" dir="auto" placeholder="Opsi ' + U + '" value="' + esc(row['opsi_' + L] || '') + '">';
                html += '<button type="button" class="pg-kunci-pill' + active + '" data-soal="' + i + '" data-kunci="' + U + '" title="Tandai sebagai kunci"><i class="fa-solid fa-check"></i></button>';
                html += '</div></div>';
            });
            html += '</div></div></div></div>';
        }
        html += '</div>';
        wrapPg.innerHTML = html;
        bindPgKunciPills();
        bindSnippetUpdate();
    }

    function renderEsai(n) {
        if (!wrapEsai) return;
        if (n < 1) {
            wrapEsai.innerHTML = '';
            return;
        }

        var html = '<div class="accordion ikhtibar-soal-accordion" id="accordion-esai">';
        for (var i = 1; i <= n; i++) {
            var row = esaiData[i] || {};
            var teks = row.teks_soal || '';
            var collapseId = 'collapse-esai-' + i;
            var expanded = i === 1;

            html += '<div class="accordion-item soal-accordion-item">';
            html += '<h3 class="accordion-header">';
            html += '<button class="accordion-button soal-accordion-header' + (expanded ? '' : ' collapsed') + '" type="button" data-bs-toggle="collapse" data-bs-target="#' + collapseId + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">';
            html += '<span class="soal-accordion-num">Esai ' + i + '</span>';
            html += '<span class="soal-snippet text-muted" data-esai="' + i + '">' + esc(snippet(teks, 40)) + '</span>';
            html += '</button></h3>';
            html += '<div id="' + collapseId + '" class="accordion-collapse collapse' + (expanded ? ' show' : '') + '" data-bs-parent="#accordion-esai">';
            html += '<div class="accordion-body pt-2">';
            html += '<label class="form-label small">Pertanyaan</label>';
            html += '<textarea name="esai_teks[' + i + ']" class="form-control form-control-sm mb-2 ikhtibar-soal-input esai-teks-input" data-esai="' + i + '" dir="auto" rows="2">' + esc(teks) + '</textarea>';
            html += '<label class="form-label small">Kunci jawaban</label>';
            html += '<textarea name="esai_kunci[' + i + ']" class="form-control form-control-sm mb-2 ikhtibar-soal-input" dir="auto" rows="3" placeholder="Kunci per kriteria, contoh: [KELENGKAPAN] poin1, poin2">' + esc(row.kunci_jawaban || '') + '</textarea>';
            html += '<div class="input-group input-group-sm" style="max-width:200px"><span class="input-group-text">Bobot</span>';
            html += '<input name="esai_bobot[' + i + ']" type="number" min="1" max="100" class="form-control" value="' + esc(String(row.bobot_nilai || '100')) + '">';
            html += '</div></div></div></div>';
        }
        html += '</div>';
        wrapEsai.innerHTML = html;
        bindSnippetUpdate();
    }

    function refreshSoal() {
        renderPg(parseInt(selPg.value, 10) || 0);
        renderEsai(parseInt(selEsai.value, 10) || 0);
        syncAllPgOpsi(globalOpsi);
    }

    function updateImportVisibility() {
        var metode = inputMetode ? inputMetode.value : '';
        if (wrapImportTabs) {
            wrapImportTabs.classList.toggle('d-none', metode !== 'import');
        }
    }

    function setMetode(metode) {
        if (inputMetode) inputMetode.value = metode;
        document.querySelectorAll('.ikhtibar-metode-card').forEach(function (card) {
            card.classList.toggle('is-selected', card.getAttribute('data-metode') === metode);
        });
        document.querySelectorAll('input[name="input_metode_radio"]').forEach(function (radio) {
            radio.checked = radio.value === metode;
        });
        updateImportVisibility();
    }

    function updateStepUi() {
        panels.forEach(function (panel) {
            var step = parseInt(panel.getAttribute('data-wizard-step'), 10);
            panel.classList.toggle('d-none', step !== currentStep);
        });
        stepIndicators.forEach(function (el) {
            var step = parseInt(el.getAttribute('data-step'), 10);
            el.classList.remove('is-active', 'is-done');
            if (step === currentStep) el.classList.add('is-active');
            else if (step < currentStep) el.classList.add('is-done');
        });
        if (btnBack) btnBack.classList.toggle('d-none', currentStep <= 1);
        if (btnNext) btnNext.classList.toggle('d-none', currentStep >= 3);
        if (navSteps) navSteps.classList.toggle('d-none', currentStep >= 3);
        if (navSubmit) navSubmit.classList.toggle('d-none', currentStep < 3);
        if (currentStep === 3) updateImportVisibility();
    }

    function validateStep1() {
        var judul = form.querySelector('[name="judul"]');
        var tanggal = form.querySelector('[name="tanggal"]');
        var durasi = form.querySelector('[name="durasi_menit"]');
        var mapel = form.querySelector('[name="kelas_mapel_key"]') || form.querySelector('[name="pkpps_kelas_key"]');

        if (judul && !judul.value.trim()) {
            judul.focus();
            alert('Nama tugas wajib diisi.');
            return false;
        }
        if (mapel && mapel.required && !mapel.value) {
            mapel.focus();
            alert('Mapel / kelas wajib dipilih.');
            return false;
        }
        if (tanggal && !tanggal.value) {
            tanggal.focus();
            alert('Tanggal pelaksanaan wajib diisi.');
            return false;
        }
        if (durasi) {
            var d = parseInt(durasi.value, 10);
            if (isNaN(d) || d < 5 || d > 300) {
                durasi.focus();
                alert('Durasi harus antara 5–300 menit.');
                return false;
            }
        }
        if (perluPin) {
            var pin1 = document.getElementById('pin_draf_tugas_baru');
            var pin2 = document.getElementById('pin_draf_tugas_konfirmasi');
            if (pin1 && pin2) {
                var p1 = pin1.value.trim();
                var p2 = pin2.value.trim();
                if (p1.length < 4 || p1.length > 6 || !/^\d+$/.test(p1)) {
                    pin1.focus();
                    alert('PIN draf harus 4–6 digit angka.');
                    return false;
                }
                if (p1 !== p2) {
                    pin2.focus();
                    alert('Konfirmasi PIN tidak cocok.');
                    return false;
                }
            }
        }
        return true;
    }

    function validateStep2() {
        if (!inputMetode || !inputMetode.value) {
            alert('Pilih metode input soal terlebih dahulu.');
            return false;
        }
        return true;
    }

    function validateStep3() {
        var nPg = parseInt(selPg.value, 10) || 0;
        for (var i = 1; i <= nPg; i++) {
            var kunci = wrapPg ? wrapPg.querySelector('.pg-kunci-hidden[data-soal="' + i + '"]') : null;
            if (kunci && !kunci.value) {
                alert('Soal PG ' + i + ': pilih kunci jawaban (klik ✓ di samping opsi).');
                return false;
            }
        }
        return true;
    }

    function goToStep(step) {
        currentStep = step;
        updateStepUi();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* Metode card selection */
    document.querySelectorAll('.ikhtibar-metode-card').forEach(function (card) {
        card.addEventListener('click', function () {
            setMetode(card.getAttribute('data-metode') || '');
        });
    });

    if (btnNext) {
        btnNext.addEventListener('click', function () {
            if (currentStep === 1 && !validateStep1()) return;
            if (currentStep === 2 && !validateStep2()) return;
            if (currentStep < 3) goToStep(currentStep + 1);
        });
    }
    if (btnBack) {
        btnBack.addEventListener('click', function () {
            if (currentStep > 1) goToStep(currentStep - 1);
        });
    }

    form.addEventListener('submit', function (e) {
        var submitter = e.submitter;
        var isPublish = submitter && submitter.name === 'publish';
        if (currentStep < 3) {
            e.preventDefault();
            return;
        }
        syncAllPgOpsi(globalOpsi);
        if (isPublish && !validateStep3()) {
            e.preventDefault();
        }
    });

    if (selPg) selPg.addEventListener('change', refreshSoal);
    if (selEsai) selEsai.addEventListener('change', refreshSoal);
    if (selOpsiGlobal) {
        selOpsiGlobal.addEventListener('change', function () {
            syncAllPgOpsi(parseInt(selOpsiGlobal.value, 10) || 4);
        });
    }

    /* Import preview */
    var btnOcr = document.getElementById('btn-ocr-run');
    var ocrFile = document.getElementById('ocr_file');
    var ocrStatus = document.getElementById('ocr_status');
    var ocrTa = document.getElementById('ocr_teks_import');
    var btnPreview = document.getElementById('btn-preview-import');
    var previewModalEl = document.getElementById('modal-preview-import');
    var previewBody = document.getElementById('preview-import-body');
    var previewErrors = document.getElementById('preview-import-errors');
    var btnApplyPreview = document.getElementById('btn-apply-preview');
    var previewModal = previewModalEl && typeof bootstrap !== 'undefined' ? new bootstrap.Modal(previewModalEl) : null;
    var lastPreviewSoal = null;
    var previewUrl = cfg.previewUrl || '';

    function structToFormData(soal) {
        if (!soal) return;
        pgData = {};
        esaiData = {};
        Object.keys(soal.pg || {}).forEach(function (nom) {
            var r = soal.pg[nom];
            pgData[nom] = {
                teks_soal: r.teks || '',
                opsi_a: r.a || '',
                opsi_b: r.b || '',
                opsi_c: r.c || '',
                opsi_d: r.d || '',
                opsi_e: r.e || '',
                pg_jumlah_opsi: r.jumlah_opsi || (r.e ? 5 : 4),
                kunci_jawaban: r.kunci || ''
            };
        });
        Object.keys(soal.esai || {}).forEach(function (nom) {
            var r = soal.esai[nom];
            esaiData[nom] = {
                teks_soal: r.teks || '',
                kunci_jawaban: r.kunci || '',
                bobot_nilai: r.bobot || 100
            };
        });
        if (soal.pg && Object.keys(soal.pg).length && selPg) {
            var maxPg = Math.max.apply(null, Object.keys(soal.pg).map(Number));
            var opts = Array.prototype.slice.call(selPg.options);
            var best = opts.reduce(function (acc, opt) {
                var v = parseInt(opt.value, 10);
                return v >= maxPg && v >= acc ? v : acc;
            }, parseInt(selPg.value, 10) || 10);
            selPg.value = String(best);
        }
        if (soal.esai && Object.keys(soal.esai).length && selEsai) {
            var maxEsai = Math.max.apply(null, Object.keys(soal.esai).map(Number));
            var optsE = Array.prototype.slice.call(selEsai.options);
            var bestE = optsE.reduce(function (acc, opt) {
                var v = parseInt(opt.value, 10);
                return v >= maxEsai && v >= acc ? v : acc;
            }, parseInt(selEsai.value, 10) || 0);
            selEsai.value = String(bestE);
        }
        syncGlobalOpsiFromData();
        refreshSoal();
    }

    function runPreviewImport() {
        if (!previewBody) return;
        previewBody.innerHTML = '<p class="text-muted small mb-0">Memuat pratinjau…</p>';
        if (previewErrors) previewErrors.textContent = '';
        if (btnApplyPreview) btnApplyPreview.disabled = true;
        lastPreviewSoal = null;
        if (previewModal) previewModal.show();

        var fd = new FormData(form);
        fetch(previewUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                previewBody.innerHTML = data.html || '<p class="text-muted small">Tidak ada soal.</p>';
                if (previewErrors && data.errors && data.errors.length) {
                    previewErrors.textContent = data.errors.join(' · ');
                }
                if (data.soal && ((data.count_pg || 0) + (data.count_esai || 0)) > 0) {
                    lastPreviewSoal = data.soal;
                    if (btnApplyPreview) btnApplyPreview.disabled = false;
                }
            })
            .catch(function () {
                previewBody.innerHTML = '<p class="text-danger small">Gagal memuat pratinjau.</p>';
            });
    }

    if (btnPreview) btnPreview.addEventListener('click', runPreviewImport);
    if (btnApplyPreview) {
        btnApplyPreview.addEventListener('click', function () {
            structToFormData(lastPreviewSoal);
            if (previewModal) previewModal.hide();
        });
    }

    if (btnOcr && ocrFile) {
        btnOcr.addEventListener('click', function () {
            if (!ocrFile.files || !ocrFile.files[0]) {
                if (ocrStatus) ocrStatus.textContent = 'Pilih atau ambil foto terlebih dahulu.';
                return;
            }
            if (typeof Tesseract === 'undefined') {
                if (ocrStatus) ocrStatus.textContent = 'OCR tidak tersedia di browser ini.';
                return;
            }
            if (ocrStatus) ocrStatus.textContent = 'Memproses OCR (Bahasa Arab)…';
            btnOcr.disabled = true;
            Tesseract.recognize(ocrFile.files[0], 'ara', {
                logger: function (m) {
                    if (m.status === 'recognizing text' && ocrStatus) {
                        ocrStatus.textContent = 'OCR: ' + Math.round((m.progress || 0) * 100) + '%';
                    }
                }
            }).then(function (res) {
                if (ocrTa) ocrTa.value = (res.data && res.data.text) ? res.data.text.trim() : '';
                if (ocrStatus) ocrStatus.textContent = 'OCR selesai. Membuka pratinjau…';
                btnOcr.disabled = false;
                var pasteTab = document.getElementById('tab-paste');
                if (pasteTab && typeof bootstrap !== 'undefined') {
                    bootstrap.Tab.getOrCreateInstance(pasteTab).show();
                }
                runPreviewImport();
            }).catch(function () {
                if (ocrStatus) ocrStatus.textContent = 'OCR gagal. Coba foto lebih jelas atau input manual.';
                btnOcr.disabled = false;
            });
        });
    }

    /* Init */
    syncGlobalOpsiFromData();
    if (cfg.initialMetode) setMetode(cfg.initialMetode);
    refreshSoal();
    goToStep(currentStep);
})();
