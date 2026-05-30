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

if (isset($_SESSION['user']) && $pdo instanceof PDO) {
    $peranGate = strtolower(trim((string) ($_GET['peran'] ?? $_POST['peran'] ?? '')));
    $pbActGate = strtolower(trim((string) ($_GET['act'] ?? '')));
    $allowPbQrWhileLoggedIn = ($peranGate === 'pembimbing' && $pbActGate === 'qr');
    if (!$allowPbQrWhileLoggedIn) {
        app_post_login_redirect($pdo);
    }
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
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai') NOT NULL DEFAULT 'pengurus'");
        } catch (PDOException $e) { /* abaikan */ }
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");

        if ($peran === 'pembimbing' && $loginMethod === 'qr' && $qrCode !== '') {
            if (table_exists($pdo, 'pembimbing')) {
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
                        header('Location: ' . app_url('login.php?peran=pembimbing&act=qr'));
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
                    header('Location: ' . app_url('login.php?peran=pembimbing&act=qr'));
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
        set_flash('success', $loginMethod === 'qr' ? 'Scan kartu berhasil.' : 'Login berhasil.');
        if ($isRegisteredPembimbing) {
            app_redirect('pembimbing/dashboard.php');
        }
        app_post_login_redirect($pdo);
    }

    set_flash('error', $loginMethod === 'qr'
        ? 'Kartu QR tidak dikenali (pembimbing/munawib) atau tidak aktif.'
        : 'Username atau password salah.');
    $failAct = ($peran === 'pembimbing' && $loginMethod === 'qr') ? 'qr' : ($peran === 'pembimbing' ? 'portal' : '');
    $failUrl = app_url('login.php?peran=' . urlencode($peran) . ($failAct !== '' ? '&act=' . urlencode($failAct) : ''));
    header('Location: ' . $failUrl);
    exit;
}

$pbAct = strtolower(trim((string) ($_GET['act'] ?? '')));
$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$peranLabel = match ($peran) {
    'pembimbing' => 'Pembimbing',
    'pengurus' => 'Pengurus / Admin',
    default => '',
};
$pbCardTitle = match (true) {
    $peran === 'pembimbing' && $pbAct === 'qr' => 'Scan kartu pembimbing',
    $peran === 'pembimbing' && $pbAct === 'portal' => 'Masuk portal pembimbing',
    $peran === 'pembimbing' => 'Masuk sebagai pembimbing',
    $peran === 'pengurus' => 'Masuk pengurus / admin',
    default => 'Pilih portal',
};

auth_portal_layout_begin([
    'title' => $peran === '' ? 'Portal Masuk' : 'Login ' . $peranLabel,
    'headline' => '',
    'welcome_salam' => '',
    'welcome_salam_waktu' => '',
    'welcome_tagline' => '',
    'formal_body' => '',
    'subtitle_mobile' => $peran === '' ? 'Pilih portal sesuai peran Anda.' : '',
    'subtitle_desktop' => $peran === '' ? 'Pilih portal sesuai peran Anda.' : '',
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'logo_url' => '',
    'layout' => $peran === '' ? 'split' : 'stack',
    'card_title' => $pbCardTitle,
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
                    <p class="small text-muted mb-3">Semua pintu masuk portal tersedia di bawah.</p>
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
                            'desc' => 'Scan QR atau NIP & password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/presensi/login.php'),
                            'icon' => 'fa-qrcode',
                            'icon_mod' => 'presensi',
                            'title' => 'Petugas presensi',
                            'desc' => 'Scan & password',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/wali/login.php'),
                            'icon' => 'fa-mobile-screen-button',
                            'icon_mod' => 'wali',
                            'title' => 'Portal wali',
                            'desc' => 'NIS · PIN wali',
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
                            'desc' => 'Password koperasi',
                        ]);
                        auth_portal_role_link([
                            'href' => app_href('/mukimin/login.php'),
                            'icon' => 'fa-book-open',
                            'icon_mod' => 'mukimin',
                            'title' => 'Portal mukimin',
                            'desc' => 'Alumni · akun',
                            'full' => true,
                        ]);
                        ?>
                    </div>

                <?php else: ?>
                    <a href="<?= htmlspecialchars(app_href('/login.php')) ?>" class="auth-portal-back">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali pilih portal
                    </a>

                    <?php if ($peran === 'pengurus'): ?>
                        <form method="post" class="auth-portal-form mt-2">
                            <input type="hidden" name="peran" value="pengurus">
                            <input type="hidden" name="login_method" value="password">
                            <div class="mb-3">
                                <label class="form-label" for="login-username">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                    <input type="text" name="username" id="login-username" class="form-control" required autocomplete="username" placeholder="Username">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="login-password">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                    <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Password">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-auth-primary w-100">
                                <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Masuk
                            </button>
                        </form>

                    <?php elseif ($peran === 'pembimbing'): ?>
                        <?php if ($pbAct === ''): ?>
                            <p class="small text-muted mb-3">Pilih cara masuk portal pembimbing.</p>
                            <div class="login-pb-options">
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=qr')) ?>" class="login-pb-option login-pb-option--scan">
                                    <span class="login-pb-option__icon" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></span>
                                    <span class="login-pb-option__text">
                                        <strong class="login-pb-option__title">Scan QR kartu</strong>
                                        <span class="login-pb-option__desc">Tanpa password · kartu pembimbing / munawib</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                </a>
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=portal')) ?>" class="login-pb-option login-pb-option--portal">
                                    <span class="login-pb-option__icon" aria-hidden="true"><i class="fa-solid fa-chalkboard-user"></i></span>
                                    <span class="login-pb-option__text">
                                        <strong class="login-pb-option__title">NIP &amp; password</strong>
                                        <span class="login-pb-option__desc">Masuk dengan akun yang diberikan pengurus</span>
                                    </span>
                                    <span class="login-pb-option__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                </a>
                            </div>

                        <?php elseif ($pbAct === 'portal'): ?>
                            <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing')) ?>" class="auth-portal-back">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali
                            </a>
                            <div class="d-grid gap-2 mt-2 mb-3">
                                <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=qr')) ?>" class="btn btn-outline-primary">
                                    <i class="fa-solid fa-qrcode me-1"></i> Masuk dengan QR
                                </a>
                            </div>
                            <p class="small text-muted text-center mb-2">Atau masuk dengan NIP &amp; password</p>
                            <form method="post" class="auth-portal-form" autocomplete="on">
                                <input type="hidden" name="peran" value="pembimbing">
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
                                        <input type="password" name="password" id="login-password" class="form-control" required autocomplete="current-password" placeholder="Password">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-auth-primary w-100">
                                    <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Masuk ke portal
                                </button>
                            </form>

                        <?php elseif ($pbAct === 'qr'): ?>
                            <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing')) ?>" class="auth-portal-back">
                                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Kembali
                            </a>
                            <div class="login-pb-qr mt-2">
                                <div class="login-pb-qr__head">
                                    <span class="login-pb-qr__title">
                                        <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>
                                        Masuk dengan scan kartu
                                    </span>
                                    <span id="login-pb-status" class="login-pb-qr__status is-waiting d-none">Siap scan</span>
                                </div>
                                <div class="text-center py-3" id="login-pb-start-wrap">
                                    <button type="button" class="btn btn-primary btn-lg px-4" id="login-pb-start-scan">
                                        <i class="fa-solid fa-qrcode me-2"></i> Scan kartu pembimbing / munawib
                                    </button>
                                    <p class="small text-muted mt-2 mb-0">Kamera baru dibuka setelah tombol ditekan.</p>
                                </div>
                                <div class="login-pb-qr__viewport d-none" id="login-pb-camera-wrap">
                                    <div id="login-pb-reader" aria-label="Kamera scan kartu pembimbing"></div>
                                    <div class="login-pb-qr__frame" aria-hidden="true"><div class="login-pb-qr__frame-box"></div></div>
                                    <div id="login-pb-error" class="login-pb-qr__error d-none" role="alert">
                                        <div>
                                            <p class="fw-semibold mb-2" id="login-pb-error-text">Gagal membuka kamera</p>
                                            <button type="button" class="btn btn-light btn-sm" id="login-pb-retry">Coba lagi</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="login-pb-qr__hint small text-muted d-none" id="login-pb-hint">Arahkan QR kartu ke kotak hijau.</div>
                                <div class="login-pb-qr__controls d-none" id="login-pb-controls">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="login-pb-flip"><i class="fa-solid fa-camera-rotate me-1"></i> Ganti kamera</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="login-pb-restart"><i class="fa-solid fa-rotate-right me-1"></i> Ulangi</button>
                                </div>
                                <form method="post" id="login-pb-qr-form" class="visually-hidden" autocomplete="off">
                                    <input type="hidden" name="peran" value="pembimbing">
                                    <input type="hidden" name="login_method" value="qr">
                                    <input type="text" name="qr_code" id="login-pb-qr-code" readonly>
                                </form>
                            </div>
                            <p class="login-pb-alt small text-muted text-center mt-3 mb-0">
                                Atau <a href="<?= htmlspecialchars(app_href('/login.php?peran=pembimbing&act=portal')) ?>">masuk dengan NIP &amp; password</a>
                            </p>

                        <?php else: ?>
                            <?php
                            header('Location: ' . app_href('/login.php?peran=pembimbing'));
                            exit;
                            ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($peran === 'pembimbing' && $pbAct === 'qr'): ?>
                <style>
                    .login-pb-options { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 0.75rem; }
                    .login-pb-option { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.9rem 0.85rem; border-radius: 14px; border: 1px solid #e2e8f0; border-left: 4px solid #0f766e; background: #fff; text-decoration: none; color: #0f172a; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
                    .login-pb-option--scan { border-left-color: #4f46e5; }
                    .login-pb-option--portal { border-left-color: #0f766e; }
                    .login-pb-option__icon { flex: 0 0 auto; width: 2.75rem; height: 2.75rem; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; color: #fff; }
                    .login-pb-option--scan .login-pb-option__icon { background: linear-gradient(135deg, #4338ca, #6366f1); }
                    .login-pb-option--portal .login-pb-option__icon { background: linear-gradient(135deg, #0f766e, #0891b2); }
                    .login-pb-option__text { flex: 1 1 auto; min-width: 0; }
                    .login-pb-option__title { display: block; font-size: 0.92rem; font-weight: 800; }
                    .login-pb-option__desc { display: block; margin-top: 0.2rem; font-size: 0.78rem; color: #64748b; }
                    .login-pb-option__go { align-self: center; color: #94a3b8; }
                    .login-pb-qr { border-radius: 16px; border: 1px solid rgba(15,118,110,0.18); background: linear-gradient(160deg, rgba(240,253,250,0.96), #fff); padding: 0.85rem; }
                    .login-pb-qr__head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
                    .login-pb-qr__title { font-weight: 700; font-size: 0.88rem; color: #0f766e; }
                    .login-pb-qr__status { font-size: 0.7rem; font-weight: 600; padding: 0.18rem 0.55rem; border-radius: 999px; background: rgba(245,158,11,0.16); color: #b45309; }
                    .login-pb-qr__status.is-success { background: rgba(16,185,129,0.18); color: #047857; }
                    .login-pb-qr__viewport { position: relative; width: 100%; aspect-ratio: 1; max-width: 300px; margin: 0 auto; border-radius: 14px; overflow: hidden; background: #0f172a; }
                    .login-pb-qr__viewport #login-pb-reader, .login-pb-qr__viewport #login-pb-reader video { width: 100% !important; height: 100% !important; object-fit: cover; }
                    .login-pb-qr__frame { position: absolute; inset: 0; pointer-events: none; display: flex; align-items: center; justify-content: center; }
                    .login-pb-qr__frame-box { width: 68%; height: 68%; border: 2px dashed rgba(16,185,129,0.9); border-radius: 18px; box-shadow: 0 0 0 9999px rgba(15,23,42,0.32); }
                    .login-pb-qr__error { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(15,23,42,0.82); color: #fff; padding: 1rem; text-align: center; }
                    .login-pb-qr__hint { text-align: center; margin-top: 0.6rem; }
                    .login-pb-qr__controls { display: flex; gap: 0.4rem; justify-content: center; margin-top: 0.7rem; flex-wrap: wrap; }
                </style>
                <?php require_once __DIR__ . '/helpers/app_vendor.php'; require __DIR__ . '/includes/partials/app_html5_qrcode_script.php'; ?>
                <script src="<?= htmlspecialchars(app_url('assets/js/presensi-scan-camera.js')) ?>"></script>
                <script>
                (function () {
                    if (typeof window.PresensiScanCamera === 'undefined') return;
                    var formEl = document.getElementById('login-pb-qr-form');
                    var inputEl = document.getElementById('login-pb-qr-code');
                    var startBtn = document.getElementById('login-pb-start-scan');
                    var startWrap = document.getElementById('login-pb-start-wrap');
                    var cameraWrap = document.getElementById('login-pb-camera-wrap');
                    var hintEl = document.getElementById('login-pb-hint');
                    var controlsEl = document.getElementById('login-pb-controls');
                    var statusEl = document.getElementById('login-pb-status');
                    if (!formEl || !inputEl || !startBtn) return;
                    var submitted = false;
                    var scanner = null;
                    function showCameraUi() {
                        if (startWrap) startWrap.classList.add('d-none');
                        if (cameraWrap) cameraWrap.classList.remove('d-none');
                        if (hintEl) hintEl.classList.remove('d-none');
                        if (controlsEl) controlsEl.classList.remove('d-none');
                        if (statusEl) {
                            statusEl.classList.remove('d-none');
                            statusEl.textContent = 'Menyiapkan kamera…';
                        }
                    }
                    function bootScanner() {
                        if (scanner) {
                            try { scanner.restart(); } catch (e) {}
                            return;
                        }
                        scanner = new window.PresensiScanCamera({
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
                                try { scanner.stop(); } catch (e) {}
                                formEl.submit();
                            }
                        });
                        scanner.init();
                    }
                    startBtn.addEventListener('click', function () {
                        showCameraUi();
                        bootScanner();
                    });
                })();
                </script>
                <?php elseif ($peran === 'pembimbing' && $pbAct === ''): ?>
                <style>
                    .login-pb-options { display: flex; flex-direction: column; gap: 0.75rem; }
                    .login-pb-option { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.9rem 0.85rem; border-radius: 14px; border: 1px solid #e2e8f0; border-left: 4px solid #0f766e; background: #fff; text-decoration: none; color: #0f172a; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
                    .login-pb-option--scan { border-left-color: #4f46e5; }
                    .login-pb-option--portal { border-left-color: #0f766e; }
                    .login-pb-option__icon { flex: 0 0 auto; width: 2.75rem; height: 2.75rem; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; color: #fff; }
                    .login-pb-option--scan .login-pb-option__icon { background: linear-gradient(135deg, #4338ca, #6366f1); }
                    .login-pb-option--portal .login-pb-option__icon { background: linear-gradient(135deg, #0f766e, #0891b2); }
                    .login-pb-option__text { flex: 1 1 auto; min-width: 0; }
                    .login-pb-option__title { display: block; font-size: 0.92rem; font-weight: 800; }
                    .login-pb-option__desc { display: block; margin-top: 0.2rem; font-size: 0.78rem; color: #64748b; }
                    .login-pb-option__go { align-self: center; color: #94a3b8; }
                </style>
                <?php endif; ?>

<?php
auth_portal_layout_end();
