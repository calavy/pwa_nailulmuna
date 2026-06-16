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
        var reveal = document.querySelector('.app-sidebar-reveal');
        if (reveal) {
            reveal.setAttribute('aria-hidden', hidden ? 'false' : 'true');
            reveal.setAttribute('title', hidden ? 'Tampilkan menu' : '');
        }
        document.querySelectorAll('[data-app-sidebar-toggle].app-sidebar-nav-label--toggle').forEach(function (el) {
            el.setAttribute('title', hidden ? 'Tampilkan menu' : 'Sembunyikan menu');
            el.setAttribute('aria-label', hidden ? 'Tampilkan menu samping' : 'Sembunyikan menu samping');
        });
        var pageBlock = document.querySelector('.app-topbar-page');
        if (pageBlock) {
            pageBlock.setAttribute('title', hidden ? 'Klik untuk tampilkan menu' : '');
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

    function toggleSidebarHidden() {
        setSidebarHidden(!document.body.classList.contains('app-sidebar-hidden'), true);
    }

    function bindSidebarToggle(el) {
        if (!el || el.dataset.sidebarToggleBound === '1') {
            return;
        }
        el.dataset.sidebarToggleBound = '1';
        el.addEventListener('click', toggleSidebarHidden);
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleSidebarHidden();
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

        var pageBlock = document.querySelector('.app-topbar-page');
        if (pageBlock && pageBlock.dataset.sidebarShowBound !== '1') {
            pageBlock.dataset.sidebarShowBound = '1';
            pageBlock.addEventListener('click', function () {
                if (!document.body.classList.contains('app-sidebar-hidden')) {
                    return;
                }
                setSidebarHidden(false, true);
            });
        }
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

    function init() {
        cleanupStaleOverlays();
        dismissFlashAlerts();
        initDesktopSidebar();
        initMobileMenu();
        initSwipeTabs();
    }

    document.addEventListener('DOMContentLoaded', init);
    window.addEventListener('pageshow', function () {
        cleanupStaleOverlays();
        initSwipeTabs();
    });
    document.addEventListener('hidden.bs.offcanvas', cleanupStaleOverlays);
    document.addEventListener('hidden.bs.modal', cleanupStaleOverlays);
})();
