<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/keuangan_pkpps_syahriyah.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);

$pdo->exec('
    CREATE TABLE IF NOT EXISTS tingkatan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_tingkatan VARCHAR(80) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
require_once __DIR__ . '/../helpers/santri_list_sort.php';
tingkatan_ensure_urutan_column($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_pkpps_syahriyah') {
        $res = keuangan_pkpps_syahriyah_save_settings($pdo, $_POST);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-pkpps'));
        exit;
    }
    if ($action === 'sync_pkpps') {
        pkpps_sync_from_kelas_keuangan($pdo);
        set_flash('success', 'Tingkatan PKPPS diselaraskan dari kelas keuangan aktif.');
        header('Location: ' . app_href('/settings/tingkatan.php#pkpps'));
        exit;
    }
    if ($action === 'save_pkpps_tingkatan') {
        $tid = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $nama = mb_substr(trim((string) ($_POST['nama_tingkatan'] ?? '')), 0, 120);
        $urut = (int) ($_POST['urutan'] ?? 0);
        $aktif = (int) ($_POST['is_aktif'] ?? 1) === 1 ? 1 : 0;
        if ($tid <= 0 || $nama === '') {
            set_flash('error', 'Data tingkatan PKPPS tidak valid.');
        } else {
            $res = pkpps_tingkatan_update($pdo, $tid, $nama, $urut, $aktif);
            set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        }
        header('Location: ' . app_href('/settings/tingkatan.php#pkpps'));
        exit;
    }
    if ($action === 'delete_pkpps_tingkatan') {
        $tid = (int) ($_POST['pkpps_tingkatan_id'] ?? 0);
        $res = pkpps_tingkatan_delete($pdo, $tid);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/settings/tingkatan.php#pkpps'));
        exit;
    }
    if ($action === 'create_pkpps_tingkatan') {
        $nama = mb_substr(trim((string) ($_POST['nama_tingkatan'] ?? '')), 0, 120);
        $urut = (int) ($_POST['urutan'] ?? 0);
        $aktif = (int) ($_POST['is_aktif'] ?? 1) === 1 ? 1 : 0;
        $res = pkpps_tingkatan_create($pdo, $nama, $urut, $aktif);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/settings/tingkatan.php#pkpps'));
        exit;
    }
    if ($action === 'create') {
        $nama = mb_substr(trim((string) ($_POST['nama_tingkatan'] ?? '')), 0, 80);
        $urut = (int) ($_POST['urutan'] ?? 0);
        if ($nama !== '') {
            if ($urut <= 0) {
                $urut = (int) $pdo->query('SELECT COALESCE(MAX(urutan), 0) + 1 FROM tingkatan')->fetchColumn();
            }
            $insert = $pdo->prepare('INSERT IGNORE INTO tingkatan (nama_tingkatan, urutan) VALUES (:nama, :u)');
            $insert->execute(['nama' => $nama, 'u' => $urut]);
            set_flash('success', 'Tingkatan berhasil ditambahkan.');
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $namaBaru = mb_substr(trim((string) ($_POST['nama_tingkatan'] ?? '')), 0, 80);
        $urut = (int) ($_POST['urutan'] ?? 0);
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
                            $pdo->prepare('UPDATE tingkatan SET nama_tingkatan = :n, urutan = :u WHERE id = :id')->execute(['n' => $namaBaru, 'u' => $urut, 'id' => $id]);
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

$rows = $pdo->query('SELECT id, nama_tingkatan, urutan FROM tingkatan ORDER BY urutan ASC, nama_tingkatan ASC')->fetchAll();
$totalTingkatan = count($rows);

$pageTitle = 'Tingkatan & PKPPS';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/tingkatan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a></p>
    <h1 class="h4 mb-1">Tingkatan kajian &amp; PKPPS</h1>
    <p class="text-muted mb-0">Master tingkatan kajian santri/jadwal. Tingkatan PKPPS otomatis mengikuti <a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">kelas keuangan</a> aktif (sub-level 1–3 per kelas).</p>
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
                        <label class="form-label small mb-0">Urutan tampil</label>
                        <input type="number" class="form-control" name="urutan" value="0" min="0">
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
                    <thead><tr><th>Urut</th><th>Ubah nama</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-center" style="width:4rem"><?= (int) ($row['urutan'] ?? 0) ?></td>
                            <td>
                                <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="number" name="urutan" class="form-control form-control-sm" style="width:4rem" min="0" value="<?= (int) ($row['urutan'] ?? 0) ?>" title="Urutan">
                                    <input type="text" name="nama_tingkatan" class="form-control form-control-sm flex-grow-1" style="flex-basis:12rem;min-width:8rem;max-width:24rem;" maxlength="80" required value="<?= htmlspecialchars((string) $row['nama_tingkatan']) ?>">
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
$pkppsRows = pkpps_tingkatan_list($pdo, false);
?>
<div class="card shadow-sm mt-4" id="pkpps">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h6 mb-0 fw-bold">Tingkatan PKPPS</h2>
            <p class="small text-muted mb-0">Disinkronkan dari kelas keuangan (klik <em>Sinkron ulang</em>) · status aktif dapat diubah manual dan akan tetap tersimpan.</p>
        </div>
        <form method="post" class="m-0">
            <input type="hidden" name="action" value="sync_pkpps">
            <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-rotate me-1"></i> Sinkron ulang</button>
        </form>
    </div>
    <div class="card-body border-bottom py-3">
        <p class="small fw-semibold mb-2">Tambah tingkatan manual</p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="create_pkpps_tingkatan">
            <div class="col-md-5">
                <label class="form-label small mb-0">Nama tingkatan</label>
                <input type="text" name="nama_tingkatan" class="form-control form-control-sm" maxlength="120" required placeholder="Contoh: Muadalah A">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Urut</label>
                <input type="number" name="urutan" class="form-control form-control-sm" min="0" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Status</label>
                <select name="is_aktif" class="form-select form-select-sm">
                    <option value="1">Aktif</option>
                    <option value="0">Nonaktif</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i> Tambah</button>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
            <tr>
                <th style="width:4rem">Urut</th>
                <th>Nama &amp; status</th>
                <th class="text-end" style="width:8rem">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($pkppsRows === []): ?>
                <tr><td colspan="3" class="text-center text-muted py-3 small">Belum ada tingkatan PKPPS. Tambah/aktifkan kelas keuangan lalu klik sinkron.</td></tr>
            <?php endif; ?>
            <?php foreach ($pkppsRows as $prow): ?>
                <tr>
                    <td>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="save_pkpps_tingkatan">
                            <input type="hidden" name="pkpps_tingkatan_id" value="<?= (int) ($prow['id'] ?? 0) ?>">
                            <input type="number" name="urutan" class="form-control form-control-sm" style="width:4rem" min="0" value="<?= (int) ($prow['urutan'] ?? 0) ?>">
                    </td>
                    <td>
                            <input type="text" name="nama_tingkatan" class="form-control form-control-sm" maxlength="120" required value="<?= htmlspecialchars((string) ($prow['nama_tingkatan'] ?? '')) ?>">
                            <select name="is_aktif" class="form-select form-select-sm mt-1" style="max-width:8rem">
                                <option value="1" <?= (int) ($prow['is_aktif'] ?? 0) === 1 ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= (int) ($prow['is_aktif'] ?? 0) !== 1 ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                    </td>
                    <td class="text-end">
                            <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus tingkatan PKPPS ini?');">
                            <input type="hidden" name="action" value="delete_pkpps_tingkatan">
                            <input type="hidden" name="pkpps_tingkatan_id" value="<?= (int) ($prow['id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mt-4" id="syahriyah-pkpps">
    <div class="card-header fw-semibold">Tambahan syahriyah PKPPS</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Nominal per <strong>kelas keuangan</strong> (bukan per Wustho 1/2/3). Kelola di menu Keuangan.
        </p>
        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-pkpps')) ?>">
            Keuangan → Pengaturan syahriyah
        </a>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
