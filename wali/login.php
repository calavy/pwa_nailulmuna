<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/wali_portal.php';
require_once __DIR__ . '/../helpers/login_rate_limit.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';

if (!app_is_wali_host() && app_wali_public_url() !== '') {
    $identity = trim((string) ($_GET['identity'] ?? $_GET['nis'] ?? ''));
    $qs = $identity !== '' ? '?identity=' . urlencode($identity) : '';
    header('Location: ' . app_wali_login_href() . $qs);
    exit;
}

if (isset($_SESSION['wali']['santri_id'])) {
    app_redirect('wali/index.php');
}

$prefillIdentity = trim((string) ($_GET['identity'] ?? $_GET['nis'] ?? $_GET['username'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string) ($_POST['username'] ?? $_POST['identity'] ?? $_POST['nis'] ?? ''));
    $pin = (string) ($_POST['password'] ?? $_POST['pin'] ?? '');
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $failRedirect = app_url('wali/login.php');

    if ($identity === '' || $pin === '') {
        set_flash('error', 'Isi NIS atau nama santri dan PIN portal wali.');
        header('Location: ' . $failRedirect);
        exit;
    }

    $waliLogin = wali_portal_verify_login($pdo, $identity, $pin);
    if (!empty($waliLogin['ok']) && is_array($waliLogin['row'] ?? null)) {
        $waliRow = $waliLogin['row'];
        session_regenerate_id(true);
        $_SESSION['wali'] = [
            'santri_id' => (int) $waliRow['id'],
            'nis' => (string) ($waliRow['nis'] ?? ''),
            'nama_santri' => (string) ($waliRow['nama_santri'] ?? ''),
            'wali_santri_id' => (int) ($waliRow['wali_santri_id'] ?? 0),
        ];
        if ($pdo instanceof PDO) {
            login_rate_limit_clear($pdo, $clientIp, $identity);
        }
        set_flash('success', 'Login berhasil.');
        app_redirect('wali/index.php');
    }

    if ($pdo instanceof PDO) {
        login_rate_limit_record_failure($pdo, $clientIp, $identity);
    }
    $msg = trim((string) ($waliLogin['message'] ?? ''));
    set_flash('error', $msg !== '' ? $msg : 'NIS/nama santri atau PIN salah.');
    header('Location: ' . $failRedirect . ($identity !== '' ? '?identity=' . urlencode($identity) : ''));
    exit;
}

$brandNama = auth_portal_brand_nama($pdo);
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$welcome = auth_portal_welcome_copy($pdo);

auth_portal_layout_begin([
    'title' => 'Portal wali',
    'welcome_salam' => $welcome['salam'],
    'welcome_salam_waktu' => $welcome['salam_waktu'],
    'welcome_tagline' => $welcome['tagline_portal'],
    'subtitle' => 'Masuk dengan NIS atau nama santri dan PIN portal wali.',
    'kicker' => $jenisPendidikan,
    'nama_ponpes' => $brandNama,
    'layout' => 'center_card',
    'card_style' => 'center',
    'card_subtitle' => 'Portal wali santri',
    'show_alt_nav' => false,
    'shell_mod' => 'default',
    'card_title' => '',
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

                <form method="post" class="auth-portal-form auth-portal-form--center" autocomplete="on">
                    <div class="auth-portal-field-group auth-portal-field-group--center">
                        <div class="auth-portal-field auth-portal-field--center auth-portal-suggest-wrap">
                            <input type="text" name="username" id="login-username" class="auth-portal-field__input auth-portal-field__input--center" required autocomplete="username" placeholder="NIS atau nama santri" value="<?= htmlspecialchars($prefillIdentity) ?>" data-santri-suggest="1" data-santri-suggest-url="<?= htmlspecialchars(app_href('/api/login_santri_suggest.php')) ?>" role="combobox" aria-autocomplete="list" aria-controls="login-santri-suggest" aria-expanded="false">
                            <div id="login-santri-suggest" class="auth-portal-suggest-list d-none" role="listbox" hidden aria-label="Saran nama santri"></div>
                        </div>
                    </div>
                    <div class="auth-portal-field-group auth-portal-field-group--center">
                        <div class="auth-portal-field auth-portal-field--center">
                            <input type="password" name="password" id="login-password" class="auth-portal-field__input auth-portal-field__input--center" required autocomplete="current-password" placeholder="PIN portal wali">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-auth-center w-100">Masuk</button>
                </form>

                <p class="small text-muted text-center mt-3 mb-0">Silakan cari nama santri, atau masukkan NIS, lalu isi PIN yang telah diberikan pengurus.</p>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/login-santri-suggest.js')) ?>" defer></script>
<?php
auth_portal_layout_end();
