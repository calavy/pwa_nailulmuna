/**
 * Tampilkan/sembunyikan input nominal per bulan pada pengaturan syahriyah.
 */
(function () {
    function toggleBlock(btn) {
        const sel = btn.getAttribute('data-target');
        if (!sel) {
            return;
        }
        const root = document.querySelector(sel);
        if (!root) {
            return;
        }
        const cols = root.querySelectorAll('.syahriyah-bulan-cols');
        const intro = root.querySelector('.syahriyah-bulan-intro');
        const show = cols.length ? cols[0].classList.contains('d-none') : intro !== null;
        cols.forEach(function (el) {
            el.classList.toggle('d-none', !show);
        });
        if (intro) {
            intro.classList.toggle('d-none', show);
        }
        btn.setAttribute('aria-expanded', show ? 'true' : 'false');
        btn.textContent = show ? 'Sembunyikan per bulan' : 'Ubah per bulan';
    }

    function init() {
        document.querySelectorAll('.syahriyah-toggle-bulan').forEach(function (btn) {
            if (btn.dataset.syahriyahToggleBound === '1') {
                return;
            }
            btn.dataset.syahriyahToggleBound = '1';
            btn.addEventListener('click', function () {
                toggleBlock(btn);
            });
        });
        if (window.location.hash === '#tambahan-pkpps' || window.location.hash === '#tambahan-syahriyah' || window.location.hash === '#tarif-per-bulan') {
            const el = document.querySelector(window.location.hash);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            if (window.location.hash === '#tarif-per-bulan') {
                const btn = document.querySelector('.syahriyah-toggle-bulan[data-target="#tarif-bulan-panel"]');
                if (btn && btn.getAttribute('aria-expanded') !== 'true') {
                    toggleBlock(btn);
                }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
