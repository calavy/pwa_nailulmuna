(function () {
    const form = document.getElementById('form-pembayaran');
    if (!form) {
        return;
    }

    const santriSel = document.getElementById('santri_id');
    const jenisSel = document.getElementById('jenis_periode');
    const bulanSel = document.getElementById('bulan_tagihan');
    const wrapBulan = document.getElementById('wrap-bulan');
    const tierHint = document.getElementById('santri-tier-hint');
    const metodeSel = document.getElementById('metode_bayar');
    const noRef = document.getElementById('no_referensi');
    const statusLabel = document.getElementById('status_lunas_label');
    const statusHidden = document.getElementById('status_lunas');
    const summaryHint = document.getElementById('tagihan-summary-hint');
    const syBreakdownBox = document.getElementById('syahriyah-breakdown-box');
    const syBreakdownLines = document.getElementById('syahriyah-breakdown-lines');
    const syBreakdownTotal = document.getElementById('syahriyah-breakdown-total');
    const opsPanel = document.getElementById('panel-komponen-opsional');
    const opsStatusPill = document.getElementById('opsional-status-pill');
    const opsBulkLink = document.getElementById('opsional-bulk-link');
    const opsEditorCards = document.querySelectorAll('.opsional-editor-card');
    const map = window.keuanganSantriTier || {};
    const feeMatrix = window.keuanganFeeMatrix || {};
    let tagihanPos = {};
    let savingSlug = null;

    function appBase() {
        var b = (typeof window !== 'undefined' && window.PONDOK_APP_BASE != null) ? String(window.PONDOK_APP_BASE) : '';
        return b.replace(/\/$/, '');
    }

    function appUrl(path) {
        var p = String(path || '');
        if (/^https?:\/\//i.test(p)) return p;
        if (p.charAt(0) !== '/') p = '/' + p;
        var base = appBase();
        return base === '' ? p : base + p;
    }

    function fmtThousand(n) {
        return Number(n || 0).toLocaleString('id-ID');
    }

    function setOpsPill(text, cls) {
        if (!opsStatusPill) return;
        opsStatusPill.textContent = text;
        opsStatusPill.className = 'badge ' + (cls || 'bg-light text-muted');
    }

    function getOpsEditor(slug) {
        for (let i = 0; i < opsEditorCards.length; i++) {
            if (opsEditorCards[i].getAttribute('data-slug') === slug) {
                return opsEditorCards[i];
            }
        }
        return null;
    }

    function resetOpsEditors(message) {
        opsEditorCards.forEach(function (card) {
            const slug = card.getAttribute('data-slug') || '';
            const aktifInp = card.querySelector('.opsional-aktif-input');
            const nomInp = card.querySelector('.opsional-nominal-input');
            const saveBtn = card.querySelector('.opsional-save-btn');
            const defaultHint = card.querySelector('[data-role="default-hint"]');
            const status = card.querySelector('[data-role="status"]');
            if (aktifInp) {
                aktifInp.checked = false;
                aktifInp.disabled = true;
            }
            if (nomInp) {
                nomInp.value = '';
                nomInp.disabled = true;
            }
            if (saveBtn) {
                saveBtn.disabled = true;
            }
            if (defaultHint) {
                defaultHint.textContent = 'Default tier: —';
            }
            if (status) {
                status.textContent = message || 'Pilih santri untuk mengatur.';
                status.className = 'opsional-editor-status small mt-1 text-muted';
            }
        });
    }

    function renderOpsEditor(slug, info) {
        const card = getOpsEditor(slug);
        if (!card) return;
        const aktifInp = card.querySelector('.opsional-aktif-input');
        const nomInp = card.querySelector('.opsional-nominal-input');
        const saveBtn = card.querySelector('.opsional-save-btn');
        const defaultHint = card.querySelector('[data-role="default-hint"]');
        const status = card.querySelector('[data-role="status"]');
        const expectedDefault = parseInt(info && info.expected_default, 10) || 0;
        const overrideAktif = info ? !!info.override_aktif : true;
        const overrideNominal = info && info.override_nominal != null ? parseInt(info.override_nominal, 10) : null;

        if (aktifInp) {
            aktifInp.disabled = false;
            aktifInp.checked = overrideAktif;
        }
        if (nomInp) {
            nomInp.disabled = !overrideAktif;
            nomInp.value = overrideNominal != null ? fmtThousand(overrideNominal) : '';
            nomInp.placeholder = expectedDefault > 0
                ? 'Default tier Rp ' + fmtThousand(expectedDefault)
                : 'kosong = pakai default tier';
        }
        if (saveBtn) {
            saveBtn.disabled = false;
        }
        if (defaultHint) {
            defaultHint.textContent = expectedDefault > 0
                ? 'Default tier: Rp ' + fmtThousand(expectedDefault)
                : 'Default tier: belum diatur';
        }
        if (status) {
            if (!overrideAktif) {
                status.textContent = 'Nonaktif — tagihan ini tidak ditampilkan untuk santri ini.';
                status.className = 'opsional-editor-status small mt-1 text-danger';
            } else if (overrideNominal != null) {
                status.textContent = 'Nominal khusus aktif (Rp ' + fmtThousand(overrideNominal) + ').';
                status.className = 'opsional-editor-status small mt-1 text-success';
            } else {
                status.textContent = 'Aktif — pakai default tier kelas.';
                status.className = 'opsional-editor-status small mt-1 text-muted';
            }
        }
    }

    function updateOpsPanelOpenState() {
        let activeCount = 0;
        let hasSisa = false;
        Object.keys(tagihanPos || {}).forEach(function (slug) {
            const info = tagihanPos[slug];
            if (!info || !info.is_opsional) return;
            if (info.override_aktif !== false && (info.expected_default || 0) > 0) {
                activeCount++;
            }
            if ((info.sisa || 0) > 0) {
                hasSisa = true;
            }
        });
        if (opsStatusPill) {
            if (activeCount === 0) {
                setOpsPill('semua nonaktif', 'bg-light text-muted');
            } else if (hasSisa) {
                setOpsPill('ada sisa bayar', 'bg-warning-subtle text-warning');
            } else {
                setOpsPill(activeCount + ' pos aktif', 'bg-info-subtle text-info');
            }
        }
    }

    function fmtRp(n) {
        return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    }

    function parseRpInput(val) {
        const digits = String(val || '').replace(/[^\d]/g, '');
        return digits === '' ? 0 : parseInt(digits, 10);
    }

    function kategoriForJenis() {
        return jenisSel && jenisSel.value === 'AWAL_TAHUN' ? 'Awal Tahun' : 'Bulanan';
    }

    function tahunMulai() {
        const el = form.querySelector('[name="tahun_ajaran_mulai"]');
        return el ? parseInt(el.value, 10) || 0 : 0;
    }

    function tahunSelesai() {
        const el = form.querySelector('[name="tahun_ajaran_selesai"]');
        return el ? parseInt(el.value, 10) || 0 : 0;
    }

    function filterKomponenRows() {
        const kat = kategoriForJenis();
        document.querySelectorAll('#tabel-komponen tbody tr').forEach(function (tr) {
            let show = tr.getAttribute('data-kategori') === kat;
            const slug = tr.getAttribute('data-slug') || '';
            const info = tagihanPos[slug];
            if (show && info && info.is_opsional && info.override_aktif === false) {
                show = false;
            }
            tr.style.display = show ? '' : 'none';
            if (!show) {
                const cb = tr.querySelector('.bayar-pos-check');
                if (cb) {
                    cb.checked = false;
                }
            }
        });
        if (wrapBulan) {
            wrapBulan.style.display = kat === 'Bulanan' ? '' : 'none';
        }
    }

    function updateStatusTransaksi() {
        let stillCicilan = false;
        document.querySelectorAll('#tabel-komponen tbody tr').forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const slug = tr.getAttribute('data-slug');
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            if (!slug || slug === 'saku' || !cb || !cb.checked || !inp) {
                return;
            }
            const info = tagihanPos[slug];
            if (!info || info.expected <= 0) {
                return;
            }
            const bayar = parseRpInput(inp.value);
            const sisaSetelah = Math.max(0, (info.sisa || 0) - bayar);
            if (sisaSetelah > 0) {
                stillCicilan = true;
            }
        });
        const val = stillCicilan ? 'CICILAN' : 'LUNAS';
        const label = stillCicilan ? 'Cicilan' : 'Lunas';
        if (statusHidden) {
            statusHidden.value = val;
        }
        if (statusLabel) {
            statusLabel.value = label;
        }
    }

    function renderSyahriyahBreakdown(summary) {
        if (!syBreakdownBox || !syBreakdownLines || !syBreakdownTotal) {
            return;
        }
        const bd = summary && summary.syahriyah_breakdown ? summary.syahriyah_breakdown : null;
        if (!bd || (bd.total || 0) <= 0) {
            syBreakdownBox.classList.add('d-none');
            return;
        }
        const tier = bd.tier_label ? ' (' + bd.tier_label + ')' : '';
        const lines = [];
        lines.push('Syahriyah pokok' + tier + ': <strong>' + fmtRp(bd.dasar || 0) + '</strong>');
        if ((bd.pkpps || 0) > 0) {
            lines.push('Tambahan PKPPS: <strong>' + fmtRp(bd.pkpps) + '</strong>');
        }
        if ((bd.kelas_syahriyah || 0) > 0) {
            lines.push('Tambahan kelas syahriyah: <strong>' + fmtRp(bd.kelas_syahriyah) + '</strong>');
        }
        syBreakdownLines.innerHTML = lines.join('<br>');
        const sisaLine = (bd.sisa || 0) > 0
            ? ' · Sisa bayar: <strong>' + fmtRp(bd.sisa) + '</strong>'
            : ' · <span class="text-success">Lunas</span>';
        syBreakdownTotal.innerHTML =
            'Total tagihan: <strong>' + fmtRp(bd.total) + '</strong>' + sisaLine;
        syBreakdownBox.classList.remove('d-none');
    }

    function renderPaidHints() {
        document.querySelectorAll('.paid-hint').forEach(function (cell) {
            const slug = cell.getAttribute('data-slug');
            const info = slug ? tagihanPos[slug] : null;
            if (!info || info.expected <= 0) {
                cell.textContent = '—';
                cell.className = 'small text-muted paid-hint';
                return;
            }
            const status = info.status || '—';
            let cls = 'text-muted';
            if (status === 'Lunas') {
                cls = 'text-success';
            } else if (status === 'Sebagian') {
                cls = 'text-warning';
            } else if (status === 'Belum') {
                cls = 'text-danger';
            }
            cell.className = 'small paid-hint ' + cls;
            let potonganLine = '';
            if (info.persen_potongan > 0 && info.expected_dasar > info.expected) {
                potonganLine =
                    '<br><span class="text-warning" style="font-size:.72rem">−' +
                    info.persen_potongan +
                    '% · ' +
                    (info.keterangan_potongan || 'potongan') +
                    '</span>';
            }
            let rincianLine = '';
            if (slug === 'syahriyah' && ((info.pkpps_tambahan || 0) > 0 || (info.kelas_syahriyah_tambahan || 0) > 0)) {
                const dasar = (info.expected_setelah_potongan != null)
                    ? info.expected_setelah_potongan
                    : Math.max(0, (info.expected || 0) - (info.pkpps_tambahan || 0) - (info.kelas_syahriyah_tambahan || 0));
                rincianLine = '<br><span style="font-size:.72rem">' +
                    fmtRp(dasar) +
                    ((info.pkpps_tambahan || 0) > 0 ? ' + PKPPS ' + fmtRp(info.pkpps_tambahan) : '') +
                    ((info.kelas_syahriyah_tambahan || 0) > 0 ? ' + kelas ' + fmtRp(info.kelas_syahriyah_tambahan) : '') +
                    '</span>';
            }
            if (info.sisa > 0) {
                cell.innerHTML =
                    'Sisa ' +
                    fmtRp(info.sisa) +
                    '<br><span class="text-muted">/' +
                    fmtRp(info.expected) +
                    '</span>' +
                    rincianLine +
                    potonganLine;
            } else {
                cell.innerHTML =
                    '<span class="text-success">Lunas</span><br><span class="text-muted">' +
                    fmtRp(info.expected) +
                    '</span>' +
                    rincianLine +
                    potonganLine;
            }
        });
    }

    function applyNominalFromTagihan() {
        document.querySelectorAll('.nominal-pos').forEach(function (inp) {
            const slug = inp.getAttribute('data-slug');
            const row = inp.closest('tr');
            const cb = row ? row.querySelector('.bayar-pos-check') : null;
            if (!slug || !row || row.style.display === 'none') {
                return;
            }
            const info = tagihanPos[slug];
            let nominal = 0;
            if (info && info.sisa > 0) {
                nominal = info.sisa;
            } else if (info && info.expected > 0 && info.sisa <= 0) {
                nominal = 0;
                if (cb) {
                    cb.checked = false;
                }
            } else {
                const sid = santriSel ? String(santriSel.value || '') : '';
                const tierInfo = map[sid];
                const tierKey = tierInfo ? tierInfo.tier_key : '';
                if (tierKey && feeMatrix[slug] && feeMatrix[slug][tierKey] != null) {
                    nominal = parseInt(feeMatrix[slug][tierKey], 10) || 0;
                }
            }
            inp.value = nominal > 0 ? nominal.toLocaleString('id-ID') : '0';
        });
        updateStatusTransaksi();
    }

    function applyTarifSantri() {
        const sid = santriSel ? String(santriSel.value || '') : '';
        const info = map[sid];
        if (tierHint) {
            if (info) {
                tierHint.textContent = 'Kelas: ' + info.kelas_label + ' · Tarif ' + info.tier_label;
            } else {
                tierHint.textContent = 'Tarif mengikuti kelas keuangan santri yang dipilih.';
            }
        }
        if (opsBulkLink) {
            const baseHref = appUrl('/settings/opsional_santri.php');
            if (sid) {
                opsBulkLink.href = baseHref + '?q=' + encodeURIComponent(santriSel.options[santriSel.selectedIndex].text || '');
            } else {
                opsBulkLink.href = baseHref;
            }
        }
        if (!sid) {
            tagihanPos = {};
            resetOpsEditors('Pilih santri untuk mengatur.');
            setOpsPill('tutup', 'bg-light text-muted');
            renderPaidHints();
            renderSyahriyahBreakdown(null);
            return;
        }
        resetOpsEditors('Memuat pengaturan…');
        loadTagihanPreview();
    }

    function postOpsionalSave(slug, aktif, nominal) {
        const sid = santriSel ? parseInt(santriSel.value, 10) : 0;
        if (sid <= 0) {
            return Promise.reject(new Error('Pilih santri terlebih dahulu.'));
        }
        const fd = new FormData();
        fd.append('santri_id', String(sid));
        fd.append('slug', slug);
        if (aktif) fd.append('aktif', '1');
        if (nominal != null && nominal !== '') fd.append('nominal', String(nominal));
        return fetch(appUrl('/api/keuangan/opsional_save.php'), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        }).then(function (res) {
            return res.json();
        });
    }

    function handleOpsionalSaveClick(ev) {
        const btn = ev.currentTarget;
        const slug = btn.getAttribute('data-slug') || '';
        const card = getOpsEditor(slug);
        if (!card || savingSlug) return;
        const aktifInp = card.querySelector('.opsional-aktif-input');
        const nomInp = card.querySelector('.opsional-nominal-input');
        const status = card.querySelector('[data-role="status"]');
        const aktif = !!(aktifInp && aktifInp.checked);
        const nominalRaw = nomInp ? nomInp.value : '';
        const nominal = nominalRaw === '' ? '' : parseRpInput(nominalRaw);

        savingSlug = slug;
        btn.disabled = true;
        if (status) {
            status.textContent = 'Menyimpan…';
            status.className = 'opsional-editor-status small mt-1 text-muted';
        }

        postOpsionalSave(slug, aktif, nominal === '' ? null : nominal)
            .then(function (data) {
                if (!data || !data.ok) {
                    throw new Error((data && data.error) || 'Gagal menyimpan.');
                }
                if (status) {
                    status.textContent = 'Tersimpan.';
                    status.className = 'opsional-editor-status small mt-1 text-success';
                }
                loadTagihanPreview();
            })
            .catch(function (err) {
                if (status) {
                    status.textContent = (err && err.message) || 'Gagal menyimpan.';
                    status.className = 'opsional-editor-status small mt-1 text-danger';
                }
            })
            .finally(function () {
                savingSlug = null;
                btn.disabled = false;
            });
    }

    function bindOpsionalEditorEvents() {
        opsEditorCards.forEach(function (card) {
            const slug = card.getAttribute('data-slug') || '';
            const aktifInp = card.querySelector('.opsional-aktif-input');
            const nomInp = card.querySelector('.opsional-nominal-input');
            const saveBtn = card.querySelector('.opsional-save-btn');
            if (aktifInp) {
                aktifInp.addEventListener('change', function () {
                    if (nomInp) {
                        nomInp.disabled = !aktifInp.checked;
                    }
                });
            }
            if (saveBtn) {
                saveBtn.addEventListener('click', handleOpsionalSaveClick);
            }
            if (nomInp) {
                nomInp.addEventListener('blur', function () {
                    const n = parseRpInput(nomInp.value);
                    nomInp.value = n > 0 ? fmtThousand(n) : '';
                });
            }
            if (saveBtn) {
                saveBtn.setAttribute('data-slug', slug);
            }
        });
    }

    function loadTagihanPreview() {
        const sid = santriSel ? parseInt(santriSel.value, 10) : 0;
        if (sid <= 0) {
            tagihanPos = {};
            renderPaidHints();
            return;
        }
        const params = new URLSearchParams({
            santri_id: String(sid),
            jenis_periode: jenisSel ? jenisSel.value : 'BULANAN',
            bulan_tagihan: bulanSel ? String(bulanSel.value) : '1',
            tahun_ajaran_mulai: String(tahunMulai()),
            tahun_ajaran_selesai: String(tahunSelesai()),
        });
        fetch(appUrl('/api/keuangan/tagihan_preview.php') + '?' + params.toString(), {
            credentials: 'same-origin',
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    tagihanPos = {};
                    resetOpsEditors('Gagal memuat pengaturan.');
                    renderPaidHints();
                    return;
                }
                tagihanPos = data.pos || {};
                Object.keys(tagihanPos).forEach(function (slug) {
                    const info = tagihanPos[slug];
                    if (info && info.is_opsional) {
                        renderOpsEditor(slug, info);
                    }
                });
                updateOpsPanelOpenState();
                filterKomponenRows();
                renderPaidHints();
                applyNominalFromTagihan();
                renderSyahriyahBreakdown(data.summary || null);
                if (summaryHint && data.summary) {
                    const sisa = parseInt(data.summary.sisa_wajib, 10) || 0;
                    const exp = parseInt(data.summary.expected_wajib, 10) || 0;
                    if (exp <= 0) {
                        summaryHint.textContent = 'Belum ada tarif tagihan wajib untuk kelas santri ini.';
                    } else if (sisa <= 0) {
                        summaryHint.textContent = 'Tagihan wajib bulan ini sudah lunas. Anda masih bisa mencatat pembayaran Saku atau pos lain.';
                    } else {
                        summaryHint.textContent =
                            'Sisa tagihan wajib bulan ini: ' +
                            fmtRp(sisa) +
                            ' dari ' +
                            fmtRp(exp) +
                            '. Nominal diisi otomatis sesuai sisa — bisa diubah untuk cicilan.';
                    }
                }
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('santri_id')) {
                    ['syahriyah'].forEach(function (slug) {
                        const cb = document.querySelector('.bayar-pos-check[value="' + slug + '"]');
                        const info = tagihanPos[slug];
                        if (cb && info && info.sisa > 0 && !cb.checked) {
                            cb.checked = true;
                        }
                    });
                    updateStatusTransaksi();
                }
            })
            .catch(function () {
                tagihanPos = {};
                renderPaidHints();
            });
    }

    function toggleRefRequired() {
        if (!noRef || !metodeSel) {
            return;
        }
        const transfer = metodeSel.value === 'TRANSFER';
        noRef.required = transfer;
        noRef.placeholder = transfer ? 'Wajib untuk transfer' : 'Opsional';
    }

    if (santriSel) {
        santriSel.addEventListener('change', applyTarifSantri);
    }
    if (jenisSel) {
        jenisSel.addEventListener('change', function () {
            filterKomponenRows();
            applyTarifSantri();
        });
    }
    if (bulanSel) {
        bulanSel.addEventListener('change', loadTagihanPreview);
    }
    form.querySelectorAll('[name="tahun_ajaran_mulai"], [name="tahun_ajaran_selesai"]').forEach(function (el) {
        el.addEventListener('change', loadTagihanPreview);
    });
    if (metodeSel) {
        metodeSel.addEventListener('change', toggleRefRequired);
    }

    document.querySelectorAll('.bayar-pos-check, .nominal-pos').forEach(function (el) {
        el.addEventListener('change', updateStatusTransaksi);
        el.addEventListener('input', updateStatusTransaksi);
    });

    bindOpsionalEditorEvents();
    filterKomponenRows();
    applyTarifSantri();
    toggleRefRequired();
})();
