/**
 * Validasi formulir kas sebelum submit — tampilkan keterangan kesalahan di layar.
 */
(function () {
    'use strict';

    function parseRp(val) {
        var s = String(val || '').replace(/[^\d]/g, '');
        return s === '' ? 0 : parseInt(s, 10);
    }

    function ensureAlertBox(form) {
        var box = form.querySelector('.keuangan-validasi-alert');
        if (box) {
            return box;
        }
        box = document.createElement('div');
        box.className = 'alert alert-danger d-none keuangan-validasi-alert mb-3';
        box.setAttribute('role', 'alert');
        box.innerHTML = '<strong><i class="fa-solid fa-circle-xmark me-1"></i> Tidak dapat disimpan:</strong> <span class="keuangan-validasi-teks"></span>';
        form.insertBefore(box, form.firstChild);
        return box;
    }

    function showError(form, message) {
        var box = ensureAlertBox(form);
        var teks = box.querySelector('.keuangan-validasi-teks');
        if (teks) {
            teks.textContent = message;
        }
        box.classList.remove('d-none');
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearError(form) {
        var box = form.querySelector('.keuangan-validasi-alert');
        if (box) {
            box.classList.add('d-none');
        }
    }

    function akunInvalid(form) {
        var sel = form.querySelector('[name="akun_id"]');
        if (!sel) {
            return null;
        }
        var v = parseInt(String(sel.value || '0'), 10);
        if (!v || v <= 0) {
            return 'Akun kas/bank wajib dipilih. Tanpa akun, uang tidak masuk saldo fisik dan rekap kas tidak selaras.';
        }
        return null;
    }

    function transferInvalid(form) {
        var metode = form.querySelector('[name="metode_bayar"], [name="metode_keluar"]');
        var ref = form.querySelector('[name="no_referensi"], [name="no_bukti"]');
        if (!metode || !ref) {
            return null;
        }
        var m = String(metode.value || '').toUpperCase();
        if (m === 'TRANSFER' && String(ref.value || '').trim() === '') {
            return 'Metode transfer wajib diisi nomor referensi/bukti transfer.';
        }
        return null;
    }

    function nominalInvalid(form, fieldNames) {
        for (var i = 0; i < fieldNames.length; i++) {
            var el = form.querySelector('[name="' + fieldNames[i] + '"]');
            if (!el) {
                continue;
            }
            if (parseRp(el.value) <= 0) {
                return 'Nominal harus lebih dari nol.';
            }
        }
        return null;
    }

    window.keuanganFormValidasi = {
        showError: showError,
        clearError: clearError,
        parseRp: parseRp,
        bind: function (form, rules) {
            if (!form) {
                return;
            }
            rules = rules || {};
            form.addEventListener('submit', function (ev) {
                clearError(form);
                var err = null;
                if (rules.cekAkun !== false) {
                    err = akunInvalid(form);
                }
                if (!err && rules.cekTransfer !== false) {
                    err = transferInvalid(form);
                }
                if (!err && rules.nominalFields && rules.nominalFields.length) {
                    err = nominalInvalid(form, rules.nominalFields);
                }
                if (!err && typeof rules.extra === 'function') {
                    err = rules.extra(form);
                }
                if (err) {
                    ev.preventDefault();
                    showError(form, err);
                }
            });
            form.querySelectorAll('[name="akun_id"], [name="metode_bayar"], [name="metode_keluar"], [name="no_referensi"], [name="no_bukti"]').forEach(function (el) {
                el.addEventListener('change', function () {
                    clearError(form);
                });
            });
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-keuangan-validasi]').forEach(function (form) {
            var nominal = (form.getAttribute('data-keuangan-nominal') || '').split(',').filter(Boolean);
            var cekAkun = form.getAttribute('data-keuangan-cek-akun') !== '0';
            window.keuanganFormValidasi.bind(form, {
                cekAkun: cekAkun,
                nominalFields: nominal,
            });
        });
    });
})();
