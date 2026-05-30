<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/jadwal_pembimbing.php';

jadwal_require_module_access();

if (!table_exists($pdo, 'kegiatan')) {
    set_flash('error', 'Tabel kegiatan belum ada.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}
ensure_kegiatan_kategori_column($pdo);

$editId = (int) ($_GET['edit_id'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stEdit = $pdo->prepare('SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan, COALESCE(is_active, 1) AS is_active FROM kegiatan WHERE id = :id LIMIT 1');
    $stEdit->execute(['id' => $editId]);
    $editRow = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Kegiatan yang akan diedit tidak ditemukan.');
        header('Location: ' . app_href('/jadwal/index.php?panel=kegiatan'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'tambah');
    $namaKegiatan = trim((string) ($_POST['nama_kegiatan'] ?? ''));
    $kategoriKegiatan = strtoupper(trim((string) ($_POST['kategori_kegiatan'] ?? 'TAALIM')));
    $isActive = ((int) ($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
    if (!in_array($kategoriKegiatan, ['JAMAAH', 'TAALIM'], true)) {
        $kategoriKegiatan = 'TAALIM';
    }
    if ($namaKegiatan === '') {
        set_flash('error', 'Nama kegiatan wajib diisi.');
        header('Location: ' . app_href('/jadwal/index.php?panel=kegiatan'));
        exit;
    }
    if ($action === 'edit') {
        $idEditPost = (int) ($_POST['id'] ?? 0);
        if ($idEditPost <= 0) {
            set_flash('error', 'ID kegiatan tidak valid.');
            header('Location: ' . app_href('/jadwal/index.php?panel=kegiatan'));
            exit;
        }
        $pdo->prepare('UPDATE kegiatan SET nama_kegiatan = :nama, kategori_kegiatan = :kat, is_active = :aktif WHERE id = :id')
            ->execute(['nama' => $namaKegiatan, 'kat' => $kategoriKegiatan, 'aktif' => $isActive, 'id' => $idEditPost]);
        set_flash('success', 'Kegiatan "' . $namaKegiatan . '" berhasil diperbarui.');
    } else {
        $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:nama, :kat, 1)')
            ->execute(['nama' => $namaKegiatan, 'kat' => $kategoriKegiatan]);
        $newKegiatanId = (int) $pdo->lastInsertId();
        set_flash('success', 'Kegiatan "' . $namaKegiatan . '" berhasil ditambahkan. Lanjut buat jadwal.');
        header('Location: ' . app_href('/jadwal/index.php?panel=jadwal&kegiatan_id=' . $newKegiatanId));
        exit;
    }
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

$pageTitle = 'Tambah Kegiatan';
$bodyClass = 'jadwal-page';
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
$kegiatanRows = $pdo->query('SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan, COALESCE(is_active, 1) AS is_active FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal</a></p>
    <h1 class="h4 mb-0"><?= $editRow ? 'Edit kegiatan' : 'Tambah kegiatan baru' ?></h1>
    <p class="text-muted small mb-0 mt-1">Master kegiatan dipakai saat membuat jadwal baru. Setelah disimpan, lanjut ke formulir tambah jadwal.</p>
</div>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<div class="card shadow-sm jadwal-form-card" style="max-width:28rem;">
    <div class="card-body">
        <form method="post" class="d-grid gap-3">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'tambah' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
            <?php endif; ?>
            <div>
                <label class="form-label">Nama kegiatan</label>
                <input type="text" class="form-control" name="nama_kegiatan" required maxlength="120" placeholder="Mis. Kajian Pagi, Sholat Dhuha" value="<?= htmlspecialchars((string) ($editRow['nama_kegiatan'] ?? '')) ?>">
            </div>
            <div>
                <label class="form-label">Kategori kegiatan</label>
                <select class="form-select" name="kategori_kegiatan">
                    <option value="TAALIM" <?= strtoupper((string) ($editRow['kategori_kegiatan'] ?? 'TAALIM')) === 'TAALIM' ? 'selected' : '' ?>>Ta'lim & Ta'alum</option>
                    <option value="JAMAAH" <?= strtoupper((string) ($editRow['kategori_kegiatan'] ?? 'TAALIM')) === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah</option>
                </select>
                <div class="form-text">Dipakai untuk jalur libur terpisah (mis. ta'lim libur, jama'ah tetap aktif).</div>
            </div>
            <?php if ($editRow): ?>
                <div>
                    <label class="form-label">Status kegiatan</label>
                    <select class="form-select" name="is_active">
                        <option value="1" <?= (int) ($editRow['is_active'] ?? 1) === 1 ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= (int) ($editRow['is_active'] ?? 1) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i> <?= $editRow ? 'Update' : 'Simpan' ?></button>
                <?php if (!$editRow): ?>
                <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>" class="btn btn-outline-primary">Lanjut tambah jadwal</a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-outline-secondary">Batal</a>
                <?php if ($editRow): ?>
                    <a href="<?= htmlspecialchars(app_href('/jadwal/tambah_kegiatan.php')) ?>" class="btn btn-outline-primary">Mode tambah</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-header py-2"><strong>Daftar kegiatan</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Nama</th><th>Kategori</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            <?php if ($kegiatanRows === []): ?><tr><td colspan="4" class="text-center text-muted py-3">Belum ada data kegiatan.</td></tr><?php endif; ?>
            <?php foreach ($kegiatanRows as $row): ?>
                <tr>
                    <td class="small fw-semibold"><?= htmlspecialchars((string) ($row['nama_kegiatan'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($row['kategori_kegiatan'] ?? 'TAALIM')) ?></td>
                    <td class="small"><?= (int) ($row['is_active'] ?? 1) === 1 ? 'Aktif' : 'Nonaktif' ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/jadwal/tambah_kegiatan.php?edit_id=' . (int) ($row['id'] ?? 0))) ?>">
                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
