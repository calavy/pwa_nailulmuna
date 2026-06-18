(function () {
    function boxesIn(targetId) {
        var wrap = document.getElementById(targetId + '-wrap');
        if (!wrap) {
            return [];
        }
        return Array.prototype.slice.call(wrap.querySelectorAll('.tingkatan-multi-cb'));
    }

    function setBoxes(boxes, checked) {
        boxes.forEach(function (cb) {
            cb.checked = checked;
        });
    }

    function applySemuaRule(wrap) {
        if (!wrap) {
            return;
        }
        var boxes = wrap.querySelectorAll('.tingkatan-multi-cb');
        var semua = wrap.querySelector('.tingkatan-multi-cb[data-semua="1"]');
        if (!semua) {
            return;
        }
        if (semua.checked) {
            boxes.forEach(function (cb) {
                if (cb !== semua) {
                    cb.checked = false;
                }
            });
        }
    }

    document.querySelectorAll('.js-tingkatan-pilih-semua').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tid = btn.getAttribute('data-target');
            if (tid) {
                setBoxes(boxesIn(tid), true);
                applySemuaRule(document.getElementById(tid + '-wrap'));
            }
        });
    });

    document.querySelectorAll('.js-tingkatan-bersihkan').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tid = btn.getAttribute('data-target');
            if (tid) {
                setBoxes(boxesIn(tid), false);
            }
        });
    });

    document.querySelectorAll('.tingkatan-multi-picker').forEach(function (wrap) {
        wrap.addEventListener('change', function (ev) {
            var cb = ev.target;
            if (!cb || !cb.classList || !cb.classList.contains('tingkatan-multi-cb')) {
                return;
            }
            if (cb.getAttribute('data-semua') === '1' && cb.checked) {
                applySemuaRule(wrap);
            } else if (cb.checked) {
                var semua = wrap.querySelector('.tingkatan-multi-cb[data-semua="1"]');
                if (semua) {
                    semua.checked = false;
                }
            }
        });
    });

    document.querySelectorAll('form[data-tingkatan-min]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var min = parseInt(form.getAttribute('data-tingkatan-min') || '1', 10);
            var tid = form.getAttribute('data-tingkatan-target') || '';
            var n = tid ? boxesIn(tid).filter(function (cb) { return cb.checked; }).length : 0;
            if (n < min) {
                ev.preventDefault();
                alert('Pilih minimal ' + min + ' tingkatan.');
            }
        });
    });
})();
