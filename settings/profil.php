<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/user_profil.php';
require_once __DIR__ . '/../helpers/login_pembimbing.php';

require_login();
user_profil_ensure_schema($pdo);

const PROFIL_PASSWORD_MIN_LEN = 6;

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$st = $pdo->prepare('SELECT id, nama, username, role, foto_profil, jenis_kelamin, no_wa, password FROM users WHERE id = :id LIMIT 1');
$st->execute(['id' => $userId]);
$userRow = $st->fetch(PDO::FETCH_ASSOC);

if (!$userRow) {
    set_flash('error', 'Akun tidak ditemukan.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'upload'));

    if ($action === 'remove_foto') {
        $old = trim((string) ($userRow['foto_profil'] ?? ''));
        if ($old !== '') {
            user_profil_delete_file($old);
            $pdo->prepare('UPDATE users SET foto_profil = NULL WHERE id = :id')->execute(['id' => $userId]);
            user_profil_sync_session($pdo, $userId);
            set_flash('success', 'Foto profil dihapus.');
        }
        header('Location: ' . app_href('/settings/profil.php'));
        exit;
    }

    if ($action === 'set_jenis_kelamin') {
        $jk = user_profil_normalize_jenis_kelamin((string) ($_POST['jenis_kelamin'] ?? ''));
        $pdo->prepare('UPDATE users SET jenis_kelamin = :jk WHERE id = :id')->execute([
            'jk' => $jk,
            'id' => $userId,
        ]);
        $_SESSION['user']['jenis_kelamin'] = $jk;
            set_flash('success', 'Foto default disesuaikan.');
        header('Location: ' . app_href('/settings/profil.php'));
        exit;
    }

    if ($action === 'save_no_wa') {
        $noWa = trim((string) ($_POST['no_wa'] ?? ''));
        $pdo->prepare('UPDATE users SET no_wa = :wa WHERE id = :id')->execute([
            'wa' => $noWa !== '' ? $noWa : null,
            'id' => $userId,
        ]);
        set_flash('success', 'Nomor WhatsApp disimpan.');
        header('Location: ' . app_href('/settings/profil.php'));
        exit;
    }

    if ($action === 'change_password') {
        $currentInput = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $storedHash = (string) ($userRow['password'] ?? '');

        if ($currentInput === '' || $newPassword === '' || $confirmPassword === '') {
            set_flash('error', 'Password lama, baru, dan konfirmasi wajib diisi.');
        } elseif ($storedHash === '' || !password_verify($currentInput, $storedHash)) {
            set_flash('error', 'Password lama salah.');
        } elseif (strlen($newPassword) < PROFIL_PASSWORD_MIN_LEN) {
            set_flash('error', 'Password baru minimal ' . PROFIL_PASSWORD_MIN_LEN . ' karakter.');
        } elseif ($newPassword !== $confirmPassword) {
            set_flash('error', 'Konfirmasi password baru tidak cocok.');
        } elseif (password_verify($newPassword, $storedHash)) {
            set_flash('error', 'Password baru sama dengan password lama. Pilih yang berbeda.');
        } else {
            try {
                $pdo->prepare('UPDATE users SET password = :p WHERE id = :id')->execute([
                    'p' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => $userId,
                ]);
                // Privasi user: hapus salinan plaintext yang mungkin
                // disimpan saat admin men-set/reset password sebelumnya.
                if (function_exists('login_pembimbing_forget_password_plain')) {
                    login_pembimbing_forget_password_plain($pdo, $userId);
                }
                set_flash('success', 'Password berhasil diubah. Password baru akan dipakai pada login berikutnya.');
            } catch (Throwable $e) {
                set_flash('error', 'Gagal menyimpan password: ' . $e->getMessage());
            }
        }
        header('Location: ' . app_href('/settings/profil.php'));
        exit;
    }

    if ($action === 'upload' && isset($_FILES['foto_profil']) && is_array($_FILES['foto_profil'])) {
        $result = user_profil_handle_upload($_FILES['foto_profil'], (string) ($userRow['foto_profil'] ?? ''));
        if (!$result['ok']) {
            set_flash('error', (string) ($result['error'] ?? 'Upload gagal.'));
        } elseif (isset($result['path'])) {
            $pdo->prepare('UPDATE users SET foto_profil = :f WHERE id = :id')->execute([
                'f' => $result['path'],
                'id' => $userId,
            ]);
            user_profil_sync_session($pdo, $userId);
            set_flash('success', 'Foto profil berhasil diperbarui.');
        }
        header('Location: ' . app_href('/settings/profil.php'));
        exit;
    }
}

$st->execute(['id' => $userId]);
$userRow = $st->fetch(PDO::FETCH_ASSOC) ?: $userRow;

require_once __DIR__ . '/../helpers/user_permissions.php';
$aksesSummary = user_permission_access_summary($pdo);

$pageTitle = 'Profil Saya';
$bodyClass = 'settings-module-page';
$loadPushFcm = true;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>">Beranda</a> · Akun</p>
    <h1 class="h4 mb-1">Profil &amp; foto</h1>
    <p class="text-muted mb-0">Sebelum upload, foto default dari aplikasi ditampilkan. Setelah upload, foto Anda dipakai di header dan daftar pengguna.</p>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body text-center p-4">
                <div class="user-profil-preview mb-3">
                    <?= user_profil_render_avatar($userRow, 'app-user-avatar--xl') ?>
                </div>
                <h2 class="h5 mb-1"><?= htmlspecialchars((string) $userRow['nama']) ?></h2>
                <p class="text-muted small mb-0 font-monospace">@<?= htmlspecialchars((string) $userRow['username']) ?></p>
                <p class="mb-0 mt-2"><span class="badge text-bg-light border text-dark"><?= htmlspecialchars((string) ($userRow['role'] ?? '')) ?></span></p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-3">Foto default (jenis kelamin)</h2>
                <?php $jkProfil = user_profil_normalize_jenis_kelamin($userRow['jenis_kelamin'] ?? null); ?>
                <p class="small text-muted">Dipakai bila belum ada foto upload. Pilih agar avatar default sesuai.</p>
                <form method="post" class="row g-3 mb-4">
                    <input type="hidden" name="action" value="set_jenis_kelamin">
                    <div class="col-12">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="jenis_kelamin" id="profil-jk-l" value="Laki-laki"<?= $jkProfil === 'Laki-laki' ? ' checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="profil-jk-l">Laki-laki</label>
                            <input type="radio" class="btn-check" name="jenis_kelamin" id="profil-jk-p" value="Perempuan"<?= $jkProfil === 'Perempuan' ? ' checked' : '' ?>>
                            <label class="btn btn-outline-secondary" for="profil-jk-p">Perempuan</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Simpan</button>
                    </div>
                </form>
                <h2 class="h6 mb-3">Ubah foto profil</h2>
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="action" value="upload">
                    <div class="col-12">
                        <label class="form-label" for="foto_profil">Pilih foto</label>
                        <input class="form-control" type="file" name="foto_profil" id="foto_profil" accept="image/jpeg,image/png,image/webp" required>
                        <div class="form-text">JPG, PNG, atau WEBP · maks. 2 MB · disarankan persegi (1:1).</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">Simpan foto</button>
                    </div>
                </form>
                <?php if (trim((string) ($userRow['foto_profil'] ?? '')) !== ''): ?>
                    <hr class="my-4">
                    <form method="post" onsubmit="return confirm('Hapus foto profil?');">
                        <input type="hidden" name="action" value="remove_foto">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus foto</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$aksesPanelCompact = true;
require __DIR__ . '/partials/akses_saya_panel.php';
?>

<div class="row g-4 mt-1">
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                        <i class="fa-regular fa-bell" aria-hidden="true"></i>
                    </span>
                    <h2 class="h6 mb-0">Notifikasi push</h2>
                </div>
                <p class="small text-muted mb-3">Terima pemberitahuan penting (izin, alpa, tugas) langsung di perangkat ini.</p>
                <button type="button" class="btn btn-outline-primary js-fcm-subscribe" id="btn-fcm-subscribe-profil">
                    <i class="fa-regular fa-bell me-1" aria-hidden="true"></i> Aktifkan notifikasi
                </button>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h2 class="h6 mb-2">WhatsApp pribadi</h2>
                <p class="small text-muted mb-3">Dipakai untuk notifikasi otomatis izin (saat Anda menyetujui surat) jika diaktifkan di Pengaturan → WA Otomatis → Izin.</p>
                <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                    <input type="hidden" name="action" value="save_no_wa">
                    <div class="flex-grow-1" style="min-width:12rem;">
                        <label class="form-label small mb-1" for="profil-no-wa">No. WhatsApp</label>
                        <input type="text" class="form-control form-control-sm" id="profil-no-wa" name="no_wa" value="<?= htmlspecialchars(trim((string) ($userRow['no_wa'] ?? ''))) ?>" placeholder="628xxxxxxxxxx" inputmode="tel" autocomplete="tel">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm">Simpan</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                    </span>
                    <h2 class="h6 mb-0">Ubah password login</h2>
                </div>
                <p class="small text-muted mb-3">
                    Password dipakai untuk login portal dengan username
                    <span class="font-monospace fw-semibold">@<?= htmlspecialchars((string) $userRow['username']) ?></span>.
                    Minimal <?= (int) PROFIL_PASSWORD_MIN_LEN ?> karakter — campur huruf &amp; angka untuk lebih aman.
                </p>
                <form method="post" class="row g-3" autocomplete="off" id="form-change-password">
                    <input type="hidden" name="action" value="change_password">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1" for="profil-pwd-cur">Password lama <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" class="form-control" id="profil-pwd-cur" name="current_password" autocomplete="current-password" required>
                            <button type="button" class="btn btn-outline-secondary" data-pwd-toggle="profil-pwd-cur" aria-label="Tampilkan/sembunyikan password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1" for="profil-pwd-new">Password baru <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" class="form-control" id="profil-pwd-new" name="new_password" minlength="<?= (int) PROFIL_PASSWORD_MIN_LEN ?>" autocomplete="new-password" required>
                            <button type="button" class="btn btn-outline-secondary" data-pwd-toggle="profil-pwd-new" aria-label="Tampilkan/sembunyikan password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimal <?= (int) PROFIL_PASSWORD_MIN_LEN ?> karakter.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1" for="profil-pwd-conf">Konfirmasi password baru <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" class="form-control" id="profil-pwd-conf" name="confirm_password" minlength="<?= (int) PROFIL_PASSWORD_MIN_LEN ?>" autocomplete="new-password" required>
                            <button type="button" class="btn btn-outline-secondary" data-pwd-toggle="profil-pwd-conf" aria-label="Tampilkan/sembunyikan password">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text" id="profil-pwd-match-hint"></div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="fa-solid fa-key me-1" aria-hidden="true"></i> Ubah password
                        </button>
                        <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-pwd-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-pwd-toggle');
            var input = id ? document.getElementById(id) : null;
            if (!input) { return; }
            var isPwd = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPwd ? 'text' : 'password');
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
        });
    });

    var form = document.getElementById('form-change-password');
    if (!form) { return; }
    var pwdNew = document.getElementById('profil-pwd-new');
    var pwdConf = document.getElementById('profil-pwd-conf');
    var hint = document.getElementById('profil-pwd-match-hint');
    function syncHint() {
        if (!pwdNew || !pwdConf || !hint) { return; }
        if (pwdConf.value === '') {
            hint.textContent = '';
            hint.className = 'form-text';
            return;
        }
        if (pwdNew.value === pwdConf.value) {
            hint.textContent = 'Cocok dengan password baru.';
            hint.className = 'form-text text-success';
        } else {
            hint.textContent = 'Belum cocok dengan password baru.';
            hint.className = 'form-text text-danger';
        }
    }
    if (pwdNew) { pwdNew.addEventListener('input', syncHint); }
    if (pwdConf) { pwdConf.addEventListener('input', syncHint); }

    form.addEventListener('submit', function (e) {
        if (!pwdNew || !pwdConf) { return; }
        if (pwdNew.value !== pwdConf.value) {
            e.preventDefault();
            if (hint) {
                hint.textContent = 'Konfirmasi password belum cocok dengan password baru.';
                hint.className = 'form-text text-danger';
            }
            pwdConf.focus();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
