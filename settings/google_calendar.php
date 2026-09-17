<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/google_calendar_sync.php';

require_roles(['admin', 'pengurus']);

ensure_pondok_settings_defaults($pdo);
ensure_google_calendar_tables($pdo);
$defaults = pondok_settings_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'simpan') {
        save_setting($pdo, 'google_calendar_enabled', isset($_POST['google_calendar_enabled']) ? '1' : '0');
        save_setting($pdo, 'google_calendar_sa_json_path', trim((string) ($_POST['google_calendar_sa_json_path'] ?? '')));
        save_setting($pdo, 'google_calendar_id_internal', trim((string) ($_POST['google_calendar_id_internal'] ?? '')));
        save_setting($pdo, 'google_calendar_id_public', trim((string) ($_POST['google_calendar_id_public'] ?? '')));
        save_setting($pdo, 'google_calendar_embed_public_url', trim((string) ($_POST['google_calendar_embed_public_url'] ?? '')));
        save_setting($pdo, 'google_calendar_cron_key', trim((string) ($_POST['google_calendar_cron_key'] ?? '')));
        set_flash('success', 'Pengaturan Google Calendar disimpan.');
        header('Location: ' . app_href('/settings/google_calendar.php'));
        exit;
    }

    if ($action === 'tes_koneksi') {
        $test = google_calendar_test_connection($pdo);
        if ($test['ok']) {
            set_flash('success', 'Koneksi OK. SA: ' . ($test['client_email'] ?? '') . ' · Internal: ' . ($test['internal_summary'] ?? '—') . ' · Publik: ' . ($test['public_summary'] ?? '—'));
        } else {
            set_flash('error', 'Koneksi gagal: ' . ($test['error'] ?? 'unknown'));
        }
        header('Location: ' . app_href('/settings/google_calendar.php'));
        exit;
    }

    if ($action === 'sync_sekarang') {
        $full = !empty($_POST['full_push']);
        $result = google_calendar_sync_run($pdo, $full);
        if ($result['ok']) {
            set_flash('success', 'Sync selesai. Perubahan dari Google diterapkan: ' . (int) $result['pulled'] . ' event.');
        } else {
            set_flash('error', 'Sync gagal: ' . (string) $result['error']);
        }
        header('Location: ' . app_href('/settings/google_calendar.php'));
        exit;
    }

    if ($action === 'daftar_webhook') {
        $base = trim((string) ($_POST['webhook_base_url'] ?? ''));
        if ($base === '') {
            $base = app_href('');
            if (str_starts_with($base, '/')) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $base = $scheme . '://' . $host . $base;
            }
        }
        $okInt = google_calendar_register_webhook($pdo, 'internal', $base);
        $okPub = google_calendar_register_webhook($pdo, 'public', $base);
        if ($okInt && $okPub) {
            set_flash('success', 'Webhook Google Calendar didaftarkan untuk internal & publik.');
        } else {
            set_flash('error', 'Webhook gagal sebagian/penuh. Pastikan sync aktif, cron key terisi, dan URL HTTPS dapat diakses Google.');
        }
        header('Location: ' . app_href('/settings/google_calendar.php'));
        exit;
    }
}

$v = static fn(string $k, string $d = ''): string => (string) app_setting($pdo, $k, $defaults[$k] ?? $d);
$settings = google_calendar_settings($pdo);
$cronKey = $v('google_calendar_cron_key');
$cronUrl = app_href('/cron/google_calendar_sync.php') . ($cronKey !== '' ? ('?key=' . rawurlencode($cronKey)) : '');
$webhookUrl = app_href('/api/google/calendar/webhook.php') . ($cronKey !== '' ? ('?key=' . rawurlencode($cronKey)) : '');
$lastSync = $v('google_calendar_last_sync_at');
$lastError = $v('google_calendar_last_error');
$saPath = $settings['sa_path'];

$pageTitle = 'Google Calendar Sync';
$settingsNavActive = '/settings/google_calendar.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/settings_nav.php';
?>

<div class="mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Sinkron Google Calendar</h1>
    <p class="text-muted small mb-0">
        Agenda kalender akademik &amp; slot jadwal kegiatan ↔ kalender Google (internal + publik). Libur Hijri tetap di PWA.
        <strong>Tampilan:</strong> di Kalender Akademik / Jadwal, Google dibuka di <strong>tab browser baru</strong> (bukan iframe); sinkron data lewat API + cron, bukan lewat embed.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Status</div>
    <div class="card-body small">
        <dl class="row mb-0">
            <dt class="col-sm-4">Sync aktif</dt>
            <dd class="col-sm-8"><?= google_calendar_enabled($pdo) ? '<span class="badge text-bg-success">Ya</span>' : '<span class="badge text-bg-secondary">Tidak / belum lengkap</span>' ?></dd>
            <dt class="col-sm-4">File SA</dt>
            <dd class="col-sm-8"><code class="small"><?= htmlspecialchars($saPath) ?></code> <?= is_file($saPath) ? '<span class="badge text-bg-success">ada</span>' : '<span class="badge text-bg-danger">tidak ditemukan</span>' ?></dd>
            <dt class="col-sm-4">Terakhir sync</dt>
            <dd class="col-sm-8"><?= $lastSync !== '' ? htmlspecialchars($lastSync) : '—' ?></dd>
            <?php if ($lastError !== ''): ?>
            <dt class="col-sm-4 text-danger">Error terakhir</dt>
            <dd class="col-sm-8 text-danger"><?= htmlspecialchars($lastError) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<div class="card shadow-sm mb-3 border-info">
    <div class="card-header bg-white fw-semibold small">Setup Google (sekali)</div>
    <div class="card-body small">
        <ol class="mb-0 ps-3">
            <li>Aktifkan <strong>Google Calendar API</strong> di Google Cloud Console.</li>
            <li>Service Account → unduh JSON → simpan di server (bisa sama dengan snapshot Sheet).</li>
            <li>Di Google Calendar: <strong>Share</strong> kalender internal &amp; publik ke email SA dengan izin <strong>Make changes to events</strong>.</li>
            <li>Salin <strong>Calendar ID</strong> (Settings → Integrate calendar) ke form bawah.</li>
            <li>Opsional: URL embed publik (hanya untuk mengekstrak Calendar ID jika ID publik kosong).</li>
        </ol>
    </div>
</div>

<form method="post" class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Pengaturan</div>
    <div class="card-body">
        <input type="hidden" name="action" value="simpan">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="google_calendar_enabled" name="google_calendar_enabled" value="1" <?= $v('google_calendar_enabled', '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="google_calendar_enabled">Aktifkan sinkron dua arah</label>
        </div>
        <div class="mb-3">
            <label class="form-label small" for="google_calendar_sa_json_path">Path JSON Service Account (kosong = pakai setting snapshot Sheet)</label>
            <input type="text" class="form-control font-monospace small" name="google_calendar_sa_json_path" id="google_calendar_sa_json_path" value="<?= htmlspecialchars($v('google_calendar_sa_json_path')) ?>">
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small" for="google_calendar_id_internal">Calendar ID internal</label>
                <input type="text" class="form-control font-monospace small" name="google_calendar_id_internal" id="google_calendar_id_internal" value="<?= htmlspecialchars($v('google_calendar_id_internal')) ?>" placeholder="...@group.calendar.google.com">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="google_calendar_id_public">Calendar ID publik</label>
                <input type="text" class="form-control font-monospace small" name="google_calendar_id_public" id="google_calendar_id_public" value="<?= htmlspecialchars($v('google_calendar_id_public')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label small" for="google_calendar_embed_public_url">URL embed kalender publik (opsional, cadangan Calendar ID)</label>
                <input type="url" class="form-control font-monospace small" name="google_calendar_embed_public_url" id="google_calendar_embed_public_url" value="<?= htmlspecialchars($v('google_calendar_embed_public_url')) ?>" placeholder="https://calendar.google.com/calendar/embed?src=...">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="google_calendar_cron_key">Cron / webhook key</label>
                <input type="text" class="form-control font-monospace small" name="google_calendar_cron_key" id="google_calendar_cron_key" value="<?= htmlspecialchars($cronKey) ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm mt-3">Simpan</button>
    </div>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Tes &amp; sync manual</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="tes_koneksi">
            <button type="submit" class="btn btn-outline-primary btn-sm">Tes koneksi</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="sync_sekarang">
            <button type="submit" class="btn btn-outline-success btn-sm">Tarik dari Google sekarang</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="sync_sekarang">
            <input type="hidden" name="full_push" value="1">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Push semua jadwal &amp; agenda ke Google</button>
        </form>
    </div>
    <div class="card-footer small text-muted">
        Cron (disarankan tiap 5 menit): <code><?= htmlspecialchars($cronUrl) ?></code>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Webhook (production HTTPS)</div>
    <div class="card-body small">
        <p class="mb-2">URL notifikasi: <code><?= htmlspecialchars($webhookUrl) ?></code></p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="daftar_webhook">
            <div class="col-md-8">
                <label class="form-label small">Base URL publik (opsional)</label>
                <input type="url" class="form-control form-control-sm" name="webhook_base_url" placeholder="https://pwa.nailulmuna.id">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Daftar ulang webhook</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
