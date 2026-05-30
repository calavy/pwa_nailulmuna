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
ensure_kegiatan_kategori_column($pdo);

/**
 * Hapus satu slot jadwal + audit + presensi terkait.
 *
 * @return array{ok:bool, presensi:int}
 */
function jadwal_hapus_satu(PDO $pdo, int $id, int $auditUserId): array
{
    if ($id <= 0) {
        return ['ok' => false, 'presensi' => 0];
    }
    $before = jadwal_kegiatan_audit_fetch($pdo, $id);
    if ($before === null) {
        return ['ok' => false, 'presensi' => 0];
    }
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

    return ['ok' => true, 'presensi' => $hapusPresensi];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_jadwal') {
    $id = (int) ($_POST['id'] ?? 0);
    $result = jadwal_hapus_satu($pdo, $id, $auditUserId);
    if ($result['ok']) {
        $msg = 'Jadwal berhasil dihapus.';
        if ($result['presensi'] > 0) {
            $msg .= ' Presensi terkait: ' . $result['presensi'] . ' baris ikut dihapus.';
        }
        set_flash('success', $msg);
    } else {
        set_flash('error', 'ID jadwal tidak valid.');
    }
    header('Location: ' . app_href('/jadwal/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_jadwal_massal') {
    $rawIds = $_POST['ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [];
    }
    $ids = [];
    foreach ($rawIds as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);

    if ($ids === []) {
        set_flash('error', 'Centang minimal satu jadwal yang akan dihapus.');
        header('Location: ' . app_href('/jadwal/index.php'));
        exit;
    }

    $terhapus = 0;
    $presensiTotal = 0;
    $gagal = 0;
    foreach ($ids as $id) {
        $result = jadwal_hapus_satu($pdo, $id, $auditUserId);
        if ($result['ok']) {
            $terhapus++;
            $presensiTotal += $result['presensi'];
        } else {
            $gagal++;
        }
    }

    if ($terhapus > 0) {
        $msg = $terhapus . ' jadwal berhasil dihapus.';
        if ($presensiTotal > 0) {
            $msg .= ' Presensi terkait: ' . $presensiTotal . ' baris ikut dihapus.';
        }
        if ($gagal > 0) {
            $msg .= ' (' . $gagal . ' tidak ditemukan.)';
        }
        set_flash('success', $msg);
    } else {
        set_flash('error', 'Tidak ada jadwal yang berhasil dihapus.');
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
$kegiatanList = $pdo->query('SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan, is_active FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll();
$jadwalList = $pdo->query("SELECT j.id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, 'TAALIM') AS kategori_kegiatan, COALESCE(p.nama_pembimbing, '-') AS nama_pembimbing FROM jadwal_kegiatan j INNER JOIN kegiatan k ON k.id = j.kegiatan_id LEFT JOIN pembimbing p ON p.id = j.pembimbing_id ORDER BY k.nama_kegiatan ASC, j.hari_ke ASC, j.jam_mulai ASC, j.tingkatan ASC")->fetchAll();

$filterTingkatan = trim((string) ($_GET['filter_tingkatan'] ?? ''));
$filterHari = (int) ($_GET['filter_hari'] ?? 0);
if ($filterTingkatan !== '' && $filterTingkatan !== 'Semua Tingkatan') {
    $jadwalList = array_values(array_filter($jadwalList, static function (array $row) use ($filterTingkatan): bool {
        return strcasecmp(trim((string) ($row['tingkatan'] ?? '')), $filterTingkatan) === 0;
    }));
}
if ($filterHari >= 1 && $filterHari <= 7) {
    $jadwalList = array_values(array_filter($jadwalList, static function (array $row) use ($filterHari): bool {
        return (int) ($row['hari_ke'] ?? 0) === $filterHari;
    }));
}
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

$viewRingkas = (($_GET['view'] ?? '') === 'ringkas');
if (isset($_GET['view']) && $_GET['view'] === 'ringkas') {
    jadwal_simpan_tampilan_grup($pdo, 'kegiatan');
}

$pageTitle = 'Jadwal Kegiatan';
$bodyClass = 'jadwal-page' . ($viewRingkas ? ' jadwal-page--ringkas' : '');
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Jadwal</p>
    <h1 class="h4 mb-1">Jadwal kegiatan santri</h1>
    <p class="text-muted mb-0">Ringkasan visual per kegiatan. Penambahan lewat formulir terpisah — jadwal bentrok ditolak otomatis.</p>
    <p class="small mb-0 mt-2 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/import.php')) ?>"><i class="fa-solid fa-file-import me-1"></i> Import Excel</a>
        <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/import.php?template=xlsx')) ?>"><i class="fa-solid fa-file-arrow-down me-1"></i> Download template jadwal</a>
        <?php if ($viewRingkas): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>"><i class="fa-solid fa-table me-1"></i> Tampilan lengkap</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?view=ringkas')) ?>"><i class="fa-solid fa-bars me-1"></i> Tampilan ringkas</a>
        <?php endif; ?>
    </p>
    <?php if (user_can_lihat_audit_operasional()): ?>
        <p class="small mb-0 mt-2">
            <a class="btn btn-outline-warning btn-sm" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php?modul=jadwal_kegiatan')) ?>"><i class="fa-solid fa-clipboard-list me-1"></i> Log audit</a>
        </p>
    <?php endif; ?>
</div>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <?php if ($viewRingkas): ?><input type="hidden" name="view" value="ringkas"><?php endif; ?>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0">Tingkatan</label>
                <select name="filter_tingkatan" class="form-select form-select-sm">
                    <option value="">Semua tingkatan</option>
                    <?php foreach ($tingkatanList as $tkOpt): ?>
                        <?php if ((string) $tkOpt === 'Semua Tingkatan') { continue; } ?>
                        <option value="<?= htmlspecialchars((string) $tkOpt) ?>" <?= $filterTingkatan === (string) $tkOpt ? 'selected' : '' ?>><?= htmlspecialchars((string) $tkOpt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0">Hari</label>
                <select name="filter_hari" class="form-select form-select-sm">
                    <option value="0">Semua hari</option>
                    <?php foreach ($hari as $hk => $hn): ?>
                        <?php if ((int) $hk === 0) { continue; } ?>
                        <option value="<?= (int) $hk ?>" <?= $filterHari === (int) $hk ? 'selected' : '' ?>><?= htmlspecialchars((string) $hn) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . ($viewRingkas ? '?view=ringkas' : ''))) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
            <div class="col-auto ms-md-auto">
                <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>" class="btn btn-success btn-sm"><i class="fa-solid fa-calendar-plus me-1"></i> Tambah jadwal</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen me-1"></i> Edit slot</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4 border-0 jadwal-peta-card">
    <div class="card-body p-0">
        <div class="jadwal-peta-card__head px-3 px-md-4 py-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h2 class="h6 mb-1">Peta jadwal per kegiatan</h2>
                <p class="text-muted small mb-0">Kelola slot jadwal langsung dari tabel — edit atau hapus per baris.</p>
            </div>
            <div class="btn-group btn-group-sm" role="group">
                <a href="<?= htmlspecialchars(app_href('/jadwal/tambah.php')) ?>" class="btn btn-success"><i class="fa-solid fa-calendar-plus me-1"></i> Tambah</a>
            </div>
        </div>
        <div class="jadwal-peta-card__body px-2 px-md-3 pb-3">
            <?php require __DIR__ . '/../includes/partials/jadwal_matrix_kegiatan.php'; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
