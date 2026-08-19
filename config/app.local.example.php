<?php

declare(strict_types=1);

/**
 * OPSIONAL — salin ke app.local.php hanya jika deteksi otomatis tidak cocok.
 * File app.local.php tidak di-upload ke GitHub.
 */
return [
    // Preview XAMPP (opsional — null = deteksi otomatis, aman untuk ngrok):
    // 'base_path' => '/pwa_nailulmuna',
    // 'public_url' => 'http://localhost/pwa_nailulmuna',
    //
    // Ngrok: cukup biarkan public_url kosong / jangan set localhost jika sering buka lewat ngrok.
    // Atau isi keduanya dengan URL ngrok lengkap Anda.
    //
    // Portal wali subdomain (opsional). Kosong = login di /wali/login.php pada host yang sama.
    // Di server live isi:
    // 'public_url' => 'https://pwa.nailulmuna.id',
    // 'wali_public_url' => 'https://wali.nailulmuna.id',
];
