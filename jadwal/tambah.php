<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/operasional_audit.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';

require_roles(['admin', 'pengurus']);
$auditUserId = (int) ($_SESSION['user']['id'] ?? 0);

if (!table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'jadwal_kegiatan')) {
    set_flash('error', 'Tabel jadwal belum ada.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS tempat VARCHAR(255) NULL');

$hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
array_unshift($tingkatanList, 'Semua Tingkatan');
$pembimbingList = table_exists($pdo, 'pembimbing')
    ? $pdo->query('SELECT id, nama_pembimbing FROM pembimbing ORDER BY nama_pembimbing ASC')->fetchAll()
    : [];
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE COALESCE(is_active, 1) = 1 ORDER BY nama_kegiatan ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tingkatanInput = $_POST['tingkatan'] ?? [];
    $hariInput = $_POST['hari_ke'] ?? [];
    $tingkatanDipilih = is_array($tingkatanInput) ? array_values(array_filter(array_map('trim', $tingkatanInput), static fn ($v): bool => $v !== '')) : [];
    $hariDipilih = is_array($hariInput) ? array_values(array_filter(array_map('intval', $hariInput), static fn ($v): bool => $v >= 0 && $v <= 7)) : [];

    if (!$tingkatanDipilih || !$hariDipilih) {
        set_flash('error', 'Pilih minimal 1 tingkatan dan 1 hari.');
        header('Location: ' . app_href('/jadwal/tambah.php'));
        exit;
    }

    $kegiatanId = (int) ($_POST['kegiatan_id'] ?? 0);
    if ($kegiatanId <= 0) {
        set_flash('error', 'Pilih kegiatan.');
        header('Location: ' . app_href('/jadwal/tambah.php'));
        exit;
    }

    $jamMulai = (string) ($_POST['jam_mulai'] ?? '00:00');
    $jamSelesai = (string) ($_POST['jam_selesai'] ?? '00:00');
    if (jadwal_norm_jam($jamSelesai) <= jadwal_norm_jam($jamMulai)) {
        set_flash('error', 'Jam selesai harus setelah jam mulai.');
        header('Location: ' . app_href('/jadwal/tambah.php'));
        exit;
    }

    $tempatJadwal = trim((string) ($_POST['tempat'] ?? ''));
    $tempatJadwal = $tempatJadwal !== '' ? $tempatJadwal : null;

    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $bentrok = jadwal_cek_bentrok($pdo, $tingkatan, $hariKe, $jamMulai, $jamSelesai);
            if ($bentrok !== null) {
                set_flash('error', jadwal_pesan_bentrok($bentrok, $hari));
                header('Location: ' . app_href('/jadwal/tambah.php'));
                exit;
            }
        }
    }

    $insert = $pdo->prepare('
        INSERT INTO jadwal_kegiatan (kegiatan_id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat)
        VALUES (:kegiatan_id, :tingkatan, :hari_ke, :jam_mulai, :jam_selesai, :pembimbing_id, :tempat)
    ');
    $created = 0;
    $createdIds = [];
    foreach ($tingkatanDipilih as $tingkatan) {
        foreach ($hariDipilih as $hariKe) {
            $insert->execute([
                'kegiatan_id' => $kegiatanId,
                'tingkatan' => $tingkatan,
                'hari_ke' => $hariKe,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'pembimbing_id' => (int) ($_POST['pembimbing_id'] ?? 0) ?: null,
                'tempat' => $tempatJadwal,
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($newId > 0) {
                $createdIds[] = $newId;
            }
            $created++;
        }
    }

    $firstId = $createdIds[0] ?? 0;
    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'CREATE',
        $firstId,
        null,
        [
            'jumlah_baru' => $created,
            'jadwal_ids' => $createdIds,
            'kegiatan_id' => $kegiatanId,
            'tingkatan' => $tingkatanDipilih,
            'hari_ke' => $hariDipilih,
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'tempat' => $tempatJadwal,
        ],
        $auditUserId,
        'Penambahan jadwal (' . $created . ' baris)'
    );
    set_flash('success', 'Jadwal berhasil ditambahkan: ' . $created . ' slot.');
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

$pageTitle = 'Tambah Jadwal';
$bodyClass = 'jadwal-page';
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal</a></p>
    <h1 class="h4 mb-1">Tambah slot jadwal</h1>
    <p class="text-muted small mb-0">Sistem menolak jadwal bentrok: tingkatan + hari + jam yang tumpang tidak diizinkan (cegah alpa ganda).</p>
</div>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm jadwal-form-card">
    <div class="card-body">
        <?php if ($kegiatanList === []): ?>
            <p class="text-warning small mb-3">Belum ada kegiatan. <a href="<?= htmlspecialchars(app_href('/jadwal/tambah_kegiatan.php')) ?>">Tambah kegiatan</a> dulu.</p>
        <?php endif; ?>
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Kegiatan</label>
                <select class="form-select" name="kegiatan_id" required>
                    <option value="">— Pilih kegiatan —</option>
                    <?php foreach ($kegiatanList as $kegiatan): ?>
                        <option value="<?= (int) $kegiatan['id'] ?>"><?= htmlspecialchars((string) $kegiatan['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Pembimbing (opsional)</label>
                <select class="form-select" name="pembimbing_id">
                    <option value="0">Belum ditentukan</option>
                    <?php foreach ($pembimbingList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['nama_pembimbing']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Tingkatan (boleh banyak)</label>
                <?php $selectedTingkatan = []; require __DIR__ . '/../includes/partials/jadwal_tingkatan_chips.php'; ?>
            </div>
            <div class="col-12">
                <label class="form-label">Hari (boleh banyak)</label>
                <div class="jadwal-hari-pills border rounded p-2 d-flex flex-wrap gap-2">
                    <?php foreach ($hari as $key => $label): ?>
                        <label class="jadwal-hari-pill">
                            <input class="form-check-input" type="checkbox" name="hari_ke[]" value="<?= (int) $key ?>">
                            <span><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam mulai</label>
                <input type="time" class="form-control" name="jam_mulai" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jam selesai</label>
                <input type="time" class="form-control" name="jam_selesai" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tempat / lokasi</label>
                <input type="text" class="form-control" name="tempat" maxlength="255" placeholder="Masjid, Aula, …">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"<?= $kegiatanList === [] ? ' disabled' : '' ?>><i class="fa-solid fa-calendar-plus me-1"></i> Simpan jadwal</button>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
