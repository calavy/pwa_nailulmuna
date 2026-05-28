<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

require_once __DIR__ . '/includes/pondok_settings_logic.php';

$pageTitle = 'WA Gateway';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/wa_otomatis.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">WA Gateway &amp; Otomatisasi</h1>
    <p class="text-muted mb-0 small">Pengaturan token, sender, nomor tujuan, jadwal kirim, notifikasi mudabir, dan tagihan otomatis.</p>
    <p class="small mb-0 mt-1"><a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>">← Kembali ke Pusat WA Otomatis</a></p>
</div>

<?php require __DIR__ . '/partials/pondok_settings_view.php'; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
