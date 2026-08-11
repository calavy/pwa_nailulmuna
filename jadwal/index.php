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
require_once __DIR__ . '/../helpers/kegiatan_kategori.php';

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
$kegiatanListAktif = $pdo->query('SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan FROM kegiatan WHERE COALESCE(is_active, 1) = 1 ORDER BY nama_kegiatan ASC')->fetchAll();
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
if (!in_array($filterKat, kegiatan_kategori_list(), true)) {
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
$bodyClass = 'jadwal-page jadwal-page--focus' . ($viewRingkas ? ' jadwal-page--ringkas' : '');
$pageScripts = [app_asset_href('/assets/js/jadwal-ui.js')];
$showJadwalAksi = !$jadwalPembimbingScope;
$kegiatanListEdit = array_map(
    static fn (array $row): array => [
        'id' => (int) ($row['id'] ?? 0),
        'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
        'kategori_kegiatan' => (string) ($row['kategori_kegiatan'] ?? 'TAALIM'),
    ],
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

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<?php require __DIR__ . '/../includes/partials/jadwal_toolbar.php'; ?>

<?php require __DIR__ . '/../includes/partials/jadwal_inline_panels.php'; ?>

<div class="card shadow-sm mb-4 border-0 jadwal-view-card">
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
            <p class="text-muted small mb-3 d-none d-lg-block">
                Dikelompokkan per <?= $tampilanGrup === 'pembimbing' ? 'pembimbing' : ($tampilanGrup === 'tingkatan' ? 'tingkatan' : 'kegiatan') ?>.
                Satu baris = satu slot waktu (hari & tingkatan digabung).
            </p>
            <?php require __DIR__ . '/../includes/partials/jadwal_daftar_grup.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/../includes/partials/jadwal_matrix_kegiatan.php'; ?>
        <?php endif; ?>
    </div>
</div>

<button type="button" class="jadwal-fab d-lg-none jadwal-panel-toggle" data-panel="jadwal" aria-label="Tambah jadwal">
    <i class="fa-solid fa-plus" aria-hidden="true"></i>
</button>

<?php if ($showJadwalAksi): ?>
    <form method="post" id="form-jadwal-delete-one" class="d-none">
        <input type="hidden" name="action" value="hapus_jadwal_massal">
    </form>
    <?php
    $kegiatanList = $kegiatanListEdit;
    require __DIR__ . '/../includes/partials/jadwal_detail_modal.php';
    require __DIR__ . '/../includes/partials/jadwal_quick_edit_modal.php';
    ?>
<?php endif; ?>

<div id="jadwal-context-menu" class="jadwal-context-menu d-none" role="menu">
    <button type="button" class="jadwal-context-menu__item" data-action="edit"><i class="fa-solid fa-pen me-2"></i>Edit</button>
    <a href="#" class="jadwal-context-menu__item jadwal-context-menu__link" data-action="full"><i class="fa-solid fa-up-right-from-square me-2"></i>Form lengkap</a>
    <button type="button" class="jadwal-context-menu__item text-danger" data-action="delete"><i class="fa-solid fa-trash me-2"></i>Hapus</button>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
