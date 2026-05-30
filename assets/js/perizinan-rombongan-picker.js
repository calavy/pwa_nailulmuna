(function () {
    function boxesIn(targetId) {
        var wrap = document.getElementById(targetId + '-wrap');
        if (!wrap) {
            return [];
        }
        return Array.prototype.slice.call(wrap.querySelectorAll('.rombongan-santri-cb'));
    }

    function setBoxes(boxes, checked) {
        boxes.forEach(function (cb) {
            if (!cb.disabled) {
                cb.checked = checked;
            }
        });
    }

    document.querySelectorAll('.js-rombongan-pilih-semua').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tid = btn.getAttribute('data-target');
            if (tid) {
                setBoxes(boxesIn(tid), true);
            }
        });
    });

    document.querySelectorAll('.js-rombongan-bersihkan').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tid = btn.getAttribute('data-target');
            if (tid) {
                setBoxes(boxesIn(tid), false);
            }
        });
    });

    document.querySelectorAll('.js-rombongan-pilih-tingkatan').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tid = btn.getAttribute('data-target');
            var tk = btn.getAttribute('data-tingkatan') || '';
            if (!tid) {
                return;
            }
            boxesIn(tid).forEach(function (cb) {
                if ((cb.getAttribute('data-tingkatan') || '') === tk) {
                    cb.checked = true;
                }
            });
        });
    });

    document.querySelectorAll('.js-rombongan-pilih-belum').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tid = btn.getAttribute('data-target');
            if (!tid) {
                return;
            }
            boxesIn(tid).forEach(function (cb) {
                cb.checked = cb.getAttribute('data-belum-kembali') === '1';
            });
        });
    });

    document.querySelectorAll('form[data-rombongan-min]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var min = parseInt(form.getAttribute('data-rombongan-min') || '1', 10);
            var tid = form.getAttribute('data-rombongan-target') || '';
            var n = tid ? boxesIn(tid).filter(function (cb) { return cb.checked; }).length : 0;
            if (n < min) {
                ev.preventDefault();
                alert('Pilih minimal ' + min + ' santri.');
            }
        });
    });
})();
