<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

require_login();

$aksesSummary = user_permission_access_summary($pdo);

$pageTitle = 'Hak Akses Saya';
$bodyClass = 'settings-module-page';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>">Beranda</a>
        · <a href="<?= htmlspecialchars(app_href('/settings/profil.php')) ?>">Profil</a>
        · Akun
    </p>
    <h1 class="h4 mb-1">Hak akses saya</h1>
    <p class="text-muted mb-0">Tampilan ini hanya membaca pengaturan dari super admin — tidak bisa diubah dari halaman ini.</p>
</div>

<?php
$aksesPanelCompact = false;
require __DIR__ . '/partials/akses_saya_panel.php';
?>

<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/settings/profil.php')) ?>">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Kembali ke profil
    </a>
    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>">Ke dashboard</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
