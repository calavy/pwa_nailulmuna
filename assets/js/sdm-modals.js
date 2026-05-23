(function () {
    function resolveAppUrl(url) {
        url = (url || '').trim();
        if (!url || /^https?:\/\//i.test(url) || url.indexOf('//') === 0) {
            return url;
        }
        if (url.charAt(0) !== '/') {
            return url;
        }
        var base = (window.PONDOK_APP_BASE || '').replace(/\/$/, '');
        if (!base) {
            return url;
        }
        if (url === base || url.indexOf(base + '/') === 0) {
            return url;
        }
        return base + url;
    }

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
            url = resolveAppUrl(url);
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
