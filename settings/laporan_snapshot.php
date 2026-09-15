<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

require_roles(['admin', 'pengurus']);

ensure_pondok_settings_defaults($pdo);
$defaults = pondok_settings_defaults();
$status = laporan_snapshot_status($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'simpan') {
        save_setting($pdo, 'laporan_snapshot_enabled', isset($_POST['laporan_snapshot_enabled']) ? '1' : '0');
        $jam = trim((string) ($_POST['laporan_snapshot_jam'] ?? '05:00'));
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $jam, $m)) {
            $jam = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        } else {
            $jam = '05:00';
        }
        save_setting($pdo, 'laporan_snapshot_jam', $jam);
        save_setting($pdo, 'laporan_snapshot_cron_key', trim((string) ($_POST['laporan_snapshot_cron_key'] ?? '')));
        save_setting($pdo, 'laporan_snapshot_share_emails', trim((string) ($_POST['laporan_snapshot_share_emails'] ?? '')));
        $jsonPath = trim((string) ($_POST['laporan_snapshot_sa_json_path'] ?? ''));
        save_setting($pdo, 'laporan_snapshot_sa_json_path', $jsonPath !== '' ? $jsonPath : ($defaults['laporan_snapshot_sa_json_path'] ?? ''));
        $spreadsheetId = trim((string) ($_POST['laporan_snapshot_spreadsheet_id'] ?? ''));
        if ($spreadsheetId !== '') {
            save_setting($pdo, 'laporan_snapshot_spreadsheet_id', $spreadsheetId);
        }
        set_flash('success', 'Pengaturan snapshot laporan disimpan.');
        header('Location: ' . app_href('/settings/laporan_snapshot.php'));
        exit;
    }

    if ($action === 'tes_snapshot') {
        $saStatus = laporan_snapshot_sa_status($pdo);
        if (!$saStatus['valid_json']) {
            set_flash('error', 'Kredensial Google belum siap: ' . (string) ($saStatus['error'] ?? 'tidak valid'));
            header('Location: ' . app_href('/settings/laporan_snapshot.php'));
            exit;
        }

        try {
            $asOf = laporan_snapshot_normalize_as_of($_POST['as_of'] ?? null);
            $collected = laporan_snapshot_collect_all($pdo, $asOf);
            $push = laporan_snapshot_push_to_google($pdo, $collected, true);
            save_setting($pdo, 'laporan_snapshot_last_run_at', date('Y-m-d H:i:s'));
            save_setting($pdo, 'laporan_snapshot_last_result', json_encode([
                'as_of' => $asOf,
                'spreadsheet_id' => $push['spreadsheet_id'] ?? '',
                'tabs' => $push['tabs'] ?? [],
                'shared' => $push['shared'] ?? [],
                'ok' => (bool) ($push['ok'] ?? false),
                'manual' => true,
            ], JSON_UNESCAPED_UNICODE));
            if ($push['ok'] ?? false) {
                save_setting($pdo, 'laporan_snapshot_last_error', '');
                set_flash('success', 'Snapshot as_of ' . $asOf . ' berhasil dikirim ke Google Sheet. ID: ' . (string) ($push['spreadsheet_id'] ?? ''));
            } else {
                save_setting($pdo, 'laporan_snapshot_last_error', (string) ($push['error'] ?? 'Gagal'));
                set_flash('error', 'Snapshot as_of ' . $asOf . ' selesai dengan error. Cek detail di bawah.');
            }
        } catch (Throwable $e) {
            save_setting($pdo, 'laporan_snapshot_last_error', $e->getMessage());
            set_flash('error', 'Kirim snapshot gagal: ' . $e->getMessage());
        }
        header('Location: ' . app_href('/settings/laporan_snapshot.php'));
        exit;
    }
}

$v = static fn(string $k, string $d = ''): string => (string) app_setting($pdo, $k, $defaults[$k] ?? $d);
$status = laporan_snapshot_status($pdo);
$lastResult = is_array($status['last_result'] ?? null) ? $status['last_result'] : null;
$cronKey = $v('laporan_snapshot_cron_key');
$cronUrl = app_href('/cron/laporan_snapshot.php') . ($cronKey !== '' ? ('?key=' . rawurlencode($cronKey)) : '');
$saStatus = laporan_snapshot_sa_status($pdo);
$saPath = (string) ($saStatus['path'] ?? '');
$spreadsheetUrl = ($status['spreadsheet_id'] ?? '') !== ''
    ? ('https://docs.google.com/spreadsheets/d/' . rawurlencode((string) $status['spreadsheet_id']))
    : '';

$pageTitle = 'Snapshot Laporan Google Sheet';
$settingsNavActive = '/settings/laporan_snapshot.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/settings_nav.php';
?>

<div class="mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Snapshot Laporan Google Sheet</h1>
    <p class="text-muted small mb-0">
        Cron harian menulis 7 tab laporan keuangan (data tabular mentah) ke satu Google Spreadsheet,
        lalu membagikan ke email penerima.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Status</div>
    <div class="card-body small">
        <dl class="row mb-0">
            <dt class="col-sm-4">Aktif</dt>
            <dd class="col-sm-8"><?= ($status['enabled'] ?? false) ? '<span class="badge text-bg-success">Ya</span>' : '<span class="badge text-bg-secondary">Tidak</span>' ?></dd>
            <dt class="col-sm-4">Jam snapshot</dt>
            <dd class="col-sm-8"><code><?= htmlspecialchars((string) ($status['jam'] ?? '05:00')) ?></code>
                <?= ($status['send_time_ok'] ?? false) ? ' <span class="text-success">(window aktif)</span>' : '' ?></dd>
            <dt class="col-sm-4">Terakhir sukses</dt>
            <dd class="col-sm-8"><?= htmlspecialchars((string) ($status['last_date'] ?? '—')) ?></dd>
            <dt class="col-sm-4">Terakhir jalan</dt>
            <dd class="col-sm-8"><?= htmlspecialchars((string) ($status['last_run_at'] ?? '—')) ?></dd>
            <dt class="col-sm-4">Spreadsheet ID</dt>
            <dd class="col-sm-8">
                <?php if ($spreadsheetUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($spreadsheetUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string) $status['spreadsheet_id']) ?></a>
                <?php else: ?>
                    <span class="text-muted">Belum dibuat (otomatis saat tes/run pertama)</span>
                <?php endif; ?>
            </dd>
            <dt class="col-sm-4">Kredensial SA</dt>
            <dd class="col-sm-8">
                <code class="small"><?= htmlspecialchars($saPath) ?></code>
                <?php if ($saStatus['valid_json'] ?? false): ?>
                    <span class="badge text-bg-success">siap</span>
                    <span class="text-muted"> — <?= htmlspecialchars((string) ($saStatus['client_email'] ?? '')) ?></span>
                <?php elseif ($saStatus['exists'] ?? false): ?>
                    <span class="badge text-bg-warning">file ada, JSON belum valid</span>
                <?php else: ?>
                    <span class="badge text-bg-danger">tidak ditemukan</span>
                <?php endif; ?>
            </dd>
            <?php if (($status['last_error'] ?? '') !== ''): ?>
            <dt class="col-sm-4 text-danger">Error terakhir</dt>
            <dd class="col-sm-8 text-danger"><?= htmlspecialchars((string) $status['last_error']) ?></dd>
            <?php endif; ?>
        </dl>
        <?php if (is_array($lastResult) && !empty($lastResult['tabs'])): ?>
        <div class="table-responsive mt-3">
            <table class="table table-sm table-bordered mb-0">
                <thead><tr><th>Tab</th><th>Baris</th><th>Error</th></tr></thead>
                <tbody>
                <?php foreach ((array) $lastResult['tabs'] as $tab => $info): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $tab) ?></td>
                        <td><?= (int) ($info['rows_count'] ?? 0) ?></td>
                        <td class="text-danger small"><?= htmlspecialchars((string) ($info['error'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mb-3 border-info">
    <div class="card-header bg-white fw-semibold small">Setup Google Cloud (sekali)</div>
    <div class="card-body small">
        <ol class="mb-0 ps-3">
            <li>Buat project di <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>.</li>
            <li>Aktifkan <strong>Google Sheets API</strong> dan <strong>Google Drive API</strong>.</li>
            <li>Buat <strong>Service Account</strong> → unduh JSON key → simpan sebagai <code>config/google_service_account.json</code>.</li>
            <li>Catat email service account (<code>...@...iam.gserviceaccount.com</code>).</li>
            <li>Opsional: buat spreadsheet kosong → share <strong>Editor</strong> ke email service account → tempel Spreadsheet ID di form.</li>
            <li>Isi email penerima di bawah — cron akan share <strong>Viewer</strong> ke alamat tersebut.</li>
            <li>Jalankan <code>setup-cron-laporan-snapshot.bat</code> (Windows) atau crontab HTTP/CLI.</li>
        </ol>
    </div>
</div>

<form method="post" class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Pengaturan</div>
    <div class="card-body">
        <input type="hidden" name="action" value="simpan">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="laporan_snapshot_enabled" name="laporan_snapshot_enabled" value="1" <?= $v('laporan_snapshot_enabled', '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="laporan_snapshot_enabled">Aktifkan snapshot harian otomatis</label>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small" for="laporan_snapshot_jam">Jam snapshot (WIB)</label>
                <input type="time" class="form-control" id="laporan_snapshot_jam" name="laporan_snapshot_jam" value="<?= htmlspecialchars($v('laporan_snapshot_jam', '05:00')) ?>" required>
            </div>
            <div class="col-md-8">
                <label class="form-label small" for="laporan_snapshot_share_emails">Email penerima (Viewer, pisah koma)</label>
                <input type="text" class="form-control" id="laporan_snapshot_share_emails" name="laporan_snapshot_share_emails" value="<?= htmlspecialchars($v('laporan_snapshot_share_emails')) ?>" placeholder="bendahara@example.com, ketua@example.com">
            </div>
            <div class="col-12">
                <label class="form-label small" for="laporan_snapshot_sa_json_path">Path file JSON Service Account</label>
                <input type="text" class="form-control font-monospace small" id="laporan_snapshot_sa_json_path" name="laporan_snapshot_sa_json_path" value="<?= htmlspecialchars($v('laporan_snapshot_sa_json_path', 'config/google_service_account.json')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="laporan_snapshot_spreadsheet_id">Spreadsheet ID (kosongkan = buat otomatis)</label>
                <input type="text" class="form-control font-monospace small" id="laporan_snapshot_spreadsheet_id" name="laporan_snapshot_spreadsheet_id" value="<?= htmlspecialchars($v('laporan_snapshot_spreadsheet_id')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="laporan_snapshot_cron_key">Cron key HTTP (opsional)</label>
                <input type="text" class="form-control font-monospace small" id="laporan_snapshot_cron_key" name="laporan_snapshot_cron_key" value="<?= htmlspecialchars($cronKey) ?>" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
    </div>
</form>

<?php $defaultAsOf = laporan_snapshot_as_of_date(); ?>
<form method="post" class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Kirim snapshot manual</div>
    <div class="card-body">
        <input type="hidden" name="action" value="tes_snapshot">
        <?php if ($saStatus['valid_json'] ?? false): ?>
        <div class="alert alert-success small py-2 mb-3">
            Kredensial siap — service account <code><?= htmlspecialchars((string) ($saStatus['client_email'] ?? '')) ?></code>.
        </div>
        <?php else: ?>
        <div class="alert alert-danger small py-2 mb-3">
            <strong>Kredensial belum siap.</strong>
            <?= htmlspecialchars((string) ($saStatus['error'] ?? 'File JSON Service Account belum valid.')) ?>
            <ol class="mb-0 mt-2 ps-3">
                <li>Unduh JSON key dari Google Cloud Console.</li>
                <li>Simpan di path yang tertera di status (mis. <code>config/google_service_account.json</code>).</li>
                <li>Isi path di form Pengaturan di atas → klik <strong>Simpan</strong>.</li>
            </ol>
        </div>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small" for="as_of">Tanggal data (as_of)</label>
                <input type="date" class="form-control" id="as_of" name="as_of" value="<?= htmlspecialchars($defaultAsOf) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>">
                <div class="form-text">Kosongkan atau invalid = kemarin. Tab Sheet tetap 7 tab utama (data untuk tanggal tersebut).</div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white">
        <button type="submit" class="btn btn-outline-success btn-sm">
            Kirim snapshot sekarang
        </button>
    </div>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small">Perintah cron</div>
    <div class="card-body small">
        <p class="mb-2"><strong>CLI (XAMPP):</strong></p>
        <pre class="bg-light p-2 rounded small mb-3"><code>php cron/laporan_snapshot.php</code></pre>
        <p class="mb-2"><strong>HTTP (hosting):</strong></p>
        <pre class="bg-light p-2 rounded small mb-0"><code><?= htmlspecialchars($cronUrl) ?></code></pre>
        <p class="text-muted mt-2 mb-0">Tab: Neraca_Pondok, Rekap_Kas_Bulanan, Tunggakan_Syahriyah, Syahriyah_12Bulan, Payroll_Pembimbing, BOS_BKU, BOS_LRA.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
