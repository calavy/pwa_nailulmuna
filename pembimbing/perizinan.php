<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus', 'petugas_absensi', 'pembimbing']);

$pdo->exec('
    CREATE TABLE IF NOT EXISTS perizinan_pembimbing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pembimbing_id INT NOT NULL,
        kegiatan_id INT NULL,
        jenis_izin ENUM("SAKIT","KELUAR","TUGAS","PULANG") NOT NULL DEFAULT "KELUAR",
        tanggal_mulai DATE NOT NULL,
        tanggal_selesai DATE NOT NULL,
        jam_mulai TIME NULL,
        jam_selesai TIME NULL,
        durasi_jam DECIMAL(5,2) NULL,
        alasan TEXT NOT NULL,
        status_izin ENUM("IZIN","KEMBALI") NOT NULL DEFAULT "IZIN",
        waktu_kembali DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
    )
');
$pdo->exec('ALTER TABLE perizinan_pembimbing ADD COLUMN IF NOT EXISTS kegiatan_id INT NULL');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'id' => (int) ($_POST['pembimbing_id'] ?? 0),
        'kegiatan_id' => (int) ($_POST['kegiatan_id'] ?? 0),
        'jenis' => in_array(($_POST['jenis_izin'] ?? ''), ['SAKIT', 'KELUAR', 'TUGAS', 'PULANG'], true) ? $_POST['jenis_izin'] : 'KELUAR',
        'mulai' => $_POST['tanggal_mulai'] ?? date('Y-m-d'),
        'selesai' => $_POST['tanggal_selesai'] ?? date('Y-m-d'),
        'jam_mulai' => $_POST['jam_mulai'] ?? date('H:i'),
        'jam_selesai' => $_POST['jam_selesai'] ?? date('H:i'),
        'durasi' => (float) ($_POST['durasi_jam'] ?? 0),
        'alasan' => trim((string) ($_POST['alasan'] ?? '')),
    ];
    $ins = $pdo->prepare('INSERT INTO perizinan_pembimbing (pembimbing_id, kegiatan_id, jenis_izin, tanggal_mulai, tanggal_selesai, jam_mulai, jam_selesai, durasi_jam, alasan) VALUES (:id, :kegiatan_id, :jenis, :mulai, :selesai, :jam_mulai, :jam_selesai, :durasi, :alasan)');
    $ins->execute($data);
    $pdo->prepare('UPDATE pembimbing SET is_aktif = 0 WHERE id = :id')->execute(['id' => $data['id']]);
    set_flash('success', 'Perizinan pembimbing berhasil dibuat.');
    header('Location: ' . app_href('/pembimbing/perizinan.php'));
    exit;
}

$pembimbingList = $pdo->query('SELECT id, nama_pembimbing, nip FROM pembimbing ORDER BY nama_pembimbing ASC')->fetchAll();
$kegiatanList = [];
if (table_exists($pdo, 'kegiatan')) {
    $kegiatanList = $pdo->query('SELECT id, nama_kegiatan FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll();
}
$izinList = $pdo->query('
    SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.status_izin, b.nama_pembimbing, b.nip, k.nama_kegiatan
    FROM perizinan_pembimbing i
    INNER JOIN pembimbing b ON b.id = i.pembimbing_id
    LEFT JOIN kegiatan k ON k.id = i.kegiatan_id
    ORDER BY i.id DESC
')->fetchAll();
$totalIzin = count($izinList);
$izinAktif = count(array_filter($izinList, static fn(array $r): bool => (string) ($r['status_izin'] ?? 'IZIN') === 'IZIN'));
$totalPembimbing = count($pembimbingList);

$pageTitle = 'Perizinan Pembimbing';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Izin Pembimbing</p>
    <h1 class="h4 mb-1">Perizinan pembimbing</h1>
    <p class="text-muted mb-0">Catat izin pembimbing dan pantau status aktif/nonaktif pembimbing secara cepat.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total izin</div>
            <div class="app-mini-stat-value"><?= $totalIzin ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Izin aktif</div>
            <div class="app-mini-stat-value text-warning"><?= $izinAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Data pembimbing</div>
            <div class="app-mini-stat-value"><?= $totalPembimbing ?></div>
        </div>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Input Izin Pembimbing</h1>
                <form method="post" class="row g-2">
                    <div class="col-12">
                        <select class="form-select" name="pembimbing_id" required>
                            <option value="">Pilih pembimbing</option>
                            <?php foreach ($pembimbingList as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nama_pembimbing']) ?> (<?= htmlspecialchars($p['nip']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <select class="form-select" name="jenis_izin">
                            <option value="SAKIT">Izin Sakit</option>
                            <option value="KELUAR">Keluar</option>
                            <option value="TUGAS">Tugas</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <select class="form-select" name="kegiatan_id">
                            <option value="0">Kegiatan (opsional)</option>
                            <?php foreach ($kegiatanList as $kg): ?>
                                <option value="<?= (int) ($kg['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '-')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><input type="number" class="form-control" name="durasi_jam" step="0.25" placeholder="Durasi (jam)"></div>
                    <div class="col-6"><input type="date" class="form-control" name="tanggal_mulai" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><input type="date" class="form-control" name="tanggal_selesai" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><input type="time" class="form-control" name="jam_mulai" value="<?= date('H:i') ?>"></div>
                    <div class="col-6"><input type="time" class="form-control" name="jam_selesai" value="<?= date('H:i') ?>"></div>
                    <div class="col-12"><textarea class="form-control" name="alasan" rows="2" placeholder="Alasan" required></textarea></div>
                    <div class="col-12"><button class="btn btn-success">Simpan Izin</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar Izin Pembimbing</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Pembimbing</th><th>Kegiatan</th><th>Jenis</th><th>Tanggal</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($izinList as $i): ?>
                        <tr>
                            <td><?= htmlspecialchars($i['nama_pembimbing']) ?></td>
                            <td><?= htmlspecialchars((string) ($i['nama_kegiatan'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($i['jenis_izin']) ?></td>
                            <td><?= htmlspecialchars($i['tanggal_mulai']) ?> s/d <?= htmlspecialchars($i['tanggal_selesai']) ?></td>
                            <td><?= htmlspecialchars($i['status_izin']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
