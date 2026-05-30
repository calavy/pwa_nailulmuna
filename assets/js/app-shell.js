/**
 * Bersihkan backdrop Bootstrap yang tertinggal (menu offcanvas / modal).
 * Gejala: layar seperti ada bayang hitam setelah login atau tutup menu.
 */
(function () {
    function cleanupStaleOverlays() {
        var openModal = document.querySelector('.modal.show');
        var openOffcanvas = document.querySelector('.offcanvas.show');
        if (openModal || openOffcanvas) {
            return;
        }
        document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach(function (el) {
            el.remove();
        });
        document.body.classList.remove('modal-open');
        document.body.removeAttribute('aria-hidden');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }

    function dismissFlashAlerts() {
        document.querySelectorAll('.app-flash[role="alert"]').forEach(function (el) {
            if (el.dataset.flashDismissBound === '1') {
                return;
            }
            el.dataset.flashDismissBound = '1';
            window.setTimeout(function () {
                el.classList.add('app-flash--hide');
                window.setTimeout(function () {
                    el.remove();
                }, 320);
            }, 6000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        cleanupStaleOverlays();
        dismissFlashAlerts();
    });
    window.addEventListener('pageshow', cleanupStaleOverlays);
    document.addEventListener('hidden.bs.offcanvas', cleanupStaleOverlays);
    document.addEventListener('hidden.bs.modal', cleanupStaleOverlays);
})();
