<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
require_once __DIR__ . '/../helpers/wa_tagihan.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'jalankan_wa_tagihan') {
    $bulanPaksa = max(0, (int) ($_POST['bulan_tagihan'] ?? 0));
    $res = wa_tagihan_jalankan_kirim($pdo, true, $bulanPaksa > 0 ? $bulanPaksa : null);
    set_flash($res['ok'] ? 'success' : 'warning', (string) ($res['message'] ?? ''));
    header('Location: ' . app_href('/settings/wa_otomatis.php'));
    exit;
}

$waJadwal = wa_tagihan_jadwal_context($pdo);
$waLastRun = trim((string) app_setting($pdo, 'wa_tagihan_last_run_at', ''));
$waLastStatsRaw = trim((string) app_setting($pdo, 'wa_tagihan_last_run_stats', ''));
$waLastStats = $waLastStatsRaw !== '' ? json_decode($waLastStatsRaw, true) : null;
if (!is_array($waLastStats)) {
    $waLastStats = null;
}

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

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Status WA tagihan otomatis</h2>
        <ul class="small text-muted mb-3 ps-3">
            <li>Kalender: <strong><?= $waJadwal['calendar'] === 'HIJRIYAH' ? 'Hijriyah' : 'Masehi' ?></strong> · jadwal hari ke-<strong><?= (int) $waJadwal['due_day'] ?></strong> (hari ini ke-<?= (int) $waJadwal['today_day'] ?>)</li>
            <li>Jam kirim: <strong><?= $waJadwal['send_time'] !== '' ? htmlspecialchars($waJadwal['send_time']) : 'Langsung' ?></strong>
                <?= $waJadwal['send_time_ok'] ? '<span class="text-success">(sudah lewat)</span>' : '<span class="text-warning">(belum)</span>' ?></li>
            <li>Terakhir sukses periode: <strong><?= $waJadwal['last_sent_at'] !== '' ? htmlspecialchars($waJadwal['last_sent_at']) : 'Belum pernah' ?></strong></li>
            <?php if ($waLastRun !== ''): ?>
                <li>Percobaan terakhir: <?= htmlspecialchars($waLastRun) ?>
                    <?php if ($waLastStats): ?>
                        — terkirim <?= (int) ($waLastStats['sent'] ?? 0) ?>, gagal <?= (int) ($waLastStats['failed'] ?? 0) ?>, dilewati <?= (int) ($waLastStats['skipped'] ?? 0) ?>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        </ul>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end" onsubmit="return confirm('Jalankan kirim WA tagihan sekarang? (mengabaikan cek hari jadwal, tetap hormati jam kirim jika diatur)');">
            <input type="hidden" name="action" value="jalankan_wa_tagihan">
            <div>
                <label class="form-label small mb-0">Bulan tagihan (opsional)</label>
                <input type="number" name="bulan_tagihan" class="form-control form-control-sm" min="0" max="12" value="0" placeholder="0 = bulan berjalan" style="width:6rem">
            </div>
            <button type="submit" class="btn btn-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Jalankan kirim sekarang</button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">Tagihan per santri</a>
        </form>
        <p class="small text-muted mb-0 mt-2">Untuk kirim per santri dengan tombol WA, buka halaman Tagihan Bulanan.</p>
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
        <a href="<?= htmlspecialchars(app_href('/settings/wa_pesan.php')) ?>" class="card shadow-sm border-0 h-100 text-decoration-none">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="menu-hub-tile-icon" aria-hidden="true"><i class="fa-solid fa-message"></i></span>
                    <div>
                        <h2 class="h6 mb-1 app-card-title">Template Pesan WA</h2>
                        <p class="small text-muted mb-0">Atur teks tagihan, pengingat scan pembimbing, rekap ALPA, dan pesan otomatis lainnya.</p>
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

