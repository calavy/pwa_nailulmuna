<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_portal_layout.php';

if (isset($_SESSION['user']) && $pdo instanceof PDO) {
    app_post_login_redirect($pdo);
}

$welcome = auth_portal_welcome_copy($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', 'Pondok Pesantren'));
auth_portal_layout_begin([
    'title' => 'Portal Digital',
    'welcome_salam' => $welcome['salam'],
    'welcome_paragraphs' => $welcome['paragraphs'] ?? [],
    'welcome_tagline' => $welcome['tagline'],
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $welcome['ponpes'],
    'logo_url' => '',
    'layout' => 'split',
    'card_title' => 'Masuk ke Portal',
    'card_meta' => 'Pilih peran setelah menekan tombol di bawah',
    'accent' => 'teal',
]);
?>
<div class="d-grid gap-2">
    <a class="btn btn-auth-primary btn-lg" href="<?= htmlspecialchars(app_href('/login.php')) ?>">
        <i class="fa-solid fa-right-to-bracket me-2" aria-hidden="true"></i>Login Portal
    </a>
</div>
<?php
auth_portal_layout_end();
