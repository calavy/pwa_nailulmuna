<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/operasional_audit.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';
require_once __DIR__ . '/../helpers/kegiatan_kategori.php';

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
    $kegiatanIdEff = $kegiatanId > 0 ? $kegiatanId : (int) ($jadwal['kegiatan_id'] ?? 0);
    if (kegiatan_kategori_is_extra(kegiatan_kategori_fetch($pdo, $kegiatanIdEff))) {
        $tingkatanDipilih = ['Semua Tingkatan'];
    }

    $jamMulai = $_POST['jam_mulai'] ?? '00:00';
    $jamSelesai = $_POST['jam_selesai'] ?? '00:00';
    if (jadwal_norm_jam($jamSelesai) <= jadwal_norm_jam($jamMulai)) {
        set_flash('error', 'Jam selesai harus setelah jam mulai.');
        header('Location: ' . app_rewrite_internal_url('/jadwal/edit.php?id=' . $id));
        exit;
    }
    $pembimbingId = (int) ($_POST['pembimbing_id'] ?? 0) ?: null;
    ensure_kegiatan_kategori_column($pdo);
    $stKat = $pdo->prepare('SELECT COALESCE(kategori_kegiatan, "TAALIM") FROM kegiatan WHERE id = :id LIMIT 1');
    $stKat->execute(['id' => $kegiatanId > 0 ? $kegiatanId : (int) ($jadwal['kegiatan_id'] ?? 0)]);
    if (strtoupper((string) ($stKat->fetchColumn() ?: 'TAALIM')) === 'JAMAAH') {
        $pembimbingId = null;
    }
    $tempatVal = trim((string) ($_POST['tempat'] ?? ''));
    $tempatVal = $tempatVal !== '' ? $tempatVal : null;

    $result = jadwal_simpan_perubahan_massal(
        $pdo,
        $id,
        $siblingIdsBefore !== [] ? $siblingIdsBefore : [$id],
        $kegiatanId,
        $jamMulai,
        $jamSelesai,
        $pembimbingId,
        $tempatVal,
        $tingkatanDipilih,
        $hariDipilih,
        $auditUserId
    );
    set_flash($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? ''));
    header('Location: ' . app_href($result['ok'] ? '/jadwal/index.php' : '/jadwal/edit.php?id=' . $id));
    exit;
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
array_unshift($tingkatanList, 'Semua Tingkatan');
$pembimbingList = table_exists($pdo, 'pembimbing')
    ? $pdo->query('SELECT id, nama_pembimbing, nip FROM pembimbing ORDER BY ' . pembimbing_list_order_sql(''))->fetchAll()
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
ensure_kegiatan_kategori_column($pdo);
$stJadwalKat = $pdo->prepare('SELECT COALESCE(k.kategori_kegiatan, "TAALIM") FROM kegiatan k INNER JOIN jadwal_kegiatan j ON j.kegiatan_id = k.id WHERE j.id = :id LIMIT 1');
$stJadwalKat->execute(['id' => $id]);
$jadwalKategori = strtoupper((string) ($stJadwalKat->fetchColumn() ?: 'TAALIM'));
$isJadwalJamaah = $jadwalKategori === 'JAMAAH';

$pageTitle = 'Edit Jadwal Kegiatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal</a></p>
    <h1 class="h4 mb-1">Edit slot jadwal</h1>
    <p class="text-muted mb-0 small">Ubah waktu, hari, tingkatan, atau tempat. Perubahan langsung memperbarui baris jadwal yang ada — tidak menghapus kegiatan lalu membuat ulang.</p>
</div>

<?php if ($isJadwalJamaah): ?>
<div class="alert alert-warning py-2 small mb-3">
    <i class="fa-solid fa-mosque me-1"></i>
    <strong>Jama'ah:</strong> ubah waktu sekaligus per kelompok Putra/Putri di
    <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah')) ?>">Atur waktu Jama'ah</a>.
    Di halaman ini hanya sesuaikan tingkatan/tempat jika perlu.
</div>
<?php endif; ?>

<?php if ($siblingCount > 1): ?>
<div class="alert alert-info py-2 small mb-3">
    <i class="fa-solid fa-layer-group me-1"></i>
    Slot terhubung <strong><?= (int) $siblingCount ?></strong> baris (kegiatan &amp; jam yang sama).
    Centang hari/tingkatan lalu simpan — baris yang ada diperbarui, hanya kombinasi yang dicabut yang dihapus.
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12"><h2 class="h6 text-muted mb-0">Kegiatan & kelas</h2></div>
            <div class="col-md-6">
                <label class="form-label">Kegiatan</label>
                <?php if ($isJadwalJamaah):
                    $namaKegEdit = '';
                    foreach ($kegiatanList as $kegiatan) {
                        if ((int) $kegiatan['id'] === (int) $jadwal['kegiatan_id']) {
                            $namaKegEdit = (string) $kegiatan['nama_kegiatan'];
                            break;
                        }
                    }
                    ?>
                    <input type="hidden" name="kegiatan_id" value="<?= (int) $jadwal['kegiatan_id'] ?>">
                    <div class="form-control bg-light">
                        <?= htmlspecialchars($namaKegEdit !== '' ? $namaKegEdit : '—') ?>
                        <span class="badge jadwal-kat-badge jadwal-kat-badge--jamaah ms-1">Jama'ah</span>
                    </div>
                <?php else: ?>
                <select name="kegiatan_id" class="form-select" required>
                    <option value="">Pilih kegiatan</option>
                    <?php foreach ($kegiatanList as $kegiatan): ?>
                        <option value="<?= $kegiatan['id'] ?>" <?= (int) $jadwal['kegiatan_id'] === (int) $kegiatan['id'] ? 'selected' : '' ?>><?= htmlspecialchars($kegiatan['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
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
            <?php if ($isJadwalJamaah): ?>
            <div class="col-12">
                <div class="alert alert-info py-2 small mb-0">
                    Munawib jamaah diatur <strong>per hari</strong> (Putra/Putri) di
                    <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah_munawib')) ?>">Munawib Jama'ah</a>, bukan per slot kegiatan.
                </div>
            </div>
            <?php else: ?>
            <div class="col-md-6">
                <label class="form-label">Pembimbing (opsional)</label>
                <select name="pembimbing_id" class="form-select">
                    <option value="0">Belum ditentukan</option>
                    <?php foreach ($pembimbingList as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $jadwal['pembimbing_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nama_pembimbing']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan perubahan</button>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>