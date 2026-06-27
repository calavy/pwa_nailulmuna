<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/operasional_audit.php';
require_once __DIR__ . '/../helpers/presensi_admin.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';
require_once __DIR__ . '/../helpers/jadwal_pembimbing.php';
require_once __DIR__ . '/../helpers/jadwal_form_handlers.php';
require_once __DIR__ . '/../helpers/jadwal_jamaah.php';
require_once __DIR__ . '/../helpers/jadwal_jamaah_pembimbing.php';
require_once __DIR__ . '/../helpers/munawib.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';

jadwal_require_module_access();
$auditUserId = (int) ($_SESSION['user']['id'] ?? 0);
$jadwalPembimbingScope = jadwal_is_pembimbing_scope();
$pembimbingScopeId = $jadwalPembimbingScope ? jadwal_current_pembimbing_id($pdo) : 0;

if (!table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'jadwal_kegiatan')) {
    set_flash('error', 'Tabel jadwal belum ada. Jalankan schema_presensi.sql terlebih dahulu.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}
if (!column_exists($pdo, 'jadwal_kegiatan', 'pembimbing_id')) {
    $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN pembimbing_id INT NULL');
}
if (!column_exists($pdo, 'jadwal_kegiatan', 'tempat')) {
    ensure_jadwal_kegiatan_tempat($pdo);
}
if (table_exists($pdo, 'kegiatan') && !column_exists($pdo, 'kegiatan', 'kategori_kegiatan')) {
    ensure_kegiatan_kategori_column($pdo);
}
jadwal_jamaah_munawib_ensure_schema($pdo);

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

if (jadwal_handle_post($pdo, $auditUserId, $jadwalPembimbingScope, $pembimbingScopeId)) {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_jadwal') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($jadwalPembimbingScope && !jadwal_slot_owned_by_pembimbing($pdo, $id, $pembimbingScopeId)) {
        set_flash('error', 'Anda hanya dapat menghapus jadwal milik sendiri.');
        header('Location: ' . app_href('/jadwal/index.php'));
        exit;
    }
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
        if ($jadwalPembimbingScope && !jadwal_slot_owned_by_pembimbing($pdo, $id, $pembimbingScopeId)) {
            $gagal++;
            continue;
        }
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
    if (in_array($g, ['kegiatan', 'tingkatan', 'pembimbing'], true)) {
        jadwal_simpan_tampilan_grup($pdo, $g);
    }
    $redir = '/jadwal/index.php';
    if (($_GET['view'] ?? '') === 'ringkas') {
        $redir .= '?view=ringkas';
    }
    header('Location: ' . app_href($redir));
    exit;
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
array_unshift($tingkatanList, 'Semua Tingkatan');
$kegiatanRows = $pdo->query('SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan, COALESCE(is_active, 1) AS is_active FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$kegiatanListAktif = $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE COALESCE(is_active, 1) = 1 ORDER BY nama_kegiatan ASC')->fetchAll();
$pembimbingList = (!$jadwalPembimbingScope && table_exists($pdo, 'pembimbing'))
    ? $pdo->query('SELECT id, nama_pembimbing, nip FROM pembimbing ORDER BY ' . pembimbing_list_order_sql(''))->fetchAll()
    : [];
$panelOpen = trim((string) ($_GET['panel'] ?? ''));
if (!in_array($panelOpen, ['kegiatan', 'jadwal'], true)) {
    $panelOpen = '';
}
$preselectKegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$jadwalListSql = "SELECT j.id, j.kegiatan_id, j.pembimbing_id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, 'TAALIM') AS kategori_kegiatan, COALESCE(p.nama_pembimbing, '-') AS nama_pembimbing FROM jadwal_kegiatan j INNER JOIN kegiatan k ON k.id = j.kegiatan_id LEFT JOIN pembimbing p ON p.id = j.pembimbing_id";
if ($jadwalPembimbingScope && $pembimbingScopeId > 0) {
    $stJadwal = $pdo->prepare($jadwalListSql . ' WHERE j.pembimbing_id = :pb ORDER BY k.nama_kegiatan ASC, j.hari_ke ASC, j.jam_mulai ASC, j.tingkatan ASC');
    $stJadwal->execute(['pb' => $pembimbingScopeId]);
    $jadwalList = $stJadwal->fetchAll();
} else {
    $jadwalList = $pdo->query($jadwalListSql . ' ORDER BY k.nama_kegiatan ASC, j.hari_ke ASC, j.jam_mulai ASC, j.tingkatan ASC')->fetchAll();
}

foreach ($jadwalList as &$jadwalRow) {
    if (strtoupper((string) ($jadwalRow['kategori_kegiatan'] ?? 'TAALIM')) !== 'JAMAAH') {
        continue;
    }
    $displayHk = (int) ($jadwalRow['hari_ke'] ?? 0);
    if ($displayHk < 1 || $displayHk > 7) {
        $displayHk = (int) date('N');
    }
    $namaMw = jadwal_jamaah_munawib_nama_untuk_slot($pdo, (string) ($jadwalRow['tingkatan'] ?? ''), $displayHk);
    if ($namaMw !== '') {
        $jadwalRow['nama_pembimbing'] = $namaMw;
        $jadwalRow['munawib_harian'] = true;
    }
}
unset($jadwalRow);

$filterTingkatan = trim((string) ($_GET['filter_tingkatan'] ?? ''));
$filterHari = (int) ($_GET['filter_hari'] ?? 0);
$filterKat = strtoupper(trim((string) ($_GET['filter_kat'] ?? '')));
if (!in_array($filterKat, ['JAMAAH', 'TAALIM'], true)) {
    $filterKat = '';
}
$filterKegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
if ($filterKat !== '') {
    $jadwalList = array_values(array_filter($jadwalList, static function (array $row) use ($filterKat): bool {
        return strtoupper((string) ($row['kategori_kegiatan'] ?? 'TAALIM')) === $filterKat;
    }));
}
if ($filterKegiatanId > 0) {
    $jadwalList = array_values(array_filter($jadwalList, static function (array $row) use ($filterKegiatanId): bool {
        return (int) ($row['kegiatan_id'] ?? 0) === $filterKegiatanId;
    }));
}
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
$totalKegiatan = count($kegiatanRows);
$totalJadwal = count($jadwalList);
$tingkatanTerjadwal = count(array_unique(array_map(static fn (array $r): string => (string) ($r['tingkatan'] ?? '-'), $jadwalList)));

$hari = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

$activeTab = strtolower(trim((string) ($_GET['tab'] ?? 'minggu')));
if ($activeTab === 'jamaah_pembimbing') {
    $activeTab = 'jamaah_munawib';
}
if (!in_array($activeTab, ['minggu', 'daftar', 'tabel', 'jamaah', 'jamaah_munawib'], true)) {
    $activeTab = 'minggu';
}
$jamaahEditorRows = jadwal_jamaah_daftar_editor($pdo);
$jamaahMunawibMap = jadwal_jamaah_munawib_map($pdo);
$munawibList = (!$jadwalPembimbingScope && table_exists($pdo, 'munawib'))
    ? munawib_list_aktif($pdo)
    : [];

$tampilanGrup = jadwal_tampilan_grup($pdo);
$jadwalGrouped = [];
if ($activeTab === 'daftar') {
    if ($tampilanGrup === 'pembimbing') {
        $jadwalGrouped = jadwal_kelompokkan_per_pembimbing($jadwalList);
        jadwal_urutkan_grup_hari_jam($jadwalGrouped);
        ksort($jadwalGrouped, SORT_NATURAL | SORT_FLAG_CASE);
    } elseif ($tampilanGrup === 'tingkatan') {
        $jadwalGrouped = jadwal_kelompokkan_per_tingkatan($jadwalList);
        jadwal_urutkan_grup_hari($jadwalGrouped);
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
        $jadwalGrouped = jadwal_kelompokkan_per_kegiatan($jadwalList);
        jadwal_urutkan_grup_hari_jam($jadwalGrouped);
        ksort($jadwalGrouped, SORT_NATURAL | SORT_FLAG_CASE);
    }
}

$viewRingkas = (($_GET['view'] ?? '') === 'ringkas');
if (isset($_GET['view']) && $_GET['view'] === 'ringkas') {
    jadwal_simpan_tampilan_grup($pdo, 'kegiatan');
}

$pageTitle = 'Jadwal Kegiatan';
$bodyClass = 'jadwal-page' . ($viewRingkas ? ' jadwal-page--ringkas' : '');
$pageScripts = [app_asset_href('/assets/js/jadwal-ui.js')];
$showJadwalAksi = !$jadwalPembimbingScope;
$kegiatanListEdit = array_map(
    static fn (array $row): array => ['id' => (int) ($row['id'] ?? 0), 'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? '')],
    $kegiatanRows
);
$jadwalTabQs = static function (string $tab, array $extra = []) use ($viewRingkas, $filterTingkatan, $filterHari, $filterKat, $filterKegiatanId): string {
    $q = array_merge(['tab' => $tab], $extra);
    if ($viewRingkas) {
        $q['view'] = 'ringkas';
    }
    if ($filterKat !== '') {
        $q['filter_kat'] = $filterKat;
    }
    if ($filterKegiatanId > 0) {
        $q['kegiatan_id'] = (string) $filterKegiatanId;
    }
    if ($filterTingkatan !== '' && $filterTingkatan !== 'Semua Tingkatan') {
        $q['filter_tingkatan'] = $filterTingkatan;
    }
    if ($filterHari >= 1 && $filterHari <= 7) {
        $q['filter_hari'] = (string) $filterHari;
    }

    return '?' . http_build_query($q);
};
require_once __DIR__ . '/../includes/header.php';
$err = get_flash('error');
$ok = get_flash('success');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Jadwal</p>
    <h1 class="h4 mb-1">Jadwal kegiatan santri</h1>
    <p class="text-muted mb-0">Lihat per hari (Senin–Minggu), atur waktu jamaah sekaligus, atau edit cepat dari kartu jadwal. Ubah waktu memperbarui slot yang ada — tidak menghapus kegiatan.</p>
    <p class="small mb-0 mt-2 d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah')) ?>"><i class="fa-solid fa-mosque me-1"></i> Atur waktu Jama'ah</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah_munawib')) ?>"><i class="fa-solid fa-user-check me-1"></i> Munawib Jama'ah</a>
        <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>"><i class="fa-solid fa-bookmark me-1"></i> Kegiatan Ta'lim / Jama'ah</a>
        <?php if (!$jadwalPembimbingScope): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/import.php')) ?>"><i class="fa-solid fa-file-import me-1"></i> Import Excel</a>
        <?php endif; ?>
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

<div class="row g-2 mb-3 jadwal-stat-row">
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--kegiatan">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-bookmark"></i></div>
            <div class="jadwal-stat-val"><?= (int) $totalKegiatan ?></div>
            <div class="jadwal-stat-lbl">Kegiatan</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--jadwal">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="jadwal-stat-val"><?= (int) $totalJadwal ?></div>
            <div class="jadwal-stat-lbl">Slot jadwal</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--tingkat">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-layer-group"></i></div>
            <div class="jadwal-stat-val"><?= (int) $tingkatanTerjadwal ?></div>
            <div class="jadwal-stat-lbl">Tingkatan terjadwal</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="jadwal-stat-card jadwal-stat-card--aksi">
            <div class="jadwal-stat-ico"><i class="fa-solid fa-plus"></i></div>
            <button type="button" class="btn btn-light btn-sm jadwal-panel-toggle" data-panel="jadwal"><i class="fa-solid fa-calendar-plus me-1"></i>Tambah</button>
            <div class="jadwal-stat-lbl mt-1">Slot baru</div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/partials/jadwal_inline_panels.php'; ?>

<?php if ($activeTab !== 'jamaah' && $activeTab !== 'jamaah_munawib'): ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
            <?php if ($viewRingkas): ?><input type="hidden" name="view" value="ringkas"><?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Kategori</label>
                <select name="filter_kat" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="TAALIM" <?= $filterKat === 'TAALIM' ? 'selected' : '' ?>>Ta'lim</option>
                    <option value="JAMAAH" <?= $filterKat === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-0">Kegiatan</label>
                <select name="kegiatan_id" class="form-select form-select-sm">
                    <option value="0">Semua kegiatan</option>
                    <?php foreach ($kegiatanListAktif as $kgOpt): ?>
                        <option value="<?= (int) $kgOpt['id'] ?>" <?= $filterKegiatanId === (int) $kgOpt['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $kgOpt['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tingkatan</label>
                <select name="filter_tingkatan" class="form-select form-select-sm">
                    <option value="">Semua tingkatan</option>
                    <?php foreach ($tingkatanList as $tkOpt): ?>
                        <?php if ((string) $tkOpt === 'Semua Tingkatan') { continue; } ?>
                        <option value="<?= htmlspecialchars((string) $tkOpt) ?>" <?= $filterTingkatan === (string) $tkOpt ? 'selected' : '' ?>><?= htmlspecialchars((string) $tkOpt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
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
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs($activeTab, []))) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
            <?php if ($activeTab === 'daftar'): ?>
            <div class="col-auto ms-md-auto d-flex flex-wrap gap-1 align-items-center">
                <span class="small text-muted me-1">Kelompok:</span>
                <?php
                $grupQs = static function (string $g) use ($jadwalTabQs, $activeTab): string {
                    return $jadwalTabQs($activeTab, ['grup' => $g]);
                };
                ?>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $grupQs('kegiatan'))) ?>"
                   class="btn btn-sm <?= $tampilanGrup === 'kegiatan' ? 'btn-primary' : 'btn-outline-secondary' ?>">Kegiatan</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $grupQs('pembimbing'))) ?>"
                   class="btn btn-sm <?= $tampilanGrup === 'pembimbing' ? 'btn-primary' : 'btn-outline-secondary' ?>">Pembimbing</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $grupQs('tingkatan'))) ?>"
                   class="btn btn-sm <?= $tampilanGrup === 'tingkatan' ? 'btn-primary' : 'btn-outline-secondary' ?>">Tingkatan</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4 border-0 jadwal-view-card">
    <div class="card-header bg-white border-0 pt-3 pb-0">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="h6 mb-0">Tampilan jadwal</h2>
            <div class="btn-group btn-group-sm jadwal-view-tabs" role="tablist">
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('minggu'))) ?>"
                   class="btn btn-outline-primary<?= $activeTab === 'minggu' ? ' active' : '' ?>"
                   aria-selected="<?= $activeTab === 'minggu' ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-table-columns me-1"></i> Mingguan
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('daftar'))) ?>"
                   class="btn btn-outline-primary<?= $activeTab === 'daftar' ? ' active' : '' ?>"
                   aria-selected="<?= $activeTab === 'daftar' ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-list me-1"></i> Daftar
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('tabel'))) ?>"
                   class="btn btn-outline-primary<?= $activeTab === 'tabel' ? ' active' : '' ?>"
                   aria-selected="<?= $activeTab === 'tabel' ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-table me-1"></i> Tabel
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('jamaah'))) ?>"
                   class="btn btn-outline-primary jadwal-tab-jamaah<?= $activeTab === 'jamaah' ? ' active' : '' ?>"
                   aria-selected="<?= $activeTab === 'jamaah' ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-mosque me-1"></i> Waktu Jama'ah
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('jamaah_munawib'))) ?>"
                   class="btn btn-outline-primary jadwal-tab-jamaah-mw<?= $activeTab === 'jamaah_munawib' ? ' active' : '' ?>"
                   aria-selected="<?= $activeTab === 'jamaah_munawib' ? 'true' : 'false' ?>">
                    <i class="fa-solid fa-user-check me-1"></i> Munawib Jama'ah
                </a>
            </div>
        </div>
    </div>
    <div class="card-body pt-2">
        <?php if ($activeTab === 'jamaah'): ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_jamaah_waktu.php'; ?>
        <?php elseif ($activeTab === 'jamaah_munawib'): ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_jamaah_pembimbing.php'; ?>
        <?php elseif ($activeTab === 'minggu'): ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_legend.php'; ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_minggu_grid.php'; ?>
        <?php elseif ($activeTab === 'daftar'): ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_legend.php'; ?>
            <p class="text-muted small mb-3">
                Dikelompokkan per <?= $tampilanGrup === 'pembimbing' ? 'pembimbing' : ($tampilanGrup === 'tingkatan' ? 'tingkatan' : 'kegiatan') ?>.
                Satu baris = satu slot waktu (hari & tingkatan digabung).
            </p>
            <?php require __DIR__ . '/../includes/partials/jadwal_daftar_grup.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_matrix_kegiatan.php'; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($showJadwalAksi): ?>
    <form method="post" id="form-jadwal-delete-one" class="d-none">
        <input type="hidden" name="action" value="hapus_jadwal_massal">
    </form>
    <?php
    $kegiatanList = $kegiatanListEdit;
    require __DIR__ . '/../includes/partials/jadwal_quick_edit_modal.php';
    ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
