<?php

declare(strict_types=1);

/**
 * Daftar pintu masuk portal (login, presensi, wali, dll.).
 * Dipakai di beranda.php dan login.php.
 */
?>
<p class="small text-muted mb-3">Semua pintu masuk portal tersedia di bawah.</p>
<div class="auth-portal-role-grid">
    <?php
    auth_portal_role_link([
        'href' => app_href('/login.php'),
        'icon' => 'fa-user-tie',
        'icon_mod' => 'pengurus',
        'title' => 'Pengurus / Admin',
        'desc' => 'Username & password',
    ]);
    auth_portal_role_link([
        'href' => app_href('/login.php'),
        'icon' => 'fa-chalkboard-user',
        'icon_mod' => 'pembimbing',
        'title' => 'Pembimbing',
        'desc' => 'NIP & password',
    ]);
    auth_portal_role_link([
        'href' => app_href('/presensi/login.php'),
        'icon' => 'fa-qrcode',
        'icon_mod' => 'presensi',
        'title' => 'Petugas presensi',
        'desc' => 'Scan & password',
    ]);
    auth_portal_role_link([
        'href' => app_href('/santri_portal/login.php'),
        'icon' => 'fa-user-graduate',
        'icon_mod' => 'santri',
        'title' => 'Portal santri',
        'desc' => 'NIS · PIN santri',
    ]);
    auth_portal_role_link([
        'href' => app_href('/mukimin/login.php'),
        'icon' => 'fa-book-open',
        'icon_mod' => 'mukimin',
        'title' => 'Portal mukimin',
        'desc' => 'Alumni · akun',
        'full' => true,
    ]);
    ?>
</div>
