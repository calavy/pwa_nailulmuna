<?php

declare(strict_types=1);

/**
 * Konfigurasi aplikasi — cukup file ini di GitHub.
 *
 * base_path null = deteksi otomatis:
 *   - XAMPP: http://localhost/pwa_nailulmuna/...
 *   - Hosting (root domain): https://pwa.nailulmuna.id/...
 *
 * public_url null = ikut domain browser saat dibuka.
 * Opsional: salin config/app.local.example.php → app.local.php jika perlu override.
 */
return [
    'base_path' => null,
    'public_url' => null,
];
