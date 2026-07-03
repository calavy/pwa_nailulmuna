<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wali.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/mukimin.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_status.php';
require_once __DIR__ . '/../helpers/asrama.php';
require_once __DIR__ . '/../helpers/kelas_ruangan.php';
require_once __DIR__ . '/../helpers/sdm_embed.php';
require_once __DIR__ . '/../helpers/santri_foto.php';

require_roles(['admin', 'pengurus']);
$embed = sdm_is_embed();
ensure_santri_identity_columns($pdo);
santri_foto_ensure_schema($pdo);
ensure_wali_santri_table($pdo);
ensure_asrama_kamar_ranjang_tables($pdo);
ensure_kelas_ruangan_table($pdo);

$tingkatanList = [];
if (table_exists($pdo, 'tingkatan')) {
    require_once __DIR__ . '/../helpers/santri_list_sort.php';
    tingkatan_ensure_urutan_column($pdo);
    $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY urutan ASC, nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN);
}

$kelasKeuanganList = kelas_keuangan_list_active($pdo);

$asramaKamarRows = [];
$asramaRanjangRows = [];
if (table_exists($pdo, 'asrama_kamar')) {
    $asramaKamarRows = $pdo->query('SELECT id, nama_kamar FROM asrama_kamar ORDER BY urutan ASC, nama_kamar ASC')->fetchAll(PDO::FETCH_ASSOC);
}
if (table_exists($pdo, 'asrama_ranjang')) {
    $posCol = column_exists($pdo, 'asrama_ranjang', 'posisi');
    $asramaRanjangRows = $pdo->query(
        $posCol
            ? 'SELECT id, kamar_id, label, posisi FROM asrama_ranjang ORDER BY urutan ASC, label ASC, posisi ASC'
            : 'SELECT id, kamar_id, label, \'ATAS\' AS posisi FROM asrama_ranjang ORDER BY urutan ASC, label ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}
$kelasRuanganRows = kelas_ruangan_list_all($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $atasKeinginan = trim((string) ($_POST['atas_keinginan'] ?? ''));
    if (!in_array($atasKeinginan, ['SENDIRI', 'ORANGTUA_WALI'], true)) {
        $atasKeinginan = '';
    }
    $statusValid = santri_status_validate_save(
        (string) ($_POST['status_santri'] ?? 'AKTIF'),
        (string) ($_POST['alasan_keluar'] ?? ''),
        (string) ($_POST['tanggal_keluar'] ?? ''),
        (string) ($_POST['jenis_keluar'] ?? '')
    );
    if (!$statusValid['ok']) {
        set_flash('error', (string) $statusValid['error']);
        header('Location: ' . app_href(sdm_embed_url('/santri/create.php')));
        exit;
    }
    $statusSantri = $statusValid['status'];
    $alasanKeluar = $statusValid['alasan_keluar'];
    $tanggalKeluar = $statusValid['tanggal_keluar'];
    $keluarKategori = $statusValid['keluar_kategori'];
    $isAktifFlag = $statusValid['is_aktif'];
    $kategoriKelas = santri_normalize_kategori_kelas($pdo, (string) ($_POST['kategori_kelas'] ?? ''));
    $namaKamar = trim((string) ($_POST['nama_kamar'] ?? ''));
    $noRanjang = trim((string) ($_POST['no_ranjang'] ?? ''));
    $asramaRid = (int) ($_POST['asrama_ranjang_id'] ?? 0);
    $resolved = asrama_resolve_ranjang_to_kamar_fields($pdo, $asramaRid);
    if ($resolved !== null) {
        $namaKamar = $resolved['nama_kamar'];
        $noRanjang = $resolved['no_ranjang'];
    }
    $asramaRanjangDb = $asramaRid > 0 ? $asramaRid : null;
    if (!santri_status_is_di_pondok($statusSantri)) {
        $asramaRanjangDb = null;
        $namaKamar = '';
        $noRanjang = '';
    }
    $kelasRuanganId = (int) ($_POST['kelas_ruangan_id'] ?? 0);
    $kelasRuanganDb = null;
    if ($kelasRuanganId > 0) {
        $chkR = $pdo->prepare('SELECT 1 FROM kelas_ruangan WHERE id = :id LIMIT 1');
        $chkR->execute(['id' => $kelasRuanganId]);
        if (!$chkR->fetchColumn()) {
            set_flash('error', 'Ruangan kelas yang dipilih tidak valid.');
            header('Location: ' . app_href(sdm_embed_url('/santri/create.php')));
            exit;
        }
        $kelasRuanganDb = $kelasRuanganId;
    }
    $bedErr = santri_validate_asrama_bed_unik($pdo, 0, $statusSantri, (int) ($asramaRanjangDb ?: 0), $namaKamar, $noRanjang);
    if ($bedErr !== null) {
        set_flash('error', $bedErr);
        header('Location: ' . app_href(sdm_embed_url('/santri/create.php')));
        exit;
    }
    $data = [
        'qr' => trim($_POST['qr'] ?? ''),
        'nis' => trim($_POST['nis'] ?? ''),
        'nama_santri' => trim($_POST['nama_santri'] ?? ''),
        'nik' => trim($_POST['nik'] ?? ''),
        'jenis_kelamin' => trim($_POST['jenis_kelamin'] ?? ''),
        'tempat_lahir_kab' => trim($_POST['tempat_lahir_kab'] ?? ''),
        'tanggal_lahir' => trim($_POST['tanggal_lahir'] ?? ''),
        'bulan_lahir' => trim($_POST['bulan_lahir'] ?? ''),
        'tahun_lahir' => trim($_POST['tahun_lahir'] ?? ''),
        'jumlah_saudara' => trim($_POST['jumlah_saudara'] ?? ''),
        'anak_ke' => trim($_POST['anak_ke'] ?? ''),
        'hobi' => trim($_POST['hobi'] ?? ''),
        'cita_cita' => trim($_POST['cita_cita'] ?? ''),
        'dusun' => trim($_POST['dusun'] ?? ''),
        'rt_rw' => trim($_POST['rt_rw'] ?? ''),
        'desa_kelurahan' => trim($_POST['desa_kelurahan'] ?? ''),
        'kecamatan' => trim($_POST['kecamatan'] ?? ''),
        'kabupaten' => trim($_POST['kabupaten'] ?? ''),
        'propinsi' => trim($_POST['propinsi'] ?? ''),
        'nama_ayah' => trim($_POST['nama_ayah'] ?? ''),
        'pekerjaan_ayah' => trim($_POST['pekerjaan_ayah'] ?? ''),
        'no_kontak_ayah' => trim($_POST['no_kontak_ayah'] ?? ''),
        'nama_ibu' => trim($_POST['nama_ibu'] ?? ''),
        'pekerjaan_ibu' => trim($_POST['pekerjaan_ibu'] ?? ''),
        'no_kontak_ibu' => trim($_POST['no_kontak_ibu'] ?? ''),
        'nama_kafil' => trim($_POST['nama_kafil'] ?? ''),
        'status_kafil' => trim($_POST['status_kafil'] ?? ''),
        'pekerjaan_kafil' => trim($_POST['pekerjaan_kafil'] ?? ''),
        'no_kontak_kafil' => trim($_POST['no_kontak_kafil'] ?? ''),
        'pendidikan_diniyyah_terakhir' => trim($_POST['pendidikan_diniyyah_terakhir'] ?? ''),
        'pendidikan_formal_terakhir' => trim($_POST['pendidikan_formal_terakhir'] ?? ''),
        'kitab_yang_pernah_dikaji' => trim($_POST['kitab_yang_pernah_dikaji'] ?? ''),
        'keluhan_sakit' => trim($_POST['keluhan_sakit'] ?? ''),
        'pengobatan' => trim($_POST['pengobatan'] ?? ''),
        'tanggal_masuk' => trim($_POST['tanggal_masuk'] ?? ''),
        'alasan_mondok' => trim($_POST['alasan_mondok'] ?? ''),
        'atas_keinginan' => $atasKeinginan,
        'mengapa_nailul' => trim($_POST['mengapa_nailul'] ?? ''),
        'tingkatan' => trim($_POST['tingkatan'] ?? ''),
        'kategori_kelas' => $kategoriKelas,
        'no_wa_wali' => trim($_POST['no_wa_wali'] ?? ''),
        'status_santri' => $statusSantri,
        'alasan_keluar' => $alasanKeluar,
        'tanggal_keluar' => $tanggalKeluar,
        'keluar_kategori' => $keluarKategori,
        'is_aktif' => $isAktifFlag,
        'nama_kamar' => $namaKamar,
        'no_ranjang' => $noRanjang,
        'asrama_ranjang_id' => $asramaRanjangDb,
        'kelas_ruangan_id' => $kelasRuanganDb,
    ];

    $statement = $pdo->prepare('
        INSERT INTO santri (qr, nis, nama_santri, nik, jenis_kelamin, tempat_lahir_kab, tanggal_lahir, bulan_lahir, tahun_lahir, jumlah_saudara, anak_ke, hobi, cita_cita, dusun, rt_rw, desa_kelurahan, kecamatan, kabupaten, propinsi, nama_ayah, pekerjaan_ayah, no_kontak_ayah, nama_ibu, pekerjaan_ibu, no_kontak_ibu, nama_kafil, status_kafil, pekerjaan_kafil, no_kontak_kafil, pendidikan_diniyyah_terakhir, pendidikan_formal_terakhir, kitab_yang_pernah_dikaji, keluhan_sakit, pengobatan, tanggal_masuk, alasan_mondok, atas_keinginan, mengapa_nailul, tingkatan, kategori_kelas, no_wa_wali, status_santri, alasan_keluar, tanggal_keluar, keluar_kategori, nama_kamar, no_ranjang, asrama_ranjang_id, kelas_ruangan_id, is_aktif)
        VALUES (:qr, :nis, :nama_santri, :nik, :jenis_kelamin, :tempat_lahir_kab, :tanggal_lahir, :bulan_lahir, :tahun_lahir, :jumlah_saudara, :anak_ke, :hobi, :cita_cita, :dusun, :rt_rw, :desa_kelurahan, :kecamatan, :kabupaten, :propinsi, :nama_ayah, :pekerjaan_ayah, :no_kontak_ayah, :nama_ibu, :pekerjaan_ibu, :no_kontak_ibu, :nama_kafil, :status_kafil, :pekerjaan_kafil, :no_kontak_kafil, :pendidikan_diniyyah_terakhir, :pendidikan_formal_terakhir, :kitab_yang_pernah_dikaji, :keluhan_sakit, :pengobatan, :tanggal_masuk, :alasan_mondok, :atas_keinginan, :mengapa_nailul, :tingkatan, :kategori_kelas, :no_wa_wali, :status_santri, :alasan_keluar, :tanggal_keluar, :keluar_kategori, :nama_kamar, :no_ranjang, :asrama_ranjang_id, :kelas_ruangan_id, :is_aktif)
    ');
    $statement->execute($data);
    $newId = (int) $pdo->lastInsertId();
    if ($newId > 0) {
        if (santri_status_is_di_pondok($statusSantri)) {
            sync_santri_wali_from_kafil($pdo, $newId);
        }
        if (santri_status_hapus_operasional($statusSantri)) {
            santri_hapus_data_operasional_nonaktif($pdo, $newId);
        }
        if (santri_status_sync_mukimin($statusSantri)) {
            mukimin_sync_from_santri($pdo, $newId);
        }
        $tglMasuk = trim((string) ($data['tanggal_masuk'] ?? ''));
        santri_riwayat_upsert_tingkatan($pdo, $newId, trim((string) ($data['tingkatan'] ?? '')), $data['kategori_kelas'] ?: null);
        if ($tglMasuk !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMasuk)) {
            santri_riwayat_upsert_tingkatan($pdo, $newId, trim((string) ($data['tingkatan'] ?? '')), $data['kategori_kelas'] ?: null, $tglMasuk, 'Tahun masuk pondok');
            require_once __DIR__ . '/../helpers/tagihan_santri_masuk.php';
            tagihan_santri_masuk_riwayat_sync($pdo, $newId, $tglMasuk);
        }
        if (!empty($_FILES['foto_profil']['name'])) {
            $fotoUp = santri_foto_handle_upload($_FILES['foto_profil'], null, $newId);
            if (!$fotoUp['ok']) {
                set_flash('error', (string) ($fotoUp['error'] ?? 'Upload foto gagal.'));
            } elseif (!empty($fotoUp['path'])) {
                $pdo->prepare('UPDATE santri SET foto_profil = :f WHERE id = :id')->execute([
                    'f' => $fotoUp['path'],
                    'id' => $newId,
                ]);
            }
        }
    }

    set_flash('success', 'Data santri berhasil ditambahkan.');
    if (santri_status_is_keluar($statusSantri) && $newId > 0) {
        sdm_embed_done_redirect('/santri/keluar.php?id=' . $newId);
    }
    sdm_embed_done_redirect('/santri/index.php');
}

$pageTitle = 'Tambah santri';
if ($embed) {
    sdm_embed_layout_start($pageTitle);
} else {
    require_once __DIR__ . '/../includes/header.php';
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0">Tambah santri</h1>
    <?php if (!$embed): ?><div class="d-flex gap-2">
        <a href="/santri/semua_jati.php" class="btn btn-outline-primary btn-sm">Data induk</a>
        <a href="/santri/index.php" class="btn btn-outline-secondary">Santri aktif</a>
    </div><?php endif; ?>
</div>
<?php if (!$embed): ?><p class="small text-muted mb-3">Biodata lengkap dapat diubah lewat <a href="/santri/semua_jati.php">Data induk santri</a>.</p><?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-12">
                <div class="border rounded-3 p-3 santri-foto-upload-preview bg-light">
                    <label class="form-label fw-semibold mb-1">Foto profil</label>
                    <p class="small text-muted mb-2">Ditampilkan di portal wali &amp; portal santri. JPG/PNG/WEBP, maks. 2 MB.</p>
                    <input type="file" name="foto_profil" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">QR</label>
                <input type="text" name="qr" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">NIS</label>
                <input type="text" name="nis" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama</label>
                <input type="text" name="nama_santri" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">NIK</label>
                <input type="text" name="nik" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis Kelamin</label>
                <div class="d-flex flex-wrap gap-2">
                    <input type="radio" class="btn-check" name="jenis_kelamin" id="jk-l" value="Laki-laki" autocomplete="off">
                    <label class="btn btn-outline-primary btn-sm" for="jk-l">Laki-laki</label>
                    <input type="radio" class="btn-check" name="jenis_kelamin" id="jk-p" value="Perempuan" autocomplete="off">
                    <label class="btn btn-outline-primary btn-sm" for="jk-p">Perempuan</label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tempat Lahir (Kab.)</label>
                <input type="text" name="tempat_lahir_kab" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tanggal Lahir</label>
                <input type="text" name="tanggal_lahir" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Bulan Lahir</label>
                <input type="text" name="bulan_lahir" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tahun Lahir</label>
                <input type="text" name="tahun_lahir" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Jumlah Saudara</label>
                <input type="number" name="jumlah_saudara" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Anak Ke</label>
                <input type="number" name="anak_ke" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Hobi</label>
                <input type="text" name="hobi" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Cita-cita</label>
                <input type="text" name="cita_cita" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Tingkatan</label>
                <?php if ($tingkatanList): ?>
                    <select name="tingkatan" class="form-select" required>
                        <option value="">Pilih tingkatan</option>
                        <?php foreach ($tingkatanList as $tg): ?>
                            <option value="<?= htmlspecialchars((string) $tg) ?>"><?= htmlspecialchars((string) $tg) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="tingkatan" class="form-control" placeholder="Contoh: SMP, SMA, MA" required>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">No WA Wali</label>
                <input type="text" name="no_wa_wali" class="form-control" placeholder="628xxxxxxxxxx">
            </div>
            <div class="col-md-6">
                <label class="form-label">Kelas/Kategori Keuangan</label>
                <select name="kategori_kelas" class="form-select" required>
                    <option value="">Pilih kategori kelas</option>
                    <?php foreach ($kelasKeuanganList as $kk): ?>
                        <?php
                        $kKode = strtoupper(trim((string) ($kk['kode'] ?? '')));
                        $kNama = trim((string) ($kk['nama_tampilan'] ?? ''));
                        ?>
                        <option value="<?= htmlspecialchars($kKode) ?>" title="<?= htmlspecialchars($kKode) ?>"><?= htmlspecialchars($kNama !== '' ? $kNama : $kKode) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Disimpan sebagai kode master; nama tampilan persis juga diterima lalu dinormalisasi. Daftar di <a href="/settings/kelas_keuangan.php">Kelas keuangan</a> (menu <a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a>). Tarif mengikuti pemetaan Muadalah / Wustho / Ulya.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Ruangan kelas</label>
                <select name="kelas_ruangan_id" class="form-select">
                    <option value="0">— Tidak memilih / isi nanti —</option>
                    <?php foreach ($kelasRuanganRows as $kr): ?>
                        <option value="<?= (int) $kr['id'] ?>"><?= htmlspecialchars((string) $kr['nama_ruangan']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Master di <a href="/settings/kelas_ruangan.php">Ruangan kelas</a> (<a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a>).</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Status santri</label>
                <select name="status_santri" id="status-santri" class="form-select" required>
                    <?php foreach (santri_status_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $opt === 'AKTIF' ? ' selected' : '' ?>><?= htmlspecialchars(santri_status_label($opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Kamar &amp; ranjang</label>
                <?php if ($asramaKamarRows !== [] && $asramaRanjangRows !== []): ?>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <select id="asrama-sel-kamar" class="form-select" aria-label="Pilih kamar master">
                                <option value="0">— Pilih dari master —</option>
                                <?php foreach ($asramaKamarRows as $ak): ?>
                                    <option value="<?= (int) $ak['id'] ?>"><?= htmlspecialchars((string) $ak['nama_kamar']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <select name="asrama_ranjang_id" id="asrama-sel-ranjang" class="form-select" aria-label="Pilih ranjang">
                                <option value="0">— Pilih ranjang —</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-text mb-2">Master di <a href="/settings/kamar_ranjang.php">Pengaturan kamar &amp; ranjang</a> (<a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a>). Satu ranjang (termasuk atas/bawah) hanya untuk satu santri <strong>aktif</strong>. Memilih ranjang master mengisi otomatis.</div>
                <?php elseif ($asramaKamarRows !== []): ?>
                    <p class="small text-muted mb-2">Sudah ada kamar master, belum ada ranjang. Tambah ranjang di <a href="/settings/kamar_ranjang.php">pengaturan kamar &amp; ranjang</a> atau isi manual di bawah.</p>
                <?php else: ?>
                    <p class="small text-muted mb-2">Belum ada master kamar. Isi manual atau buat master di <a href="/settings/kamar_ranjang.php">pengaturan kamar &amp; ranjang</a>.</p>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Nama kamar (manual / cadangan)</label>
                        <input type="text" name="nama_kamar" id="asrama-inp-nama-kamar" class="form-control" placeholder="Contoh: Kamar A1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">No. ranjang (manual / cadangan)</label>
                        <input type="text" name="no_ranjang" id="asrama-inp-no-ranjang" class="form-control" placeholder="Contoh: 01 — Atas (jika manual)">
                    </div>
                </div>
            </div>
            <div class="col-md-6 status-keluar-wrap status-nonaktif-only d-none">
                <label class="form-label">Alasan keluar</label>
                <input type="text" name="alasan_keluar" class="form-control" placeholder="Wajib untuk status Nonaktif">
            </div>
            <div class="col-md-6 status-keluar-wrap status-tanggal-wrap d-none">
                <label class="form-label">Tanggal keluar</label>
                <input type="date" name="tanggal_keluar" class="form-control">
            </div>
            <div class="col-12 status-keluar-wrap status-jenis-wrap d-none">
                <label class="form-label">Kategori keluar (arsip)</label>
                <select name="jenis_keluar" class="form-select form-select-sm">
                    <option value="KELUAR">Belum tamat / boyong</option>
                    <option value="MUQIM">Tamat / alumni</option>
                </select>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="toggle-jati-diri" checked>
                    <label class="form-check-label fw-semibold" for="toggle-jati-diri">Isi Jati Diri Santri Lengkap</label>
                </div>
            </div>
            <div class="col-12" id="jati-diri-fields">
                <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Dusun</label>
                <input type="text" name="dusun" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">RT/RW</label>
                <input type="text" name="rt_rw" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Desa/Kelurahan</label>
                <input type="text" name="desa_kelurahan" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kecamatan</label>
                <input type="text" name="kecamatan" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kabupaten</label>
                <input type="text" name="kabupaten" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Propinsi</label>
                <input type="text" name="propinsi" class="form-control">
            </div>
            <div class="col-md-4"><label class="form-label">Nama Ayah</label><input type="text" name="nama_ayah" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Pekerjaan Ayah</label><input type="text" name="pekerjaan_ayah" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">No Kontak Ayah</label><input type="text" name="no_kontak_ayah" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Nama Ibu</label><input type="text" name="nama_ibu" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Pekerjaan Ibu</label><input type="text" name="pekerjaan_ibu" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">No Kontak Ibu</label><input type="text" name="no_kontak_ibu" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Nama Kafil</label><input type="text" name="nama_kafil" class="form-control"></div>
            <div class="col-md-4">
                <label class="form-label">Status Kafil</label>
                <div class="d-flex flex-wrap gap-2">
                    <input type="radio" class="btn-check" name="status_kafil" id="kafil-aktif" value="Aktif" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="kafil-aktif">Aktif</label>
                    <input type="radio" class="btn-check" name="status_kafil" id="kafil-nonaktif" value="Tidak Aktif" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="kafil-nonaktif">Tidak Aktif</label>
                    <input type="radio" class="btn-check" name="status_kafil" id="kafil-tidakada" value="Tidak Ada" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="kafil-tidakada">Tidak Ada</label>
                </div>
            </div>
            <div class="col-md-4"><label class="form-label">Pekerjaan Kafil</label><input type="text" name="pekerjaan_kafil" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">No Kontak Kafil</label><input type="text" name="no_kontak_kafil" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Pendidikan Diniyyah Terakhir</label><input type="text" name="pendidikan_diniyyah_terakhir" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Pendidikan Formal Terakhir</label><input type="text" name="pendidikan_formal_terakhir" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Kitab Yang Pernah Dikaji</label><input type="text" name="kitab_yang_pernah_dikaji" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Keluhan Sakit</label><textarea name="keluhan_sakit" rows="2" class="form-control"></textarea></div>
            <div class="col-md-6"><label class="form-label">Pengobatan</label><textarea name="pengobatan" rows="2" class="form-control"></textarea></div>
            <div class="col-md-4"><label class="form-label">Tanggal Masuk</label><input type="date" name="tanggal_masuk" class="form-control"></div>
            <div class="col-md-8"><label class="form-label">Alasan Mondok</label><input type="text" name="alasan_mondok" class="form-control"></div>
            <div class="col-md-6">
                <label class="form-label">Atas Keinginan</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="atas_keinginan" value="SENDIRI" id="atas-1"><label class="form-check-label" for="atas-1">Sendiri</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="atas_keinginan" value="ORANGTUA_WALI" id="atas-2"><label class="form-check-label" for="atas-2">Orangtua/Wali</label></div>
                </div>
            </div>
            <div class="col-md-6"><label class="form-label">Mengapa Nailul</label><input type="text" name="mengapa_nailul" class="form-control"></div>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const toggle = document.getElementById('toggle-jati-diri');
        const section = document.getElementById('jati-diri-fields');
        if (!toggle || !section) return;
        function syncSection() {
            section.style.display = toggle.checked ? '' : 'none';
        }
        toggle.addEventListener('change', syncSection);
        syncSection();

        const statusSelect = document.getElementById('status-santri');
        const nonaktifFields = Array.from(document.querySelectorAll('.status-nonaktif-only, .status-tanggal-wrap, .status-jenis-wrap'));
        const alasan = document.querySelector('input[name="alasan_keluar"]');
        const tanggal = document.querySelector('input[name="tanggal_keluar"]');
        function syncStatusKeluar() {
            const v = statusSelect ? statusSelect.value : 'AKTIF';
            const isNon = v === 'NONAKTIF';
            nonaktifFields.forEach((el) => el.classList.toggle('d-none', !isNon));
            if (alasan) alasan.required = isNon;
            if (tanggal) tanggal.required = isNon;
        }
        if (statusSelect) {
            statusSelect.addEventListener('change', syncStatusKeluar);
            syncStatusKeluar();
        }
    })();
</script>
<?php if ($asramaKamarRows !== [] && $asramaRanjangRows !== []): ?>
<script>
    (function () {
        const asramaRanjangAll = <?= json_encode(array_map(static function ($r) {
            return [
                'id' => (int) $r['id'],
                'kamar_id' => (int) $r['kamar_id'],
                'label' => (string) $r['label'],
                'posisi' => strtoupper((string) ($r['posisi'] ?? 'ATAS')) === 'BAWAH' ? 'BAWAH' : 'ATAS',
            ];
        }, $asramaRanjangRows), JSON_UNESCAPED_UNICODE) ?>;
        const selKamar = document.getElementById('asrama-sel-kamar');
        const selRanjang = document.getElementById('asrama-sel-ranjang');
        const inpNama = document.getElementById('asrama-inp-nama-kamar');
        const inpNo = document.getElementById('asrama-inp-no-ranjang');
        if (!selKamar || !selRanjang || !Array.isArray(asramaRanjangAll)) return;
        function refillRanjang(preserveId) {
            const kid = parseInt(selKamar.value, 10) || 0;
            const cur = parseInt(preserveId || selRanjang.value, 10) || 0;
            selRanjang.innerHTML = '<option value="0">— Pilih ranjang —</option>';
                asramaRanjangAll.filter(function (r) { return r.kamar_id === kid; }).forEach(function (r) {
                    const o = document.createElement('option');
                    o.value = String(r.id);
                    const posLabel = r.posisi === 'BAWAH' ? 'Bawah' : 'Atas';
                    o.textContent = r.label + ' — ' + posLabel;
                    selRanjang.appendChild(o);
                });
            if (cur && Array.from(selRanjang.options).some(function (o) { return o.value === String(cur); })) {
                selRanjang.value = String(cur);
            } else {
                selRanjang.value = '0';
            }
        }
        selKamar.addEventListener('change', function () { refillRanjang(0); });
        selRanjang.addEventListener('change', function () {
            const rid = parseInt(selRanjang.value, 10) || 0;
            if (rid > 0 && inpNama && inpNo) {
                const row = asramaRanjangAll.find(function (x) { return x.id === rid; });
                if (row) {
                    const opt = selKamar.options[selKamar.selectedIndex];
                    if (opt) inpNama.value = opt.text;
                    const posLabel = row.posisi === 'BAWAH' ? 'Bawah' : 'Atas';
                    inpNo.value = row.label + ' — ' + posLabel;
                }
            }
        });
        refillRanjang(0);
    })();
</script>
<?php endif; ?>

<?php
if ($embed) {
    sdm_embed_layout_end();
}
require_once __DIR__ . '/../includes/footer.php';
?>
