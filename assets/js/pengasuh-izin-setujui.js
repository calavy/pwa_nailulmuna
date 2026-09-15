(function () {
    function flashEls() {
        return [
            document.getElementById('pg-dash-izin-flash'),
            document.getElementById('pg-izin-flash')
        ].filter(Boolean);
    }

    function showFlash(ok, message) {
        flashEls().forEach(function (el) {
            el.className = 'alert mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
            el.textContent = message || (ok ? 'Berhasil.' : 'Gagal.');
            el.classList.remove('d-none');
        });
        var first = flashEls()[0];
        if (first && first.scrollIntoView) {
            first.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function syncDashboard() {
        var panel = document.getElementById('pg-dash-izin');
        if (!panel) {
            return;
        }
        var badge = panel.querySelector('.badge.text-bg-warning');
        var intro = panel.querySelector('p.small.text-muted');
        var n = panel.querySelectorAll('.pg-dash-izin-card').length;
        if (badge) {
            if (n > 0) {
                badge.textContent = String(n);
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        }
        if (intro) {
            intro.innerHTML = n > 0
                ? '<strong>' + n + '</strong> permohonan menunggu persetujuan pengasuh.'
                : 'Tidak ada permohonan izin yang menunggu saat ini.';
        }
        if (n === 0) {
            panel.querySelectorAll('.pg-dash-izin-list').forEach(function (list) { list.remove(); });
            var body = panel.querySelector('.card-body');
            if (body && !body.querySelector('.text-muted.text-center')) {
                var empty = document.createElement('div');
                empty.className = 'small text-muted text-center py-2';
                empty.textContent = 'Permohonan izin yang menunggu akan muncul di sini.';
                body.appendChild(empty);
            }
        }
    }

    function syncDaftar() {
        var badge = document.querySelector('.card-header .badge.text-bg-warning');
        var n = document.querySelectorAll('.pg-izin-card').length;
        if (badge) {
            badge.textContent = String(document.querySelectorAll('.pg-izin-card:not(.pg-izin-card--rombongan)').length);
        }
        if (n === 0) {
            document.querySelectorAll('.pg-izin-list').forEach(function (list) { list.remove(); });
            var body = document.querySelector('.card-body');
            if (body && !body.querySelector('.text-muted.text-center')) {
                var empty = document.createElement('div');
                empty.className = 'text-muted text-center py-4';
                empty.textContent = 'Tidak ada izin yang menunggu.';
                body.appendChild(empty);
            }
        }
    }

    function afterSuccess(form) {
        var card = form.closest('.pg-dash-izin-card, .pg-izin-card');
        if (card) {
            card.remove();
        }
        syncDashboard();
        syncDaftar();
    }

    function postForm(form, bypass) {
        var fd = new FormData(form);
        if (bypass) {
            fd.set('bypass_alpa', '1');
        } else {
            fd.delete('bypass_alpa');
        }
        return fetch(form.getAttribute('action') || '', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (res) {
            return res.json().then(function (data) {
                return data || {};
            }, function () {
                return { ok: false, message: 'Tidak dapat memproses. Coba lagi.' };
            });
        });
    }

    function setRowVisible(wrapEl, visible) {
        if (wrapEl) {
            wrapEl.classList.toggle('d-none', !visible);
        }
    }

    function fillIzinDetail(form) {
        var judulEl = document.getElementById('pg-izin-setujui-judul');
        var subEl = document.getElementById('pg-izin-setujui-sub');
        var pemohonEl = document.getElementById('pg-izin-setujui-pemohon');
        var pemohonWrap = document.getElementById('pg-izin-setujui-pemohon-wrap');
        var jenisEl = document.getElementById('pg-izin-setujui-jenis');
        var jenisWrap = document.getElementById('pg-izin-setujui-jenis-wrap');
        var keperluanEl = document.getElementById('pg-izin-setujui-keperluan');
        var keperluanWrap = document.getElementById('pg-izin-setujui-keperluan-wrap');
        var tglEl = document.getElementById('pg-izin-setujui-tanggal');
        var keteranganEl = document.getElementById('pg-izin-setujui-keterangan');
        var keteranganWrap = document.getElementById('pg-izin-setujui-keterangan-wrap');
        var tujuanEl = document.getElementById('pg-izin-setujui-tujuan');
        var tujuanWrap = document.getElementById('pg-izin-setujui-tujuan-wrap');

        if (judulEl) {
            judulEl.textContent = form.getAttribute('data-judul') || 'Permohonan izin';
        }
        var sub = (form.getAttribute('data-sub') || '').trim();
        if (subEl) {
            subEl.textContent = sub;
            subEl.classList.toggle('d-none', sub === '');
        }
        var pemohon = (form.getAttribute('data-pemohon') || '').trim();
        if (pemohonEl) {
            pemohonEl.textContent = pemohon;
        }
        setRowVisible(pemohonWrap, pemohon !== '');
        var jenis = (form.getAttribute('data-jenis-label') || '').trim();
        if (jenisEl) {
            jenisEl.textContent = jenis;
        }
        setRowVisible(jenisWrap, jenis !== '');
        var keperluan = (form.getAttribute('data-keperluan') || '').trim();
        if (keperluanEl) {
            keperluanEl.textContent = keperluan;
        }
        setRowVisible(keperluanWrap, keperluan !== '');
        if (tglEl) {
            tglEl.textContent = form.getAttribute('data-tanggal') || '—';
        }
        var keterangan = (form.getAttribute('data-keterangan') || '').trim();
        if (keteranganEl) {
            keteranganEl.textContent = keterangan !== '' ? keterangan : (keperluan !== '' ? '—' : '');
        }
        setRowVisible(keteranganWrap, keterangan !== '' || keperluan !== '');
        var tujuan = (form.getAttribute('data-tujuan') || '').trim();
        if (tujuanEl) {
            tujuanEl.textContent = tujuan;
        }
        setRowVisible(tujuanWrap, tujuan !== '');
    }

    function fillAlpa(form) {
        fillIzinDetail(form);
        var panel = document.getElementById('pg-izin-setujui-alpa');
        var wrap = document.getElementById('pg-izin-setujui-bypass-wrap');
        var cb = document.getElementById('pg-izin-setujui-bypass');
        var submitBtn = document.getElementById('pg-izin-setujui-submit');
        var subject = form.getAttribute('data-alpa-subject') === '1';
        var allowed = form.getAttribute('data-alpa-allowed') === '1';
        var rombonganNote = form.getAttribute('data-alpa-rombongan-note') || '';
        if (panel && typeof window.renderIzinAlpaModal === 'function') {
            var payload = typeof window.parseIzinAlpaModalPayload === 'function'
                ? window.parseIzinAlpaModalPayload(form)
                : null;
            window.renderIzinAlpaModal(panel, payload, { rombonganNote: rombonganNote });
        } else if (panel) {
            panel.className = 'alert alert-secondary py-2 small mb-3 izin-alpa-panel-modal';
            panel.textContent = subject
                ? 'Syarat ALPA — muat ulang halaman jika panel kosong.'
                : 'Syarat ALPA tidak berlaku untuk permohonan ini.';
        }
        var needBypass = subject && !allowed;
        if (wrap) {
            wrap.classList.toggle('d-none', !needBypass);
        }
        if (cb) {
            cb.checked = false;
        }
        if (submitBtn) {
            submitBtn.disabled = needBypass;
        }
        return { needBypass: needBypass };
    }

    function getModal(modalEl) {
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return null;
        }
        return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    var modalEl = document.getElementById('pgIzinSetujuiModal');
    if (!modalEl) {
        return;
    }

    var pendingForm = null;
    var bypassCb = document.getElementById('pg-izin-setujui-bypass');
    var submitBtn = document.getElementById('pg-izin-setujui-submit');

    if (bypassCb && submitBtn) {
        bypassCb.addEventListener('change', function () {
            if (!pendingForm) {
                return;
            }
            var need = pendingForm.getAttribute('data-alpa-subject') === '1'
                && pendingForm.getAttribute('data-alpa-allowed') !== '1';
            submitBtn.disabled = need && !bypassCb.checked;
        });
    }

    document.querySelectorAll('.pg-izin-setujui-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) {
                e.stopImmediatePropagation();
            }
            if (form.getAttribute('data-submitting') === '1') {
                return;
            }
            pendingForm = form;
            fillAlpa(form);
            var modal = getModal(modalEl);
            if (modal) {
                modal.show();
                return;
            }
            window.addEventListener('load', function () {
                var late = getModal(modalEl);
                if (late) {
                    late.show();
                }
            }, { once: true });
        }, true);
    });

    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            if (!pendingForm || pendingForm.getAttribute('data-submitting') === '1') {
                return;
            }
            var need = pendingForm.getAttribute('data-alpa-subject') === '1'
                && pendingForm.getAttribute('data-alpa-allowed') !== '1';
            var bypass = !!(bypassCb && bypassCb.checked);
            if (need && !bypass) {
                submitBtn.disabled = true;
                return;
            }
            var form = pendingForm;
            form.setAttribute('data-submitting', '1');
            var oldLabel = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses…';
            postForm(form, bypass).then(function (data) {
                var ok = !!data.ok;
                showFlash(ok, data.message || '');
                if (!ok) {
                    form.removeAttribute('data-submitting');
                    submitBtn.disabled = need && !bypass;
                    submitBtn.textContent = oldLabel;
                    return;
                }
                var modal = getModal(modalEl);
                if (modal) {
                    modal.hide();
                }
                afterSuccess(form);
                pendingForm = null;
                submitBtn.textContent = oldLabel;
                submitBtn.disabled = false;
            }).catch(function () {
                showFlash(false, 'Tidak dapat memproses. Coba lagi.');
                form.removeAttribute('data-submitting');
                submitBtn.disabled = false;
                submitBtn.textContent = oldLabel;
            });
        });
    }

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (pendingForm) {
            pendingForm.removeAttribute('data-submitting');
        }
        pendingForm = null;
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Setujui';
        }
        if (bypassCb) {
            bypassCb.checked = false;
        }
    });

    document.querySelectorAll('.pg-izin-tolak-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) {
                e.stopImmediatePropagation();
            }
            if (form.getAttribute('data-submitting') === '1') {
                return;
            }
            var confirmMsg = form.getAttribute('data-confirm');
            if (confirmMsg && !window.confirm(confirmMsg)) {
                return;
            }
            form.setAttribute('data-submitting', '1');
            var btn = form.querySelector('button[type="submit"]');
            var oldLabel = btn ? btn.textContent : '';
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Memproses…';
            }
            postForm(form, false).then(function (data) {
                var ok = !!data.ok;
                showFlash(ok, data.message || '');
                if (!ok) {
                    form.removeAttribute('data-submitting');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = oldLabel;
                    }
                    return;
                }
                afterSuccess(form);
            }).catch(function () {
                showFlash(false, 'Tidak dapat memproses. Coba lagi.');
                form.removeAttribute('data-submitting');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = oldLabel;
                }
            });
        }, true);
    });
})();
