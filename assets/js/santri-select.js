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
        search.className = 'form-control form-control-sm mb-1 santri-select-search';
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

        function filterOptions() {
            const q = norm(search.value);
            let visible = 0;
            options.forEach(function (o) {
                if (o.value === '') {
                    o.el.hidden = false;
                    visible++;
                    return;
                }
                const show = q === '' || o.hay.indexOf(q) !== -1;
                o.el.hidden = !show;
                if (show) {
                    visible++;
                }
            });
            if (q !== '' && visible <= 1 && options.length > 1) {
                const first = options.find(function (o) {
                    return o.value !== '' && !o.el.hidden;
                });
                if (first) {
                    sel.value = first.value;
                }
            }
        }

        search.addEventListener('input', filterOptions);
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = options.find(function (o) {
                    return o.value !== '' && !o.el.hidden;
                });
                if (first) {
                    sel.value = first.value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
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
