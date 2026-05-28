<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/kegiatan_khusus.php';

require_roles(['admin', 'pengurus']);
kegiatan_khusus_ensure_schema($pdo);

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
array_unshift($tingkatanList, 'Semua Tingkatan');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'tambah_kegiatan_khusus') {
        $nama = trim((string) ($_POST['nama_kegiatan'] ?? ''));
        $kategori = strtoupper(trim((string) ($_POST['kategori_kegiatan'] ?? 'TAALIM')));
        $tingkatan = trim((string) ($_POST['tingkatan'] ?? 'Semua Tingkatan'));
        $tanggal = trim((string) ($_POST['tanggal'] ?? date('Y-m-d')));
        $jamMulai = trim((string) ($_POST['jam_mulai'] ?? '00:00'));
        $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? '00:00'));
        $tempat = trim((string) ($_POST['tempat'] ?? ''));
        if (!in_array($kategori, ['JAMAAH', 'TAALIM'], true)) {
            $kategori = 'TAALIM';
        }
        if ($nama === '' || $tanggal === '' || $jamMulai === '' || $jamSelesai === '') {
            set_flash('error', 'Nama, tanggal, jam mulai, dan jam selesai wajib diisi.');
            header('Location: ' . app_href('/presensi/kegiatan_khusus.php'));
            exit;
        }
        $ins = $pdo->prepare('
            INSERT INTO kegiatan_khusus (nama_kegiatan, kategori_kegiatan, tingkatan, tanggal, jam_mulai, jam_selesai, tempat, created_by)
            VALUES (:n, :kat, :ting, :tgl, :jm, :js, :tp, :by)
        ');
        $ins->execute([
            'n' => $nama,
            'kat' => $kategori,
            'ting' => $tingkatan !== '' ? $tingkatan : 'Semua Tingkatan',
            'tgl' => $tanggal,
            'jm' => $jamMulai,
            'js' => $jamSelesai,
            'tp' => $tempat !== '' ? $tempat : null,
            'by' => (int) ($_SESSION['user']['id'] ?? 0),
        ]);
        set_flash('success', 'Kegiatan khusus berhasil ditambahkan.');
        header('Location: ' . app_href('/presensi/kegiatan_khusus.php'));
        exit;
    }
}

$rows = $pdo->query('
    SELECT k.*,
           (SELECT COUNT(*) FROM presensi_kegiatan_khusus p WHERE p.kegiatan_khusus_id = k.id) AS total_scan
    FROM kegiatan_khusus k
    ORDER BY k.tanggal DESC, k.jam_mulai DESC, k.id DESC
    LIMIT 120
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Kegiatan Khusus';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">Presensi</a></p>
    <h1 class="h4 mb-1">Absensi kegiatan khusus (sekali pakai)</h1>
    <p class="text-muted mb-0 small">Kegiatan ini discan lewat jalur scanner yang sama, tetapi tidak wajib ada di jadwal rutin mingguan.</p>
</div>

<?php if ($m = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>Tambah kegiatan khusus</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="tambah_kegiatan_khusus">
            <div class="col-md-4">
                <label class="form-label">Nama kegiatan</label>
                <input type="text" name="nama_kegiatan" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Kategori</label>
                <select class="form-select" name="kategori_kegiatan">
                    <option value="TAALIM">Ta'lim/Ta'alum</option>
                    <option value="JAMAAH">Jama'ah</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tingkatan</label>
                <select class="form-select" name="tingkatan">
                    <?php foreach ($tingkatanList as $tg): ?>
                        <option value="<?= htmlspecialchars((string) $tg) ?>"><?= htmlspecialchars((string) $tg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tanggal</label>
                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam mulai</label>
                <input type="time" name="jam_mulai" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Jam selesai</label>
                <input type="time" name="jam_selesai" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tempat</label>
                <input type="text" name="tempat" class="form-control" placeholder="Opsional">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus me-1"></i>Simpan</button>
                <a class="btn btn-outline-secondary ms-2" href="<?= htmlspecialchars(app_href('/rekap/kegiatan_khusus.php')) ?>">Rekap</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header py-2"><strong>Daftar kegiatan khusus</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tanggal</th><th>Kegiatan</th><th>Kategori</th><th>Tingkatan</th><th>Waktu</th><th>Scan</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-3">Belum ada kegiatan khusus.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                    <td class="small fw-semibold"><?= htmlspecialchars((string) ($r['nama_kegiatan'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['kategori_kegiatan'] ?? 'TAALIM')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                    <td class="small"><?= htmlspecialchars(substr((string) ($r['jam_mulai'] ?? ''), 0, 5)) ?> - <?= htmlspecialchars(substr((string) ($r['jam_selesai'] ?? ''), 0, 5)) ?></td>
                    <td class="small"><?= (int) ($r['total_scan'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

