/**
 * Pencarian santri manual: ketik nama/NIS pada <select name="santri_id">.
 */
(function () {
    function norm(s) {
        return (s || '').toLowerCase().trim();
    }

    function enhanceSelect(sel) {
        if (!sel || sel.dataset.santriSelectEnhanced === '1') {
            return;
        }
        sel.dataset.santriSelectEnhanced = '1';

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

        const options = Array.from(sel.options).map(function (opt) {
            return {
                el: opt,
                value: opt.value,
                text: opt.textContent || '',
                hay: norm(opt.textContent) + ' ' + norm(opt.value),
            };
        });

        function visibleMatches() {
            return options.filter(function (o) {
                return o.value !== '' && !o.el.hidden;
            });
        }

        function pickOption(opt) {
            if (!opt || sel.value === opt.value) {
                return;
            }
            sel.value = opt.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
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

            const matches = visibleMatches();
            if (q !== '' && matches.length === 1) {
                pickOption(matches[0]);
            } else if (q !== '' && matches.length === 0 && sel.value !== '') {
                sel.value = '';
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        search.addEventListener('input', filterOptions);
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
                filterOptions();
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
            if (picked && picked.value !== '') {
                search.value = picked.text.trim();
            }
        });
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
