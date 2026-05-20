<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_keluar.php';
require_once __DIR__ . '/../helpers/mukimin.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_status.php';
require_once __DIR__ . '/../helpers/wali.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_santri_keluar_columns($pdo);

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$s = $st->fetch(PDO::FETCH_ASSOC);
if (!$s) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: /pwa_nailulmuna/santri/index.php');
    exit;
}

if (!santri_status_is_aktif_list(santri_status_from_row($s))) {
    set_flash('error', 'Santri ini sudah tidak berstatus Aktif. Lihat di Data induk atau Data Mukimin.');
    header('Location: /pwa_nailulmuna/santri/mukimin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = trim((string) ($_POST['tanggal_keluar'] ?? ''));
    $alasan = trim((string) ($_POST['alasan_keluar'] ?? ''));
    $jenisKeluar = trim((string) ($_POST['jenis_keluar'] ?? ''));
    if ($jenisKeluar === '') {
        set_flash('error', 'Pilih kategori keluar: tamat/alumni atau keluar sebelum lulus.');
        header('Location: /pwa_nailulmuna/santri/nonaktif_cepat.php?id=' . $id);
        exit;
    }
    $statusValid = santri_status_validate_save(
        santri_status_const_nonaktif(),
        $alasan,
        $tanggal,
        $jenisKeluar
    );
    if (!$statusValid['ok']) {
        set_flash('error', (string) $statusValid['error']);
        header('Location: /pwa_nailulmuna/santri/nonaktif_cepat.php?id=' . $id);
        exit;
    }

    $pdo->prepare('
        UPDATE santri
        SET status_santri = :st, is_aktif = 0, tanggal_keluar = :tgl, alasan_keluar = :als, keluar_kategori = :kat,
            nama_kamar = NULL, no_ranjang = NULL, asrama_ranjang_id = NULL
        WHERE id = :id
    ')->execute([
        'st' => $statusValid['status'],
        'tgl' => $statusValid['tanggal_keluar'],
        'als' => $statusValid['alasan_keluar'],
        'kat' => $statusValid['keluar_kategori'],
        'id' => $id,
    ]);
    santri_hapus_data_operasional_nonaktif($pdo, $id);
    ensure_wali_santri_table($pdo);
    sync_santri_wali_from_kafil($pdo, $id);
    $mukiminId = mukimin_sync_from_santri($pdo, $id);

    set_flash('success', 'Status diubah menjadi Nonaktif dan masuk Data Mukimin.');
    $dest = '/pwa_nailulmuna/santri/mukimin.php';
    if ($mukiminId > 0) {
        $dest .= '?edit=' . $mukiminId;
    }
    header('Location: ' . $dest);
    exit;
}

$pageTitle = 'Non aktifkan santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-3">
    <h1 class="h3 mb-1">Non aktifkan dari daftar aktif</h1>
    <p class="text-muted small mb-0">
        Status menjadi <strong>Nonaktif</strong>. Pilih apakah tamat/alumni atau keluar sebelum lulus (untuk arsip administrasi).
        Setelah simpan, data otomatis masuk <strong>Data Mukimin</strong>. Penyelesaian keuangan &amp; surat (jika perlu) lewat <a href="/pwa_nailulmuna/santri/keluar.php">Administrasi keluar</a>. Jati diri lengkap di <a href="/pwa_nailulmuna/santri/semua_jati.php">Data induk</a>.
    </p>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <p class="fw-semibold mb-1"><?= htmlspecialchars((string) $s['nama_santri']) ?></p>
        <p class="small text-muted mb-4">NIS <?= htmlspecialchars((string) $s['nis']) ?> · <?= htmlspecialchars((string) ($s['tingkatan'] ?? '-')) ?></p>
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Tanggal keluar</label>
                <input type="date" name="tanggal_keluar" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label">Kategori keluar (arsip)</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="jenis_keluar" id="jk-muqim" value="MUQIM" required>
                        <label class="form-check-label" for="jk-muqim"><strong>Tamat / alumni</strong> — selesai jenjang</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="jenis_keluar" id="jk-keluar" value="KELUAR">
                        <label class="form-check-label" for="jk-keluar"><strong>Keluar</strong> — sebelum lulus / pindah</label>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Alasan / keterangan</label>
                <textarea name="alasan_keluar" class="form-control" rows="3" required placeholder="Contoh: Tamat kelas Ulya / Pindah ke pondok X"></textarea>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-danger">Simpan &amp; masuk Data Mukimin</button>
                <a href="/pwa_nailulmuna/santri/index.php" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
