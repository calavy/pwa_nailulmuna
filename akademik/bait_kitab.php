<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_bait_kitab_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM akademik_bait_kitab WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'Kitab bait dihapus.');
        }
        header('Location: /akademik/bait_kitab.php');
        exit;
    }
    if ($action === 'simpan') {
        $id = (int) ($_POST['id'] ?? 0);
        $nama = trim((string) ($_POST['nama_kitab'] ?? ''));
        $baris = max(0, (int) ($_POST['jumlah_baris'] ?? 0));
        $hari = max(1, (int) ($_POST['estimasi_hari_selesai'] ?? 30));
        $urutan = (int) ($_POST['urutan'] ?? 0);
        $aktif = isset($_POST['is_aktif']) ? 1 : 0;
        if ($nama === '') {
            set_flash('error', 'Nama kitab wajib diisi.');
            header('Location: /akademik/bait_kitab.php' . ($id > 0 ? '?edit=' . $id : ''));
            exit;
        }
        $target = akademik_hitung_target_bait_per_hari($baris, $hari);
        if ($id > 0) {
            $pdo->prepare('
                UPDATE akademik_bait_kitab SET
                    nama_kitab = :nama, jumlah_baris = :baris, estimasi_hari_selesai = :hari,
                    target_baris_per_hari = :tgt, urutan = :urut, is_aktif = :aktif
                WHERE id = :id
            ')->execute([
                'nama' => mb_substr($nama, 0, 200),
                'baris' => $baris,
                'hari' => $hari,
                'tgt' => $target,
                'urut' => $urutan,
                'aktif' => $aktif,
                'id' => $id,
            ]);
            set_flash('success', 'Data kitab diperbarui.');
        } else {
            $pdo->prepare('
                INSERT INTO akademik_bait_kitab (nama_kitab, jumlah_baris, estimasi_hari_selesai, target_baris_per_hari, urutan, is_aktif)
                VALUES (:nama, :baris, :hari, :tgt, :urut, :aktif)
            ')->execute([
                'nama' => mb_substr($nama, 0, 200),
                'baris' => $baris,
                'hari' => $hari,
                'tgt' => $target,
                'urut' => $urutan,
                'aktif' => $aktif,
            ]);
            set_flash('success', 'Kitab bait ditambahkan.');
        }
        header('Location: /akademik/bait_kitab.php');
        exit;
    }
}

$rows = $pdo->query('SELECT * FROM akademik_bait_kitab ORDER BY urutan ASC, nama_kitab ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $e = $pdo->prepare('SELECT * FROM akademik_bait_kitab WHERE id = :id LIMIT 1');
    $e->execute(['id' => $editId]);
    $editRow = $e->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow === null) {
        set_flash('error', 'Data kitab untuk diedit tidak ditemukan.');
        header('Location: /akademik/bait_kitab.php');
        exit;
    }
}

$pageTitle = 'Pengaturan bait — kitab';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Akademik</p>
    <h1 class="h3 mb-1">Pengaturan setoran bait</h1>
    <p class="text-muted mb-0">Nama kitab, jumlah baris, dan estimasi hari selesai. <strong>Target baris per hari</strong> dihitung otomatis: ⌈jumlah baris ÷ hari⌉ (minimal 1). Dipakai di <a href="/akademik/hafalan.php">Input setoran hafalan</a> (kategori Bait).</p>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="/akademik/hafalan.php">Setoran hafalan</a>
    <a class="btn btn-outline-secondary btn-sm" href="/akademik/kalender.php">Kalender &amp; libur</a>
</div>

<div class="row g-4">
    <div class="col-lg-5" id="bait-kitab-form">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Ubah kitab' : 'Tambah kitab bait' ?></h2>
                <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="action" value="simpan">
                    <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                    <div>
                        <label class="form-label">Nama kitab</label>
                        <input type="text" name="nama_kitab" class="form-control" required maxlength="200" value="<?= htmlspecialchars((string) ($editRow['nama_kitab'] ?? '')) ?>">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Jumlah baris</label>
                            <input type="number" name="jumlah_baris" class="form-control" min="0" step="1" value="<?= (int) ($editRow['jumlah_baris'] ?? 0) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Estimasi hari selesai</label>
                            <input type="number" name="estimasi_hari_selesai" class="form-control" min="1" step="1" value="<?= (int) ($editRow['estimasi_hari_selesai'] ?? 30) ?>">
                        </div>
                    </div>
                    <p class="small text-muted mb-0">Target/hari = ⌈baris ÷ hari⌉. Contoh: 120 baris ÷ 30 hari = <strong>4</strong> baris/hari.</p>
                    <div>
                        <label class="form-label">Urutan tampil</label>
                        <input type="number" name="urutan" class="form-control" step="1" value="<?= (int) ($editRow['urutan'] ?? 0) ?>">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_aktif" id="aktif" <?= ($editRow === null || !empty($editRow['is_aktif'])) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aktif">Aktif (tampil di pilihan setoran)</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                        <?php if ($editRow): ?>
                            <a class="btn btn-outline-secondary" href="/akademik/bait_kitab.php">Batal edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="px-3 py-2 border-bottom bg-light"><h2 class="h6 mb-0">Daftar kitab bait</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kitab</th>
                                <th class="text-end">Baris</th>
                                <th class="text-end">Hari</th>
                                <th class="text-end">Target/hari</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="5" class="text-muted text-center py-4">Belum ada kitab.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <tr class="<?= empty($r['is_aktif']) ? 'table-secondary' : '' ?>">
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars((string) $r['nama_kitab']) ?></div>
                                    <span class="badge text-bg-light border small"><?= empty($r['is_aktif']) ? 'Nonaktif' : 'Aktif' ?></span>
                                </td>
                                <td class="text-end font-monospace small"><?= (int) $r['jumlah_baris'] ?></td>
                                <td class="text-end small"><?= (int) $r['estimasi_hari_selesai'] ?></td>
                                <td class="text-end font-monospace small text-success"><?= (int) $r['target_baris_per_hari'] ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="/akademik/bait_kitab.php?edit=<?= (int) $r['id'] ?>#bait-kitab-form">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus kitab ini?');">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
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

<?php if ($editRow): ?>
<script>
(function () {
    var el = document.getElementById('bait-kitab-form');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
