<?php

declare(strict_types=1);

/** @var string $downloadName */
?>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
(function () {
    var card = document.getElementById('st-kartu-santri-card');
    var btn = document.getElementById('btnDownloadKartuJpg');
    if (!card || typeof html2canvas === 'undefined') return;

    var nameSteps = ['--lg', '--md', '--sm', '--xs', '--xxs', '--xxxs'];
    var binSteps = ['', '--sm', '--xs', '--xxs', '--xxxs'];

    function waitImages(root) {
        var imgs = root.querySelectorAll('img');
        var pending = [];
        imgs.forEach(function (img) {
            if (!img.complete) {
                pending.push(new Promise(function (resolve) {
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true });
                }));
            }
        });
        return pending.length ? Promise.all(pending) : Promise.resolve();
    }

    function clearSizeClasses(el, prefix, steps) {
        steps.forEach(function (s) {
            el.classList.remove(prefix + s);
        });
    }

    function setNameSize(pill, step) {
        clearSizeClasses(pill, 'st-kartu-card__name-pill', nameSteps);
        pill.classList.add('st-kartu-card__name-pill' + step);
    }

    function setBinSize(bin, step) {
        clearSizeClasses(bin, 'st-kartu-card__bin', binSteps);
        if (step) {
            bin.classList.add('st-kartu-card__bin' + step);
        }
    }

    function measureBlockBottom(el) {
        if (!el) return 0;
        var r = el.getBoundingClientRect();
        return r.bottom;
    }

    function fitsWidth(el, maxW) {
        return el.getBoundingClientRect().width <= maxW + 1;
    }

    function fitKartuText() {
        var pill = card.querySelector('[data-kartu-nama]');
        if (!pill) return;

        var wrap = pill.parentElement;
        var body = card.querySelector('.st-kartu-card__body');
        var footer = card.querySelector('.st-kartu-card__footer');
        var bin = card.querySelector('[data-kartu-bin]');
        if (!wrap || !body || !footer) return;

        var wrapW = wrap.getBoundingClientRect().width;
        var bodyRect = body.getBoundingClientRect();
        var footerTop = footer.getBoundingClientRect().top;
        var availableH = Math.max(8, footerTop - measureBlockBottom(card.querySelector('.st-kartu-card__nis') || card.querySelector('.st-kartu-card__photo-ring')) - 2);

        pill.style.removeProperty('--st-name-max-h');
        pill.classList.remove('is-multiline');
        setNameSize(pill, '--lg');

        var bestStep = '--xxxs';
        for (var i = 0; i < nameSteps.length; i++) {
            setNameSize(pill, nameSteps[i]);
            pill.classList.remove('is-multiline');

            var pillH = pill.getBoundingClientRect().height;
            var lines = pill.scrollHeight > pill.clientHeight + 1 || pillH > parseFloat(getComputedStyle(pill).fontSize) * 2.2;
            if (lines) {
                pill.classList.add('is-multiline');
            }

            pillH = pill.getBoundingClientRect().height;
            var binH = bin ? bin.getBoundingClientRect().height : 0;
            if (fitsWidth(pill, wrapW) && (pillH + binH) <= availableH) {
                bestStep = nameSteps[i];
                break;
            }
            bestStep = nameSteps[i];
        }

        setNameSize(pill, bestStep);
        if (pill.scrollHeight > pill.clientHeight + 1) {
            pill.classList.add('is-multiline');
        }

        if (bin && bin.textContent.trim() !== '') {
            setBinSize(bin, '');
            var remainH = availableH - pill.getBoundingClientRect().height;
            for (var j = 0; j < binSteps.length; j++) {
                setBinSize(bin, binSteps[j]);
                if (bin.getBoundingClientRect().height <= remainH && fitsWidth(bin, wrapW)) {
                    break;
                }
            }
        }

        var totalH = pill.getBoundingClientRect().height + (bin ? bin.getBoundingClientRect().height : 0);
        if (totalH > availableH) {
            pill.style.setProperty('--st-name-max-h', Math.max(6, availableH - (bin ? bin.getBoundingClientRect().height : 0)) + 'px');
            pill.classList.add('is-multiline');
        }
    }

    fitKartuText();
    window.addEventListener('resize', fitKartuText);
    window.addEventListener('beforeprint', fitKartuText);

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(fitKartuText);
    }

    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        fitKartuText();

        waitImages(card).then(function () {
            return new Promise(function (r) { requestAnimationFrame(function () { requestAnimationFrame(r); }); });
        }).then(function () {
            fitKartuText();
            return html2canvas(card, {
                scale: 4,
                backgroundColor: card.classList.contains('st-kartu-card--sementara') ? '#7a8088' : '#1b4d2e',
                useCORS: true,
                allowTaint: false,
                logging: false,
                width: card.offsetWidth,
                height: card.offsetHeight,
                onclone: function (doc) {
                    var cloned = doc.getElementById('st-kartu-santri-card');
                    if (cloned) {
                        cloned.style.boxShadow = 'none';
                    }
                }
            });
        }).then(function (canvas) {
            var a = document.createElement('a');
            a.href = canvas.toDataURL('image/jpeg', 0.97);
            a.download = <?= json_encode($downloadName . '.jpg', JSON_THROW_ON_ERROR) ?>;
            a.click();
        }).catch(function () {
            alert('Gagal membuat JPG. Pastikan foto/QR dapat dimuat (koneksi internet untuk QR).');
        }).finally(function () {
            btn.disabled = false;
        });
    });
})();
</script>
