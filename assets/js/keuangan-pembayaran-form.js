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
    const map = window.keuanganSantriMap || {};
    let tagihanPos = {};

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
            const show = tr.getAttribute('data-kategori') === kat;
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
            if (info.sisa > 0) {
                cell.innerHTML =
                    'Sisa ' +
                    fmtRp(info.sisa) +
                    '<br><span class="text-muted">/' +
                    fmtRp(info.expected) +
                    '</span>' +
                    potonganLine;
            } else {
                cell.innerHTML =
                    '<span class="text-success">Lunas</span><br><span class="text-muted">' +
                    fmtRp(info.expected) +
                    '</span>' +
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
                if (tierInfo && tierInfo.fees && tierInfo.fees[slug] != null) {
                    nominal = parseInt(tierInfo.fees[slug], 10) || 0;
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
        if (!sid) {
            tagihanPos = {};
            renderPaidHints();
            return;
        }
        loadTagihanPreview();
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
        fetch('/api/keuangan/tagihan_preview.php?' + params.toString(), {
            credentials: 'same-origin',
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    tagihanPos = {};
                    renderPaidHints();
                    return;
                }
                tagihanPos = data.pos || {};
                renderPaidHints();
                applyNominalFromTagihan();
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
                    ['syahriyah', 'makan'].forEach(function (slug) {
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

    filterKomponenRows();
    applyTarifSantri();
    toggleRefRequired();
})();
