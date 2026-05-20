<?php

require_once __DIR__ . '/config/session.php';

session_unset();
session_destroy();

session_start();
$_SESSION['flash']['success'] = 'Anda telah logout.';

header('Location: /pwa_nailulmuna/login.php');
exit;
