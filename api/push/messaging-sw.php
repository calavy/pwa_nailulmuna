<?php

declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/push_fcm.php';

$cfg = push_fcm_web_config($pdo);
$apiKey = addslashes($cfg['apiKey'] ?? '');
$projectId = addslashes($cfg['projectId'] ?? '');
$senderId = addslashes($cfg['senderId'] ?? '');
$appId = addslashes($cfg['appId'] ?? '');
$authDomain = addslashes($projectId !== '' ? $projectId . '.firebaseapp.com' : '');

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
  var url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(clients.openWindow(url));
});
JS;
