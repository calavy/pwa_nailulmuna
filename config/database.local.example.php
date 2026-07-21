<?php

/**
 * Salin file ini menjadi: database.local.php
 *
 * === Profil LOKAL (XAMPP) — default di bawah ===
 * Database: pwa_nailulmuna | Impor: impor_lokal_pwa_nailulmuna.sql
 *
 * === Profil HOSTING (InfinityFree / live) ===
 * Salin database.local.hosting.example.php → database.local.hosting.php, isi host MySQL.
 * dbname/user/pass hosting: u700125577_pwanailulmuna / u700125577_pwanailulmuna
 * Di server salin database.local.hosting.php → database.local.php
 * (atau env PONDOK_DB_PROFILE=hosting di server)
 * Impor: impor_lengkap_pwa_nailulmuna.sql
 */
return [
    'environment' => 'local',
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => 'pwa_nailulmuna',
    'user' => 'root',
    'pass' => '',
];
