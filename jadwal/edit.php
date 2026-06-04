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
    require_once __DIR__ . '/../helpers/presensi_admin.php';
    $beforeAudit = jadwal_kegiatan_audit_fetch($pdo, $id);
    $siblingsBefore = jadwal_slot_sejenis($pdo, $id);
    $siblingIdsBefore = jadwal_slot_sejenis_ids($siblingsBefore);

    $tingkatanInput = $_POST['tingkatan'] ?? [];
    $hariInput = $_POST['hari_ke'] ?? [];
    $tingkatanDipilih = is_array($tingkatanInput) ? array_values(array_filter(array_map('trim', $tingkatanInput), static fn ($v): bool => $v !== '')) : [];
    $hariDipilih = is_array($hariInput) ? array_values(array_filter(array_map('intval', $hariInput), static fn ($v): bool => $v >= 0 && $v <= 7)) : [];
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

    $origKg = (int) ($jadwal['kegiatan_id'] ?? 0);
    $origPb = isset($jadwal['pembimbing_id']) && $jadwal['pembimbing_id'] !== null && $jadwal['pembimbing_id'] !== ''
        ? (int) $jadwal['pembimbing_id']
        : null;
    $origJamMulai = (string) ($jadwal['jam_mulai'] ?? '');
    $origJamSelesai = (string) ($jadwal['jam_selesai'] ?? '');
    $slotSignatureChanged = $kegiatanId !== $origKg
        || $pembimbingId !== $origPb
        || jadwal_norm_jam($jamMulai) !== jadwal_norm_jam($origJamMulai)
        || jadwal_norm_jam($jamSelesai) !== jadwal_norm_jam($origJamSelesai);

    $idsToReplace = $slotSignatureChanged ? [$id] : $siblingIdsBefore;

    $hariLabels = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
    $excludeIds = array_fill_keys($idsToReplace, true);
    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $bentrok = jadwal_cek_bentrok($pdo, $tingkatan, $hariKe, $jamMulai, $jamSelesai);
            if ($bentrok !== null && !isset($excludeIds[(int) ($bentrok['id'] ?? 0)])) {
                set_flash('error', jadwal_pesan_bentrok($bentrok, $hariLabels));
                header('Location: ' . app_rewrite_internal_url('/jadwal/edit.php?id=' . $id));
                exit;
            }
        }
    }

    foreach ($idsToReplace as $delId) {
        if ($delId !== $id) {
            presensi_hapus_untuk_jadwal($pdo, $delId);
            $pdo->prepare('DELETE FROM jadwal_kegiatan WHERE id = :id')->execute(['id' => $delId]);
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
            'slot_sejenis_diganti' => count($idsToReplace),
        ],
        $auditUserId,
        'Perubahan jadwal #' . $id . ($created > 0 ? ' (+ ' . $created . ' baris baru)' : '')
    );
    $msg = 'Data jadwal berhasil diperbarui.';
    if ($created > 0) {
        $msg .= ' Baris baru: ' . $created . '.';
    }
    if ($slotSignatureChanged) {
        $msg .= ' Jam/kegiatan/pembimbing berubah — disimpan sebagai slot terpisah.';
    }
    set_flash('success', $msg);
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
$siblingSlots = jadwal_slot_sejenis($pdo, $id);
$selectedTingkatan = [];
$selectedHari = [];
foreach ($siblingSlots as $slot) {
    $tk = trim((string) ($slot['tingkatan'] ?? ''));
    if ($tk !== '' && !in_array($tk, $selectedTingkatan, true)) {
        $selectedTingkatan[] = $tk;
    }
    $hk = (int) ($slot['hari_ke'] ?? 0);
    if (!in_array($hk, $selectedHari, true)) {
        $selectedHari[] = $hk;
    }
}
if ($selectedTingkatan === []) {
    $selectedTingkatan = [(string) ($jadwal['tingkatan'] ?? '')];
}
if ($selectedHari === []) {
    $selectedHari = [(int) ($jadwal['hari_ke'] ?? 0)];
}
$siblingCount = count($siblingSlots);

$pageTitle = 'Edit Jadwal Kegiatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal</a></p>
    <h1 class="h4 mb-1">Edit slot jadwal</h1>
    <p class="text-muted mb-0 small">Ubah hari, tingkatan, jam, pembimbing, atau tempat. Centang beberapa hari/tingkatan untuk mengubah sekaligus.</p>
</div>

<?php if ($siblingCount > 1): ?>
<div class="alert alert-info py-2 small mb-3">
    <i class="fa-solid fa-layer-group me-1"></i>
    Slot terhubung <strong><?= (int) $siblingCount ?></strong> baris (jam
    <span class="font-monospace"><?= htmlspecialchars(jadwal_jam_ringkas($jadwal)) ?></span> sama).
    Ubah jam untuk menyimpan sebagai slot terpisah.
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12"><h2 class="h6 text-muted mb-0">Kegiatan & kelas</h2></div>
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
                <label class="form-label">Tingkatan</label>
                <?php require __DIR__ . '/../includes/partials/jadwal_tingkatan_chips.php'; ?>
            </div>
            <div class="col-12"><h2 class="h6 text-muted mb-0 pt-1">Waktu & hari</h2></div>
            <div class="col-md-4">
                <label class="form-label">Jam mulai</label>
                <input type="text" name="jam_mulai" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam((string) ($jadwal['jam_mulai'] ?? ''))) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam selesai</label>
                <input type="text" name="jam_selesai" <?= app_time_input_attrs() ?> value="<?= htmlspecialchars(app_format_jam((string) ($jadwal['jam_selesai'] ?? ''))) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tempat</label>
                <input type="text" name="tempat" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) ($jadwal['tempat'] ?? '')) ?>" placeholder="Masjid, Aula, …">
            </div>
            <div class="col-12">
                <label class="form-label">Hari</label>
                <div class="jadwal-hari-pills border rounded p-2 d-flex flex-wrap gap-2">
                    <?php foreach ($hari as $key => $label): ?>
                        <label class="jadwal-hari-pill">
                            <input class="form-check-input" type="checkbox" name="hari_ke[]" value="<?= (int) $key ?>" <?= in_array((int) $key, $selectedHari, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12"><h2 class="h6 text-muted mb-0 pt-1">Pembimbing</h2></div>
            <div class="col-md-6">
                <label class="form-label">Pembimbing (opsional)</label>
                <select name="pembimbing_id" class="form-select">
                    <option value="0">Belum ditentukan</option>
                    <?php foreach ($pembimbingList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $jadwal['pembimbing_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nama_pembimbing']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan perubahan</button>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>