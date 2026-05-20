<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada. Jalankan schema_presensi.sql.');
    header('Location: /pwa_nailulmuna/dashboard.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare('SELECT i.*, s.nama_santri, s.nis, s.id AS santri_id FROM perizinan i INNER JOIN santri s ON s.id = i.santri_id WHERE i.id = :id');
$statement->execute(['id' => $id]);
$izin = $statement->fetch();

if (!$izin) {
    set_flash('error', 'Data izin tidak ditemukan.');
    header('Location: /pwa_nailulmuna/perizinan/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'id' => $id,
        'jenis_izin' => in_array($_POST['jenis_izin'] ?? '', ['SAKIT', 'KELUAR', 'TUGAS', 'PULANG'], true) ? $_POST['jenis_izin'] : 'KELUAR',
        'tanggal_mulai' => $_POST['tanggal_mulai'] ?? date('Y-m-d'),
        'tanggal_selesai' => $_POST['tanggal_selesai'] ?? date('Y-m-d'),
        'jam_mulai' => $_POST['jam_mulai'] ?? date('H:i'),
        'jam_selesai' => $_POST['jam_selesai'] ?? date('H:i'),
        'durasi_jam' => (float) ($_POST['durasi_jam'] ?? 0),
        'alasan' => trim($_POST['alasan'] ?? ''),
        'pemberi_izin' => trim($_POST['pemberi_izin'] ?? ''),
        'penandatangan_pengasuh' => trim($_POST['penandatangan_pengasuh'] ?? ''),
        'status_izin' => in_array($_POST['status_izin'] ?? '', ['IZIN', 'SELESAI'], true) ? $_POST['status_izin'] : 'IZIN',
    ];

    $update = $pdo->prepare('UPDATE perizinan SET jenis_izin = :jenis_izin, tanggal_mulai = :tanggal_mulai, tanggal_selesai = :tanggal_selesai, jam_mulai = :jam_mulai, jam_selesai = :jam_selesai, durasi_jam = :durasi_jam, alasan = :alasan, pemberi_izin = :pemberi_izin, penandatangan_pengasuh = :penandatangan_pengasuh, status_izin = :status_izin WHERE id = :id');
    $update->execute($data);

    if ($data['status_izin'] === 'SELESAI') {
        $pdo->prepare('UPDATE santri SET is_aktif = 1 WHERE id = :id')->execute(['id' => $izin['santri_id']]);
    } else {
        $pdo->prepare('UPDATE santri SET is_aktif = 0 WHERE id = :id')->execute(['id' => $izin['santri_id']]);
    }

    set_flash('success', 'Data izin berhasil diperbarui.');
    header('Location: /pwa_nailulmuna/perizinan/index.php');
    exit;
}

$pageTitle = 'Edit Izin Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Edit Izin Santri</h1>
    <a href="/pwa_nailulmuna/perizinan/index.php" class="btn btn-outline-secondary">Kembali</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Santri</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($izin['nama_santri'] . ' (' . $izin['nis'] . ')') ?>" readonly>
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis Izin</label>
                <select name="jenis_izin" class="form-select" required>
                    <option value="KELUAR" <?= $izin['jenis_izin'] === 'KELUAR' ? 'selected' : '' ?>>Keluar</option>
                    <option value="SAKIT" <?= $izin['jenis_izin'] === 'SAKIT' ? 'selected' : '' ?>>Sakit</option>
                    <option value="TUGAS" <?= in_array((string) $izin['jenis_izin'], ['TUGAS', 'PULANG'], true) ? 'selected' : '' ?>>Tugas</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Mulai</label>
                <input type="date" name="tanggal_mulai" class="form-control" value="<?= htmlspecialchars($izin['tanggal_mulai']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Selesai</label>
                <input type="date" name="tanggal_selesai" class="form-control" value="<?= htmlspecialchars($izin['tanggal_selesai']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam Mulai</label>
                <input type="time" name="jam_mulai" class="form-control" value="<?= htmlspecialchars(substr($izin['jam_mulai'] ?? '', 0, 5)) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam Selesai</label>
                <input type="time" name="jam_selesai" class="form-control" value="<?= htmlspecialchars(substr($izin['jam_selesai'] ?? '', 0, 5)) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Durasi (jam)</label>
                <input type="number" step="0.25" min="0" name="durasi_jam" class="form-control" value="<?= htmlspecialchars($izin['durasi_jam'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Status Izin</label>
                <select name="status_izin" class="form-select">
                    <option value="IZIN" <?= $izin['status_izin'] === 'IZIN' ? 'selected' : '' ?>>IZIN</option>
                    <option value="SELESAI" <?= $izin['status_izin'] === 'SELESAI' ? 'selected' : '' ?>>SELESAI</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Alasan</label>
                <textarea name="alasan" class="form-control" rows="3" required><?= htmlspecialchars($izin['alasan'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Pemberi Izin</label>
                <input type="text" name="pemberi_izin" class="form-control" value="<?= htmlspecialchars($izin['pemberi_izin'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Pengasuh</label>
                <input type="text" name="penandatangan_pengasuh" class="form-control" value="<?= htmlspecialchars($izin['penandatangan_pengasuh'] ?? '') ?>" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">Update Izin</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>