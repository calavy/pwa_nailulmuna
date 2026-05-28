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

    document.addEventListener('DOMContentLoaded', cleanupStaleOverlays);
    window.addEventListener('pageshow', cleanupStaleOverlays);
    document.addEventListener('hidden.bs.offcanvas', cleanupStaleOverlays);
    document.addEventListener('hidden.bs.modal', cleanupStaleOverlays);
})();
