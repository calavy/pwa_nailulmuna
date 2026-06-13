/**
 * Shell aplikasi: backdrop Bootstrap, flash alert, toggle sidebar desktop (klik area menu).
 */
(function () {
    'use strict';

    var SIDEBAR_STORAGE_KEY = 'app-sidebar-hidden';

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

    function dismissFlashAlerts() {
        document.querySelectorAll('.app-flash[role="alert"]').forEach(function (el) {
            if (el.dataset.flashDismissBound === '1') {
                return;
            }
            el.dataset.flashDismissBound = '1';
            window.setTimeout(function () {
                el.classList.add('app-flash--hide');
                window.setTimeout(function () {
                    el.remove();
                }, 320);
            }, 6000);
        });
    }

    function readSidebarHidden() {
        try {
            return localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function writeSidebarHidden(hidden) {
        try {
            localStorage.setItem(SIDEBAR_STORAGE_KEY, hidden ? '1' : '0');
        } catch (e) {
            // abaikan
        }
    }

    function updateSidebarToggleUi(hidden) {
        var reveal = document.querySelector('.app-sidebar-reveal');
        if (reveal) {
            reveal.setAttribute('aria-hidden', hidden ? 'false' : 'true');
            reveal.setAttribute('title', hidden ? 'Klik untuk tampilkan menu' : '');
        }
        document.querySelectorAll('[data-app-sidebar-toggle].app-sidebar-nav-label--toggle').forEach(function (el) {
            el.setAttribute('title', hidden ? 'Klik untuk tampilkan menu' : 'Klik untuk sembunyikan menu');
            el.setAttribute('aria-label', hidden ? 'Tampilkan menu samping' : 'Sembunyikan menu samping');
        });
        var pageBlock = document.querySelector('.app-topbar-page');
        if (pageBlock) {
            pageBlock.setAttribute('title', hidden ? 'Klik untuk tampilkan menu' : '');
        }
    }

    function applySidebarHidden(hidden, persist) {
        if (!document.body.classList.contains('app-body-shell')) {
            return;
        }
        if (!document.querySelector('.app-sidebar--desktop')) {
            return;
        }
        document.body.classList.toggle('app-sidebar-hidden', hidden);
        document.documentElement.classList.remove('app-sidebar-hidden-boot');
        if (persist) {
            writeSidebarHidden(hidden);
        }
        updateSidebarToggleUi(hidden);
    }

    function toggleSidebarHidden() {
        var nextHidden = !document.body.classList.contains('app-sidebar-hidden');
        applySidebarHidden(nextHidden, true);
    }

    function bindSidebarToggle(el) {
        if (el.dataset.sidebarToggleBound === '1') {
            return;
        }
        el.dataset.sidebarToggleBound = '1';
        el.addEventListener('click', function () {
            toggleSidebarHidden();
        });
        el.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            e.preventDefault();
            toggleSidebarHidden();
        });
    }

    function initSidebarToggle() {
        if (!document.querySelector('.app-sidebar--desktop')) {
            document.documentElement.classList.remove('app-sidebar-hidden-boot');
            return;
        }

        var hidden = document.documentElement.classList.contains('app-sidebar-hidden-boot')
            || readSidebarHidden();
        applySidebarHidden(hidden, false);
        if (hidden) {
            writeSidebarHidden(true);
        }

        document.querySelectorAll('[data-app-sidebar-toggle]').forEach(bindSidebarToggle);

        var pageBlock = document.querySelector('.app-topbar-page');
        if (pageBlock && pageBlock.dataset.sidebarShowBound !== '1') {
            pageBlock.dataset.sidebarShowBound = '1';
            pageBlock.addEventListener('click', function () {
                if (!document.body.classList.contains('app-sidebar-hidden')) {
                    return;
                }
                applySidebarHidden(false, true);
            });
        }
    }

    var SWIPE_TAB_SELECTOR = [
        '.app-hub-tabs__links',
        '.wali-portal-tabs',
        '.ikhtibar-portal-tabs',
        '.wali-nav-scroll',
        '.btn-group.flex-wrap[role="group"]',
        '.nav.nav-tabs.flex-wrap',
        '.nav.nav-pills.flex-wrap',
        '.nav.nav-tabs.flex-nowrap',
        '.nav.nav-tabs.overflow-auto'
    ].join(', ');

    function scrollSwipeTabActive(container) {
        var active = container.querySelector('.active');
        if (!active || typeof active.scrollIntoView !== 'function') {
            return;
        }
        try {
            active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
        } catch (err) {
            active.scrollIntoView({ inline: 'center', block: 'nearest' });
        }
    }

    function initSwipeTabs() {
        document.querySelectorAll(SWIPE_TAB_SELECTOR).forEach(function (container) {
            if (container.dataset.swipeTabsBound !== '1') {
                container.dataset.swipeTabsBound = '1';
                container.classList.add('app-swipe-row');
            }
            requestAnimationFrame(function () {
                scrollSwipeTabActive(container);
            });
        });
    }

    function init() {
        cleanupStaleOverlays();
        dismissFlashAlerts();
        initSidebarToggle();
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
