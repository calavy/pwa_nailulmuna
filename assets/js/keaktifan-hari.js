(function () {
    'use strict';

    var mobileMq = globalThis.matchMedia ? globalThis.matchMedia('(max-width: 767.98px)') : null;
    var grid = document.getElementById('khGrid');

    function khIsMobile() {
        return mobileMq ? mobileMq.matches : window.innerWidth <= 767;
    }

    function khEscapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function khRenderCardLists(card) {
        if (!card || card.getAttribute('data-kh-lists-ready') === '1') {
            return;
        }
        var dataEl = card.querySelector('.kh-santri-data');
        if (!dataEl) {
            return;
        }
        var payload;
        try {
            payload = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            return;
        }

        card.querySelectorAll('.kh-list[data-kh-lazy="1"]').forEach(function (ul) {
            var tabKey = ul.getAttribute('data-kh-list') || '';
            var list = Array.isArray(payload[tabKey]) ? payload[tabKey] : [];
            var emptyMsg = ul.getAttribute('data-kh-empty-msg') || 'Tidak ada data.';
            var html = '';
            if (list.length === 0) {
                html = '<li class="text-muted">' + khEscapeHtml(emptyMsg) + '</li>';
            } else {
                list.forEach(function (s) {
                    var nama = khEscapeHtml(s.nama_santri || '');
                    var sub = khEscapeHtml(s.tingkatan || '');
                    if (s.catatan) {
                        sub += (sub ? ' · ' : '') + khEscapeHtml(s.catatan);
                    }
                    if (s.jam_presensi) {
                        sub += (sub ? ' · ' : '') + khEscapeHtml(s.jam_presensi);
                    }
                    html += '<li><span class="kh-list__name">' + nama + '</span><span class="kh-list__sub">' + sub + '</span></li>';
                });
            }
            ul.innerHTML = html;
        });

        card.setAttribute('data-kh-lists-ready', '1');
    }

    function khCollapseNonFocusOnMobile() {
        if (!khIsMobile()) {
            return;
        }
        document.querySelectorAll('.kh-card .collapse.show').forEach(function (el) {
            var card = el.closest('.kh-card');
            if (card && card.classList.contains('is-focus')) {
                khRenderCardLists(card);
                return;
            }
            el.classList.remove('show');
            var btn = card ? card.querySelector('[data-kh-detail-btn]') : null;
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (grid) {
        grid.addEventListener('click', function (e) {
            var tabBtn = e.target.closest('.kh-tab');
            if (tabBtn) {
                var cardId = tabBtn.getAttribute('data-kh-card');
                var tab = tabBtn.getAttribute('data-kh-tab');
                var card = tabBtn.closest('.kh-card');
                if (card) {
                    khRenderCardLists(card);
                }
                tabBtn.closest('.kh-tabs')?.querySelectorAll('.kh-tab').forEach(function (t) {
                    t.classList.toggle('is-active', t === tabBtn);
                });
                grid.querySelectorAll('.kh-list[data-kh-card="' + cardId + '"]').forEach(function (ul) {
                    ul.classList.toggle('d-none', ul.getAttribute('data-kh-list') !== tab);
                });
                return;
            }
        });

        grid.addEventListener('shown.bs.collapse', function (e) {
            var collapse = e.target;
            if (!collapse.classList.contains('collapse')) {
                return;
            }
            var card = collapse.closest('.kh-card');
            khRenderCardLists(card);
            var btn = card ? card.querySelector('[data-kh-detail-btn]') : null;
            if (btn) {
                btn.setAttribute('aria-expanded', 'true');
            }
        });

        grid.addEventListener('hidden.bs.collapse', function (e) {
            var collapse = e.target;
            if (!collapse.classList.contains('collapse')) {
                return;
            }
            var btn = collapse.closest('.kh-card')?.querySelector('[data-kh-detail-btn]');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    khCollapseNonFocusOnMobile();
    document.querySelectorAll('.kh-card.is-focus .collapse.show').forEach(function (el) {
        khRenderCardLists(el.closest('.kh-card'));
    });

    if (mobileMq && mobileMq.addEventListener) {
        mobileMq.addEventListener('change', khCollapseNonFocusOnMobile);
    }

    var toggleAll = document.getElementById('khToggleAll');
    if (toggleAll) {
        toggleAll.addEventListener('click', function () {
            var expanded = toggleAll.getAttribute('data-expanded') === '1';
            document.querySelectorAll('.kh-card .collapse').forEach(function (el) {
                if (expanded) {
                    el.classList.remove('show');
                } else {
                    el.classList.add('show');
                    khRenderCardLists(el.closest('.kh-card'));
                }
            });
            toggleAll.setAttribute('data-expanded', expanded ? '0' : '1');
            toggleAll.textContent = expanded ? 'Buka semua detail' : 'Tutup semua detail';
            document.querySelectorAll('[data-kh-detail-btn]').forEach(function (btn) {
                btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            });
        });
    }

    if (location.hash && location.hash.indexOf('keg-') === 1) {
        var targetCard = document.querySelector(location.hash);
        if (targetCard) {
            var col = targetCard.querySelector('.collapse');
            if (col) {
                col.classList.add('show');
                khRenderCardLists(targetCard);
            }
            targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
})();
