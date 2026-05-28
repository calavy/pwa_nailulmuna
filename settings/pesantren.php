<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

require_once __DIR__ . '/includes/pondok_settings_logic.php';

$pageTitle = 'Pesantren';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/pesantren.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Pesantren</h1>
    <p class="text-muted mb-0 small">Khusus identitas pesantren (nama, alamat, pengasuh, logo). Pengaturan WA dipisah di Pusat WA Otomatis.</p>
</div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Logo</div>
                <div class="app-mini-stat-value <?= $logoConfigured ? 'text-success' : 'text-warning' ?>"><?= $logoConfigured ? 'Siap' : 'Belum' ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Token WA</div>
                <div class="app-mini-stat-value <?= $waConfigured ? 'text-success' : 'text-warning' ?>"><?= $waConfigured ? 'Aktif' : 'Kosong' ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">No. pengurus</div>
                <div class="app-mini-stat-value"><?= (int) $pengurusWaCount ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label">Jam kirim otomatis</div>
                <div class="app-mini-stat-value" style="font-size:1rem;"><?= htmlspecialchars($values['jam_kirim_wa_auto'] !== '' ? $values['jam_kirim_wa_auto'] : 'Langsung') ?></div>
            </div>
        </div>
    </div>
<?php require __DIR__ . '/partials/pondok_identity_view.php'; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
