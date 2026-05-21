<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus']);

$pdo->exec('
    CREATE TABLE IF NOT EXISTS pembimbing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        qr VARCHAR(120) NULL,
        nip VARCHAR(40) NOT NULL UNIQUE,
        nama_pembimbing VARCHAR(120) NOT NULL,
        no_wa VARCHAR(30) NULL,
        is_aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'qr' => trim((string) ($_POST['qr'] ?? '')),
        'nip' => trim((string) ($_POST['nip'] ?? '')),
        'nama' => trim((string) ($_POST['nama_pembimbing'] ?? '')),
        'wa' => trim((string) ($_POST['no_wa'] ?? '')),
    ];
    if ($data['nip'] !== '' && $data['nama'] !== '') {
        $stmt = $pdo->prepare('INSERT INTO pembimbing (qr, nip, nama_pembimbing, no_wa) VALUES (:qr, :nip, :nama, :wa)');
        $stmt->execute($data);
        set_flash('success', 'Data pembimbing ditambahkan.');
    }
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$rows = $pdo->query('SELECT id, qr, nip, nama_pembimbing, no_wa, is_aktif FROM pembimbing ORDER BY nama_pembimbing ASC')->fetchAll();
$totalPembimbing = count($rows);
$pembimbingAktif = count(array_filter($rows, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 1) === 1));
$pembimbingNonAktif = $totalPembimbing - $pembimbingAktif;

$pageTitle = 'Data Pembimbing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Santri &amp; SDM · Pembimbing</p>
    <h1 class="h4 mb-1">Data pembimbing</h1>
    <p class="text-muted mb-0">Kelola identitas pembimbing dan status aktif untuk jadwal kegiatan.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total pembimbing</div>
            <div class="app-mini-stat-value"><?= $totalPembimbing ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Aktif</div>
            <div class="app-mini-stat-value text-success"><?= $pembimbingAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tidak aktif</div>
            <div class="app-mini-stat-value text-warning"><?= $pembimbingNonAktif ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Tambah Pembimbing</h1>
                <form method="post" class="row g-2">
                    <div class="col-12"><input class="form-control" name="qr" placeholder="Kode QR"></div>
                    <div class="col-12"><input class="form-control" name="nip" placeholder="NIP / ID Pembimbing" required></div>
                    <div class="col-12"><input class="form-control" name="nama_pembimbing" placeholder="Nama Pembimbing" required></div>
                    <div class="col-12"><input class="form-control" name="no_wa" placeholder="No WA"></div>
                    <div class="col-12"><button class="btn btn-success">Simpan</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Daftar Pembimbing</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>NIP</th><th>Nama</th><th>WA</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['nip']) ?></td>
                            <td><?= htmlspecialchars($row['nama_pembimbing']) ?></td>
                            <td><?= htmlspecialchars($row['no_wa'] ?: '-') ?></td>
                            <td><span class="badge text-bg-<?= (int) $row['is_aktif'] === 1 ? 'success' : 'warning' ?>"><?= (int) $row['is_aktif'] === 1 ? 'Aktif' : 'Izin/Pulang' ?></span></td>
                            <td class="text-end">
                                <a href="/pembimbing/edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
