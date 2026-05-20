<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);

$id = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare('SELECT * FROM pembimbing WHERE id = :id');
$statement->execute(['id' => $id]);
$pembimbing = $statement->fetch();

if (!$pembimbing) {
    set_flash('error', 'Data pembimbing tidak ditemukan.');
    header('Location: /pembimbing/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'id' => $id,
        'qr' => trim($_POST['qr'] ?? ''),
        'nip' => trim($_POST['nip'] ?? ''),
        'nama' => trim($_POST['nama_pembimbing'] ?? ''),
        'wa' => trim($_POST['no_wa'] ?? ''),
        'is_aktif' => isset($_POST['is_aktif']) && $_POST['is_aktif'] === '1' ? 1 : 0,
    ];

    $update = $pdo->prepare('UPDATE pembimbing SET qr = :qr, nip = :nip, nama_pembimbing = :nama, no_wa = :wa, is_aktif = :is_aktif WHERE id = :id');
    $update->execute($data);

    set_flash('success', 'Data pembimbing berhasil diperbarui.');
    header('Location: /pembimbing/index.php');
    exit;
}

$pageTitle = 'Edit Pembimbing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Edit Pembimbing</h1>
    <a href="/pembimbing/index.php" class="btn btn-outline-secondary">Kembali</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">QR</label>
                <input type="text" name="qr" class="form-control" value="<?= htmlspecialchars($pembimbing['qr'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">NIP / ID</label>
                <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($pembimbing['nip'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama Pembimbing</label>
                <input type="text" name="nama_pembimbing" class="form-control" value="<?= htmlspecialchars($pembimbing['nama_pembimbing'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">No WA</label>
                <input type="text" name="no_wa" class="form-control" value="<?= htmlspecialchars($pembimbing['no_wa'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select name="is_aktif" class="form-select">
                    <option value="1" <?= (int) $pembimbing['is_aktif'] === 1 ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= (int) $pembimbing['is_aktif'] === 0 ? 'selected' : '' ?>>Izin / Tidak Aktif</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">Update</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>