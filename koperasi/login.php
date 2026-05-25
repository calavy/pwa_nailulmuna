<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

cashless_koperasi_ensure_schema($pdo);

$koperasiId = cashless_koperasi_resolve_id_from_request();
if ($koperasiId < 1 || $koperasiId > 3) {
    app_redirect('koperasi/index.php');
}

$koperasi = cashless_koperasi_by_id($pdo, $koperasiId);
if (!is_array($koperasi)) {
    set_flash('error', 'Koperasi tidak ditemukan.');
    app_redirect('koperasi/index.php');
}

if (cashless_koperasi_session_active() && (int) ($_SESSION['koperasi_cashless']['id'] ?? 0) === $koperasiId) {
    app_redirect('koperasi/scan.php');
}

$stored = trim((string) app_setting($pdo, cashless_koperasi_password_setting_key($koperasiId), ''));
$passwordConfigured = $stored !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$passwordConfigured) {
        set_flash('error', 'Password koperasi belum diatur. Minta pengurus membuka Cashless & Uang Saku → Password koperasi.');
        app_redirect('koperasi/login.php?k=' . $koperasiId);
    }
    $password = (string) ($_POST['password'] ?? '');
    if (cashless_koperasi_login($pdo, $koperasiId, $password)) {
        set_flash('success', 'Selamat datang. Silakan mulai scan cashless.');
        app_redirect('koperasi/scan.php');
    }
    set_flash('error', 'Password koperasi tidak sesuai.');
    app_redirect('koperasi/login.php?k=' . $koperasiId);
}

$brandNama = auth_portal_brand_nama($pdo);
$welcome = auth_portal_welcome_copy($pdo);

auth_portal_layout_begin([
    'title' => 'Login ' . (string) $koperasi['nama'],
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $welcome['salam_waktu'],
    'welcome_tagline' => (string) $koperasi['nama'] . ' — ' . $welcome['tagline_portal'],
    'subtitle' => 'Masuk sebagai petugas koperasi untuk scan belanja cashless santri.',
    'nama_ponpes' => $brandNama,
    'accent' => 'teal',
]);

$err = get_flash('error');
$ok = get_flash('success');
?>
<?php if ($err): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>
<?php if (!$passwordConfigured): ?>
    <div class="alert alert-warning py-2 small">
        <strong>Belum siap.</strong> Pengurus harus mengisi password koperasi di halaman <strong>Cashless &amp; Uang Saku</strong> terlebih dahulu.
    </div>
<?php endif; ?>
<form method="post" action="<?= htmlspecialchars(app_href('/koperasi/login.php?k=' . $koperasiId)) ?>">
    <div class="mb-3">
        <label class="form-label fw-semibold" for="kop-pw">Password koperasi</label>
        <input id="kop-pw" class="form-control form-control-lg" type="password" name="password" required autocomplete="current-password" <?= $passwordConfigured ? '' : 'disabled' ?> placeholder="Password dari pengurus">
    </div>
    <button type="submit" class="btn btn-auth-primary w-100 btn-lg" <?= $passwordConfigured ? '' : 'disabled' ?>>Masuk ke scan</button>
</form>
<p class="small text-muted text-center mt-3 mb-0">
    <a href="<?= htmlspecialchars(app_href('/koperasi/index.php')) ?>">← Pilih koperasi lain</a>
</p>
<?php
auth_portal_layout_end([
    ['href' => '/login.php', 'label' => 'Login pengurus / peran lain'],
]);
