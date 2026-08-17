<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

if (isset($_SESSION['user'])) {
    $role = (string) ($_SESSION['user']['role'] ?? '');
    app_redirect($role === 'petugas_absensi' ? 'presensi/scan.php' : 'dashboard.php');
}

$stored = trim((string) app_setting($pdo, 'presensi_password', ''));
$passwordConfigured = $stored !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$passwordConfigured) {
        set_flash('error', 'Password presensi belum diatur. Minta pengurus membuka Pengaturan → Password presensi.');
        app_redirect('presensi/login.php');
    }

    $password = (string) ($_POST['password'] ?? '');
    $isValid = false;

    $info = password_get_info($stored);
    if ($info['algo'] !== 0) {
        $isValid = password_verify($password, $stored);
    } else {
        $isValid = hash_equals($stored, $password);
        if ($isValid && $password !== '') {
            save_setting($pdo, 'presensi_password', password_hash($password, PASSWORD_DEFAULT));
        }
    }

    if ($isValid && $password !== '') {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => 0,
            'nama' => 'Petugas Presensi',
            'username' => 'petugas_presensi',
            'role' => 'petugas_absensi',
            'is_super_admin' => 0,
        ];
        set_flash('success', 'Selamat datang. Silakan mulai scan presensi.');
        app_redirect('presensi/scan.php');
    }

    set_flash('error', 'Password presensi tidak sesuai.');
    app_redirect('presensi/login.php');
    exit;
}

$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$welcome = auth_portal_welcome_copy($pdo);
auth_portal_layout_begin([
    'title' => 'Petugas presensi',
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $welcome['salam_waktu'],
    'welcome_tagline' => $welcome['tagline_portal'],
    'subtitle' => 'Scan QR kehadiran santri & pembimbing. Izinkan akses kamera belakang setelah masuk.',
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'logo_url' => '',
    'card_title' => 'Masuk presensi',
    'card_meta' => 'Password dari menu Pengaturan pondok',
    'accent' => 'indigo',
]);

$err = get_flash('error');
$ok = get_flash('success');
?>
                <?php if ($err): ?>
                    <div class="alert alert-danger py-2 small" role="alert"><?= htmlspecialchars($err) ?></div>
                <?php endif; ?>
                <?php if ($ok): ?>
                    <div class="alert alert-success py-2 small" role="status"><?= htmlspecialchars($ok) ?></div>
                <?php endif; ?>
                <?php if (!$passwordConfigured): ?>
                    <div class="alert alert-warning py-2 small" role="note">
                        <strong>Belum siap.</strong> Pengurus harus mengisi <strong>Password presensi</strong> di halaman Pengaturan terlebih dahulu.
                    </div>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(app_href('/login.php')) ?>" class="auth-portal-back">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali ke portal utama
                </a>
                <form method="post" class="auth-portal-form" action="<?= htmlspecialchars(app_href('/presensi/login.php')) ?>" autocomplete="on">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="presensi-pw">Password presensi</label>
                        <div class="input-group">
                            <input id="presensi-pw" class="form-control form-control-lg" type="password" name="password" required autocomplete="current-password" <?= $passwordConfigured ? '' : 'disabled' ?> placeholder="Password dari pengurus">
                            <button class="btn btn-outline-secondary" type="button" id="presensi-pw-toggle" aria-label="Tampilkan password" <?= $passwordConfigured ? '' : 'disabled' ?>>
                                <i class="fa-regular fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-auth-primary w-100 btn-lg" <?= $passwordConfigured ? '' : 'disabled' ?>>Masuk ke scan</button>
                </form>
                <script>
                (function () {
                    var btn = document.getElementById('presensi-pw-toggle');
                    var inp = document.getElementById('presensi-pw');
                    if (!btn || !inp) return;
                    btn.addEventListener('click', function () {
                        if (inp.disabled) return;
                        var show = inp.getAttribute('type') === 'password';
                        inp.setAttribute('type', show ? 'text' : 'password');
                        btn.innerHTML = show ? '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i>' : '<i class="fa-regular fa-eye" aria-hidden="true"></i>';
                        btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
                    });
                })();
                </script>
<?php
auth_portal_layout_end([
    ['href' => '/login.php', 'label' => 'Portal utama'],
]);
