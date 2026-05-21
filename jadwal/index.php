<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/operasional_audit.php';
require_once __DIR__ . '/../helpers/presensi_admin.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';

require_roles(['admin', 'pengurus']);
$auditUserId = (int) ($_SESSION['user']['id'] ?? 0);

if (!table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'jadwal_kegiatan')) {
    set_flash('error', 'Tabel jadwal belum ada. Jalankan schema_presensi.sql terlebih dahulu.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
$pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS tempat VARCHAR(255) NULL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'kegiatan') {
        $namaKegiatan = trim($_POST['nama_kegiatan'] ?? '');
        if ($namaKegiatan !== '') {
            $insert = $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, is_active) VALUES (:nama_kegiatan, 1)');
            $insert->execute(['nama_kegiatan' => $namaKegiatan]);
            set_flash('success', 'Kegiatan ditambahkan.');
        }
    }

    if ($action === 'jadwal') {
        $tingkatanInput = $_POST['tingkatan'] ?? [];
        $hariInput = $_POST['hari_ke'] ?? [];
        $tingkatanDipilih = is_array($tingkatanInput) ? array_values(array_filter(array_map('trim', $tingkatanInput), static fn($v): bool => $v !== '')) : [];
        $hariDipilih = is_array($hariInput) ? array_values(array_filter(array_map('intval', $hariInput), static fn($v): bool => $v >= 0 && $v <= 7)) : [];

        if (!$tingkatanDipilih || !$hariDipilih) {
            set_flash('error', 'Pilih minimal 1 tingkatan dan 1 hari.');
            header('Location: ' . app_href('/jadwal/index.php'));
            exit;
        }

        $tempatJadwal = trim((string) ($_POST['tempat'] ?? ''));
        $tempatJadwal = $tempatJadwal !== '' ? $tempatJadwal : null;
        $insert = $pdo->prepare('
            INSERT INTO jadwal_kegiatan (kegiatan_id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat)
            VALUES (:kegiatan_id, :tingkatan, :hari_ke, :jam_mulai, :jam_selesai, :pembimbing_id, :tempat)
        ');
        $created = 0;
        $createdIds = [];
        foreach ($tingkatanDipilih as $tingkatan) {
            foreach ($hariDipilih as $hariKe) {
                $insert->execute([
                    'kegiatan_id' => (int) ($_POST['kegiatan_id'] ?? 0),
                    'tingkatan' => $tingkatan,
                    'hari_ke' => $hariKe,
                    'jam_mulai' => $_POST['jam_mulai'] ?? '00:00',
                    'jam_selesai' => $_POST['jam_selesai'] ?? '00:00',
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
                'kegiatan_id' => (int) ($_POST['kegiatan_id'] ?? 0),
                'tingkatan' => $tingkatanDipilih,
                'hari_ke' => $hariDipilih,
                'jam_mulai' => $_POST['jam_mulai'] ?? '00:00',
                'jam_selesai' => $_POST['jam_selesai'] ?? '00:00',
                'tempat' => $tempatJadwal,
            ],
            $auditUserId,
            'Penambahan jadwal kegiatan (' . $created . ' baris)'
        );
        set_flash('success', 'Jadwal berhasil ditambahkan: ' . $created . ' data.');
    }

    if ($action === 'hapus_jadwal') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $before = jadwal_kegiatan_audit_fetch($pdo, $id);
            $hapusPresensi = presensi_hapus_untuk_jadwal($pdo, $id);
            $delete = $pdo->prepare('DELETE FROM jadwal_kegiatan WHERE id = :id');
            $delete->execute(['id' => $id]);
            operasional_audit_log(
                $pdo,
                OPERASIONAL_AUDIT_MODUL_JADWAL,
                'DELETE',
                $id,
                $before,
                null,
                $auditUserId,
                'Penghapusan jadwal #' . $id . ($hapusPresensi > 0 ? ' (+ ' . $hapusPresensi . ' presensi terkait)' : '')
            );
            $msg = 'Jadwal berhasil dihapus.';
            if ($hapusPresensi > 0) {
                $msg .= ' Presensi terkait: ' . $hapusPresensi . ' baris ikut dihapus.';
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', 'ID jadwal tidak valid.');
        }
    }

    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

if (isset($_GET['grup'])) {
    $g = strtolower(trim((string) $_GET['grup']));
    if (in_array($g, ['kegiatan', 'tingkatan'], true)) {
        jadwal_simpan_tampilan_grup($pdo, $g);
    }
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
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan, is_active FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll();
$jadwalList = $pdo->query("SELECT j.id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, k.nama_kegiatan, COALESCE(p.nama_pembimbing, '-') AS nama_pembimbing FROM jadwal_kegiatan j INNER JOIN kegiatan k ON k.id = j.kegiatan_id LEFT JOIN pembimbing p ON p.id = j.pembimbing_id ORDER BY k.nama_kegiatan ASC, j.hari_ke ASC, j.jam_mulai ASC, j.tingkatan ASC")->fetchAll();
$totalKegiatan = count($kegiatanList);
$totalJadwal = count($jadwalList);

$hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

$tampilanGrup = jadwal_tampilan_grup($pdo);
$jadwalGrouped = $tampilanGrup === 'kegiatan'
    ? jadwal_kelompokkan_per_kegiatan($jadwalList)
    : jadwal_kelompokkan_per_tingkatan($jadwalList);
jadwal_urutkan_grup_hari($jadwalGrouped);

if ($tampilanGrup === 'tingkatan') {
    $tingkatanSortIndex = array_flip(array_values($tingkatanList));
    uksort($jadwalGrouped, static function (string $a, string $b) use ($tingkatanSortIndex): int {
        $ia = $tingkatanSortIndex[$a] ?? PHP_INT_MAX;
        $ib = $tingkatanSortIndex[$b] ?? PHP_INT_MAX;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }

        return strcmp($a, $b);
    });
} else {
    ksort($jadwalGrouped, SORT_NATURAL | SORT_FLAG_CASE);
}

$pageTitle = 'Jadwal Kegiatan';
$bodyClass = 'jadwal-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Jadwal</p>
    <h1 class="h4 mb-1">Jadwal kegiatan santri</h1>
    <p class="text-muted mb-0">Atur kegiatan per tingkatan, hari, jam, pembimbing, dan lokasi dalam satu halaman.</p>
    <?php if (user_can_lihat_audit_operasional()): ?>
        <p class="small mb-0 mt-2">
            <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php?modul=jadwal_kegiatan')) ?>"><i class="fa-solid fa-clipboard-list me-1"></i> Log audit jadwal</a>
        </p>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Data kegiatan</div>
            <div class="app-mini-stat-value"><?= $totalKegiatan ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total jadwal</div>
            <div class="app-mini-stat-value"><?= $totalJadwal ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tingkatan terjadwal</div>
            <div class="app-mini-stat-value"><?= count(array_unique(array_map(static fn(array $r): string => (string) ($r['tingkatan'] ?? '-'), $jadwalList))) ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Tambah Kegiatan</h2>
                <form method="post">
                    <input type="hidden" name="action" value="kegiatan">
                    <input type="text" class="form-control mb-2" name="nama_kegiatan" placeholder="Nama kegiatan" required>
                    <button class="btn btn-success btn-sm">Simpan Kegiatan</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Set Jadwal per Tingkatan</h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="jadwal">
                    <div class="col-12">
                        <label class="form-label">Kegiatan</label>
                        <select class="form-select" name="kegiatan_id" required>
                            <option value="">Pilih kegiatan</option>
                            <?php foreach ($kegiatanList as $kegiatan): ?>
                                <option value="<?= $kegiatan['id'] ?>"><?= htmlspecialchars($kegiatan['nama_kegiatan']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Tingkatan (boleh centang banyak)</label>
                        <?php $selectedTingkatan = []; require __DIR__ . '/../includes/partials/jadwal_tingkatan_chips.php'; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Pembimbing (opsional)</label>
                        <select class="form-select" name="pembimbing_id">
                            <option value="0">Belum ditentukan</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nama_pembimbing']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Hari (boleh centang banyak)</label>
                        <div class="border rounded p-2">
                            <?php foreach ($hari as $key => $label): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="hari_ke[]" id="hari-<?= (int) $key ?>" value="<?= (int) $key ?>">
                                    <label class="form-check-label" for="hari-<?= (int) $key ?>"><?= htmlspecialchars($label) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jam Mulai</label>
                        <input type="time" class="form-control" name="jam_mulai" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jam Selesai</label>
                        <input type="time" class="form-control" name="jam_selesai" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Tempat / lokasi</label>
                        <input type="text" class="form-control" name="tempat" maxlength="255" placeholder="Contoh: Masjid Utama, Aula, Ruang Kelas A">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm">Simpan Jadwal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Daftar Jadwal</h2>
                        <p class="text-muted small mb-0">Utama per <strong>kegiatan</strong>; tingkatan badge kecil di kolom tabel.</p>
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Tampilan grup jadwal">
                        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?grup=kegiatan')) ?>"
                           class="btn <?= $tampilanGrup === 'kegiatan' ? 'btn-primary' : 'btn-outline-primary' ?>">Per kegiatan</a>
                        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?grup=tingkatan')) ?>"
                           class="btn <?= $tampilanGrup === 'tingkatan' ? 'btn-primary' : 'btn-outline-primary' ?>">Per tingkatan</a>
                    </div>
                </div>
                <?php require __DIR__ . '/../includes/partials/jadwal_daftar_grup.php'; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
