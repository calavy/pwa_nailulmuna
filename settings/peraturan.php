<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

require_once __DIR__ . '/includes/poin_settings_logic.php';

$pageTitle = 'Peraturan poin';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/pwa_nailulmuna/settings/peraturan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Peraturan poin</h1>
    <p class="text-muted mb-0 small">Auto poin presensi, daftar pelanggaran, dan ambang sanksi.</p>
</div>

<?php require __DIR__ . '/partials/poin_settings_view.php'; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
