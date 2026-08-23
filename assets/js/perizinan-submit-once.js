/**
 * Kunci tombol aksi perizinan setelah satu submit valid (hindari dobel).
 * Form GET (filter) dan data-allow-resubmit tidak dikunci.
 */
(function (global) {
    'use strict';

    if (global.PondokPerizinanSubmitOnce) {
        return;
    }

    function isGetForm(form) {
        var method = (form.getAttribute('method') || form.method || 'get').toLowerCase();
        return method === 'get';
    }

    function isWriteForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }
        if (isGetForm(form)) {
            return false;
        }
        if (form.getAttribute('data-allow-resubmit') === '1') {
            return false;
        }
        return true;
    }

    function lockSubmitButtons(form) {
        form.querySelectorAll('button, input[type="submit"]').forEach(function (btn) {
            var type = (btn.getAttribute('type') || (btn.tagName === 'BUTTON' ? 'submit' : '')).toLowerCase();
            if (type === 'button' || type === 'reset' || btn.getAttribute('data-bs-dismiss') === 'modal') {
                return;
            }
            if (type !== 'submit' && btn.tagName !== 'BUTTON') {
                return;
            }
            btn.disabled = true;
            if (btn.tagName === 'BUTTON') {
                btn.setAttribute('data-perizinan-submit-label', btn.textContent || '');
                btn.textContent = 'Memproses…';
            }
        });
    }

    function onSubmit(ev) {
        var form = ev.target;
        if (!isWriteForm(form)) {
            return;
        }
        if (form.getAttribute('data-submitting') === '1') {
            ev.preventDefault();
            return;
        }
        if (ev.defaultPrevented) {
            return;
        }
        form.setAttribute('data-submitting', '1');
        lockSubmitButtons(form);
    }

    document.addEventListener('submit', onSubmit, false);

    global.PondokPerizinanSubmitOnce = {
        lock: lockSubmitButtons
    };
})(window);
