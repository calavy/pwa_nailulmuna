(function () {
    var form = document.getElementById('form-tagihan-filter');
    var cari = document.getElementById('tagihan-cari');
    var ringkasInput = document.getElementById('tagihan-ringkas-input');
    var listRoot = document.getElementById('tagihan-list-root');
    var debounceTimer = null;
    var partialBusy = false;
    var apiBase = window.TAGIHAN_WA_API || '/api/wa/tagihan_santri.php';

    function buildParams(opts) {
        opts = opts || {};
        var params = new URLSearchParams(new FormData(form));
        if (opts.page) {
            params.set('page', String(opts.page));
        } else if (opts.resetPage) {
            params.set('page', '1');
        }
        return params;
    }

    function restoreCariFocus(selStart, selEnd) {
        if (!cari) {
            return;
        }
        cari.focus();
        if (typeof selStart === 'number' && typeof selEnd === 'number') {
            try {
                cari.setSelectionRange(selStart, selEnd);
            } catch (e) {
                /* ignore */
            }
        }
    }

    function wireWaGrup(grup) {
        var btnChat = grup.querySelector('.tagihan-btn-wa-chat');
        var btnGw = grup.querySelector('.tagihan-btn-wa-gateway');
        var nama = grup.getAttribute('data-nama') || 'santri';

        if (btnChat && !btnChat.dataset.wired) {
            btnChat.dataset.wired = '1';
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

        if (btnGw && !btnGw.dataset.wired) {
            btnGw.dataset.wired = '1';
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
    }

    function wireTagihanList(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('.tagihan-wa-grup').forEach(wireWaGrup);
    }

    function loadTagihanPartial(opts) {
        if (!form || !listRoot || partialBusy) {
            return;
        }
        partialBusy = true;
        var selStart = cari ? cari.selectionStart : null;
        var selEnd = cari ? cari.selectionEnd : null;
        var params = buildParams(opts);
        var url = '?' + params.toString();

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Tagihan-Partial': '1', Accept: 'text/html' },
        })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.text();
            })
            .then(function (html) {
                listRoot.innerHTML = html;
                wireTagihanList(listRoot);
                restoreCariFocus(selStart, selEnd);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', url);
                }
            })
            .catch(function () {
                if (form.requestSubmit) {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            })
            .finally(function () {
                partialBusy = false;
            });
    }

    if (form) {
        form.addEventListener('submit', function (ev) {
            if (ev.submitter && ev.submitter.type === 'submit' && ev.submitter.closest('form') !== form) {
                return;
            }
            ev.preventDefault();
            loadTagihanPartial({ resetPage: true });
        });

        form.querySelectorAll('[data-auto-submit="1"]').forEach(function (el) {
            el.addEventListener('change', function () {
                loadTagihanPartial({ resetPage: true });
            });
        });
    }

    if (cari && form) {
        // Jangan reload tiap jeda ketik — fokus hilang / teks loncat.
        // Muat ulang hanya saat Enter (tombol filter / select tetap lewat handler lain).
        cari.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                    debounceTimer = null;
                }
                loadTagihanPartial({ resetPage: true });
            }
        });
    }

    if (listRoot) {
        listRoot.addEventListener('click', function (ev) {
            var ringkasBtn = ev.target.closest('#btn-tagihan-ringkas');
            if (ringkasBtn && ringkasInput && form) {
                ev.preventDefault();
                var on = ringkasInput.value !== '1';
                ringkasInput.value = on ? '1' : '0';
                loadTagihanPartial({ resetPage: false });
                return;
            }

            var pageBtn = ev.target.closest('.tagihan-page-link');
            if (pageBtn) {
                ev.preventDefault();
                var p = parseInt(pageBtn.getAttribute('data-page') || '1', 10);
                if (p > 0) {
                    loadTagihanPartial({ page: p });
                }
                return;
            }

            var detailBtn = ev.target.closest('.tagihan-btn-detail');
            if (detailBtn) {
                var id = detailBtn.getAttribute('data-row');
                var row = document.getElementById('tagihan-detail-' + id);
                if (!row) {
                    return;
                }
                var open = row.classList.toggle('is-open');
                row.classList.toggle('d-none', !open);
                detailBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        });
    }

    wireTagihanList(listRoot);

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
        if (!btn) {
            return;
        }
        btn.disabled = loading;
        if (loading) {
            btn.dataset.prevHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
        } else if (btn.dataset.prevHtml) {
            btn.innerHTML = btn.dataset.prevHtml;
            delete btn.dataset.prevHtml;
        }
    }
})();
