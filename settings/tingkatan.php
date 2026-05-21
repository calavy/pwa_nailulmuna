<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);

$pdo->exec('
    CREATE TABLE IF NOT EXISTS tingkatan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_tingkatan VARCHAR(80) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $nama = mb_substr(trim((string) ($_POST['nama_tingkatan'] ?? '')), 0, 80);
        if ($nama !== '') {
            $insert = $pdo->prepare('INSERT IGNORE INTO tingkatan (nama_tingkatan) VALUES (:nama)');
            $insert->execute(['nama' => $nama]);
            set_flash('success', 'Tingkatan berhasil ditambahkan.');
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $namaBaru = mb_substr(trim((string) ($_POST['nama_tingkatan'] ?? '')), 0, 80);
        if ($id <= 0 || $namaBaru === '') {
            set_flash('error', 'Nama tingkatan tidak valid.');
        } else {
            $cur = $pdo->prepare('SELECT nama_tingkatan FROM tingkatan WHERE id = :id LIMIT 1');
            $cur->execute(['id' => $id]);
            $lamaRow = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$lamaRow) {
                set_flash('error', 'Data tingkatan tidak ditemukan.');
            } else {
                $namaLama = (string) $lamaRow['nama_tingkatan'];
                if ($namaBaru === $namaLama) {
                    set_flash('success', 'Tidak ada perubahan nama.');
                } else {
                    $dup = $pdo->prepare('SELECT id FROM tingkatan WHERE nama_tingkatan = :n AND id <> :id LIMIT 1');
                    $dup->execute(['n' => $namaBaru, 'id' => $id]);
                    if ($dup->fetch()) {
                        set_flash('error', 'Nama tingkatan sudah dipakai entri lain.');
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $pdo->prepare('UPDATE tingkatan SET nama_tingkatan = :n WHERE id = :id')->execute(['n' => $namaBaru, 'id' => $id]);
                            if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tingkatan')) {
                                $pdo->prepare('UPDATE santri SET tingkatan = :baru WHERE tingkatan = :lama')->execute(['baru' => $namaBaru, 'lama' => $namaLama]);
                            }
                            if (table_exists($pdo, 'jadwal_kegiatan')) {
                                $pdo->prepare('UPDATE jadwal_kegiatan SET tingkatan = :baru WHERE tingkatan = :lama')->execute(['baru' => $namaBaru, 'lama' => $namaLama]);
                            }
                            $pdo->commit();
                            set_flash('success', 'Nama tingkatan diperbarui. Data santri dan jadwal yang memakai nama lama ikut diselaraskan.');
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            set_flash('error', 'Gagal menyimpan perubahan. Coba lagi atau hubungi admin.');
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $delete = $pdo->prepare('DELETE FROM tingkatan WHERE id = :id');
            $delete->execute(['id' => $id]);
            set_flash('success', 'Tingkatan berhasil dihapus.');
        }
    }
    header('Location: ' . app_href('/settings/tingkatan.php'));
    exit;
}

$rows = $pdo->query('SELECT id, nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll();
$totalTingkatan = count($rows);

$pageTitle = 'Master Tingkatan';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/tingkatan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
    <h1 class="h4 mb-1">Kelola tingkatan</h1>
    <p class="text-muted mb-0">Tambah, ubah nama, atau hapus master tingkatan. Mengubah nama akan menyamakan teks tingkatan di data santri dan baris jadwal kegiatan yang memakai nama lama.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total tingkatan</div>
            <div class="app-mini-stat-value"><?= $totalTingkatan ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Tambah Tingkatan</h1>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create">
                    <div class="col-12">
                        <input type="text" class="form-control" name="nama_tingkatan" placeholder="Contoh: SMP Kelas 7" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar Tingkatan</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle">
                    <thead><tr><th>Ubah nama</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="text" name="nama_tingkatan" class="form-control form-control-sm" style="min-width:12rem;max-width:24rem;" maxlength="80" required value="<?= htmlspecialchars((string) $row['nama_tingkatan']) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </form>
                            </td>
                            <td class="text-end">
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus tingkatan ini? Data santri tidak ikut terhapus; hanya entri master yang hilang.')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
