<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);

$waToken = trim((string) app_setting($pdo, 'wa_gateway_token', ''));
$waPengurus = trim((string) app_setting($pdo, 'wa_pengurus', ''));
$waAutoJam = trim((string) app_setting($pdo, 'jam_kirim_wa_auto', ''));
$waTagihanAuto = trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) === '1';
$waMudabirAuto = trim((string) app_setting($pdo, 'wa_notif_mudabir_enabled', '1')) === '1';
$alpaMode = trim((string) app_setting($pdo, 'alpa_notif_periode_mode', 'monthly'));
$pengurusWaCount = $waPengurus === ''
    ? 0
    : count(preg_split('/[\s,;]+/', $waPengurus, -1, PREG_SPLIT_NO_EMPTY) ?: []);

$alpaModeLabel = match ($alpaMode) {
    'weekly' => 'Mingguan',
    'default' => 'Akumulatif',
    default => 'Bulanan',
};

$pageTitle = 'Pusat WA Otomatis';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/wa_otomatis.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Pusat WA Otomatis</h1>
    <p class="text-muted mb-0 small">Satu pintu untuk semua pengaturan dan kontrol WhatsApp otomatis.</p>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Token Gateway</div>
            <div class="app-mini-stat-value <?= $waToken !== '' ? 'text-success' : 'text-warning' ?>">
                <?= $waToken !== '' ? 'Aktif' : 'Belum diisi' ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">No. Pengurus</div>
            <div class="app-mini-stat-value"><?= (int) $pengurusWaCount ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Jam Kirim WA</div>
            <div class="app-mini-stat-value" style="font-size:1rem;"><?= htmlspecialchars($waAutoJam !== '' ? $waAutoJam : 'Langsung') ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Mode Alpa</div>
            <div class="app-mini-stat-value" style="font-size:1rem;"><?= htmlspecialchars($alpaModeLabel) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/settings/wa_gateway.php')) ?>" class="card shadow-sm border-0 h-100 text-decoration-none">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="menu-hub-tile-icon" aria-hidden="true"><i class="fa-solid fa-gears"></i></span>
                    <div>
                        <h2 class="h6 mb-1 app-card-title">Gateway &amp; Jadwal WA</h2>
                        <p class="small text-muted mb-2">Token gateway, sender, nomor tujuan, jam kirim, WA mudabir, dan WA tagihan otomatis.</p>
                        <span class="badge app-badge-muted">
                            <?= $waTagihanAuto ? 'Tagihan: Aktif' : 'Tagihan: Nonaktif' ?>
                        </span>
                        <span class="badge app-badge-muted ms-1">
                            <?= $waMudabirAuto ? 'Mudabir: Aktif' : 'Mudabir: Nonaktif' ?>
                        </span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/settings/alpa_notif.php')) ?>" class="card shadow-sm border-0 h-100 text-decoration-none">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="menu-hub-tile-icon" aria-hidden="true"><i class="fa-solid fa-tower-broadcast"></i></span>
                    <div>
                        <h2 class="h6 mb-1 app-card-title">Notifikasi Alpa Bertahap</h2>
                        <p class="small text-muted mb-2">Atur tier penerima WA berdasarkan ambang alpa dan periode hitung.</p>
                        <span class="badge text-bg-info-subtle text-info border"><?= htmlspecialchars($alpaModeLabel) ?></span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/settings/kalender.php')) ?>" class="card shadow-sm border-0 h-100 text-decoration-none">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="menu-hub-tile-icon" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></span>
                    <div>
                        <h2 class="h6 mb-1 app-card-title">Kalender &amp; Jadwal Kirim Tagihan</h2>
                        <p class="small text-muted mb-0">Atur kalender, tanggal kirim, dan parameter periode untuk WA tagihan otomatis ke wali.</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/settings/push.php')) ?>" class="card shadow-sm border-0 h-100 text-decoration-none">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="menu-hub-tile-icon" aria-hidden="true"><i class="fa-solid fa-bell"></i></span>
                    <div>
                        <h2 class="h6 mb-1 app-card-title">Push FCM</h2>
                        <p class="small text-muted mb-0">Kelola push notifikasi aplikasi untuk melengkapi kanal WA otomatis.</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a href="<?= htmlspecialchars(app_href('/settings/wa_laporan_kelas_kosong.php')) ?>" class="card shadow-sm border-0 h-100 text-decoration-none">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="menu-hub-tile-icon" aria-hidden="true"><i class="fa-solid fa-clipboard-list"></i></span>
                    <div>
                        <h2 class="h6 mb-1 app-card-title">Laporan WA Kelas Kosong</h2>
                        <p class="small text-muted mb-0">Riwayat kirim WA saat dalam satu kelas/jam tidak ada pembimbing maupun munawib yang masuk.</p>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';

