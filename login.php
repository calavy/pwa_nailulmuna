<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/helpers/user_profil.php';
require_once __DIR__ . '/helpers/login_pembimbing.php';
require_once __DIR__ . '/helpers/munawib.php';
require_once __DIR__ . '/includes/auth_portal_layout.php';

// Akses cepat "Scan Presensi" dari portal pembimbing — tanpa password.
// Membentuk sesi sementara sebagai petugas_absensi lalu lompat ke halaman scan.
// Diletakkan SEBELUM pengecekan sesi agar tetap berfungsi walau ada sesi lama.
$_pbScanPeran = strtolower(trim((string) ($_GET['peran'] ?? '')));
$_pbScanAct = strtolower(trim((string) ($_GET['act'] ?? '')));
if (
    $_pbScanPeran === 'pembimbing'
    && $_pbScanAct === 'scan'
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
) {
    $_pbCurrentRole = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    // Kalau sudah login sebagai pembimbing/pengurus/petugas, langsung saja ke
    // halaman scan — tidak perlu downgrade sesi.
    if (in_array($_pbCurrentRole, ['pembimbing', 'pengurus', 'admin', 'petugas_absensi'], true)) {
        app_redirect('presensi/scan.php');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'id' => 0,
        'nama' => 'Pembimbing — Scan Presensi',
        'username' => 'pembimbing_scan',
        'role' => 'petugas_absensi',
        'is_super_admin' => 0,
    ];
    if (function_exists('set_flash')) {
        set_flash('success', 'Silakan mulai scan presensi & keaktifan.');
    }
    app_redirect('presensi/scan.php');
}

if (isset($_SESSION['user']) && $pdo instanceof PDO) {
    app_post_login_redirect($pdo);
}

$peran = strtolower(trim((string) ($_GET['peran'] ?? $_POST['peran'] ?? '')));
$peranValid = ['pengurus', 'petugas', 'wali', 'pembimbing'];
if ($peran !== '' && !in_array($peran, $peranValid, true)) {
    $peran = '';
}

if ($peran === 'wali') {
    app_redirect('wali/login.php');
}

if ($peran === 'petugas' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('presensi/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($peran, ['pengurus', 'pembimbing'], true)) {
    $loginMethod = strtolower(trim((string) ($_POST['login_method'] ?? 'password')));
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $qrCode = trim((string) ($_POST['qr_code'] ?? ''));
    $isValidLogin = false;
    $userName = 'Administrator';
    $userRow = null;

    if (table_exists($pdo, 'users')) {
        user_profil_ensure_schema($pdo);
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
        // Perluas enum role agar 'pembimbing' & 'kiai' valid pada DB existing.
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
        } catch (PDOException $e) { /* abaikan jika MySQL versi lama */ }
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");

        if ($peran === 'pembimbing' && $loginMethod === 'qr' && $qrCode !== '') {
            if (table_exists($pdo, 'pembimbing')) {
                // Login via scan QR kartu pembimbing — cocokkan ke kolom qr/nip,
                // lalu ambil akun users yang username-nya = pembimbing.nip.
                $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
                    ? ' AND COALESCE(p.is_aktif, 1) = 1'
                    : '';
                $stmtPb = $pdo->prepare('
                    SELECT p.id AS pembimbing_id, p.nip, p.nama_pembimbing
                    FROM pembimbing p
                    WHERE (p.qr = :code OR p.nip = :code)' . $aktifSql . '
                    LIMIT 1
                ');
                $stmtPb->execute(['code' => $qrCode]);
                $pbRow = $stmtPb->fetch();
                if ($pbRow) {
                    $stmtUser = $pdo->prepare('
                        SELECT id, nama, username, role, is_super_admin, foto_profil
                        FROM users
                        WHERE TRIM(username) = :nip
                        LIMIT 1
                    ');
                    $stmtUser->execute(['nip' => trim((string) $pbRow['nip'])]);
                    $userRow = $stmtUser->fetch();
                    if ($userRow) {
                        $isValidLogin = true;
                        $userName = (string) ($userRow['nama'] ?? $pbRow['nama_pembimbing']);
                        $username = (string) $userRow['username'];
                    } else {
                        set_flash('error', 'Kartu QR dikenali (' . (string) $pbRow['nama_pembimbing'] . '), tetapi akun login pembimbing belum dibuat. Hubungi pengurus.');
                        header('Location: ' . app_url('login.php') . '?peran=' . urlencode($peran) . '&act=portal');
                        exit;
                    }
                }
            }
            if (!$isValidLogin) {
                munawib_ensure_schema($pdo);
                $mwLogin = munawib_buat_sesi_portal($pdo, $qrCode);
                if ($mwLogin['ok'] && isset($mwLogin['session']['user']) && is_array($mwLogin['session']['user'])) {
                    session_regenerate_id(true);
                    $_SESSION['user'] = $mwLogin['session']['user'];
                    $_SESSION['munawib_id'] = (int) ($mwLogin['session']['munawib_id'] ?? 0);
                    $_SESSION['munawib_tingkatan'] = $mwLogin['session']['munawib_tingkatan'] ?? [];
                    $_SESSION['munawib_pembimbing_id'] = (int) ($mwLogin['session']['munawib_pembimbing_id'] ?? 0);
                    set_flash('success', 'Kartu munawib dikenali. Kelas: ' . implode(', ', $_SESSION['munawib_tingkatan']));
                    app_redirect('pembimbing/dashboard.php');
                }
                if (!$isValidLogin && ($mwLogin['message'] ?? '') !== '' && str_contains((string) $mwLogin['message'], 'penugasan')) {
                    set_flash('error', (string) $mwLogin['message']);
                    header('Location: ' . app_url('login.php') . '?peran=' . urlencode($peran) . '&act=portal');
                    exit;
                }
            }
        }

        if (!$isValidLogin && $loginMethod !== 'qr') {
            $statement = $pdo->prepare('SELECT id, nama, username, password, role, is_super_admin, foto_profil FROM users WHERE username = :username LIMIT 1');
            $statement->execute(['username' => $username]);
            $userRow = $statement->fetch();

            if ($userRow && password_verify($password, $userRow['password'])) {
                $isValidLogin = true;
                $userName = $userRow['nama'];
            }
        }
    }

    if (!$isValidLogin && $loginMethod !== 'qr' && app_is_local_dev() && $username === 'admin' && $password === 'admin123') {
        $isValidLogin = true;
    }

    if ($isValidLogin) {
        session_regenerate_id(true);
        $isSuperAdmin = (int) ($userRow['is_super_admin'] ?? 0) === 1;
        if ($username === 'admin') {
            $isSuperAdmin = true;
        }
        $userId = (int) ($userRow['id'] ?? 1);

        // Role efektif: jika user login lewat portal "Pembimbing" DAN
        // username mereka memang terdaftar sebagai pembimbing (lewat NIP /
        // QR), paksa role di session menjadi 'pembimbing'. Tujuannya supaya
        // routing & menu mengikuti role baru. Sekaligus simpan permanen ke
        // tabel users.
        //
        // Pengaman:
        //  - super admin tidak pernah diturunkan role-nya
        //  - akun yang tidak ada di tabel `pembimbing` tetap pakai role asli
        //    (mis. pengurus biasa yang memilih portal pembimbing tanpa
        //    benar-benar tercatat sebagai pembimbing → tetap pengurus)
        $sessionRole = (string) ($userRow['role'] ?? 'admin');
        $isRegisteredPembimbing = false;
        if ($peran === 'pembimbing' && !$isSuperAdmin && $username !== '' && table_exists($pdo, 'pembimbing')) {
            $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
                ? ' AND COALESCE(is_aktif, 1) = 1'
                : '';
            $chk = $pdo->prepare('SELECT 1 FROM pembimbing WHERE TRIM(nip) = :u' . $aktifSql . ' LIMIT 1');
            $chk->execute(['u' => $username]);
            $isRegisteredPembimbing = (bool) $chk->fetchColumn();
        }
        unset($_SESSION['munawib_id'], $_SESSION['munawib_tingkatan'], $_SESSION['munawib_pembimbing_id']);

        if ($isRegisteredPembimbing) {
            $sessionRole = 'pembimbing';
            if ($userId > 0) {
                try {
                    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id AND COALESCE(is_super_admin, 0) = 0')
                        ->execute(['r' => 'pembimbing', 'id' => $userId]);
                } catch (PDOException $e) { /* abaikan */ }
            }
        }

        $_SESSION['user'] = [
            'id' => $userId,
            'nama' => $userName,
            'username' => $username,
            'role' => $sessionRole,
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
            'foto_profil' => trim((string) ($userRow['foto_profil'] ?? '')),
        ];
        if ($isRegisteredPembimbing && $userId > 0) {
            login_pembimbing_ensure_acl($pdo, $userId);
        }
        set_flash('success', $loginMethod === 'qr' ? 'Scan kartu berhasil. Selamat datang.' : 'Login berhasil.');
        if ($isRegisteredPembimbing) {
            app_redirect('pembimbing/dashboard.php');
        }
        app_post_login_redirect($pdo);
    }

    set_flash('error', $loginMethod === 'qr'
        ? 'Kartu QR tidak dikenali (pembimbing/munawib) atau tidak aktif.'
        : 'Username atau password salah.');
    header('Location: ' . app_url('login.php') . '?peran=' . urlencode($peran));
    exit;
}

$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$welcome = auth_portal_welcome_copy($pdo);
$portalHeadline = 'Selamat Datang di Portal Digital Ponpes API Nailul Muna';
$portalFormalBody = auth_portal_formal_body();
$portalHintMobile = 'Silakan ketuk dan pilih pintu masuk di bawah sesuai klasifikasi portal Anda.';
$portalHintDesktop = 'Silakan klik dan pilih pintu masuk di samping sesuai klasifikasi portal Anda.';
$peranLabel = $peran === 'pembimbing' ? 'Pembimbing' : ($peran === 'pengurus' ? 'Pengurus / Admin' : '');

$pbActParam = strtolower(trim((string) ($_GET['act'] ?? '')));
$pbCardTitle = 'Masuk ke akun';
if ($peran === 'pembimbing') {
    $pbCardTitle = $pbActParam === 'portal' ? 'Masuk Portal Pembimbing' : 'Pilih layanan pembimbing';
}

auth_portal_layout_begin([
    'title' => $peran === '' ? 'Portal Masuk' : 'Login ' . $peranLabel,
    'headline' => $peran === '' ? $portalHeadline : '',
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $peran === '' ? '' : $welcome['salam_waktu'],
    'welcome_tagline' => $peran === '' ? '' : $welcome['tagline_portal'],
    'formal_body' => $peran === '' ? $portalFormalBody : '',
    'subtitle_mobile' => $peran === '' ? $portalHintMobile : '',
    'subtitle_desktop' => $peran === '' ? $portalHintDesktop : '',
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'logo_url' => '',
    'layout' => $peran === '' ? 'split' : 'stack',
    'card_title' => $peran === '' ? 'Pilih cara masuk' : $pbCardTitle,
    'accent' => 'teal',
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

                <?php if ($peran === ''): ?>
                    <p class="small text-muted mb-3 mb-md-2">Pilih portal sesuai peran Anda. Semua layanan memakai satu pintu masuk yang rapi.</p>
                    <div class="auth-portal-role-grid">
                        <?php
                        auth_portal_role_link([
                            'href' => app_href('/login.php?peran=pengurus'),
                            'icon' => 'fa-user-tie',
                            'icon_mod' => 'pengurus',
                            'title' => 'Pengurus / Admin',
                            'desc' => 'Username & password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/login.php?peran=pembimbing'),
                            'icon' => 'fa-chalkboard-user',
                            'icon_mod' => 'pembimbing',
                            'title' => 'Pembimbing',
                            'desc' => 'QR pembimbing/munawib · NIP & password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/presensi/login.php'),
                            'icon' => 'fa-qrcode',
                            'icon_mod' => 'presensi',
                            'title' => 'Petugas presensi',
                            'desc' => 'Password presensi',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/wali/login.php'),
                            'icon' => 'fa-mobile-screen-button',
                            'icon_mod' => 'wali',
                            'title' => 'Portal wali',
                            'desc' => 'NIS · PIN wali santri',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/santri_portal/login.php'),
                            'icon' => 'fa-user-graduate',
                            'icon_mod' => 'santri',
                            'title' => 'Portal santri',
                            'desc' => 'NIS · PIN santri',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/koperasi/index.php'),
                            'icon' => 'fa-store',
                            'icon_mod' => 'koperasi',
                            'title' => 'Koperasi cashless',
                            'desc' => 'Pilih koperasi · password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/mukimin/login.php'),
                            'icon' => 'fa-book-open',
                            'icon_mod' => 'mukimin',
                            'title' => 'Portal mukimin',
                            'desc' => 'Alumni · username & password',
                            'full' => true,
                        ]);
                        ?>
                    </div>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_href('/login.php')) ?>" class="auth-portal-back">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali pilih peran
                    </a>

                    <?php if ($peran === 'pembimbing'): ?>
                        <?php $pbAction = strtolower(trim((string) ($_GET['act'] ?? ''))); ?>

                        <?php if ($pbAction === ''): ?>
                            <p class="small text-muted mb-3">
                                Silakan pilih cara masuk yang Anda butuhkan.
                            </p>
                            <div class="login-pb-options">
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=scan')) ?>" class="login-pb-option login-pb-option--scan">
                                    <span class="login-pb-option__icon" aria-hidden="true">
                                        <i class="fa-solid fa-qrcode"></i>
                                    </span>
                                    <span class="login-pb-option__text">
                                        <strong>Scan Presensi</strong>
                                        <span>Langsung scan tanpa password — catat presensi &amp; keaktifan santri.</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </span>
                                </a>
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=portal')) ?>" class="login-pb-option login-pb-option--portal">
                                    <span class="login-pb-option__icon" aria-hidden="true">
                                        <i class="fa-solid fa-chalkboard-user"></i>
                                    </span>
                                    <span class="login-pb-option__text">
                                        <strong>Masuk Portal Pembimbing</strong>
                                        <span>Dashboard tingkatan, ikhtibar, penilaian, izin pembimbing.</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </span>
                                </a>
                            </div>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing')) ?>" class="auth-portal-back">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Pilih cara masuk lain
                            </a>
                            <div class="login-pb-qr">
                                <div class="login-pb-qr__head">
                                    <span class="login-pb-qr__title">
                                        <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>
                                        Scan kartu pembimbing / munawib
                                    </span>
                                    <span id="login-pb-status" class="login-pb-qr__status is-waiting">Menyiapkan kamera…</span>
                                </div>
                                <div class="login-pb-qr__viewport">
                                    <div id="login-pb-reader" aria-label="Kamera scan kartu pembimbing"></div>
                                    <div class="login-pb-qr__frame" aria-hidden="true">
                                        <div class="login-pb-qr__frame-box"></div>
                                    </div>
                                    <div id="login-pb-error" class="login-pb-qr__error d-none" role="alert">
                                        <div>
                                            <p class="fw-semibold mb-2" id="login-pb-error-text">Gagal membuka kamera</p>
                                            <p class="small opacity-75 mb-3">Izinkan akses kamera lalu ketuk Ulangi.</p>
                                            <button type="button" class="btn btn-light btn-sm" id="login-pb-retry">Coba lagi</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="login-pb-qr__hint small text-muted">
                                    Arahkan QR kartu pembimbing atau munawib ke kotak hijau · masuk ke portal kelas yang diwakili.
                                </div>
                                <div class="login-pb-qr__controls">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="login-pb-flip">
                                        <i class="fa-solid fa-camera-rotate me-1"></i> Ganti kamera
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="login-pb-restart">
                                        <i class="fa-solid fa-rotate-right me-1"></i> Ulangi
                                    </button>
                                </div>
                                <form method="post" id="login-pb-qr-form" class="visually-hidden" autocomplete="off">
                                    <input type="hidden" name="peran" value="pembimbing">
                                    <input type="hidden" name="login_method" value="qr">
                                    <input type="text" name="qr_code" id="login-pb-qr-code" readonly>
                                </form>
                            </div>

                            <div class="login-pb-divider"><span>atau</span></div>

                            <button type="button" class="btn btn-outline-primary w-100 mb-3" id="login-pb-toggle-manual"
                                aria-expanded="false" aria-controls="login-pb-manual">
                                <i class="fa-solid fa-keyboard me-1" aria-hidden="true"></i>
                                Masuk dengan NIP &amp; password
                            </button>

                            <div id="login-pb-manual" class="login-pb-manual" hidden>
                                <form method="post" class="auth-portal-form" autocomplete="on">
                                    <input type="hidden" name="peran" value="<?= htmlspecialchars($peran) ?>">
                                    <input type="hidden" name="login_method" value="password">
                                    <div class="mb-3">
                                        <label class="form-label" for="login-username">NIP / username</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                            <input type="text" name="username" id="login-username" class="form-control" required autocomplete="username" placeholder="NIP pembimbing">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="login-password">Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                            <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-auth-primary w-100">
                                        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Masuk ke portal
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" class="auth-portal-form">
                            <input type="hidden" name="peran" value="<?= htmlspecialchars($peran) ?>">
                            <input type="hidden" name="login_method" value="password">
                            <div class="mb-3">
                                <label class="form-label" for="login-username">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                    <input type="text" name="username" id="login-username" class="form-control" required autocomplete="username" placeholder="Masukkan username">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="login-password">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                    <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-auth-primary w-100">
                                <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Masuk ke portal
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($peran === 'pembimbing'): ?>
                <style>
                    .login-pb-options {
                        display: flex;
                        flex-direction: column;
                        gap: 0.75rem;
                        margin-bottom: 0.75rem;
                    }
                    .login-pb-option {
                        display: flex;
                        align-items: center;
                        gap: 0.85rem;
                        padding: 0.95rem 1rem;
                        border-radius: 14px;
                        border: 1px solid #e2e8f0;
                        border-left: 4px solid #0f766e;
                        background: #fff;
                        text-decoration: none;
                        color: #0f172a;
                        transition: none;
                        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
                    }
                    .login-pb-option:hover {
                        box-shadow: 0 6px 16px rgba(15, 118, 110, 0.1);
                        border-color: #99f6e4;
                        color: #0f172a;
                    }
                    .login-pb-option--scan { border-left-color: #4f46e5; }
                    .login-pb-option--portal { border-left-color: #0f766e; }
                    .login-pb-option__icon {
                        flex: 0 0 auto;
                        width: 46px;
                        height: 46px;
                        border-radius: 12px;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 1.25rem;
                        color: #fff;
                    }
                    .login-pb-option--scan .login-pb-option__icon {
                        background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);
                    }
                    .login-pb-option--portal .login-pb-option__icon {
                        background: linear-gradient(135deg, #0f766e 0%, #0891b2 100%);
                    }
                    .login-pb-option__text {
                        flex: 1 1 auto;
                        display: flex;
                        flex-direction: column;
                        gap: 0.1rem;
                    }
                    .login-pb-option__text strong {
                        font-size: 0.98rem;
                        font-weight: 800;
                        color: #0f172a;
                    }
                    .login-pb-option__text span {
                        font-size: 0.8rem;
                        color: #475569;
                        line-height: 1.4;
                    }
                    .login-pb-option__go {
                        color: #94a3b8;
                        font-size: 1rem;
                    }
                    [data-theme="dark"] .login-pb-option {
                        background: rgba(30, 41, 59, 0.7);
                        border-color: rgba(71, 85, 105, 0.4);
                        color: #e2e8f0;
                    }
                    [data-theme="dark"] .login-pb-option:hover { color: #fff; }
                    [data-theme="dark"] .login-pb-option__text span { color: #cbd5e1; }

                    .login-pb-qr {
                        border-radius: 16px;
                        border: 1px solid rgba(15, 118, 110, 0.18);
                        background: linear-gradient(160deg, rgba(240, 253, 250, 0.96) 0%, #fff 100%);
                        padding: 0.85rem 0.85rem 1rem;
                        margin-bottom: 0.85rem;
                    }
                    .login-pb-qr__head {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 0.5rem;
                        margin-bottom: 0.5rem;
                    }
                    .login-pb-qr__title {
                        font-weight: 700;
                        font-size: 0.92rem;
                        color: #0f766e;
                    }
                    .login-pb-qr__status {
                        font-size: 0.72rem;
                        font-weight: 600;
                        padding: 0.18rem 0.55rem;
                        border-radius: 999px;
                        background: rgba(15, 118, 110, 0.1);
                        color: #115e59;
                    }
                    .login-pb-qr__status.is-waiting { background: rgba(245, 158, 11, 0.16); color: #b45309; }
                    .login-pb-qr__status.is-success { background: rgba(16, 185, 129, 0.18); color: #047857; }
                    .login-pb-qr__status.is-error { background: rgba(220, 38, 38, 0.16); color: #b91c1c; }
                    .login-pb-qr__viewport {
                        position: relative;
                        width: 100%;
                        aspect-ratio: 1 / 1;
                        max-width: 320px;
                        margin: 0 auto;
                        border-radius: 14px;
                        overflow: hidden;
                        background: #0f172a;
                    }
                    .login-pb-qr__viewport #login-pb-reader,
                    .login-pb-qr__viewport #login-pb-reader video {
                        width: 100% !important;
                        height: 100% !important;
                        object-fit: cover;
                    }
                    .login-pb-qr__frame {
                        position: absolute;
                        inset: 0;
                        pointer-events: none;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .login-pb-qr__frame-box {
                        width: 68%;
                        height: 68%;
                        border-radius: 18px;
                        border: 2px dashed rgba(16, 185, 129, 0.9);
                        box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.32);
                    }
                    .login-pb-qr__error {
                        position: absolute;
                        inset: 0;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        text-align: center;
                        background: rgba(15, 23, 42, 0.82);
                        color: #fff;
                        padding: 1rem;
                    }
                    .login-pb-qr__hint {
                        text-align: center;
                        margin-top: 0.6rem;
                    }
                    .login-pb-qr__controls {
                        display: flex;
                        gap: 0.4rem;
                        justify-content: center;
                        margin-top: 0.7rem;
                        flex-wrap: wrap;
                    }
                    .login-pb-divider {
                        position: relative;
                        text-align: center;
                        margin: 0.5rem 0 0.85rem;
                        color: #64748b;
                        font-size: 0.78rem;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.08em;
                    }
                    .login-pb-divider::before,
                    .login-pb-divider::after {
                        content: '';
                        position: absolute;
                        top: 50%;
                        width: 38%;
                        height: 1px;
                        background: rgba(15, 23, 42, 0.12);
                    }
                    .login-pb-divider::before { left: 0; }
                    .login-pb-divider::after  { right: 0; }
                    .login-pb-divider span { background: transparent; padding: 0 0.5rem; }
                    [data-theme="dark"] .login-pb-qr {
                        background: linear-gradient(160deg, rgba(15, 23, 42, 0.7) 0%, rgba(30, 41, 59, 0.78) 100%);
                        border-color: rgba(71, 85, 105, 0.5);
                    }
                    [data-theme="dark"] .login-pb-qr__title { color: #5eead4; }
                    [data-theme="dark"] .login-pb-qr__hint { color: #cbd5e1; }
                    [data-theme="dark"] .login-pb-divider { color: #94a3b8; }
                    [data-theme="dark"] .login-pb-divider::before,
                    [data-theme="dark"] .login-pb-divider::after { background: rgba(148, 163, 184, 0.3); }
                </style>
                <?php require_once __DIR__ . '/helpers/app_vendor.php'; require __DIR__ . '/includes/partials/app_html5_qrcode_script.php'; ?>
                <script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-camera.js')) ?>"></script>
                <script>
                (function () {
                    var toggleBtn = document.getElementById('login-pb-toggle-manual');
                    var manualPanel = document.getElementById('login-pb-manual');
                    if (toggleBtn && manualPanel) {
                        toggleBtn.addEventListener('click', function () {
                            var open = !manualPanel.hasAttribute('hidden');
                            if (open) {
                                manualPanel.setAttribute('hidden', '');
                                toggleBtn.setAttribute('aria-expanded', 'false');
                                toggleBtn.innerHTML = '<i class="fa-solid fa-keyboard me-1" aria-hidden="true"></i> Masuk dengan NIP &amp; password';
                            } else {
                                manualPanel.removeAttribute('hidden');
                                toggleBtn.setAttribute('aria-expanded', 'true');
                                toggleBtn.innerHTML = '<i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Sembunyikan input manual';
                                var firstInput = manualPanel.querySelector('input[name="username"]');
                                if (firstInput) { try { firstInput.focus(); } catch (e) {} }
                            }
                        });
                    }

                    if (typeof window.PresensiScanCamera === 'undefined') {
                        return;
                    }
                    var statusEl = document.getElementById('login-pb-status');
                    var formEl = document.getElementById('login-pb-qr-form');
                    var inputEl = document.getElementById('login-pb-qr-code');
                    if (!formEl || !inputEl) return;

                    var submitted = false;
                    var scanner = new window.PresensiScanCamera({
                        readerId: 'login-pb-reader',
                        statusEl: statusEl,
                        errorPanel: document.getElementById('login-pb-error'),
                        errorText: document.getElementById('login-pb-error-text'),
                        btnFlip: document.getElementById('login-pb-flip'),
                        btnRestart: document.getElementById('login-pb-restart'),
                        btnRetry: document.getElementById('login-pb-retry'),
                        onSubmit: function (code) {
                            if (submitted) return;
                            submitted = true;
                            inputEl.value = code;
                            if (statusEl) {
                                statusEl.textContent = 'Kartu terdeteksi — masuk…';
                                statusEl.className = 'login-pb-qr__status is-success';
                            }
                            try { scanner.stop(); } catch (e) {}
                            formEl.submit();
                        }
                    });
                    scanner.init();
                })();
                </script>
                <?php endif; ?>
<?php
auth_portal_layout_end();
