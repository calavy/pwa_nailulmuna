(function () {
    var form = document.getElementById('form-tagihan-filter');
    var cari = document.getElementById('tagihan-cari');
    var ringkasBtn = document.getElementById('btn-tagihan-ringkas');
    var ringkasInput = document.getElementById('tagihan-ringkas-input');
    var card = document.querySelector('.tagihan-list-card');
    var debounceTimer = null;
    var apiBase = window.TAGIHAN_WA_API || '/api/wa/tagihan_santri.php';

    if (cari && form) {
        cari.addEventListener('input', function () {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(function () {
                debounceTimer = null;
                if (form.requestSubmit) {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }, 450);
        });
        cari.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                }
                if (form.requestSubmit) {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
    }

    if (ringkasBtn && ringkasInput && form) {
        ringkasBtn.addEventListener('click', function () {
            var on = ringkasInput.value !== '1';
            ringkasInput.value = on ? '1' : '0';
            if (card) {
                card.classList.toggle('tagihan-list-card--ringkas', on);
            }
            ringkasBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            ringkasBtn.textContent = on ? 'Tampilkan detail kolom' : 'Mode ringkas';
            if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }

    document.querySelectorAll('.tagihan-btn-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-row');
            var row = document.getElementById('tagihan-detail-' + id);
            if (!row) {
                return;
            }
            var open = row.classList.toggle('is-open');
            row.classList.toggle('d-none', !open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    function fetchWaPreview(grup) {
        var sid = grup.getAttribute('data-santri-id');
        var bulan = grup.getAttribute('data-bulan');
        var taMulai = grup.getAttribute('data-ta-mulai');
        var taSelesai = grup.getAttribute('data-ta-selesai');
        var url = apiBase + '?santri_id=' + encodeURIComponent(sid)
            + '&bulan=' + encodeURIComponent(bulan)
            + '&ta_mulai=' + encodeURIComponent(taMulai)
            + '&ta_selesai=' + encodeURIComponent(taSelesai);
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); });
    }

    function setBtnLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = loading;
        if (loading) {
            btn.dataset.prevHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
        } else if (btn.dataset.prevHtml) {
            btn.innerHTML = btn.dataset.prevHtml;
            delete btn.dataset.prevHtml;
        }
    }

    document.querySelectorAll('.tagihan-wa-grup').forEach(function (grup) {
        var btnChat = grup.querySelector('.tagihan-btn-wa-chat');
        var btnGw = grup.querySelector('.tagihan-btn-wa-gateway');
        var nama = grup.getAttribute('data-nama') || 'santri';

        if (btnChat) {
            btnChat.addEventListener('click', function () {
                setBtnLoading(btnChat, true);
                fetchWaPreview(grup).then(function (data) {
                    if (data && data.ok && data.wa_url) {
                        window.open(data.wa_url, '_blank', 'noopener');
                    } else {
                        alert((data && data.error) ? data.error : 'Tidak bisa membuat pesan WA.');
                    }
                }).catch(function () {
                    alert('Gagal memuat preview WA.');
                }).finally(function () {
                    setBtnLoading(btnChat, false);
                });
            });
        }

        if (btnGw) {
            btnGw.addEventListener('click', function () {
                if (!confirm('Kirim tagihan via gateway ke wali ' + nama + '?')) {
                    return;
                }
                setBtnLoading(btnGw, true);
                var body = new FormData();
                body.append('mode', 'gateway');
                body.append('santri_id', grup.getAttribute('data-santri-id') || '');
                body.append('bulan', grup.getAttribute('data-bulan') || '');
                body.append('ta_mulai', grup.getAttribute('data-ta-mulai') || '');
                body.append('ta_selesai', grup.getAttribute('data-ta-selesai') || '');
                fetch(apiBase, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            alert('WA tagihan terkirim ke ' + (data.nama || nama) + '.');
                        } else {
                            alert((data && data.error) ? data.error : 'Gagal mengirim via gateway.');
                        }
                    })
                    .catch(function () {
                        alert('Gagal menghubungi server.');
                    })
                    .finally(function () {
                        setBtnLoading(btnGw, false);
                    });
            });
        }
    });
})();
