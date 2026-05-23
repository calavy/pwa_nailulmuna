<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'kegiatan')) {
    set_flash('error', 'Tabel kegiatan belum ada.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaKegiatan = trim((string) ($_POST['nama_kegiatan'] ?? ''));
    if ($namaKegiatan === '') {
        set_flash('error', 'Nama kegiatan wajib diisi.');
        header('Location: ' . app_href('/jadwal/tambah_kegiatan.php'));
        exit;
    }
    $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, is_active) VALUES (:nama, 1)')
        ->execute(['nama' => $namaKegiatan]);
    set_flash('success', 'Kegiatan "' . $namaKegiatan . '" berhasil ditambahkan.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

$pageTitle = 'Tambah Kegiatan';
$bodyClass = 'jadwal-page';
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal</a></p>
    <h1 class="h4 mb-0">Tambah kegiatan baru</h1>
</div>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<div class="card shadow-sm jadwal-form-card" style="max-width:28rem;">
    <div class="card-body">
        <form method="post" class="d-grid gap-3">
            <div>
                <label class="form-label">Nama kegiatan</label>
                <input type="text" class="form-control" name="nama_kegiatan" required maxlength="120" placeholder="Mis. Kajian Pagi, Sholat Dhuha">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i> Simpan</button>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
