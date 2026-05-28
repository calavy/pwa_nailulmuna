<?php

declare(strict_types=1);

/**
 * @deprecated Gunakan api/pwa/app-sw.php (offline + FCM). File ini dipertahankan agar
 * instalasi lama tidak error; pendaftaran baru memakai app-sw.php via pwa-register.js / fcm-push.js.
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/push_fcm.php';
require_once __DIR__ . '/../../helpers/app_path.php';

$cfg = push_fcm_web_config($pdo);
$apiKey = addslashes($cfg['apiKey'] ?? '');
$projectId = addslashes($cfg['projectId'] ?? '');
$senderId = addslashes($cfg['senderId'] ?? '');
$appId = addslashes($cfg['appId'] ?? '');
$authDomain = addslashes($projectId !== '' ? $projectId . '.firebaseapp.com' : '');
$basePath = addslashes(rtrim(app_base_path(), '/') . '/');

echo <<<JS
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');
firebase.initializeApp({
  apiKey: '{$apiKey}',
  authDomain: '{$authDomain}',
  projectId: '{$projectId}',
  messagingSenderId: '{$senderId}',
  appId: '{$appId}'
});
const messaging = firebase.messaging();
messaging.onBackgroundMessage(function (payload) {
  const title = (payload.notification && payload.notification.title) || 'Pondok';
  const options = {
    body: (payload.notification && payload.notification.body) || '',
    data: payload.data || {},
    tag: (payload.data && payload.data.category) || 'pondok'
  };
  return self.registration.showNotification(title, options);
});
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var base = '{$basePath}';
  var raw = (event.notification.data && event.notification.data.url) || base;
  var url = raw;
  try {
    if (typeof raw === 'string' && raw.indexOf('://') < 0 && raw.charAt(0) === '/' && base !== '/' && raw.indexOf(base) !== 0) {
      url = base.replace(/\/$/, '') + raw;
    }
  } catch (e) {}
  event.waitUntil(clients.openWindow(url));
});
JS;
