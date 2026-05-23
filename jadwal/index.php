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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_jadwal') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $before = jadwal_kegiatan_audit_fetch($pdo, $id);
        $hapusPresensi = presensi_hapus_untuk_jadwal($pdo, $id);
        $pdo->prepare('DELETE FROM jadwal_kegiatan WHERE id = :id')->execute(['id' => $id]);
        operasional_audit_log(
            $pdo,
            OPERASIONAL_AUDIT_MODUL_JADWAL,
            'DELETE',
            $id,
            $before,
            null,
            $auditUserId,
            'Penghapusan jadwal #' . $id . ($hapusPresensi > 0 ? ' (+ ' . $hapusPresensi . ' presensi)' : '')
        );
        $msg = 'Jadwal berhasil dihapus.';
        if ($hapusPresensi > 0) {
            $msg .= ' Presensi terkait: ' . $hapusPresensi . ' baris ikut dihapus.';
        }
        set_flash('success', $msg);
    } else {
        set_flash('error', 'ID jadwal tidak valid.');
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
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan, is_active FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll();
$jadwalList = $pdo->query("SELECT j.id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, k.nama_kegiatan, COALESCE(p.nama_pembimbing, '-') AS nama_pembimbing FROM jadwal_kegiatan j INNER JOIN kegiatan k ON k.id = j.kegiatan_id LEFT JOIN pembimbing p ON p.id = j.pembimbing_id ORDER BY k.nama_kegiatan ASC, j.hari_ke ASC, j.jam_mulai ASC, j.tingkatan ASC")->fetchAll();
$totalKegiatan = count($kegiatanList);
$totalJadwal = count($jadwalList);
$tingkatanTerjadwal = count(array_unique(array_map(static fn (array $r): string => (string) ($r['tingkatan'] ?? '-'), $jadwalList)));

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
$err = get_flash('error');
$ok = get_flash('success');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Jadwal</p>
    <h1 class="h4 mb-1">Jadwal kegiatan santri</h1>
    <p class="text-muted mb-0">Ringkasan visual per kegiatan. Penambahan lewat formulir terpisah — jadwal bentrok (tingkatan + jam sama) ditolak otomatis.</p>
    <?php if (user_can_lihat_audit_operasional()): ?>
        <p class="small mb-0 mt-2">
            <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php?modul=jadwal_kegiatan')) ?>"><i class="fa-solid fa-clipboard-list me-1"></i> Log audit</a>
        </p>
    <?php endif; ?>
</div>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="row g-3 mb-4 jadwal-stat-row">
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--kegiatan">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-list-check"></i></div>
            <div class="jadwal-stat-val"><?= $totalKegiatan ?></div>
            <div class="jadwal-stat-lbl">Kegiatan</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--jadwal">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-calendar-days"></i></div>
            <div class="jadwal-stat-val"><?= $totalJadwal ?></div>
            <div class="jadwal-stat-lbl">Slot jadwal</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--tingkat">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-layer-group"></i></div>
            <div class="jadwal-stat-val"><?= $tingkatanTerjadwal ?></div>
            <div class="jadwal-stat-lbl">Tingkatan</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--aksi">
            <div class="jadwal-stat-lbl mb-2">Penambahan</div>
            <div class="d-flex flex-column gap-1">
                <a href="<?= htmlspecialchars(app_href('/jadwal/tambah_kegiatan.php')) ?>" class="btn btn-sm btn-light fw-semibold"><i class="fa-solid fa-plus me-1"></i> Kegiatan</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>" class="btn btn-sm btn-success fw-semibold"><i class="fa-solid fa-calendar-plus me-1"></i> Jadwal</a>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4 border-0 jadwal-peta-card">
    <div class="card-body p-0">
        <div class="jadwal-peta-card__head px-3 px-md-4 py-3">
            <h2 class="h6 mb-1">Peta jadwal per kegiatan</h2>
            <p class="text-muted small mb-0">Kolom terpisah: hari, waktu, nama kegiatan, dan tingkatan — mudah dibaca sekilas.</p>
        </div>
        <div class="jadwal-peta-card__body px-2 px-md-3 pb-3">
            <?php require __DIR__ . '/../includes/partials/jadwal_matrix_kegiatan.php'; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">Detail &amp; kelola</h2>
                <p class="text-muted small mb-0">Edit / hapus per baris</p>
            </div>
            <div class="btn-group btn-group-sm" role="group">
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?grup=kegiatan')) ?>"
                   class="btn <?= $tampilanGrup === 'kegiatan' ? 'btn-primary' : 'btn-outline-primary' ?>">Per kegiatan</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?grup=tingkatan')) ?>"
                   class="btn <?= $tampilanGrup === 'tingkatan' ? 'btn-primary' : 'btn-outline-primary' ?>">Per tingkatan</a>
            </div>
        </div>
        <?php require __DIR__ . '/../includes/partials/jadwal_daftar_grup.php'; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
