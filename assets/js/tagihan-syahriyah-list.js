(function () {
    var form = document.getElementById('form-tagihan-filter');
    var cari = document.getElementById('tagihan-cari');
    var ringkasBtn = document.getElementById('btn-tagihan-ringkas');
    var ringkasInput = document.getElementById('tagihan-ringkas-input');
    var card = document.querySelector('.tagihan-list-card');
    var debounceTimer = null;

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
})();
