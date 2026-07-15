/**
 * Pencarian santri manual: ketik nama/NIS pada <select name="santri_id">.
 * Mode AJAX (data-santri-ajax): muat opsi dari server, tidak perlu dropdown penuh.
 */
(function () {
    function norm(s) {
        return (s || '').toLowerCase().trim();
    }

    function appUrl(path) {
        var p = String(path || '');
        if (/^https?:\/\//i.test(p)) {
            return p;
        }
        if (p.charAt(0) !== '/') {
            p = '/' + p;
        }
        var base = (typeof window !== 'undefined' && window.PONDOK_APP_BASE != null)
            ? String(window.PONDOK_APP_BASE).replace(/\/$/, '')
            : '';
        return base === '' ? p : base + p;
    }

    function mergeTier(item) {
        if (!item || !item.id || !item.tier) {
            return;
        }
        if (!window.keuanganSantriTier) {
            window.keuanganSantriTier = {};
        }
        window.keuanganSantriTier[String(item.id)] = item.tier;
    }

    function enhanceSelect(sel) {
        if (!sel || sel.dataset.santriSelectEnhanced === '1') {
            return;
        }
        sel.dataset.santriSelectEnhanced = '1';
        var ajaxMode = sel.getAttribute('data-santri-ajax') === '1';
        var searchUrl = sel.getAttribute('data-santri-search-url') || appUrl('/api/keuangan/santri_search.php');

        const box = document.createElement('div');
        box.className = 'santri-select-wrap position-relative';

        const search = document.createElement('input');
        search.type = 'search';
        const useLg = sel.classList.contains('form-select-lg');
        search.className = 'form-control mb-1 santri-select-search' + (useLg ? ' form-control-lg' : ' form-control-sm');
        search.placeholder = sel.getAttribute('data-search-placeholder') || 'Ketik nama atau NIS…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Cari santri');

        const parent = sel.parentNode;
        parent.insertBefore(box, sel);
        box.appendChild(search);
        box.appendChild(sel);

        let options = Array.from(sel.options).map(function (opt) {
            return {
                el: opt,
                value: opt.value,
                text: opt.textContent || '',
                hay: norm(opt.textContent) + ' ' + norm(opt.value),
            };
        });

        function rebuildOptionsFromSelect() {
            options = Array.from(sel.options).map(function (opt) {
                return {
                    el: opt,
                    value: opt.value,
                    text: opt.textContent || '',
                    hay: norm(opt.textContent) + ' ' + norm(opt.value),
                };
            });
        }

        function visibleMatches() {
            return options.filter(function (o) {
                return o.value !== '' && !o.el.hidden;
            });
        }

        function pickOption(opt) {
            if (!opt) {
                return;
            }
            if (sel.value !== opt.value) {
                sel.value = opt.value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            // Label lengkap hanya setelah pilih sengaja (Enter/blur), bukan saat mengetik.
            search.value = (opt.text || '').trim();
        }

        function filterOptions() {
            const q = norm(search.value);
            options.forEach(function (o) {
                if (o.value === '') {
                    o.el.hidden = q !== '';
                    return;
                }
                const show = q === '' || o.hay.indexOf(q) !== -1;
                o.el.hidden = !show;
            });

            // Jangan auto-pilih / kosongkan saat mengetik — fokus & teks user tetap.
            // Kosongkan select hanya jika query kosong (user hapus semua).
            if (q === '' && sel.value !== '' && sel.value !== '0') {
                sel.value = '';
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        var ajaxTimer = null;
        var ajaxSeq = 0;

        function renderAjaxItems(items) {
            var keep = sel.value;
            while (sel.options.length > 1) {
                sel.remove(1);
            }
            (items || []).forEach(function (item) {
                var opt = document.createElement('option');
                opt.value = String(item.id);
                opt.textContent = item.label || ((item.nis || '-') + ' — ' + (item.nama || ''));
                sel.appendChild(opt);
                mergeTier(item);
            });
            rebuildOptionsFromSelect();
            if (keep && sel.querySelector('option[value="' + keep + '"]')) {
                sel.value = keep;
            }
            filterOptions();
        }

        function fetchAjax(q) {
            var seq = ++ajaxSeq;
            fetch(searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (seq !== ajaxSeq) {
                        return;
                    }
                    if (data && data.ok) {
                        renderAjaxItems(data.items || []);
                    }
                })
                .catch(function () {});
        }

        search.addEventListener('input', function () {
            if (ajaxMode) {
                var q = norm(search.value);
                if (q.length < 2) {
                    while (sel.options.length > 1) {
                        sel.remove(1);
                    }
                    rebuildOptionsFromSelect();
                    // Clear pilihan hanya jika sebelumnya ada santri terpilih (bukan placeholder/0).
                    var prev = sel.value;
                    if (prev !== '' && prev !== '0') {
                        sel.value = sel.querySelector('option[value="0"]') ? '0' : '';
                        if (sel.value !== prev) {
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    return;
                }
                clearTimeout(ajaxTimer);
                ajaxTimer = setTimeout(function () {
                    fetchAjax(q);
                }, 280);
                return;
            }
            filterOptions();
        });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const matches = visibleMatches();
                if (matches.length >= 1) {
                    pickOption(matches[0]);
                }
            }
        });
        search.addEventListener('blur', function () {
            setTimeout(function () {
                const q = norm(search.value);
                if (q === '') {
                    return;
                }
                if (!ajaxMode) {
                    filterOptions();
                }
                const matches = visibleMatches();
                if (matches.length === 1) {
                    pickOption(matches[0]);
                }
            }, 150);
        });

        sel.addEventListener('change', function () {
            const picked = options.find(function (o) {
                return o.value === sel.value;
            });
            if (picked && picked.value !== '' && picked.value !== '0') {
                search.value = picked.text.trim();
            }
        });

        if (ajaxMode && sel.value) {
            fetch(searchUrl + '?id=' + encodeURIComponent(sel.value), { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.ok && data.items && data.items[0]) {
                        renderAjaxItems(data.items);
                        sel.value = String(data.items[0].id);
                        search.value = data.items[0].label || search.value;
                        mergeTier(data.items[0]);
                    }
                })
                .catch(function () {});
        }
    }

    function init() {
        document.querySelectorAll('select[name="santri_id"], select.santri-select-searchable').forEach(enhanceSelect);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
