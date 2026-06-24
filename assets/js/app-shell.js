/**
 * Shell: backdrop, flash, menu desktop (panah) + menu mobile (offcanvas).
 */
(function () {
    'use strict';

    var SIDEBAR_KEY = 'app-sidebar-hidden';
    var DESKTOP_MQ = window.matchMedia('(min-width: 992px)');

    function cleanupStaleOverlays() {
        if (document.querySelector('.modal.show, .offcanvas.show')) {
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
                window.setTimeout(function () { el.remove(); }, 320);
            }, 6000);
        });
    }

    function sidebarHidden() {
        try {
            return localStorage.getItem(SIDEBAR_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function updateSidebarToggleUi(hidden) {
        document.querySelectorAll('[data-app-sidebar-toggle="hide"].app-sidebar-nav-label--toggle').forEach(function (el) {
            el.setAttribute('title', 'Sembunyikan menu');
            el.setAttribute('aria-label', 'Sembunyikan menu samping');
        });
        var showBtn = document.getElementById('appMenuBtnDesktop');
        if (showBtn) {
            showBtn.classList.toggle('d-none', !hidden);
            showBtn.classList.toggle('d-lg-inline-flex', hidden);
            showBtn.setAttribute('aria-hidden', hidden ? 'false' : 'true');
            showBtn.setAttribute('title', hidden ? 'Tampilkan menu' : '');
            showBtn.setAttribute('aria-label', hidden ? 'Tampilkan menu samping' : 'Tampilkan menu samping');
        }
    }

    function setSidebarHidden(hidden, save) {
        if (!document.querySelector('.app-sidebar--desktop')) {
            return;
        }
        document.body.classList.toggle('app-sidebar-hidden', hidden);
        document.documentElement.classList.remove('app-sidebar-hidden-boot');
        if (save) {
            try {
                localStorage.setItem(SIDEBAR_KEY, hidden ? '1' : '0');
            } catch (e) {}
        }
        updateSidebarToggleUi(hidden);
    }

    function bindSidebarToggle(el) {
        if (!el || el.dataset.sidebarToggleBound === '1') {
            return;
        }
        el.dataset.sidebarToggleBound = '1';
        var mode = el.getAttribute('data-app-sidebar-toggle') || 'toggle';
        el.addEventListener('click', function () {
            if (mode === 'hide') {
                setSidebarHidden(true, true);
                return;
            }
            if (mode === 'show') {
                setSidebarHidden(false, true);
                return;
            }
            setSidebarHidden(!document.body.classList.contains('app-sidebar-hidden'), true);
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (mode === 'hide') {
                    setSidebarHidden(true, true);
                    return;
                }
                if (mode === 'show') {
                    setSidebarHidden(false, true);
                    return;
                }
                setSidebarHidden(!document.body.classList.contains('app-sidebar-hidden'), true);
            }
        });
    }

    function initDesktopSidebar() {
        if (!document.querySelector('.app-sidebar--desktop')) {
            document.documentElement.classList.remove('app-sidebar-hidden-boot');
            return;
        }
        var hidden = document.documentElement.classList.contains('app-sidebar-hidden-boot') || sidebarHidden();
        setSidebarHidden(hidden, hidden);
        document.querySelectorAll('[data-app-sidebar-toggle]').forEach(bindSidebarToggle);
    }

    function initMobileMenu() {
        var btn = document.getElementById('appMenuBtnMobile');
        var panel = document.getElementById('mobileSidebar');
        if (!btn || !panel || !window.bootstrap || !bootstrap.Offcanvas) {
            return;
        }
        var offcanvas = bootstrap.Offcanvas.getOrCreateInstance(panel);
        var icon = btn.querySelector('i');

        function setOpen(open) {
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
            if (icon) {
                icon.classList.toggle('fa-bars', !open);
                icon.classList.toggle('fa-xmark', open);
            }
        }

        btn.addEventListener('click', function () {
            if (panel.classList.contains('show')) {
                offcanvas.hide();
            } else {
                offcanvas.show();
            }
        });

        panel.addEventListener('show.bs.offcanvas', function () { setOpen(true); });
        panel.addEventListener('hidden.bs.offcanvas', function () { setOpen(false); });
        panel.querySelectorAll('a.app-side-nav-item').forEach(function (link) {
            link.addEventListener('click', function () { offcanvas.hide(); });
        });
    }

    var SWIPE_TAB_SELECTOR = [
        '.app-hub-tabs__links', '.wali-portal-tabs', '.ikhtibar-portal-tabs', '.wali-nav-scroll',
        '.btn-group.flex-wrap[role="group"]', '.nav.nav-tabs.flex-wrap', '.nav.nav-pills.flex-wrap',
        '.nav.nav-tabs.flex-nowrap', '.nav.nav-tabs.overflow-auto'
    ].join(', ');

    function initSwipeTabs() {
        document.querySelectorAll(SWIPE_TAB_SELECTOR).forEach(function (container) {
            if (container.dataset.swipeTabsBound !== '1') {
                container.dataset.swipeTabsBound = '1';
                container.classList.add('app-swipe-row');
            }
            requestAnimationFrame(function () {
                var active = container.querySelector('.active');
                if (active && active.scrollIntoView) {
                    try {
                        active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
                    } catch (e) {
                        active.scrollIntoView({ inline: 'center', block: 'nearest' });
                    }
                }
            });
        });
    }

    function initSmoothNavigation() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        if (document.body.classList.contains('app-scan-page') || document.body.classList.contains('app-kiosk')) {
            return;
        }
        document.body.classList.add('app-ready');
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (!link || link.target === '_blank' || link.hasAttribute('download') || link.dataset.noTransition === '1') {
                return;
            }
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) {
                return;
            }
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
                return;
            }
            try {
                var url = new URL(link.href, window.location.href);
                if (url.origin !== window.location.origin) {
                    return;
                }
            } catch (err) {
                return;
            }
            e.preventDefault();
            document.body.classList.add('app-page-leaving');
            window.setTimeout(function () {
                window.location.href = link.href;
            }, 150);
        });
    }

    function toggleRekapRankDetail(summaryRow) {
        var id = summaryRow.getAttribute('data-rank-detail');
        if (!id) {
            return;
        }
        var detail = document.getElementById('rank-detail-' + id);
        if (!detail) {
            return;
        }
        var willOpen = !detail.classList.contains('is-open');
        document.querySelectorAll('.rekap-rank-detail.is-open').forEach(function (row) {
            row.classList.remove('is-open');
        });
        document.querySelectorAll('.rekap-rank-summary.is-expanded').forEach(function (row) {
            row.classList.remove('is-expanded');
            row.setAttribute('aria-expanded', 'false');
        });
        if (willOpen) {
            detail.classList.add('is-open');
            summaryRow.classList.add('is-expanded');
            summaryRow.setAttribute('aria-expanded', 'true');
            var tingkatan = summaryRow.getAttribute('data-tingkatan') || '';
            if (tingkatan && window.history && window.history.replaceState) {
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('tingkatan', tingkatan);
                    window.history.replaceState({}, '', url.toString());
                } catch (err) { /* ignore */ }
            }
            window.setTimeout(function () {
                detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 80);
        } else if (window.history && window.history.replaceState) {
            try {
                var closeUrl = new URL(window.location.href);
                closeUrl.searchParams.delete('tingkatan');
                window.history.replaceState({}, '', closeUrl.toString());
            } catch (err2) { /* ignore */ }
        }
    }

    function initRekapRankExpand() {
        document.querySelectorAll('.rekap-rank-summary').forEach(function (row) {
            if (row.dataset.rankExpandBound === '1') {
                return;
            }
            row.dataset.rankExpandBound = '1';
            row.addEventListener('click', function (e) {
                if (e.target.closest('a, button, input, select, label')) {
                    return;
                }
                toggleRekapRankDetail(row);
            });
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleRekapRankDetail(row);
                }
            });
        });
        document.querySelectorAll('.yp-rank-podium__item[data-rank-detail]').forEach(function (item) {
            if (item.dataset.rankExpandBound === '1') {
                return;
            }
            item.dataset.rankExpandBound = '1';
            item.addEventListener('click', function () {
                var idx = item.getAttribute('data-rank-detail');
                var summary = document.querySelector('.rekap-rank-summary[data-rank-detail="' + idx + '"]');
                if (summary) {
                    if (!summary.classList.contains('is-expanded')) {
                        toggleRekapRankDetail(summary);
                    } else {
                        summary.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            });
        });
        var preOpen = document.querySelector('.rekap-rank-detail.is-open');
        if (preOpen) {
            window.setTimeout(function () {
                preOpen.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 200);
        }
    }

    function init() {
        cleanupStaleOverlays();
        dismissFlashAlerts();
        initDesktopSidebar();
        initMobileMenu();
        initSwipeTabs();
        initSmoothNavigation();
        initRekapRankExpand();
    }

    document.addEventListener('DOMContentLoaded', init);
    window.addEventListener('pageshow', function () {
        cleanupStaleOverlays();
        initSwipeTabs();
    });
    document.addEventListener('hidden.bs.offcanvas', cleanupStaleOverlays);
    document.addEventListener('hidden.bs.modal', cleanupStaleOverlays);
})();
