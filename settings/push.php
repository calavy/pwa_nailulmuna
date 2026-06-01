<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_fcm.php';
require_once __DIR__ . '/../helpers/push_events.php';

require_roles(['admin', 'pengurus']);

ensure_push_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan_fcm') {
        save_setting($pdo, 'fcm_enabled', isset($_POST['fcm_enabled']) ? '1' : '0');
        save_setting($pdo, 'fcm_project_id', trim((string) ($_POST['fcm_project_id'] ?? '')));
        save_setting($pdo, 'fcm_client_email', trim((string) ($_POST['fcm_client_email'] ?? '')));
        save_setting($pdo, 'fcm_private_key', trim((string) ($_POST['fcm_private_key'] ?? '')));
        save_setting($pdo, 'fcm_vapid_key', trim((string) ($_POST['fcm_vapid_key'] ?? '')));
        save_setting($pdo, 'fcm_web_api_key', trim((string) ($_POST['fcm_web_api_key'] ?? '')));
        save_setting($pdo, 'fcm_sender_id', trim((string) ($_POST['fcm_sender_id'] ?? '')));
        save_setting($pdo, 'fcm_app_id', trim((string) ($_POST['fcm_app_id'] ?? '')));
        save_setting($pdo, 'fcm_notify_mode', in_array($_POST['fcm_notify_mode'] ?? '', ['push', 'wa', 'both'], true) ? (string) $_POST['fcm_notify_mode'] : 'wa');
        save_setting($pdo, 'fcm_daily_kiai_enabled', isset($_POST['fcm_daily_kiai_enabled']) ? '1' : '0');
        save_setting($pdo, 'fcm_daily_kiai_time', trim((string) ($_POST['fcm_daily_kiai_time'] ?? '20:00')));
        set_flash('success', 'Pengaturan FCM disimpan.');
        header('Location: ' . app_href('/settings/push.php'));
        exit;
    }
    if ($action === 'test_push') {
        $n = push_notify_all_staff($pdo, 'rapat', 'Uji push FCM', 'Notifikasi uji dari pengaturan pondok. Jika ini muncul, FCM berhasil.');
        set_flash($n > 0 ? 'success' : 'error', $n > 0 ? "Push terkirim ke {$n} perangkat staff." : 'Tidak ada token staff atau FCM gagal.');
        header('Location: ' . app_href('/settings/push.php'));
        exit;
    }
    if ($action === 'test_kiai') {
        push_event_keuangan_harian_kiai($pdo, 'Uji ringkasan harian — ' . date('d/m/Y H:i'));
        set_flash('success', 'Uji push pengasuh dikirim (jika ada token).');
        header('Location: ' . app_href('/settings/push.php'));
        exit;
    }
    if ($action === 'import_json') {
        $raw = trim((string) ($_POST['firebase_json'] ?? ''));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            set_flash('error', 'JSON service account tidak valid.');
        } else {
            $projectId = trim((string) ($decoded['project_id'] ?? ''));
            $email = trim((string) ($decoded['client_email'] ?? ''));
            $key = trim((string) ($decoded['private_key'] ?? ''));
            if ($projectId === '' || $email === '' || $key === '') {
                set_flash('error', 'JSON harus berisi project_id, client_email, dan private_key.');
            } else {
                save_setting($pdo, 'fcm_enabled', '1');
                save_setting($pdo, 'fcm_project_id', $projectId);
                save_setting($pdo, 'fcm_client_email', $email);
                save_setting($pdo, 'fcm_private_key', $key);
                set_flash('success', 'Kredensial service account diimpor. Lengkapi Web API Key, App ID, Sender ID, dan VAPID lalu simpan.');
            }
        }
        header('Location: ' . app_href('/settings/push.php'));
        exit;
    }
}

$v = static fn(string $k, string $d = '') => app_setting($pdo, $k, $d);
$tokenCount = ['wali' => 0, 'staff' => 0, 'kiai' => 0];
if (table_exists($pdo, 'fcm_tokens')) {
    $st = $pdo->query("SELECT audience_type, COUNT(*) AS c FROM fcm_tokens WHERE is_active = 1 GROUP BY audience_type");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tokenCount[(string) $row['audience_type']] = (int) $row['c'];
    }
}

$pushLogs = [];
if (table_exists($pdo, 'push_logs')) {
    $pushLogs = $pdo->query('SELECT audience_type, category, title, tokens_targeted, tokens_success, is_success, created_at FROM push_logs ORDER BY id DESC LIMIT 15')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$hasLocalFirebase = is_file(__DIR__ . '/../config/firebase.local.php');

$pageTitle = 'Notifikasi & Lonceng';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/includes/settings_nav.php';
?>

<div class="mb-3">
    <h1 class="h4 mb-1">Notifikasi & Lonceng</h1>
    <p class="text-muted small mb-0">Atur cara pengiriman notifikasi. Untuk pembayaran dan pengumuman, Anda bisa pakai <strong>WhatsApp saja</strong> tanpa Firebase.</p>
    <p class="small mb-0 mt-1"><a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>">← Pusat WA Otomatis</a></p>
</div>

<div class="alert alert-success py-2 small mb-3">
    <strong>Paling mudah:</strong> pilih mode <em>WhatsApp saja</em> di bawah — notifikasi tagihan/pembayaran tetap jalan lewat WA Gateway tanpa perlu setup Firebase.
    Push FCM (lonceng browser) opsional jika ingin notifikasi langsung di layar HP.
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body text-center">
            <div class="fs-3 fw-bold text-primary"><?= (int) $tokenCount['wali'] ?></div>
            <div class="small text-muted">Perangkat wali</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body text-center">
            <div class="fs-3 fw-bold text-success"><?= (int) $tokenCount['staff'] ?></div>
            <div class="small text-muted">Perangkat pengurus</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body text-center">
            <div class="fs-3 fw-bold text-warning"><?= (int) $tokenCount['kiai'] ?></div>
            <div class="small text-muted">Perangkat pengasuh</div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6">Cara setup Firebase (sekali)</h2>
        <ol class="small mb-0 ps-3">
            <li>Buat project di <a href="https://console.firebase.google.com/" target="_blank" rel="noopener">Firebase Console</a>.</li>
            <li>Tambah <strong>Web app</strong> → salin API Key, App ID, Sender ID, Project ID.</li>
            <li>Project Settings → Cloud Messaging → <strong>Web Push certificates</strong> → salin <strong>VAPID key</strong>.</li>
            <li>Project Settings → Service accounts → Generate new private key (JSON) → salin <code>client_email</code> dan <code>private_key</code>.</li>
            <li>Centang <strong>Aktifkan FCM</strong> di bawah, simpan, lalu klik ikon <strong>lonceng</strong> di portal wali / topbar pengurus.</li>
            <li>Opsional: salin <code>config/firebase.local.example.php</code> → <code>firebase.local.php</code>.</li>
        </ol>
        <?php if ($hasLocalFirebase): ?>
            <p class="small text-success mb-0 mt-2"><i class="fa-solid fa-check me-1"></i> File <code>config/firebase.local.php</code> terdeteksi.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Impor service account (JSON)</strong></div>
    <form method="post" class="card-body">
        <input type="hidden" name="action" value="import_json">
        <label class="form-label small">Tempel JSON dari Firebase → Service accounts → Generate new private key</label>
        <textarea name="firebase_json" class="form-control form-control-sm font-monospace" rows="5" placeholder='{"project_id":"...","client_email":"...","private_key":"-----BEGIN..."}'></textarea>
        <button type="submit" class="btn btn-outline-secondary btn-sm mt-2">Impor project &amp; private key</button>
    </form>
</div>

<form method="post" class="card mb-3">
    <div class="card-header"><strong>Konfigurasi server</strong></div>
    <div class="card-body row g-3">
        <input type="hidden" name="action" value="simpan_fcm">
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="fcm_enabled" id="fcm_enabled" value="1" <?= $v('fcm_enabled') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="fcm_enabled">Aktifkan FCM Push</label>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label small fw-semibold">Mode notifikasi (disarankan: WhatsApp saja jika belum pakai Firebase)</label>
            <select name="fcm_notify_mode" class="form-select form-select-sm">
                <option value="wa" <?= $v('fcm_notify_mode', 'wa') === 'wa' ? 'selected' : '' ?>>WhatsApp saja — tanpa Firebase</option>
                <option value="both" <?= $v('fcm_notify_mode', 'wa') === 'both' ? 'selected' : '' ?>>Push lonceng + WhatsApp</option>
                <option value="push" <?= $v('fcm_notify_mode') === 'push' ? 'selected' : '' ?>>Push lonceng saja</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label small">Project ID</label>
            <input type="text" name="fcm_project_id" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_project_id')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label small">Web API Key</label>
            <input type="text" name="fcm_web_api_key" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_web_api_key')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label small">App ID</label>
            <input type="text" name="fcm_app_id" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_app_id')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label small">Sender ID</label>
            <input type="text" name="fcm_sender_id" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_sender_id')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label small">VAPID Key (Web Push)</label>
            <input type="text" name="fcm_vapid_key" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_vapid_key')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label small">Service account email</label>
            <input type="text" name="fcm_client_email" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_client_email')) ?>">
        </div>
        <div class="col-12">
            <label class="form-label small">Private key (dari JSON service account)</label>
            <textarea name="fcm_private_key" class="form-control form-control-sm font-monospace" rows="4" placeholder="-----BEGIN PRIVATE KEY----- ..."><?= htmlspecialchars($v('fcm_private_key')) ?></textarea>
        </div>
        <div class="col-md-6">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="fcm_daily_kiai_enabled" id="kiai_on" <?= $v('fcm_daily_kiai_enabled', '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label small" for="kiai_on">Ringkasan harian ke pengasuh (cron)</label>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label small">Jam ringkasan pengasuh</label>
            <input type="time" name="fcm_daily_kiai_time" class="form-control form-control-sm" value="<?= htmlspecialchars($v('fcm_daily_kiai_time', '20:00')) ?>">
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
            <?php if (isset($_SESSION['user'])): ?>
            <button type="button" class="btn btn-outline-success btn-sm ms-2" id="btn-fcm-subscribe"><i class="fa-solid fa-bell me-1"></i> Aktifkan notifikasi di perangkat ini</button>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2">
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="test_push">
            <button type="submit" class="btn btn-outline-primary btn-sm">Uji push pengurus</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="test_kiai">
            <button type="submit" class="btn btn-outline-warning btn-sm">Uji push pengasuh</button>
        </form>
        <span class="small text-muted align-self-center">Status: <?= push_fcm_enabled($pdo) ? '<span class="text-success">FCM siap</span>' : '<span class="text-danger">Belum dikonfigurasi</span>' ?></span>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Kategori notifikasi</strong></div>
    <div class="card-body small row">
        <div class="col-md-4">
            <strong>Wali</strong>
            <ul class="mb-0"><li>Syahriah</li><li>Izin keluar anak</li><li>Laporan sakit</li></ul>
        </div>
        <div class="col-md-4">
            <strong>Pengurus</strong>
            <ul class="mb-0"><li>Pengajuan izin</li><li>Rapat</li><li>Tugas keamanan</li></ul>
        </div>
        <div class="col-md-4">
            <strong>Pengasuh</strong>
            <ul class="mb-0"><li>Ringkasan keuangan harian</li><li>Pelanggaran berat</li></ul>
        </div>
    </div>
</div>

<?php if ($pushLogs !== []): ?>
<div class="card mt-3">
    <div class="card-header"><strong>Log push terakhir</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
                <tr>
                    <th>Waktu</th>
                    <th>Audiens</th>
                    <th>Kategori</th>
                    <th>Judul</th>
                    <th class="text-end">Token OK</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pushLogs as $lg): ?>
                <tr>
                    <td class="small text-muted"><?= htmlspecialchars((string) ($lg['created_at'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($lg['audience_type'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($lg['category'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($lg['title'] ?? '')) ?></td>
                    <td class="text-end small <?= (int) ($lg['is_success'] ?? 0) === 1 ? 'text-success' : 'text-danger' ?>">
                        <?= (int) ($lg['tokens_success'] ?? 0) ?>/<?= (int) ($lg['tokens_targeted'] ?? 0) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/partials/push_fcm_bootstrap.php';
require_once __DIR__ . '/../includes/footer.php';
