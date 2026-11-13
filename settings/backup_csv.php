<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/db_backup_csv.php';
require_once __DIR__ . '/../helpers/backup_crypto.php';
require_once __DIR__ . '/../helpers/backup_mingguan.php';

require_roles(['admin']);
require_super_admin();

ensure_pondok_settings_defaults($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unduh_backup_csv') {
    $confirm = strtoupper(trim((string) ($_POST['confirm_text'] ?? '')));
    if ($confirm !== 'UNDUH') {
        set_flash('error', 'Ketik UNDUH (huruf besar) untuk mengonfirmasi pengunduhan.');
        header('Location: ' . app_href('/settings/backup_csv.php'));
        exit;
    }
    try {
        db_backup_csv_send($pdo);
    } catch (Throwable $e) {
        set_flash('error', 'Backup gagal: ' . $e->getMessage());
        header('Location: ' . app_href('/settings/backup_csv.php'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'uji_kunci') {
    $pem = trim((string) ($_POST['backup_mingguan_public_key'] ?? ''));
    $plainPath = '';
    $sealedPath = '';
    try {
        $pem = backup_crypto_assert_public_only($pem);
        $plainPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pnm_uji_' . bin2hex(random_bytes(4)) . '.txt';
        $sealedPath = $plainPath . '.pnm1';
        $sentence = 'uji-kunci-backup-nailulmuna';
        if (file_put_contents($plainPath, $sentence) === false) {
            throw new RuntimeException('Gagal menulis kalimat percobaan.');
        }
        backup_crypto_seal_file($plainPath, $sealedPath, $pem);
        $sealed = file_get_contents($sealedPath);
        if ($sealed === false || str_contains($sealed, $sentence)) {
            throw new RuntimeException('Percobaan kunci gagal: kalimat masih terbaca.');
        }
        set_flash('success', 'Kunci publik bisa mengunci. Sidik ' . backup_crypto_fingerprint($pem) . '. Server tidak membuka hasilnya.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    if ($plainPath !== '' && is_file($plainPath)) {
        @unlink($plainPath);
    }
    if ($sealedPath !== '' && is_file($sealedPath)) {
        @unlink($sealedPath);
    }
    header('Location: ' . app_href('/settings/backup_csv.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan_backup_mingguan') {
    try {
        $pem = trim((string) ($_POST['backup_mingguan_public_key'] ?? ''));
        if ($pem !== '') {
            $pem = backup_crypto_assert_public_only($pem);
        }
        save_setting($pdo, 'backup_mingguan_public_key', $pem);

        $host = trim((string) ($_POST['backup_mingguan_smtp_host'] ?? ''));
        $port = (int) ($_POST['backup_mingguan_smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Port SMTP tidak valid.');
        }
        $user = trim((string) ($_POST['backup_mingguan_smtp_user'] ?? ''));
        $from = trim((string) ($_POST['backup_mingguan_smtp_from'] ?? ''));
        $adminEmail = trim((string) ($_POST['backup_mingguan_email_admin'] ?? ''));
        $ketuaEmail = trim((string) ($_POST['backup_mingguan_email_ketua'] ?? ''));
        foreach (['Pengirim' => $from, 'Email admin' => $adminEmail, 'Email Ketua' => $ketuaEmail] as $label => $email) {
            if ($email === '') {
                continue;
            }
            if (preg_match('/[\r\n]/', $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException($label . ' bukan alamat email yang valid.');
            }
        }
        if (preg_match('/[\r\n]/', $host)) {
            throw new RuntimeException('Host SMTP tidak valid.');
        }
        save_setting($pdo, 'backup_mingguan_smtp_host', $host);
        save_setting($pdo, 'backup_mingguan_smtp_port', (string) $port);
        save_setting($pdo, 'backup_mingguan_smtp_user', $user);
        save_setting($pdo, 'backup_mingguan_smtp_from', $from);
        save_setting($pdo, 'backup_mingguan_email_admin', $adminEmail);
        save_setting($pdo, 'backup_mingguan_email_ketua', $ketuaEmail);
        $smtpPass = (string) ($_POST['backup_mingguan_smtp_password'] ?? '');
        if ($smtpPass !== '') {
            save_setting($pdo, 'backup_mingguan_smtp_password', $smtpPass);
        }

        $wa = trim((string) ($_POST['backup_mingguan_wa_gagal'] ?? ''));
        if ($wa !== '' && !preg_match('/^[0-9+\s-]{8,20}$/', $wa)) {
            throw new RuntimeException('Nomor WhatsApp gagal tidak valid.');
        }
        save_setting($pdo, 'backup_mingguan_wa_gagal', $wa);

        $hari = (int) ($_POST['backup_mingguan_hari'] ?? 0);
        if ($hari < 0 || $hari > 6) {
            $hari = 0;
        }
        save_setting($pdo, 'backup_mingguan_hari', (string) $hari);

        $cronKey = trim((string) ($_POST['backup_mingguan_cron_key'] ?? ''));
        if (preg_match('/[\r\n\s]/', $cronKey)) {
            throw new RuntimeException('Kunci cron tidak boleh berisi spasi.');
        }
        save_setting($pdo, 'backup_mingguan_cron_key', $cronKey);
        set_flash('success', 'Pengaturan backup mingguan disimpan. Kunci pembuka tidak disimpan.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    header('Location: ' . app_href('/settings/backup_csv.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'jalankan_backup_mingguan') {
    $confirm = strtoupper(trim((string) ($_POST['confirm_jalan'] ?? '')));
    if ($confirm !== 'JALANKAN') {
        set_flash('error', 'Ketik JALANKAN (huruf besar) untuk menjalankan backup sekarang.');
        header('Location: ' . app_href('/settings/backup_csv.php'));
        exit;
    }
    $result = backup_mingguan_run($pdo, true);
    if ($result['ok']) {
        set_flash('success', 'Backup mingguan selesai. ' . (string) ($result['note'] ?? ''));
    } else {
        set_flash('error', 'Backup mingguan gagal: ' . (string) ($result['note'] ?? ''));
    }
    header('Location: ' . app_href('/settings/backup_csv.php'));
    exit;
}

$v = static function (string $key, string $default = '') use ($pdo): string {
    return (string) app_setting($pdo, $key, $default);
};
$publicKey = $v('backup_mingguan_public_key');
$fingerprint = '';
$fingerprintError = '';
if ($publicKey !== '') {
    try {
        $fingerprint = backup_crypto_fingerprint($publicKey);
    } catch (Throwable $e) {
        $fingerprintError = $e->getMessage();
    }
}
$lastError = trim($v('backup_mingguan_last_error'));
$lastOk = trim($v('backup_mingguan_last_ok'));
$lastRun = trim($v('backup_mingguan_last_run_at'));
$hariNow = (int) $v('backup_mingguan_hari', '0');
$hariLabel = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$smtpPassSet = $v('backup_mingguan_smtp_password') !== '';
$cronUrl = backup_mingguan_cron_url($pdo);
$sealedFiles = glob(backup_mingguan_dir() . DIRECTORY_SEPARATOR . 'Backup_Lengkap_*.pnm1');
if ($sealedFiles === false) {
    $sealedFiles = [];
}
rsort($sealedFiles, SORT_STRING);

$pageTitle = 'Backup CSV Database';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/backup_csv.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-file-zipper me-1 text-primary"></i> Backup CSV Database</h1>
    <p class="text-muted mb-0 small">Hanya <strong>super admin</strong>. Unduh ZIP periksa, atau jadwalkan backup lengkap yang terkunci. Email hanya mengirim ZIP periksa. Arsip lengkap tidak dikirim lewat email dan tidak dikirim ke Google Drive.</p>
</div>

<?php if ($lastError !== ''): ?>
    <div class="alert alert-danger">
        <strong>Backup mingguan gagal.</strong>
        <?= $lastRun !== '' ? ' Percobaan ' . htmlspecialchars($lastRun) . '.' : '' ?>
        <?= htmlspecialchars($lastError) ?>
    </div>
<?php elseif ($lastOk !== ''): ?>
    <div class="alert alert-success">
        Backup mingguan terakhir berhasil<?= $lastRun !== '' ? ' pada ' . htmlspecialchars($lastRun) : '' ?>:
        <?= htmlspecialchars($lastOk) ?>.
    </div>
<?php else: ?>
    <div class="alert alert-secondary">Backup mingguan belum pernah berhasil.</div>
<?php endif; ?>

<div class="alert alert-warning">
    ZIP unduhan manual ini untuk diperiksa, bukan dump SQL.
    Indeks dan foreign key tidak ikut, dan nilai NULL menjadi sel kosong.
    Identitas, alamat, nomor kontak, isi pesan, kesehatan, foto, rekening, sandi, PIN, token, dan kunci dikosongkan di CSV unduhan ini. Nilainya di database tidak berubah.
    Di dalam ZIP ada README.txt yang memuat tanggal ekspor dan pemisah titik koma.
    Arsip lengkap mingguan terkunci disalin sendiri ke luar hosting. Kunci pembuka dipegang Pengasuh dan Ketua. Jangan menaruh kunci pembuka di server, dan jangan mengirim arsip lengkap lewat email.
</div>

<form method="post" class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="fa-solid fa-file-csv me-1 text-success"></i> Unduh ZIP periksa</div>
    <div class="card-body">
        <p class="small text-muted">Satu file CSV per tabel, pemisah titik koma, plus <code>_manifest.csv</code> dan <code>README.txt</code>. View tidak ikut. Kolom sensitif dikosongkan.</p>
        <label class="form-label small mb-1" for="confirm_text">Ketik <strong>UNDUH</strong> untuk konfirmasi</label>
        <input type="text" class="form-control mb-3" id="confirm_text" name="confirm_text" autocomplete="off" required placeholder="UNDUH" style="max-width:16rem">
        <input type="hidden" name="action" value="unduh_backup_csv">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-download me-1"></i> Unduh ZIP CSV</button>
    </div>
</form>

<form method="post" class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="fa-solid fa-key me-1"></i> Backup mingguan terkunci</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Buat pasangan kunci di komputer Anda, bukan di server.
            Berkas kunci pembuka disimpan oleh Pengasuh dan Ketua.
            Yang ditempel di sini hanya isi <code>kunci_publik.pem</code>.
            Arsip lengkap yang terkunci disalin sendiri ke luar hosting dan tidak dikirim lewat email.
        </p>
        <pre class="small bg-light border rounded p-2">openssl genpkey -algorithm RSA -pkeyopt rsa_key_bits:2048 -out kunci_pembuka.pem
openssl rsa -pubout -in kunci_pembuka.pem -out kunci_publik.pem</pre>
        <label class="form-label small" for="backup_mingguan_public_key">Kunci publik</label>
        <textarea class="form-control font-monospace small mb-2" id="backup_mingguan_public_key" name="backup_mingguan_public_key" rows="6" spellcheck="false"><?= htmlspecialchars($publicKey) ?></textarea>
        <?php if ($fingerprint !== ''): ?>
            <p class="small text-success mb-3">Sidik kunci publik: <code><?= htmlspecialchars($fingerprint) ?></code></p>
        <?php elseif ($fingerprintError !== ''): ?>
            <p class="small text-danger mb-3"><?= htmlspecialchars($fingerprintError) ?></p>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small" for="backup_mingguan_hari">Hari jalan</label>
                <select class="form-select" id="backup_mingguan_hari" name="backup_mingguan_hari">
                    <?php foreach ($hariLabel as $idx => $nama): ?>
                        <option value="<?= $idx ?>"<?= $hariNow === $idx ? ' selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label small" for="backup_mingguan_cron_key">Kunci cron</label>
                <input type="text" class="form-control font-monospace" id="backup_mingguan_cron_key" name="backup_mingguan_cron_key" value="<?= htmlspecialchars($v('backup_mingguan_cron_key')) ?>" autocomplete="off">
            </div>
            <div class="col-12">
                <label class="form-label small">URL cron</label>
                <input type="text" class="form-control font-monospace small" readonly value="<?= htmlspecialchars($cronUrl) ?>">
                <div class="form-text">Hosting boleh memanggil URL ini tiap hari. Kirim hanya pada hari yang dipilih, sekali sehari.<?= $v('backup_mingguan_cron_key') === '' ? ' Isi kunci cron supaya URL ini tidak bisa dipanggil orang lain.' : '' ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="backup_mingguan_smtp_host">Host SMTP</label>
                <input type="text" class="form-control" id="backup_mingguan_smtp_host" name="backup_mingguan_smtp_host" value="<?= htmlspecialchars($v('backup_mingguan_smtp_host')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="backup_mingguan_smtp_port">Port</label>
                <input type="number" class="form-control" id="backup_mingguan_smtp_port" name="backup_mingguan_smtp_port" value="<?= htmlspecialchars($v('backup_mingguan_smtp_port', '587')) ?>" min="1" max="65535">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="backup_mingguan_smtp_user">User SMTP</label>
                <input type="text" class="form-control" id="backup_mingguan_smtp_user" name="backup_mingguan_smtp_user" value="<?= htmlspecialchars($v('backup_mingguan_smtp_user')) ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="backup_mingguan_smtp_password">Sandi SMTP</label>
                <input type="password" class="form-control" id="backup_mingguan_smtp_password" name="backup_mingguan_smtp_password" value="" autocomplete="new-password" placeholder="<?= $smtpPassSet ? 'sudah diisi, kosongkan jika tetap' : 'belum diisi' ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="backup_mingguan_smtp_from">Email pengirim</label>
                <input type="email" class="form-control" id="backup_mingguan_smtp_from" name="backup_mingguan_smtp_from" value="<?= htmlspecialchars($v('backup_mingguan_smtp_from')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="backup_mingguan_email_admin">Email Pengasuh / admin (hanya ZIP periksa)</label>
                <input type="email" class="form-control" id="backup_mingguan_email_admin" name="backup_mingguan_email_admin" value="<?= htmlspecialchars($v('backup_mingguan_email_admin')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="backup_mingguan_email_ketua">Email Ketua (catatan; tidak menerima lampiran)</label>
                <input type="email" class="form-control" id="backup_mingguan_email_ketua" name="backup_mingguan_email_ketua" value="<?= htmlspecialchars($v('backup_mingguan_email_ketua')) ?>">
                <div class="form-text">Alamat ini dicatat saja. Surat mingguan tidak dikirim ke Ketua. Arsip lengkap disalin sendiri ke luar server.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="backup_mingguan_wa_gagal">WhatsApp jika gagal</label>
                <input type="text" class="form-control" id="backup_mingguan_wa_gagal" name="backup_mingguan_wa_gagal" value="<?= htmlspecialchars($v('backup_mingguan_wa_gagal')) ?>" placeholder="08xxxxxxxxxx">
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" class="btn btn-primary" name="action" value="simpan_backup_mingguan">Simpan</button>
            <button type="submit" class="btn btn-outline-secondary" name="action" value="uji_kunci">Coba kunci</button>
        </div>
        <p class="small text-muted mt-3 mb-0">Tombol coba kunci hanya mengunci satu kalimat, lalu menghapusnya. Server tidak membukanya.</p>
    </div>
</form>

<form method="post" class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Jalankan sekarang</div>
    <div class="card-body">
        <p class="small text-muted">Membuat arsip lengkap terkunci dan menyimpannya di folder hosting yang tidak bisa dibuka dari browser. Salin berkas itu ke luar server; kunci pembuka dipegang Pengasuh dan Ketua. Email hanya mengirim ZIP periksa ke admin.</p>
        <label class="form-label small mb-1" for="confirm_jalan">Ketik <strong>JALANKAN</strong></label>
        <input type="text" class="form-control mb-3" id="confirm_jalan" name="confirm_jalan" autocomplete="off" required placeholder="JALANKAN" style="max-width:16rem">
        <input type="hidden" name="action" value="jalankan_backup_mingguan">
        <button type="submit" class="btn btn-outline-primary">Jalankan backup mingguan</button>
    </div>
</form>

<?php if ($sealedFiles !== []): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold">Arsip terkunci di server</div>
        <div class="card-body small text-muted border-bottom">Salin berkas ini ke luar hosting. Tidak dikirim lewat email. Kunci pembuka dipegang Pengasuh dan Ketua.</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($sealedFiles as $path): ?>
                <li class="list-group-item small d-flex justify-content-between">
                    <span><?= htmlspecialchars(basename((string) $path)) ?></span>
                    <span class="text-muted"><?= number_format((int) filesize((string) $path)) ?> byte</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
