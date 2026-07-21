<?php

/**
 * Contoh profil HOSTING — salin ke database.local.hosting.php (gitignored).
 * Isi host MySQL dari panel InfinityFree, lalu di server:
 *   salin database.local.hosting.php → database.local.php
 *
 * Profil LOKAL (XAMPP): pakai database.local.example.php → database.local.php
 */
return [
    'environment' => 'production',
    'host' => 'sql313.infinityfree.com',
    'port' => '3306',
    'dbname' => 'u700125577_pwanailulmuna',
    'user' => 'u700125577_pwanailulmuna',
    'pass' => 'GANTI_PASSWORD_DARI_PANEL',
];
