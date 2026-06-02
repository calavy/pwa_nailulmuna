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

    function khStatusBadge(tabKey) {
        var map = { perlu: 'A', ALPA: 'A', IZIN: 'I', SAKIT: 'S', HADIR: 'H' };
        var letter = map[tabKey] || '';
        if (!letter) {
            return '';
        }
        return '<span class="kh-status-badge kh-status-badge--' + letter + '" aria-hidden="true">' + letter + '</span>';
    }

    function khGetScopeRoot(scopeEl) {
        if (!scopeEl) {
            return null;
        }
        if (scopeEl.getAttribute('data-kh-stat-scope') === 'hero') {
            return document.getElementById('khHero');
        }
        return scopeEl.closest('.kh-card');
    }

    function khGetCardPayload(scopeRoot) {
        if (!scopeRoot) {
            return null;
        }
        var dataEl = scopeRoot.querySelector('.kh-santri-data');
        if (!dataEl) {
            return null;
        }
        try {
            return JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function khRenderCardLists(card) {
        if (!card || card.getAttribute('data-kh-lists-ready') === '1') {
            return;
        }
        var payload = khGetCardPayload(card);
        if (!payload) {
            return;
        }

        card.querySelectorAll('.kh-list[data-kh-lazy="1"]').forEach(function (ul) {
            var tabKey = ul.getAttribute('data-kh-list') || '';
            var list = Array.isArray(payload[tabKey]) ? payload[tabKey] : [];
            var emptyMsg = ul.getAttribute('data-kh-empty-msg') || 'Tidak ada data.';
            ul.innerHTML = khBuildListHtml(list, tabKey, emptyMsg);
        });

        card.setAttribute('data-kh-lists-ready', '1');
    }

    function khBuildListHtml(list, tabKey, emptyMsg) {
        var badge = khStatusBadge(tabKey);
        if (!list.length) {
            return '<li class="text-muted">' + khEscapeHtml(emptyMsg || 'Tidak ada data.') + '</li>';
        }
        var html = '';
        list.forEach(function (s) {
            var nama = khEscapeHtml(s.nama_santri || '');
            var sub = khEscapeHtml(s.tingkatan || '');
            if (s.kegiatan) {
                sub += (sub ? ' · ' : '') + khEscapeHtml(s.kegiatan);
            }
            if (s.catatan) {
                sub += (sub ? ' · ' : '') + khEscapeHtml(s.catatan);
            }
            if (s.jam_presensi) {
                sub += (sub ? ' · ' : '') + khEscapeHtml(s.jam_presensi);
            }
            html += '<li>' + badge + '<span class="kh-list__name">' + nama + '</span><span class="kh-list__sub">' + sub + '</span></li>';
        });
        return html;
    }

    function khCloseAllStatPopups(exceptRoot) {
        document.querySelectorAll('[data-kh-stat-popup]').forEach(function (popup) {
            var root = khGetScopeRoot(popup);
            if (exceptRoot && root === exceptRoot) {
                return;
            }
            popup.classList.add('d-none');
            popup.innerHTML = '';
        });
        document.querySelectorAll('.kh-stat.is-open, .kh-total-pill.is-open').forEach(function (btn) {
            btn.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function khRenderStatPopupList(list, tabKey) {
        if (!list.length) {
            return '<p class="kh-stat-popup__empty text-muted mb-0">Tidak ada data.</p>';
        }
        return '<ul class="kh-stat-popup__list">' + khBuildListHtml(list, tabKey, '') + '</ul>';
    }

    function khToggleStatPopup(statBtn) {
        var scopeRoot = khGetScopeRoot(statBtn);
        if (!scopeRoot) {
            return;
        }
        var tab = statBtn.getAttribute('data-kh-stat-tab') || '';
        var popup = scopeRoot.querySelector('[data-kh-stat-popup]');
        if (!tab || !popup) {
            return;
        }

        var isOpen = statBtn.classList.contains('is-open');
        khCloseAllStatPopups(null);
        if (isOpen) {
            return;
        }

        if (scopeRoot.classList.contains('kh-card')) {
            khRenderCardLists(scopeRoot);
        }
        var payload = khGetCardPayload(scopeRoot);
        if (!payload) {
            return;
        }
        var list = Array.isArray(payload[tab]) ? payload[tab] : [];
        var labelEl = statBtn.querySelector('.kh-stat__l, .kh-total-pill__l');
        var labelText = labelEl ? labelEl.textContent.trim() : tab;
        popup.innerHTML =
            '<div class="kh-stat-popup__head">' +
            '<strong>' + khEscapeHtml(labelText) + '</strong>' +
            '<span class="text-muted">(' + list.length + ')</span>' +
            '</div>' +
            khRenderStatPopupList(list, tab);
        popup.classList.remove('d-none');
        statBtn.classList.add('is-open');
        statBtn.setAttribute('aria-expanded', 'true');
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

    function khHandleStatClick(e) {
        var statBtn = e.target.closest('.kh-stat--clickable[data-kh-stat-tab], .kh-total-pill--clickable[data-kh-stat-tab]');
        if (!statBtn) {
            return;
        }
        e.preventDefault();
        khToggleStatPopup(statBtn);
    }

    if (grid) {
        grid.addEventListener('click', function (e) {
            if (e.target.closest('.kh-stat--clickable[data-kh-stat-tab]')) {
                khHandleStatClick(e);
                return;
            }

            var tabBtn = e.target.closest('.kh-tab');
            if (tabBtn) {
                khCloseAllStatPopups(null);
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
            }
        });

        grid.addEventListener('shown.bs.collapse', function (e) {
            var collapse = e.target;
            if (!collapse.classList.contains('collapse')) {
                return;
            }
            khCloseAllStatPopups(null);
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

    var hero = document.getElementById('khHero');
    if (hero) {
        hero.addEventListener('click', khHandleStatClick);
    }

    document.addEventListener('click', function (e) {
        if (
            e.target.closest('.kh-stat--clickable') ||
            e.target.closest('.kh-total-pill--clickable') ||
            e.target.closest('[data-kh-stat-popup]')
        ) {
            return;
        }
        khCloseAllStatPopups(null);
    });

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
            khCloseAllStatPopups(null);
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
