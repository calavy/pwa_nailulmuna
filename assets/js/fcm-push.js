/**
 * FCM Web Push — wali, pengurus, kiai
 */
(function (global) {
    'use strict';

    function appBase() {
        var b = (typeof window !== 'undefined' && window.PONDOK_APP_BASE != null) ? String(window.PONDOK_APP_BASE) : '';
        b = b.replace(/\/$/, '');
        return b;
    }

    function appPath(relative) {
        relative = String(relative || '').replace(/^\//, '');
        var base = appBase();
        return (base === '' ? '' : base) + '/' + relative;
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    async function initFcmPush(options) {
        options = options || {};
        var cfg = options.config || {};
        if (cfg.enabled !== '1' || !cfg.apiKey || !cfg.vapidKey || !cfg.appId) {
            return { ok: false, reason: 'not_configured' };
        }
        if (!('serviceWorker' in navigator) || !('Notification' in window)) {
            return { ok: false, reason: 'unsupported' };
        }

        await loadScript('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
        await loadScript('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

        if (!firebase.apps.length) {
            firebase.initializeApp({
                apiKey: cfg.apiKey,
                authDomain: cfg.projectId ? cfg.projectId + '.firebaseapp.com' : undefined,
                projectId: cfg.projectId,
                messagingSenderId: cfg.senderId,
                appId: cfg.appId,
            });
        }

        var swScope = (appBase() === '' ? '/' : appBase() + '/');
        var reg = await navigator.serviceWorker.register(appPath('api/push/messaging-sw.php'), {
            scope: swScope,
        });
        var messaging = firebase.messaging();
        messaging.useServiceWorker(reg);

        var permission = Notification.permission;
        if (permission === 'default' && options.prompt !== false) {
            permission = await Notification.requestPermission();
        }
        if (permission !== 'granted') {
            return { ok: false, reason: 'denied' };
        }

        var token = await messaging.getToken({ vapidKey: cfg.vapidKey });
        if (!token) {
            return { ok: false, reason: 'no_token' };
        }

        var res = await fetch(appPath('api/push/register.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                token: token,
                audience_type: options.audienceType || 'staff',
                categories: options.categories || [],
                device_label: options.deviceLabel || navigator.userAgent.slice(0, 100),
                subscribe_kiai: options.subscribeKiai ? 1 : 0,
            }),
        });
        var data = await res.json();

        messaging.onMessage(function (payload) {
            if (options.onForeground && typeof options.onForeground === 'function') {
                options.onForeground(payload);
                return;
            }
            var title = (payload.notification && payload.notification.title) || 'Notifikasi';
            var body = (payload.notification && payload.notification.body) || '';
            if (global.bootstrap && document.getElementById('fcm-toast-host')) {
                var host = document.getElementById('fcm-toast-host');
                host.innerHTML = '<div class="alert alert-info shadow-sm mb-0"><strong>' + title.replace(/</g, '&lt;') + '</strong><br>' + body.replace(/</g, '&lt;') + '</div>';
            } else if (global.Notification && Notification.permission === 'granted') {
                new Notification(title, { body: body });
            }
        });

        return { ok: !!data.ok, token: token, data: data };
    }

    global.PondokFcm = {
        init: initFcmPush,
    };
})(window);
