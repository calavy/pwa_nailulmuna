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
    const pkppsHintBox = document.getElementById('pkpps-hint-box');
    const opsPanel = document.getElementById('panel-komponen-opsional');
    const opsStatusPill = document.getElementById('opsional-status-pill');
    const opsBulkLink = document.getElementById('opsional-bulk-link');
    const btnPilihSemua = document.getElementById('btn-pilih-semua-sisa');
    const grandTotalEl = document.getElementById('pembayaran-grand-total');
    const totalPosEl = document.getElementById('pembayaran-total-pos');
    const actionsAmountEl = document.getElementById('pembayaran-actions-amount');
    const actionsTotalWrap = document.getElementById('pembayaran-actions-total');
    const opsEditorCards = document.querySelectorAll('.opsional-editor-card');
    const btnSimpan = document.getElementById('btn-simpan-pembayaran');
    const bulanBlokirBox = document.getElementById('bulan-urutan-blokir');
    const bulanBlokirTeks = document.getElementById('bulan-urutan-blokir-teks');
    const tagihanMasukBox = document.getElementById('tagihan-masuk-catatan');
    const tagihanMasukTeks = document.getElementById('tagihan-masuk-catatan-teks');
    const tagihanMasukRiwayat = document.getElementById('tagihan-masuk-riwayat');
    const map = window.keuanganSantriTier || {};
    const feeMatrix = window.keuanganFeeMatrix || {};
    let tagihanPos = {};
    let bulanUrutanMap = {};
    let bulanUrutanBlokir = false;
    let bulanUrutanBlokirPesan = '';
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

    function isPosLunas(info, slug) {
        if (!info || slug === 'saku') {
            return false;
        }
        const expected = parseInt(info.expected, 10) || 0;
        const sisa = parseInt(info.sisa, 10) || 0;
        return expected > 0 && sisa <= 0;
    }

    function applyLunasRowLocks() {
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const slug = tr.getAttribute('data-slug') || '';
            const info = slug ? tagihanPos[slug] : null;
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            const locked = isPosLunas(info, slug) || (slug !== 'saku' && info && info.status === 'Lunas');
            tr.classList.toggle('is-lunas-locked', locked);
            if (cb) {
                if (locked) {
                    cb.checked = false;
                    cb.disabled = true;
                } else if (!bulanUrutanBlokir) {
                    cb.disabled = false;
                }
            }
            if (inp) {
                if (locked) {
                    inp.value = '0';
                    inp.disabled = true;
                } else if (!bulanUrutanBlokir) {
                    inp.disabled = false;
                }
            }
        });
    }

    function collectDobelPaymentError() {
        let msg = '';
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none' || msg) {
                return;
            }
            const slug = tr.getAttribute('data-slug') || '';
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            if (!slug || slug === 'saku' || !cb || !cb.checked || !inp) {
                return;
            }
            const info = tagihanPos[slug];
            const nominal = parseRpInput(inp.value);
            if (nominal <= 0) {
                return;
            }
            if (isPosLunas(info, slug)) {
                const nama = (info && info.nama) ? info.nama : slug;
                msg = 'Komponen ' + nama + ' sudah lunas untuk periode ini. Input dobel tidak diizinkan.';
                return;
            }
            if (info && (info.expected || 0) > 0 && nominal > (info.sisa || 0)) {
                const nama = info.nama || slug;
                msg = 'Nominal ' + nama + ' melebihi sisa tagihan (' + fmtRp(info.sisa || 0) + ').';
            }
        });
        return msg;
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

    function isJenisBulanan() {
        return !jenisSel || jenisSel.value === 'BULANAN';
    }

    function bulanUrutanInfo(bulan) {
        const m = parseInt(bulan, 10);
        if (!m) {
            return null;
        }
        return bulanUrutanMap[m] || bulanUrutanMap[String(m)] || null;
    }

    function applyBulanUrutanRestrictions() {
        if (!bulanSel || !isJenisBulanan()) {
            return false;
        }
        if (!bulanUrutanMap || Object.keys(bulanUrutanMap).length === 0) {
            Array.from(bulanSel.options).forEach(function (opt) {
                opt.disabled = false;
                opt.title = '';
            });
            return false;
        }
        let currentAllowed = true;
        const currentVal = parseInt(bulanSel.value, 10) || 0;
        Array.from(bulanSel.options).forEach(function (opt) {
            const m = parseInt(opt.value, 10);
            const info = bulanUrutanInfo(m);
            if (!info) {
                opt.disabled = false;
                opt.title = '';
                return;
            }
            if (info.dibebankan === false) {
                opt.disabled = true;
                opt.title = '';
                return;
            }
            const blocked = info.allowed === false;
            opt.disabled = blocked;
            opt.title = blocked ? (info.message || '') : '';
            if (m === currentVal) {
                currentAllowed = !blocked;
            }
        });
        if (!currentAllowed) {
            for (let i = 0; i < bulanSel.options.length; i++) {
                if (!bulanSel.options[i].disabled) {
                    const nextVal = bulanSel.options[i].value;
                    if (String(bulanSel.value) !== String(nextVal)) {
                        bulanSel.value = nextVal;
                        return true;
                    }
                    break;
                }
            }
        }
        return false;
    }

    function setBulanUrutanBlokirUi(blocked, message) {
        bulanUrutanBlokir = !!blocked;
        bulanUrutanBlokirPesan = message || '';
        if (bulanBlokirBox) {
            bulanBlokirBox.classList.toggle('d-none', !bulanUrutanBlokir);
        }
        if (bulanBlokirTeks) {
            bulanBlokirTeks.textContent = bulanUrutanBlokirPesan;
        }
        if (btnSimpan) {
            btnSimpan.disabled = bulanUrutanBlokir;
        }
        form.querySelectorAll('.bayar-pos-check, .nominal-pos').forEach(function (el) {
            el.disabled = bulanUrutanBlokir;
        });
        if (!bulanUrutanBlokir) {
            applyLunasRowLocks();
        }
    }

    function renderTagihanMasukCatatan(summary) {
        if (!tagihanMasukBox) {
            return;
        }
        if (!isJenisBulanan() || !summary) {
            tagihanMasukBox.classList.add('d-none');
            if (tagihanMasukTeks) {
                tagihanMasukTeks.textContent = '';
            }
            if (tagihanMasukRiwayat) {
                tagihanMasukRiwayat.innerHTML = '';
            }
            return;
        }
        const info = summary.tagihan_masuk || null;
        const riwayat = info && Array.isArray(info.riwayat_teks) ? info.riwayat_teks : [];
        const catatanTa = info && info.catatan_ta_ini ? String(info.catatan_ta_ini) : '';
        const bulanMulai = info ? parseInt(info.bulan_mulai, 10) || 1 : 1;
        const jenis = info && info.jenis_santri ? String(info.jenis_santri) : '';
        let teks = '';
        if (jenis === 'baru' && bulanMulai > 1) {
            teks = catatanTa || ('Santri baru — tagihan bulanan mulai bulan ke-' + bulanMulai + '.');
        } else if (catatanTa !== '') {
            teks = catatanTa;
        } else if (riwayat.length > 0) {
            teks = 'Riwayat tagihan masuk santri:';
        }
        if (teks === '' && riwayat.length === 0) {
            tagihanMasukBox.classList.add('d-none');
            return;
        }
        tagihanMasukBox.classList.remove('d-none');
        if (tagihanMasukTeks) {
            tagihanMasukTeks.textContent = teks;
        }
        if (tagihanMasukRiwayat) {
            tagihanMasukRiwayat.innerHTML = '';
            const showRiwayat = riwayat.length > 1 || (riwayat.length === 1 && teks !== riwayat[0]);
            if (showRiwayat) {
                riwayat.forEach(function (line) {
                    const li = document.createElement('li');
                    li.textContent = line;
                    tagihanMasukRiwayat.appendChild(li);
                });
            }
        }
    }

    /** Baris komponen pada periode yang sedang dipilih (bulanan / awal tahun). */
    function visibleKomponenRows() {
        const kat = kategoriForJenis();
        const tb = kat === 'Bulanan'
            ? document.getElementById('tbody-komponen-bulanan')
            : document.getElementById('tbody-komponen-awal-tahun');
        if (!tb) {
            return [];
        }
        return Array.prototype.slice.call(tb.querySelectorAll('tr'));
    }

    function resetHiddenKomponenRows() {
        document.querySelectorAll('#tbody-komponen-bulanan tr, #tbody-komponen-awal-tahun tr').forEach(function (tr) {
            const parent = tr.closest('tbody');
            if (!parent || parent.classList.contains('d-none')) {
                const cb = tr.querySelector('.bayar-pos-check');
                const inp = tr.querySelector('.nominal-pos');
                if (cb) {
                    cb.checked = false;
                }
                if (inp) {
                    inp.value = '0';
                }
                tr.classList.remove('is-checked');
            }
        });
    }

    function resolveNominalForSlug(slug, info, nominalFill) {
        if (nominalFill && nominalFill[slug] != null) {
            const n = parseInt(nominalFill[slug], 10) || 0;
            if (n > 0) {
                const st = info ? info.status : '';
                return {
                    nominal: n,
                    autoCheck: st === 'Belum' || st === 'Sebagian' || (st === '' && (slug === 'syahriyah' || !isJenisBulanan())),
                };
            }
        }
        if (info) {
            if ((info.sisa || 0) > 0) {
                return { nominal: info.sisa, autoCheck: true };
            }
            if ((info.expected || 0) > 0) {
                if (info.status === 'Lunas') {
                    return { nominal: 0, autoCheck: false };
                }
                const nominal = info.status === 'Sebagian'
                    ? (info.sisa || info.expected)
                    : info.expected;
                return {
                    nominal: nominal,
                    autoCheck: info.status === 'Belum' || info.status === 'Sebagian',
                };
            }
        }
        const sid = santriSel ? String(santriSel.value || '') : '';
        const tierInfo = map[sid];
        const tierKey = tierInfo ? tierInfo.tier_key : '';
        let nominal = 0;
        if (tierKey && feeMatrix[slug] && feeMatrix[slug][tierKey] != null) {
            nominal = parseInt(feeMatrix[slug][tierKey], 10) || 0;
        }
        if (slug === 'syahriyah' && info && (info.expected || 0) > nominal) {
            nominal = info.expected;
        } else if (slug === 'syahriyah' && info && (info.pkpps_tambahan || 0) > 0) {
            const dasar = info.expected_setelah_potongan != null
                ? info.expected_setelah_potongan
                : nominal;
            nominal = dasar + (info.pkpps_tambahan || 0);
        }
        const autoCheck = nominal > 0 && (slug === 'syahriyah' || !isJenisBulanan());
        return { nominal: nominal, autoCheck: autoCheck };
    }

    /** Isi nominal dari tarif tier segera setelah santri dipilih (sebelum API selesai). */
    function applyNominalFromFeeMatrix() {
        const sid = santriSel ? String(santriSel.value || '') : '';
        if (!sid) {
            return;
        }
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const slug = tr.getAttribute('data-slug');
            const inp = tr.querySelector('.nominal-pos');
            const cb = tr.querySelector('.bayar-pos-check');
            if (!slug || !inp) {
                return;
            }
            const resolved = resolveNominalForSlug(slug, tagihanPos[slug] || null, null);
            inp.value = resolved.nominal > 0 ? fmtThousand(resolved.nominal) : '0';
            if (cb && resolved.autoCheck && resolved.nominal > 0) {
                cb.checked = true;
            }
        });
        updatePilihSemuaBtn();
        updateStatusTransaksi();
    }

    function applyNominalFromTagihan(nominalFill) {
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const slug = tr.getAttribute('data-slug');
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            if (!slug || !inp) {
                return;
            }
            const info = tagihanPos[slug];
            const resolved = resolveNominalForSlug(slug, info, nominalFill || null);
            const nominal = resolved.nominal;
            let autoCheck = resolved.autoCheck;
            if (info && (info.expected || 0) > 0 && (info.sisa || 0) <= 0 && info.status === 'Lunas') {
                autoCheck = false;
            }
            inp.value = nominal > 0 ? fmtThousand(nominal) : '0';
            if (cb) {
                if (autoCheck && nominal > 0) {
                    cb.checked = true;
                } else if (!autoCheck && nominal <= 0) {
                    cb.checked = false;
                }
            }
        });
        updatePilihSemuaBtn();
        updateStatusTransaksi();
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
        const isBulanan = kat === 'Bulanan';
        const tbBulanan = document.getElementById('tbody-komponen-bulanan');
        const tbAwal = document.getElementById('tbody-komponen-awal-tahun');
        if (tbBulanan) {
            tbBulanan.classList.toggle('d-none', !isBulanan);
        }
        if (tbAwal) {
            tbAwal.classList.toggle('d-none', isBulanan);
        }
        if (wrapBulan) {
            wrapBulan.style.display = isBulanan ? '' : 'none';
        }
        if (opsPanel) {
            opsPanel.classList.toggle('d-none', !isBulanan);
            opsPanel.setAttribute('aria-hidden', isBulanan ? 'false' : 'true');
        }
        if (!isBulanan) {
            if (syBreakdownBox) {
                syBreakdownBox.classList.add('d-none');
            }
            if (pkppsHintBox) {
                pkppsHintBox.classList.add('d-none');
            }
        }
        resetHiddenKomponenRows();
        visibleKomponenRows().forEach(function (tr) {
            const slug = tr.getAttribute('data-slug') || '';
            const info = tagihanPos[slug];
            if (info && info.is_opsional && info.override_aktif === false) {
                tr.style.display = 'none';
                const cb = tr.querySelector('.bayar-pos-check');
                if (cb) {
                    cb.checked = false;
                }
            } else if (!isJenisBulanan() && info && info.berlaku === false) {
                tr.style.display = 'none';
                const cb = tr.querySelector('.bayar-pos-check');
                const inp = tr.querySelector('.nominal-pos');
                if (cb) {
                    cb.checked = false;
                }
                if (inp) {
                    inp.value = '0';
                }
            } else {
                tr.style.display = '';
            }
        });
    }

    function updateStatusTransaksi() {
        let stillCicilan = false;
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none') {
                tr.classList.remove('is-checked');
                return;
            }
            const slug = tr.getAttribute('data-slug');
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            const checked = !!(cb && cb.checked);
            tr.classList.toggle('is-checked', checked);
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
        const label = stillCicilan ? 'BELUM DITERIMA · DI CICIL' : 'DITERIMA';
        if (statusHidden) {
            statusHidden.value = val;
        }
        if (statusLabel) {
            statusLabel.value = label;
        }
        updateGrandTotal();
    }

    function computeGrandTotal() {
        let total = 0;
        let posCount = 0;
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            if (!cb || !cb.checked || !inp) {
                return;
            }
            const n = parseRpInput(inp.value);
            if (n > 0) {
                total += n;
                posCount += 1;
            }
        });
        return { total: total, posCount: posCount };
    }

    function updateGrandTotal() {
        const { total, posCount } = computeGrandTotal();
        const label = fmtRp(total);
        if (grandTotalEl) {
            grandTotalEl.textContent = label;
        }
        if (totalPosEl) {
            totalPosEl.textContent = posCount + ' pos';
        }
        if (actionsAmountEl) {
            actionsAmountEl.textContent = label;
        }
        if (actionsTotalWrap) {
            actionsTotalWrap.classList.toggle('has-amount', total > 0);
        }
    }

    function updatePilihSemuaBtn() {
        if (!btnPilihSemua) {
            return;
        }
        const sid = santriSel ? parseInt(santriSel.value, 10) : 0;
        let hasSisa = false;
        if (sid > 0) {
            Object.keys(tagihanPos || {}).forEach(function (slug) {
                const info = tagihanPos[slug];
                if (info && (info.sisa || 0) > 0) {
                    hasSisa = true;
                }
            });
        }
        btnPilihSemua.disabled = !hasSisa || bulanUrutanBlokir;
    }

    function pilihSemuaSisa() {
        visibleKomponenRows().forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const slug = tr.getAttribute('data-slug');
            const cb = tr.querySelector('.bayar-pos-check');
            const inp = tr.querySelector('.nominal-pos');
            if (!slug || !cb || !inp) {
                return;
            }
            const info = tagihanPos[slug];
            if (info && (info.sisa || 0) > 0) {
                cb.checked = true;
                inp.value = fmtThousand(info.sisa);
            }
        });
        updateStatusTransaksi();
    }

    function formatNominalInput(inp) {
        const n = parseRpInput(inp.value);
        inp.value = n > 0 ? fmtThousand(n) : '0';
    }

    function renderSyahriyahBreakdown(summary) {
        if (!syBreakdownBox || !syBreakdownLines || !syBreakdownTotal) {
            return;
        }
        const bd = summary && summary.syahriyah_breakdown ? summary.syahriyah_breakdown : null;
        if (!bd || (bd.total || 0) <= 0) {
            syBreakdownBox.classList.add('d-none');
        } else {
            const tier = bd.tier_label ? ' (' + bd.tier_label + ')' : '';
            const lines = [];
            lines.push('Syahriyah pokok' + tier + ': <strong>' + fmtRp(bd.dasar || 0) + '</strong>');
            if ((bd.pkpps || 0) > 0) {
                lines.push('Tambahan PKPPS: <strong>' + fmtRp(bd.pkpps) + '</strong>');
            }
            syBreakdownLines.innerHTML = lines.join('<br>');
            const sisaLine = (bd.sisa || 0) > 0
                ? ' · Sisa bayar: <strong>' + fmtRp(bd.sisa) + '</strong>'
                : ' · <span class="text-success">DITERIMA</span>';
            syBreakdownTotal.innerHTML =
                'Total tagihan: <strong>' + fmtRp(bd.total) + '</strong>' + sisaLine;
            syBreakdownBox.classList.remove('d-none');
        }
        if (pkppsHintBox) {
            const pkppsAktif = !!(summary && summary.pkpps_aktif);
            const pkppsNom = bd ? (parseInt(bd.pkpps, 10) || 0) : 0;
            const showHint = pkppsAktif && pkppsNom <= 0 && summary && (parseInt(summary.expected_wajib, 10) || 0) > 0;
            pkppsHintBox.classList.toggle('d-none', !showHint);
            if (showHint && summary.kelas_tagihan) {
                const kk = summary.pkpps_kelas_kode || summary.kelas_tagihan;
                pkppsHintBox.innerHTML =
                    '<i class="fa-solid fa-triangle-exclamation me-1"></i> ' +
                    'Santri PKPPS (kelas: <strong>' + kk + '</strong>) — tambahan PKPPS = Rp 0. Periksa ' +
                    '<a href="' + appUrl('/keuangan/pengaturan.php?bagian=tarif#tambahan-pkpps') + '">nominal PKPPS</a> ' +
                    'untuk kelas keuangan tersebut.';
            }
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
            let rincianLine = '';
            if (slug === 'syahriyah' && (info.pkpps_tambahan || 0) > 0) {
                const dasar = (info.expected_setelah_potongan != null)
                    ? info.expected_setelah_potongan
                    : Math.max(0, (info.expected || 0) - (info.pkpps_tambahan || 0));
                rincianLine = '<br><span style="font-size:.72rem">' +
                    fmtRp(dasar) +
                    ((info.pkpps_tambahan || 0) > 0 ? ' + PKPPS ' + fmtRp(info.pkpps_tambahan) : '') +
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
                    '<span class="text-success">DITERIMA</span><br><span class="text-muted">' +
                    fmtRp(info.expected) +
                    '</span>' +
                    rincianLine +
                    potonganLine;
            }
        });
    }

    function applyTarifSantri() {
        const sid = santriSel ? String(santriSel.value || '') : '';
        const info = map[sid];
        if (tierHint) {
            if (info) {
                let hint = 'Kelas: ' + info.kelas_label + ' · Tarif ' + info.tier_label;
                tierHint.textContent = hint;
            } else {
                tierHint.textContent = 'Tarif mengikuti kelas keuangan santri yang dipilih.';
            }
        }
        if (opsBulkLink) {
            const baseHref = appUrl('/keuangan/pengaturan.php?bagian=santri_bulanan&sub=opsional');
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
            visibleKomponenRows().forEach(function (tr) {
                const inp = tr.querySelector('.nominal-pos');
                const cb = tr.querySelector('.bayar-pos-check');
                if (inp) {
                    inp.value = '0';
                }
                if (cb) {
                    cb.checked = false;
                }
            });
            renderPaidHints();
            renderSyahriyahBreakdown(null);
            if (pkppsHintBox) {
                pkppsHintBox.classList.add('d-none');
            }
            updatePilihSemuaBtn();
            updateGrandTotal();
            return;
        }
        filterKomponenRows();
        applyNominalFromFeeMatrix();
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
            bulanUrutanMap = {};
            if (bulanSel) {
                Array.from(bulanSel.options).forEach(function (opt) {
                    opt.disabled = false;
                    opt.title = '';
                });
            }
            setBulanUrutanBlokirUi(false, '');
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
                    bulanUrutanMap = {};
                    setBulanUrutanBlokirUi(false, '');
                    renderTagihanMasukCatatan(null);
                    resetOpsEditors('Gagal memuat pengaturan.');
                    applyNominalFromFeeMatrix();
                    renderPaidHints();
                    return;
                }
                tagihanPos = data.pos || {};
                bulanUrutanMap = data.bulan_urutan || {};
                if (applyBulanUrutanRestrictions()) {
                    window.setTimeout(loadTagihanPreview, 0);
                    return;
                }
                const currentBulan = parseInt(bulanSel ? bulanSel.value : '0', 10) || 0;
                const bulanInfo = bulanUrutanInfo(currentBulan);
                const bulanBlocked = !!(bulanInfo && bulanInfo.dibebankan !== false && bulanInfo.allowed === false);
                setBulanUrutanBlokirUi(
                    bulanBlocked,
                    bulanBlocked
                        ? (bulanInfo.message || data.bulan_blokir_pesan || 'Lunasi tagihan wajib bulan sebelumnya terlebih dahulu.')
                        : ''
                );
                const nominalFill = data.nominal_fill || null;
                Object.keys(tagihanPos).forEach(function (slug) {
                    const info = tagihanPos[slug];
                    if (info && info.is_opsional) {
                        renderOpsEditor(slug, info);
                    }
                });
                updateOpsPanelOpenState();
                filterKomponenRows();
                renderPaidHints();
                applyNominalFromTagihan(nominalFill);
                renderSyahriyahBreakdown(data.summary || null);
                renderTagihanMasukCatatan(data.summary || null);
                if (tierHint && data.summary && data.summary.pkpps_aktif) {
                    const kk = data.summary.pkpps_kelas_kode || data.summary.kelas_tagihan || '';
                    const sidInfo = map[santriSel ? String(santriSel.value) : ''];
                    const base = sidInfo
                        ? 'Kelas: ' + sidInfo.kelas_label + ' · Tarif ' + sidInfo.tier_label
                        : tierHint.textContent;
                    tierHint.textContent = base + (kk !== '' ? ' · PKPPS (' + kk + ')' : ' · Santri PKPPS');
                }
                updatePilihSemuaBtn();
                applyLunasRowLocks();
                if (summaryHint && data.summary) {
                    const sisa = parseInt(data.summary.sisa_wajib, 10) || 0;
                    const exp = parseInt(data.summary.expected_wajib, 10) || 0;
                    if (!isJenisBulanan()) {
                        const jenisLabel = (data.summary && data.summary.jenis_santri === 'lama')
                            ? 'santri lama'
                            : ((data.summary && data.summary.jenis_santri === 'baru') ? 'santri baru' : 'santri');
                        summaryHint.textContent =
                            'Pembayaran awal tahun (' + jenisLabel + ') — hanya komponen yang berlaku. Centang pos yang dibayar; nominal dapat diedit.';
                    } else if (exp <= 0 && data.summary.tagihan_masuk && parseInt(data.summary.tagihan_masuk.bulan_mulai, 10) > 1) {
                        const bm = parseInt(data.summary.tagihan_masuk.bulan_mulai, 10) || 1;
                        const bulanNow = parseInt(bulanSel ? bulanSel.value : '0', 10) || 0;
                        if (bulanNow > 0 && bulanNow < bm) {
                            summaryHint.textContent = 'Bulan ini belum ditagih (santri baru mulai bulan ke-' + bm + ').';
                        } else {
                            summaryHint.textContent = 'Belum ada tarif tagihan wajib untuk kelas santri ini.';
                        }
                    } else if (exp <= 0) {
                        summaryHint.textContent = 'Belum ada tarif tagihan wajib untuk kelas santri ini.';
                    } else if (sisa <= 0) {
                        summaryHint.textContent = 'Tagihan wajib bulan ini sudah lunas. Anda masih bisa mencatat pembayaran Saku atau pos lain.';
                    } else {
                        summaryHint.textContent =
                            'Sisa tagihan wajib bulan ini: ' +
                            fmtRp(sisa) +
                            ' dari ' +
                            fmtRp(exp) +
                            ' (termasuk PKPPS jika berlaku). Nominal default terisi otomatis — bisa diubah untuk cicilan.';
                    }
                }
                if (!bulanUrutanBlokir) {
                    visibleKomponenRows().forEach(function (tr) {
                        if (tr.style.display === 'none') {
                            return;
                        }
                        const slug = tr.getAttribute('data-slug');
                        const info = slug ? tagihanPos[slug] : null;
                        const cb = tr.querySelector('.bayar-pos-check');
                        const inp = tr.querySelector('.nominal-pos');
                        if (cb && info && (info.sisa || 0) > 0) {
                            cb.checked = true;
                        }
                        if (inp && nominalFill && nominalFill[slug] > 0) {
                            inp.value = fmtThousand(nominalFill[slug]);
                        }
                    });
                }
                updateStatusTransaksi();
            })
            .catch(function () {
                tagihanPos = {};
                applyNominalFromFeeMatrix();
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

    function onBayarPosCheckChange(ev) {
        const cb = ev.currentTarget;
        const tr = cb ? cb.closest('tr') : null;
        const slug = tr ? (tr.getAttribute('data-slug') || '') : '';
        const info = slug ? tagihanPos[slug] : null;
        if (cb && cb.checked && isPosLunas(info, slug)) {
            cb.checked = false;
            window.alert('Komponen ini sudah lunas untuk periode ini. Input dobel tidak diizinkan.');
            updateStatusTransaksi();
            return;
        }
        if (cb && cb.checked) {
            const tr = cb.closest('tr');
            if (tr && tr.style.display !== 'none') {
                const slug = tr.getAttribute('data-slug');
                const inp = tr.querySelector('.nominal-pos');
                if (slug && inp && parseRpInput(inp.value) <= 0) {
                    const resolved = resolveNominalForSlug(slug, tagihanPos[slug] || null, null);
                    if (resolved.nominal > 0) {
                        inp.value = fmtThousand(resolved.nominal);
                    }
                }
            }
        }
        updateStatusTransaksi();
    }

    function setJenisPeriode(mode) {
        if (!jenisSel) {
            return;
        }
        if (jenisSel.value !== mode) {
            jenisSel.value = mode;
            jenisSel.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            filterKomponenRows();
        }
    }

    function syncJenisToggleButtons() {
        if (!jenisSel) {
            return;
        }
        const mode = jenisSel.value;
        document.querySelectorAll('.pembayaran-jenis-toggle [data-jenis]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-jenis') === mode);
        });
        const badge = document.getElementById('pembayaran-mode-badge');
        if (badge) {
            badge.textContent = mode === 'AWAL_TAHUN' ? 'Awal tahun' : 'Tagihan bulanan';
        }
    }

    function setMetodeBayar(mode) {
        if (!metodeSel) {
            return;
        }
        if (metodeSel.value !== mode) {
            metodeSel.value = mode;
            metodeSel.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            toggleRefRequired();
        }
        syncMetodeToggleButtons();
    }

    function syncMetodeToggleButtons() {
        if (!metodeSel) {
            return;
        }
        const mode = metodeSel.value;
        document.querySelectorAll('.pembayaran-metode-toggle [data-metode]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-metode') === mode);
        });
    }

    function focusSantriField() {
        const wrap = santriSel ? santriSel.closest('.santri-select-wrap') : null;
        const searchInp = wrap ? wrap.querySelector('.santri-select-search') : null;
        if (searchInp) {
            searchInp.focus();
            return;
        }
        if (santriSel) {
            santriSel.focus();
        }
    }

    function openPembayaranForm(mode) {
        const launcher = document.getElementById('pembayaran-launcher');
        const formWrap = document.getElementById('pembayaran-form-wrap');
        if (formWrap) {
            formWrap.classList.remove('d-none');
        }
        if (launcher) {
            launcher.classList.add('d-none');
        }
        setJenisPeriode(mode);
        syncJenisToggleButtons();
        if (mode === 'BULANAN' && bulanSel) {
            const berjalan = parseInt(window.pembayaranBulanBerjalan, 10) || 0;
            if (berjalan > 0 && bulanSel.value !== String(berjalan)) {
                bulanSel.value = String(berjalan);
                bulanSel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        window.setTimeout(focusSantriField, 80);
    }

    function closePembayaranForm() {
        const launcher = document.getElementById('pembayaran-launcher');
        const formWrap = document.getElementById('pembayaran-form-wrap');
        if (formWrap) {
            formWrap.classList.add('d-none');
        }
        if (launcher) {
            launcher.classList.remove('d-none');
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function bindPembayaranLauncher() {
        document.querySelectorAll('[data-pembayaran-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openPembayaranForm(btn.getAttribute('data-pembayaran-mode') || 'BULANAN');
            });
        });
        const backBtn = document.getElementById('btn-pembayaran-kembali');
        if (backBtn) {
            backBtn.addEventListener('click', closePembayaranForm);
        }
        document.querySelectorAll('.pembayaran-jenis-toggle [data-jenis]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setJenisPeriode(btn.getAttribute('data-jenis') || 'BULANAN');
                syncJenisToggleButtons();
            });
        });
        document.querySelectorAll('.pembayaran-metode-toggle [data-metode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setMetodeBayar(btn.getAttribute('data-metode') || 'KAS');
            });
        });
        syncJenisToggleButtons();
        syncMetodeToggleButtons();
    }

    function bindSantriSelectionEvents() {
        if (!santriSel) {
            return;
        }
        santriSel.addEventListener('change', applyTarifSantri);
        santriSel.addEventListener('input', applyTarifSantri);

        function bindSearchInput() {
            const wrap = santriSel.closest('.santri-select-wrap');
            const searchInp = wrap ? wrap.querySelector('.santri-select-search') : null;
            if (!searchInp || searchInp.dataset.keuanganTarifBound === '1') {
                return;
            }
            searchInp.dataset.keuanganTarifBound = '1';
            searchInp.addEventListener('change', function () {
                if (santriSel.value) {
                    applyTarifSantri();
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindSearchInput);
        } else {
            bindSearchInput();
            window.setTimeout(bindSearchInput, 0);
        }
    }
    if (jenisSel) {
        jenisSel.addEventListener('change', function () {
            filterKomponenRows();
            syncJenisToggleButtons();
            if (santriSel && parseInt(santriSel.value, 10) > 0) {
                applyNominalFromFeeMatrix();
                loadTagihanPreview();
            } else {
                applyNominalFromTagihan(null);
                updateGrandTotal();
            }
        });
    }
    if (bulanSel) {
        bulanSel.addEventListener('change', function () {
            applyNominalFromFeeMatrix();
            loadTagihanPreview();
        });
    }
    form.querySelectorAll('[name="tahun_ajaran_mulai"], [name="tahun_ajaran_selesai"]').forEach(function (el) {
        el.addEventListener('change', loadTagihanPreview);
    });
    if (metodeSel) {
        metodeSel.addEventListener('change', function () {
            toggleRefRequired();
            syncMetodeToggleButtons();
        });
    }

    document.querySelectorAll('.bayar-pos-check').forEach(function (el) {
        el.addEventListener('change', onBayarPosCheckChange);
    });
    document.querySelectorAll('.nominal-pos').forEach(function (el) {
        el.addEventListener('change', updateStatusTransaksi);
        el.addEventListener('input', function () {
            const tr = el.closest('tr');
            if (tr && tr.style.display !== 'none') {
                const slug = tr.getAttribute('data-slug') || '';
                if (slug && slug !== 'saku') {
                    const info = tagihanPos[slug];
                    if (info && (info.expected || 0) > 0) {
                        const sisa = Math.max(0, info.sisa || 0);
                        const n = parseRpInput(el.value);
                        if (sisa <= 0 && n > 0) {
                            el.value = '0';
                            const cb = tr.querySelector('.bayar-pos-check');
                            if (cb) {
                                cb.checked = false;
                            }
                        } else if (n > sisa) {
                            el.value = sisa > 0 ? fmtThousand(sisa) : '0';
                        }
                    }
                }
            }
            updateStatusTransaksi();
        });
    });

    document.querySelectorAll('.nominal-pos').forEach(function (inp) {
        inp.addEventListener('blur', function () {
            formatNominalInput(inp);
            updateStatusTransaksi();
        });
        inp.addEventListener('focus', function () {
            const n = parseRpInput(inp.value);
            if (n > 0) {
                inp.select();
            }
        });
    });

    if (btnPilihSemua) {
        btnPilihSemua.addEventListener('click', pilihSemuaSisa);
    }

    form.addEventListener('submit', function (ev) {
        resetHiddenKomponenRows();
        if (isJenisBulanan() && bulanUrutanBlokir) {
            ev.preventDefault();
            window.alert(bulanUrutanBlokirPesan || 'Lunasi tagihan wajib bulan sebelumnya terlebih dahulu.');
            return;
        }
        const dobelErr = collectDobelPaymentError();
        if (dobelErr) {
            ev.preventDefault();
            window.alert(dobelErr);
        }
    });

    bindOpsionalEditorEvents();
    bindPembayaranLauncher();
    bindSantriSelectionEvents();
    filterKomponenRows();
    applyTarifSantri();
    toggleRefRequired();
    updateGrandTotal();
})();
