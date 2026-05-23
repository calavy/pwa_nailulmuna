<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/user_profil.php';

require_login();
user_profil_ensure_schema($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$st = $pdo->prepare('SELECT id, nama, username, role, foto_profil, jenis_kelamin FROM users WHERE id = :id LIMIT 1');
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

$pageTitle = 'Profil Saya';
$bodyClass = 'settings-module-page';
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
