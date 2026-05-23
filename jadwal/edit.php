<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/operasional_audit.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';

require_roles(['admin', 'pengurus']);
$auditUserId = (int) ($_SESSION['user']['id'] ?? 0);

if (!table_exists($pdo, 'jadwal_kegiatan')) {
    set_flash('error', 'Tabel jadwal belum ada. Jalankan schema_presensi.sql terlebih dahulu.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS tempat VARCHAR(255) NULL');

$id = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare('SELECT * FROM jadwal_kegiatan WHERE id = :id');
$statement->execute(['id' => $id]);
$jadwal = $statement->fetch();

if (!$jadwal) {
    set_flash('error', 'Data jadwal tidak ditemukan.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $beforeAudit = jadwal_kegiatan_audit_fetch($pdo, $id);
    $tingkatanInput = $_POST['tingkatan'] ?? [];
    $hariInput = $_POST['hari_ke'] ?? [];
    $tingkatanDipilih = is_array($tingkatanInput) ? array_values(array_filter(array_map('trim', $tingkatanInput), static fn($v): bool => $v !== '')) : [];
    $hariDipilih = is_array($hariInput) ? array_values(array_filter(array_map('intval', $hariInput), static fn($v): bool => $v >= 0 && $v <= 7)) : [];
    if (!$tingkatanDipilih || !$hariDipilih) {
        set_flash('error', 'Pilih minimal 1 tingkatan dan 1 hari.');
        header('Location: ' . app_rewrite_internal_url('/jadwal/edit.php?id=' . $id));
        exit;
    }

    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
    $jamMulai = $_POST['jam_mulai'] ?? '00:00';
    $jamSelesai = $_POST['jam_selesai'] ?? '00:00';
    if (jadwal_norm_jam($jamSelesai) <= jadwal_norm_jam($jamMulai)) {
        set_flash('error', 'Jam selesai harus setelah jam mulai.');
        header('Location: ' . app_rewrite_internal_url('/jadwal/edit.php?id=' . $id));
        exit;
    }
    $pembimbingId = (int) ($_POST['pembimbing_id'] ?? 0) ?: null;
    $tempatVal = trim((string) ($_POST['tempat'] ?? ''));
    $tempatVal = $tempatVal !== '' ? $tempatVal : null;

    $hariLabels = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
    $firstCombo = true;
    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $exclude = $firstCombo ? $id : 0;
            $bentrok = jadwal_cek_bentrok($pdo, $tingkatan, $hariKe, $jamMulai, $jamSelesai, $exclude);
            if ($bentrok !== null) {
                set_flash('error', jadwal_pesan_bentrok($bentrok, $hariLabels));
                header('Location: ' . app_rewrite_internal_url('/jadwal/edit.php?id=' . $id));
                exit;
            }
            $firstCombo = false;
        }
    }

    $update = $pdo->prepare('UPDATE jadwal_kegiatan SET kegiatan_id = :kegiatan_id, tingkatan = :tingkatan, hari_ke = :hari_ke, jam_mulai = :jam_mulai, jam_selesai = :jam_selesai, pembimbing_id = :pembimbing_id, tempat = :tempat WHERE id = :id');
    $insert = $pdo->prepare('INSERT INTO jadwal_kegiatan (kegiatan_id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat) VALUES (:kegiatan_id, :tingkatan, :hari_ke, :jam_mulai, :jam_selesai, :pembimbing_id, :tempat)');

    $first = true;
    $created = 0;
    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $payload = [
                'kegiatan_id' => $kegiatanId,
                'tingkatan' => $tingkatan,
                'hari_ke' => $hariKe,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'pembimbing_id' => $pembimbingId,
                'tempat' => $tempatVal,
            ];
            if ($first) {
                $update->execute($payload + ['id' => $id]);
                $first = false;
            } else {
                $insert->execute($payload);
                $created++;
            }
        }
    }

    $afterAudit = jadwal_kegiatan_audit_fetch($pdo, $id);
    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'UPDATE',
        $id,
        $beforeAudit,
        [
            'jadwal_utama' => $afterAudit,
            'jadwal_tambahan_dibuat' => $created,
        ],
        $auditUserId,
        'Perubahan jadwal #' . $id . ($created > 0 ? ' (+ ' . $created . ' baris baru)' : '')
    );
    set_flash('success', 'Data jadwal berhasil diperbarui. Jadwal tambahan dibuat: ' . $created . '.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
array_unshift($tingkatanList, 'Semua Tingkatan');
$pembimbingList = table_exists($pdo, 'pembimbing')
    ? $pdo->query('SELECT id, nama_pembimbing FROM pembimbing ORDER BY nama_pembimbing ASC')->fetchAll()
    : [];
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll();
$hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$selectedTingkatan = [(string) ($jadwal['tingkatan'] ?? '')];
$selectedHari = [(int) ($jadwal['hari_ke'] ?? 0)];

$pageTitle = 'Edit Jadwal Kegiatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Edit Jadwal Kegiatan</h1>
    <a href="/jadwal/index.php" class="btn btn-outline-secondary">Kembali</a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Kegiatan</label>
                <select name="kegiatan_id" class="form-select" required>
                    <option value="">Pilih kegiatan</option>
                    <?php foreach ($kegiatanList as $kegiatan): ?>
                        <option value="<?= $kegiatan['id'] ?>" <?= (int) $jadwal['kegiatan_id'] === (int) $kegiatan['id'] ? 'selected' : '' ?>><?= htmlspecialchars($kegiatan['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tingkatan (centang bisa banyak)</label>
                <?php require __DIR__ . '/../includes/partials/jadwal_tingkatan_chips.php'; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Hari (centang bisa banyak)</label>
                <div class="border rounded p-2">
                    <?php foreach ($hari as $key => $label): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="hari_ke[]" id="hari-<?= (int) $key ?>" value="<?= (int) $key ?>" <?= in_array((int) $key, $selectedHari, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="hari-<?= (int) $key ?>"><?= htmlspecialchars($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam Mulai</label>
                <input type="time" name="jam_mulai" class="form-control" value="<?= htmlspecialchars(substr($jadwal['jam_mulai'] ?? '', 0, 5)) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam Selesai</label>
                <input type="time" name="jam_selesai" class="form-control" value="<?= htmlspecialchars(substr($jadwal['jam_selesai'] ?? '', 0, 5)) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Pembimbing (opsional)</label>
                <select name="pembimbing_id" class="form-select">
                    <option value="0">Belum ditentukan</option>
                    <?php foreach ($pembimbingList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $jadwal['pembimbing_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nama_pembimbing']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Tempat / lokasi</label>
                <input type="text" name="tempat" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) ($jadwal['tempat'] ?? '')) ?>" placeholder="Contoh: Masjid Utama, Aula">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">Update Jadwal</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>