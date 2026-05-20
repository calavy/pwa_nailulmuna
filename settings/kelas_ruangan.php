<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/kelas_ruangan.php';

require_roles(['admin', 'pengurus']);
ensure_kelas_ruangan_table($pdo);
ensure_santri_identity_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create') {
        $nama = mb_substr(trim((string) ($_POST['nama_ruangan'] ?? '')), 0, 120);
        $urutan = (int) ($_POST['urutan'] ?? 0);
        $ket = mb_substr(trim((string) ($_POST['keterangan'] ?? '')), 0, 255);
        if ($nama === '') {
            set_flash('error', 'Nama ruangan wajib diisi.');
        } else {
            try {
                $pdo->prepare('INSERT INTO kelas_ruangan (nama_ruangan, urutan, keterangan) VALUES (:n, :u, :k)')->execute(['n' => $nama, 'u' => $urutan, 'k' => $ket !== '' ? $ket : null]);
                set_flash('success', 'Ruangan kelas berhasil ditambahkan.');
            } catch (Throwable $e) {
                set_flash('error', 'Nama ruangan sudah ada atau gagal menyimpan.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $namaBaru = mb_substr(trim((string) ($_POST['nama_ruangan'] ?? '')), 0, 120);
        $urutan = (int) ($_POST['urutan'] ?? 0);
        $ket = mb_substr(trim((string) ($_POST['keterangan'] ?? '')), 0, 255);
        if ($id <= 0 || $namaBaru === '') {
            set_flash('error', 'Data tidak valid.');
        } else {
            $cur = $pdo->prepare('SELECT nama_ruangan FROM kelas_ruangan WHERE id = :id LIMIT 1');
            $cur->execute(['id' => $id]);
            $lama = $cur->fetchColumn();
            if ($lama === false) {
                set_flash('error', 'Ruangan tidak ditemukan.');
            } else {
                $namaLama = (string) $lama;
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE kelas_ruangan SET nama_ruangan = :n, urutan = :u, keterangan = :k WHERE id = :id')->execute([
                        'n' => $namaBaru, 'u' => $urutan, 'k' => $ket !== '' ? $ket : null, 'id' => $id,
                    ]);
                    $pdo->commit();
                    set_flash('success', 'Ruangan diperbarui.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    set_flash('error', 'Gagal menyimpan (nama bentrok?).');
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            if (column_exists($pdo, 'santri', 'kelas_ruangan_id')) {
                $pdo->prepare('UPDATE santri SET kelas_ruangan_id = NULL WHERE kelas_ruangan_id = :id')->execute(['id' => $id]);
            }
            $pdo->prepare('DELETE FROM kelas_ruangan WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Ruangan dihapus.');
        }
    }
    header('Location: /pwa_nailulmuna/settings/kelas_ruangan.php');
    exit;
}

$rows = kelas_ruangan_list_all($pdo);
$total = count($rows);

$pageTitle = 'Master Ruangan Kelas';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/pwa_nailulmuna/settings/kelas_ruangan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/pwa_nailulmuna/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
    <h1 class="h4 mb-1">Ruangan kelas</h1>
    <p class="text-muted mb-0">Master ruang belajar / kelas formal. Dipilih saat input data santri (opsional).</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total ruangan</div>
            <div class="app-mini-stat-value"><?= $total ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Tambah ruangan</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="create">
                    <div class="col-12">
                        <label class="form-label small">Nama ruangan</label>
                        <input type="text" name="nama_ruangan" class="form-control" maxlength="120" placeholder="Contoh: Kelas 7A — Blok Timur" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Urutan</label>
                        <input type="number" name="urutan" class="form-control" value="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Keterangan (opsional)</label>
                        <input type="text" name="keterangan" class="form-control" maxlength="255" placeholder="Kapasitas, lantai, dll.">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar ruangan</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Nama &amp; keterangan</th>
                                <th style="width:6rem;">Urutan</th>
                                <th class="text-end" style="width:8rem;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td>
                                        <form method="post" class="row g-2 align-items-end">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <div class="col-12 col-md-5">
                                                <input type="text" name="nama_ruangan" class="form-control form-control-sm" maxlength="120" required value="<?= htmlspecialchars((string) $row['nama_ruangan']) ?>">
                                            </div>
                                            <div class="col-12 col-md-5">
                                                <input type="text" name="keterangan" class="form-control form-control-sm" maxlength="255" placeholder="Keterangan" value="<?= htmlspecialchars((string) ($row['keterangan'] ?? '')) ?>">
                                            </div>
                                            <div class="col-6 col-md-2">
                                                <input type="number" name="urutan" class="form-control form-control-sm" value="<?= (int) $row['urutan'] ?>">
                                            </div>
                                            <div class="col-6 col-md-auto">
                                                <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td class="small text-muted">—</td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus ruangan ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rows): ?>
                                <tr><td colspan="3" class="text-muted">Belum ada data.</td></tr>
                            <?php endif; ?>
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
