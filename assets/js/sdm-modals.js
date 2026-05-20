(function () {
    function initSdmModals() {
        if (typeof bootstrap === 'undefined') {
            return;
        }

        var modalEl = document.getElementById('sdmModalForm');
        if (!modalEl) {
            return;
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var iframe = document.getElementById('sdmModalIframe');
        var titleEl = document.getElementById('sdmModalFormLabel');

        function openSdmForm(url, title) {
            if (!url) {
                return;
            }
            if (!iframe) {
                window.location.href = url;
                return;
            }
            if (titleEl && title) {
                titleEl.textContent = title;
            }
            iframe.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
            modal.show();
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-sdm-modal]');
            if (!btn) {
                return;
            }
            var url = btn.getAttribute('data-sdm-modal') || btn.getAttribute('href') || '';
            if (!url || url === '#') {
                return;
            }
            e.preventDefault();
            openSdmForm(url, btn.getAttribute('data-sdm-title') || 'Formulir');
        });

        window.addEventListener('sdmFormDone', function () {
            modal.hide();
            if (iframe) {
                iframe.src = 'about:blank';
            }
            window.location.reload();
        });

        window.addEventListener('sdmFormClose', function () {
            modal.hide();
            if (iframe) {
                iframe.src = 'about:blank';
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (iframe) {
                iframe.src = 'about:blank';
            }
        });

        window.openSdmForm = openSdmForm;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSdmModals);
    } else {
        initSdmModals();
    }
})();
