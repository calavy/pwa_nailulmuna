<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wali.php';
require_once __DIR__ . '/../helpers/asrama.php';
require_once __DIR__ . '/../helpers/kelas_ruangan.php';
require_once __DIR__ . '/../helpers/sdm_embed.php';
require_once __DIR__ . '/../helpers/santri_portal.php';

require_roles(['admin', 'pengurus']);
$embed = sdm_is_embed();
ensure_santri_identity_columns($pdo);
ensure_asrama_kamar_ranjang_tables($pdo);
ensure_kelas_ruangan_table($pdo);
require_once __DIR__ . '/../helpers/santri_keluar.php';
require_once __DIR__ . '/../helpers/mukimin.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_status.php';
ensure_santri_keluar_columns($pdo);
ensure_wali_santri_table($pdo);

$tingkatanList = [];
if (table_exists($pdo, 'tingkatan')) {
    $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN);
}

$kelasKeuanganList = kelas_keuangan_list_active($pdo);

$id = (int) ($_GET['id'] ?? 0);

$statement = $pdo->prepare('SELECT * FROM santri WHERE id = :id');
$statement->execute(['id' => $id]);
$santri = $statement->fetch();

if (!$santri) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/santri/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pinWaliBaru = trim((string) ($_POST['wali_pin_baru'] ?? ''));
    $pinWaliKonf = trim((string) ($_POST['wali_pin_konfirmasi'] ?? ''));
    $pinSantriBaru = trim((string) ($_POST['santri_pin_baru'] ?? ''));
    $pinSantriKonf = trim((string) ($_POST['santri_pin_konfirmasi'] ?? ''));
    if (($pinWaliBaru !== '') !== ($pinWaliKonf !== '')) {
        set_flash('error', 'Isi PIN portal wali dan konfirmasi lengkap, atau kosongkan keduanya.');
        header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
        exit;
    }
    if (($pinSantriBaru !== '') !== ($pinSantriKonf !== '')) {
        set_flash('error', 'Isi PIN portal santri dan konfirmasi lengkap, atau kosongkan keduanya.');
        header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
        exit;
    }

    $atasKeinginan = trim((string) ($_POST['atas_keinginan'] ?? ''));
    if (!in_array($atasKeinginan, ['SENDIRI', 'ORANGTUA_WALI'], true)) {
        $atasKeinginan = '';
    }
    $statusValid = santri_status_validate_save(
        (string) ($_POST['status_santri'] ?? 'AKTIF'),
        (string) ($_POST['alasan_keluar'] ?? ''),
        (string) ($_POST['tanggal_keluar'] ?? ''),
        (string) ($_POST['jenis_keluar'] ?? ''),
        (string) ($santri['keluar_kategori'] ?? '')
    );
    if (!$statusValid['ok']) {
        set_flash('error', (string) $statusValid['error']);
        header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
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
            header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
            exit;
        }
        $kelasRuanganDb = $kelasRuanganId;
    }
    $bedErr = santri_validate_asrama_bed_unik($pdo, $id, $statusSantri, (int) ($asramaRanjangDb ?: 0), $namaKamar, $noRanjang);
    if ($bedErr !== null) {
        set_flash('error', $bedErr);
        header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
        exit;
    }
    $data = [
        'id' => $id,
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

    if ($pinWaliBaru !== '') {
        if (strlen($pinWaliBaru) < 6) {
            set_flash('error', 'PIN portal wali minimal 6 karakter.');
            header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
            exit;
        }
        if ($pinWaliBaru !== $pinWaliKonf) {
            set_flash('error', 'Konfirmasi PIN portal wali tidak sama.');
            header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
            exit;
        }
    }

    $update = $pdo->prepare('
        UPDATE santri
        SET qr = :qr, nis = :nis, nama_santri = :nama_santri, nik = :nik, jenis_kelamin = :jenis_kelamin, tempat_lahir_kab = :tempat_lahir_kab, tanggal_lahir = :tanggal_lahir, bulan_lahir = :bulan_lahir, tahun_lahir = :tahun_lahir, jumlah_saudara = :jumlah_saudara, anak_ke = :anak_ke, hobi = :hobi, cita_cita = :cita_cita, dusun = :dusun, rt_rw = :rt_rw, desa_kelurahan = :desa_kelurahan, kecamatan = :kecamatan, kabupaten = :kabupaten, propinsi = :propinsi, nama_ayah = :nama_ayah, pekerjaan_ayah = :pekerjaan_ayah, no_kontak_ayah = :no_kontak_ayah, nama_ibu = :nama_ibu, pekerjaan_ibu = :pekerjaan_ibu, no_kontak_ibu = :no_kontak_ibu, nama_kafil = :nama_kafil, status_kafil = :status_kafil, pekerjaan_kafil = :pekerjaan_kafil, no_kontak_kafil = :no_kontak_kafil, pendidikan_diniyyah_terakhir = :pendidikan_diniyyah_terakhir, pendidikan_formal_terakhir = :pendidikan_formal_terakhir, kitab_yang_pernah_dikaji = :kitab_yang_pernah_dikaji, keluhan_sakit = :keluhan_sakit, pengobatan = :pengobatan, tanggal_masuk = :tanggal_masuk, alasan_mondok = :alasan_mondok, atas_keinginan = :atas_keinginan, mengapa_nailul = :mengapa_nailul, tingkatan = :tingkatan, kategori_kelas = :kategori_kelas, no_wa_wali = :no_wa_wali, status_santri = :status_santri, alasan_keluar = :alasan_keluar, tanggal_keluar = :tanggal_keluar, keluar_kategori = :keluar_kategori, nama_kamar = :nama_kamar, no_ranjang = :no_ranjang, asrama_ranjang_id = :asrama_ranjang_id, kelas_ruangan_id = :kelas_ruangan_id, is_aktif = :is_aktif
        WHERE id = :id
    ');
    $update->execute($data);
    santri_riwayat_upsert_tingkatan($pdo, $id, $data['tingkatan'], $data['kategori_kelas'] ?: null);
    santri_riwayat_snapshot_asrama_from_santri($pdo, $id, array_merge($santri, $data));
    $tglMasukBaru = trim((string) $data['tanggal_masuk']);
    if ($tglMasukBaru !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMasukBaru)) {
        santri_riwayat_upsert_tingkatan($pdo, $id, $data['tingkatan'], $data['kategori_kelas'] ?: null, $tglMasukBaru, 'Tahun masuk pondok');
    }
    if (santri_status_hapus_operasional($statusSantri)) {
        santri_hapus_data_operasional_nonaktif($pdo, $id);
    }
    sync_santri_wali_from_kafil($pdo, $id);

    $wasKeluar = santri_status_is_nonaktif(santri_status_from_row($santri));
    $settled = trim((string) ($santri['keluar_settled_at'] ?? '')) !== '';
    if (santri_status_sync_mukimin($statusSantri) && !$wasKeluar) {
        $mukiminId = mukimin_sync_from_santri($pdo, $id);
        if (!$settled) {
            set_flash('success', 'Data santri disimpan dan masuk Data Mukimin. Penyelesaian keuangan & surat bisa lewat Administrasi keluar.');
        } else {
            set_flash('success', 'Data santri disimpan dan diperbarui di Data Mukimin.');
        }
        $dest = '/santri/mukimin.php';
        if ($mukiminId > 0) {
            $dest .= '?edit=' . $mukiminId;
        }
        header('Location: ' . app_href(sdm_embed_url($dest)));
        exit;
    }

    if ($pinWaliBaru !== '') {
        if ($pinWaliBaru !== $pinWaliKonf) {
            set_flash('error', 'Konfirmasi PIN portal wali tidak cocok.');
            header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
            exit;
        }
        $pdo->prepare('UPDATE santri SET wali_portal_pin_hash = :h WHERE id = :id')->execute([
            'h' => password_hash($pinWaliBaru, PASSWORD_DEFAULT),
            'id' => $id,
        ]);
    }
    if ($pinSantriBaru !== '') {
        if ($pinSantriBaru !== $pinSantriKonf) {
            set_flash('error', 'Konfirmasi PIN portal santri tidak cocok.');
            header('Location: ' . app_href(sdm_embed_url('/santri/edit.php?id=' . $id)));
            exit;
        }
        ensure_santri_portal_pin_column($pdo);
        $pdo->prepare('UPDATE santri SET santri_portal_pin_hash = :h WHERE id = :id')->execute([
            'h' => password_hash($pinSantriBaru, PASSWORD_DEFAULT),
            'id' => $id,
        ]);
    }

    set_flash('success', 'Data santri berhasil diperbarui.');
    $aktifAfter = santri_status_is_aktif_list($statusSantri);
    sdm_embed_done_redirect($aktifAfter ? '/santri/index.php' : '/santri/semua_jati.php');
}

$atasKeinginanSelected = strtoupper(trim((string) ($santri['atas_keinginan'] ?? '')));
if ($atasKeinginanSelected === 'SANTRI SENDIRI') {
    $atasKeinginanSelected = 'SENDIRI';
} elseif ($atasKeinginanSelected === 'ORANG TUA' || $atasKeinginanSelected === 'WALI') {
    $atasKeinginanSelected = 'ORANGTUA_WALI';
}

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
$selectedRanjangId = 0;
$selectedKamarId = 0;
if (column_exists($pdo, 'santri', 'asrama_ranjang_id') && (int) ($santri['asrama_ranjang_id'] ?? 0) > 0) {
    $selectedRanjangId = (int) $santri['asrama_ranjang_id'];
    $qk = $pdo->prepare('SELECT kamar_id FROM asrama_ranjang WHERE id = :id LIMIT 1');
    $qk->execute(['id' => $selectedRanjangId]);
    $selectedKamarId = (int) ($qk->fetchColumn() ?: 0);
} else {
    $selectedRanjangId = asrama_match_ranjang_id($pdo, $id, (string) ($santri['nama_kamar'] ?? ''), (string) ($santri['no_ranjang'] ?? ''));
    if ($selectedRanjangId > 0) {
        $qk = $pdo->prepare('SELECT kamar_id FROM asrama_ranjang WHERE id = :id LIMIT 1');
        $qk->execute(['id' => $selectedRanjangId]);
        $selectedKamarId = (int) ($qk->fetchColumn() ?: 0);
    }
}

$pageTitle = 'Edit santri';
if ($embed) {
    sdm_embed_layout_start($pageTitle);
} else {
    require_once __DIR__ . '/../includes/header.php';
}
?>

<?php
$statusEdit = santri_status_from_row($santri);
$aktifEdit = santri_status_is_aktif_list($statusEdit);
$kembaliHref = $aktifEdit ? '/santri/index.php' : '/santri/semua_jati.php';
$kembaliLabel = $aktifEdit ? 'Santri aktif' : 'Data induk';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0">Edit santri</h1>
    <?php if (!$embed): ?>
    <div class="d-flex gap-2">
        <a href="/santri/riwayat.php?id=<?= $id ?>" class="btn btn-outline-info btn-sm">Riwayat</a>
        <a href="/santri/semua_jati.php" class="btn btn-outline-primary btn-sm">Data induk</a>
        <a href="<?= htmlspecialchars($kembaliHref) ?>" class="btn btn-outline-secondary"><?= htmlspecialchars($kembaliLabel) ?></a>
    </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">QR</label>
                <input type="text" name="qr" class="form-control" value="<?= htmlspecialchars($santri['qr'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">NIS</label>
                <input type="text" name="nis" class="form-control" value="<?= htmlspecialchars($santri['nis']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama</label>
                <input type="text" name="nama_santri" class="form-control" value="<?= htmlspecialchars($santri['nama_santri']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">NIK</label>
                <input type="text" name="nik" class="form-control" value="<?= htmlspecialchars($santri['nik'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis Kelamin</label>
                <div class="d-flex flex-wrap gap-2">
                    <input type="radio" class="btn-check" name="jenis_kelamin" id="jk-l" value="Laki-laki" autocomplete="off" <?= ($santri['jenis_kelamin'] ?? '') === 'Laki-laki' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary btn-sm" for="jk-l">Laki-laki</label>
                    <input type="radio" class="btn-check" name="jenis_kelamin" id="jk-p" value="Perempuan" autocomplete="off" <?= ($santri['jenis_kelamin'] ?? '') === 'Perempuan' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary btn-sm" for="jk-p">Perempuan</label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tempat Lahir (Kab.)</label>
                <input type="text" name="tempat_lahir_kab" class="form-control" value="<?= htmlspecialchars($santri['tempat_lahir_kab'] ?? '') ?>">
            </div>
            <div class="col-md-4"><label class="form-label">Tanggal Lahir</label><input type="text" name="tanggal_lahir" class="form-control" value="<?= htmlspecialchars($santri['tanggal_lahir'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Bulan Lahir</label><input type="text" name="bulan_lahir" class="form-control" value="<?= htmlspecialchars($santri['bulan_lahir'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Tahun Lahir</label><input type="text" name="tahun_lahir" class="form-control" value="<?= htmlspecialchars($santri['tahun_lahir'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Jumlah Saudara</label><input type="number" name="jumlah_saudara" class="form-control" value="<?= htmlspecialchars($santri['jumlah_saudara'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Anak Ke</label><input type="number" name="anak_ke" class="form-control" value="<?= htmlspecialchars($santri['anak_ke'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Hobi</label><input type="text" name="hobi" class="form-control" value="<?= htmlspecialchars($santri['hobi'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Cita-cita</label><input type="text" name="cita_cita" class="form-control" value="<?= htmlspecialchars($santri['cita_cita'] ?? '') ?>"></div>
            <div class="col-md-6">
                <label class="form-label">Tingkatan</label>
                <?php if ($tingkatanList): ?>
                    <select name="tingkatan" class="form-select" required>
                        <option value="">Pilih tingkatan</option>
                        <?php foreach ($tingkatanList as $tg): ?>
                            <option value="<?= htmlspecialchars((string) $tg) ?>" <?= strtolower((string) $santri['tingkatan']) === strtolower((string) $tg) ? 'selected' : '' ?>><?= htmlspecialchars((string) $tg) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="tingkatan" class="form-control" value="<?= htmlspecialchars($santri['tingkatan'] ?? '') ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">No WA Wali</label>
                <input type="text" name="no_wa_wali" class="form-control" value="<?= htmlspecialchars($santri['no_wa_wali'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Kelas/Kategori Keuangan</label>
                <?php $kelasKeu = strtoupper(trim((string) ($santri['kategori_kelas'] ?? ''))); ?>
                <select name="kategori_kelas" class="form-select" required>
                    <option value="">Pilih kategori kelas</option>
                    <?php foreach ($kelasKeuanganList as $kk): ?>
                        <?php
                        $kKode = strtoupper(trim((string) ($kk['kode'] ?? '')));
                        $kNama = trim((string) ($kk['nama_tampilan'] ?? ''));
                        ?>
                        <option value="<?= htmlspecialchars($kKode) ?>" title="<?= htmlspecialchars($kKode) ?>" <?= $kelasKeu === $kKode ? 'selected' : '' ?>><?= htmlspecialchars($kNama !== '' ? $kNama : $kKode) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($kelasKeu !== '' && !in_array($kelasKeu, array_map(static fn ($r) => strtoupper(trim((string) ($r['kode'] ?? ''))), $kelasKeuanganList), true)): ?>
                    <div class="form-text text-warning">Nilai tersimpan &quot;<?= htmlspecialchars($kelasKeu) ?>&quot; tidak ada di daftar aktif. Pilih ulang atau aktifkan kembali di master.</div>
                <?php endif; ?>
                <div class="form-text">Nilai disimpan sebagai <strong>kode</strong> master. Bisa pilih dari daftar atau tempel <strong>nama tampilan persis</strong> saat impor — sistem menyesuaikan ke kode. Kelola di <a href="/settings/kelas_keuangan.php">Kelas keuangan</a> (<a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a>).</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Ruangan kelas</label>
                <?php $selKr = (int) ($santri['kelas_ruangan_id'] ?? 0); ?>
                <select name="kelas_ruangan_id" class="form-select">
                    <option value="0" <?= $selKr === 0 ? 'selected' : '' ?>>— Tidak memilih —</option>
                    <?php foreach ($kelasRuanganRows as $kr): ?>
                        <option value="<?= (int) $kr['id'] ?>" <?= $selKr === (int) $kr['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $kr['nama_ruangan']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Master di <a href="/settings/kelas_ruangan.php">Ruangan kelas</a>.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Status Santri</label>
                <select name="status_santri" id="status-santri" class="form-select" required>
                    <?php foreach (santri_status_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $statusEdit === $opt ? ' selected' : '' ?>><?= htmlspecialchars(santri_status_label($opt)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text small">Aktif = mondok · Nonaktif = sudah keluar · Khidmah = tetap di pondok (pengabdian).</div>
            </div>
            <div class="col-12">
                <label class="form-label">Kamar &amp; ranjang</label>
                <?php if ($asramaKamarRows !== [] && $asramaRanjangRows !== []): ?>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <select id="asrama-sel-kamar" class="form-select" aria-label="Pilih kamar master">
                                <option value="0">— Pilih dari master —</option>
                                <?php foreach ($asramaKamarRows as $ak): ?>
                                    <option value="<?= (int) $ak['id'] ?>" <?= (int) $ak['id'] === $selectedKamarId ? 'selected' : '' ?>><?= htmlspecialchars((string) $ak['nama_kamar']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <select name="asrama_ranjang_id" id="asrama-sel-ranjang" class="form-select" aria-label="Pilih ranjang">
                                <option value="0">— Pilih ranjang —</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-text mb-2">Master di <a href="/settings/kamar_ranjang.php">Pengaturan kamar &amp; ranjang</a> (<a href="/menu/menu_hub.php?id=menu-grp-pengaturan">Pengaturan</a>). Satu ranjang hanya untuk satu santri <strong>aktif</strong>. Non aktif melepas slot master.</div>
                <?php elseif ($asramaKamarRows !== []): ?>
                    <p class="small text-muted mb-2">Belum ada ranjang di master. Tambah di <a href="/settings/kamar_ranjang.php">pengaturan kamar &amp; ranjang</a> atau isi manual.</p>
                <?php else: ?>
                    <p class="small text-muted mb-2">Belum ada master kamar. Isi manual atau buat di <a href="/settings/kamar_ranjang.php">pengaturan kamar &amp; ranjang</a>.</p>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Nama kamar (manual / cadangan)</label>
                        <input type="text" name="nama_kamar" id="asrama-inp-nama-kamar" class="form-control" placeholder="Contoh: Kamar A1" value="<?= htmlspecialchars((string) ($santri['nama_kamar'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">No. ranjang (manual / cadangan)</label>
                        <input type="text" name="no_ranjang" id="asrama-inp-no-ranjang" class="form-control" placeholder="Contoh: 01 — Atas" value="<?= htmlspecialchars((string) ($santri['no_ranjang'] ?? '')) ?>">
                    </div>
                </div>
            </div>
            <?php
            $showKeluar = santri_status_is_nonaktif($statusEdit);
            $prefKat = strtoupper(trim((string) ($santri['keluar_kategori'] ?? '')));
            ?>
            <div class="col-md-6 status-keluar-wrap status-nonaktif-only <?= $showKeluar ? '' : 'd-none' ?>">
                <label class="form-label">Alasan keluar</label>
                <input type="text" name="alasan_keluar" class="form-control" value="<?= htmlspecialchars((string) ($santri['alasan_keluar'] ?? '')) ?>">
            </div>
            <div class="col-md-6 status-keluar-wrap status-tanggal-wrap <?= $showKeluar ? '' : 'd-none' ?>">
                <label class="form-label">Tanggal keluar</label>
                <input type="date" name="tanggal_keluar" class="form-control" value="<?= htmlspecialchars((string) ($santri['tanggal_keluar'] ?? '')) ?>">
            </div>
            <div class="col-12 status-keluar-wrap status-jenis-wrap <?= $showKeluar ? '' : 'd-none' ?>">
                <label class="form-label">Kategori keluar (arsip)</label>
                <select name="jenis_keluar" class="form-select form-select-sm">
                    <option value="KELUAR" <?= in_array($prefKat, ['KELUAR_PINDAH', 'BOYONG', 'KELUAR'], true) ? 'selected' : '' ?>>Belum tamat / boyong</option>
                    <option value="MUQIM" <?= in_array($prefKat, ['TAMAT', 'MUQIM'], true) ? 'selected' : '' ?>>Tamat / alumni</option>
                </select>
            </div>
            <div class="col-12">
                <div class="alert alert-info py-3 mb-0">
                    <h2 class="h6 mb-2">Portal wali santri</h2>
                    <p class="small mb-2">Wali login di <a href="/wali/login.php" target="_blank" rel="noopener">/wali/login.php</a> memakai <strong>NIS</strong> santri dan <strong>PIN</strong> yang Anda atur di sini.</p>
                    <p class="small mb-3 <?= !empty($santri['wali_portal_pin_hash']) ? 'text-success' : 'text-warning' ?>">
                        <?= !empty($santri['wali_portal_pin_hash']) ? 'PIN portal sudah diatur.' : 'PIN portal belum diatur — wali belum bisa masuk.' ?>
                    </p>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">PIN portal wali (baru)</label>
                            <input type="password" name="wali_pin_baru" class="form-control form-control-sm" autocomplete="new-password" minlength="6" placeholder="Kosongkan jika tidak diubah">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Ulangi PIN</label>
                            <input type="password" name="wali_pin_konfirmasi" class="form-control form-control-sm" autocomplete="new-password" minlength="6" placeholder="Konfirmasi">
                        </div>
                    </div>
                    <div class="form-text mt-1">Minimal 6 karakter. Isi hanya jika ingin membuat atau mengganti PIN.</div>
                </div>
            </div>
            <div class="col-12">
                <div class="alert alert-info py-3 mb-0">
                    <h2 class="h6 mb-2">Portal santri (login mandiri)</h2>
                    <p class="small mb-2">Santri login di <a href="/santri_portal/login.php" target="_blank" rel="noopener">/santri_portal/login.php</a> untuk melihat riwayat domisili dan pelanggaran sendiri.</p>
                    <p class="small mb-3 <?= !empty($santri['santri_portal_pin_hash']) ? 'text-success' : 'text-warning' ?>">
                        <?= !empty($santri['santri_portal_pin_hash']) ? 'PIN portal santri sudah diatur.' : 'PIN portal santri belum diatur.' ?>
                    </p>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">PIN portal santri (baru)</label>
                            <input type="password" name="santri_pin_baru" class="form-control form-control-sm" autocomplete="new-password" minlength="6" placeholder="Kosongkan jika tidak diubah">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Ulangi PIN</label>
                            <input type="password" name="santri_pin_konfirmasi" class="form-control form-control-sm" autocomplete="new-password" minlength="6" placeholder="Konfirmasi">
                        </div>
                    </div>
                    <div class="form-text mt-1">PIN terpisah dari PIN wali. Minimal 6 karakter.</div>
                </div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="toggle-jati-diri" checked>
                    <label class="form-check-label fw-semibold" for="toggle-jati-diri">Tampilkan Jati Diri Santri Lengkap</label>
                </div>
            </div>
            <div class="col-12" id="jati-diri-fields">
                <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Dusun</label><input type="text" name="dusun" class="form-control" value="<?= htmlspecialchars($santri['dusun'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">RT/RW</label><input type="text" name="rt_rw" class="form-control" value="<?= htmlspecialchars($santri['rt_rw'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Desa/Kelurahan</label><input type="text" name="desa_kelurahan" class="form-control" value="<?= htmlspecialchars($santri['desa_kelurahan'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Kecamatan</label><input type="text" name="kecamatan" class="form-control" value="<?= htmlspecialchars($santri['kecamatan'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Kabupaten</label><input type="text" name="kabupaten" class="form-control" value="<?= htmlspecialchars($santri['kabupaten'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Propinsi</label><input type="text" name="propinsi" class="form-control" value="<?= htmlspecialchars($santri['propinsi'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Nama Ayah</label><input type="text" name="nama_ayah" class="form-control" value="<?= htmlspecialchars($santri['nama_ayah'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Pekerjaan Ayah</label><input type="text" name="pekerjaan_ayah" class="form-control" value="<?= htmlspecialchars($santri['pekerjaan_ayah'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">No Kontak Ayah</label><input type="text" name="no_kontak_ayah" class="form-control" value="<?= htmlspecialchars($santri['no_kontak_ayah'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Nama Ibu</label><input type="text" name="nama_ibu" class="form-control" value="<?= htmlspecialchars($santri['nama_ibu'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Pekerjaan Ibu</label><input type="text" name="pekerjaan_ibu" class="form-control" value="<?= htmlspecialchars($santri['pekerjaan_ibu'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">No Kontak Ibu</label><input type="text" name="no_kontak_ibu" class="form-control" value="<?= htmlspecialchars($santri['no_kontak_ibu'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Nama Kafil</label><input type="text" name="nama_kafil" class="form-control" value="<?= htmlspecialchars($santri['nama_kafil'] ?? '') ?>"></div>
            <div class="col-md-4">
                <label class="form-label">Status Kafil</label>
                <div class="d-flex flex-wrap gap-2">
                    <input type="radio" class="btn-check" name="status_kafil" id="kafil-aktif" value="Aktif" autocomplete="off" <?= ($santri['status_kafil'] ?? '') === 'Aktif' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="kafil-aktif">Aktif</label>
                    <input type="radio" class="btn-check" name="status_kafil" id="kafil-nonaktif" value="Tidak Aktif" autocomplete="off" <?= ($santri['status_kafil'] ?? '') === 'Tidak Aktif' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="kafil-nonaktif">Tidak Aktif</label>
                    <input type="radio" class="btn-check" name="status_kafil" id="kafil-tidakada" value="Tidak Ada" autocomplete="off" <?= ($santri['status_kafil'] ?? '') === 'Tidak Ada' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="kafil-tidakada">Tidak Ada</label>
                </div>
            </div>
            <div class="col-md-4"><label class="form-label">Pekerjaan Kafil</label><input type="text" name="pekerjaan_kafil" class="form-control" value="<?= htmlspecialchars($santri['pekerjaan_kafil'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">No Kontak Kafil</label><input type="text" name="no_kontak_kafil" class="form-control" value="<?= htmlspecialchars($santri['no_kontak_kafil'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Pendidikan Diniyyah Terakhir</label><input type="text" name="pendidikan_diniyyah_terakhir" class="form-control" value="<?= htmlspecialchars($santri['pendidikan_diniyyah_terakhir'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Pendidikan Formal Terakhir</label><input type="text" name="pendidikan_formal_terakhir" class="form-control" value="<?= htmlspecialchars($santri['pendidikan_formal_terakhir'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Kitab Yang Pernah Dikaji</label><input type="text" name="kitab_yang_pernah_dikaji" class="form-control" value="<?= htmlspecialchars($santri['kitab_yang_pernah_dikaji'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Keluhan Sakit</label><textarea name="keluhan_sakit" rows="2" class="form-control"><?= htmlspecialchars($santri['keluhan_sakit'] ?? '') ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Pengobatan</label><textarea name="pengobatan" rows="2" class="form-control"><?= htmlspecialchars($santri['pengobatan'] ?? '') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Tanggal Masuk</label><input type="date" name="tanggal_masuk" class="form-control" value="<?= htmlspecialchars($santri['tanggal_masuk'] ?? '') ?>"></div>
            <div class="col-md-8"><label class="form-label">Alasan Mondok</label><input type="text" name="alasan_mondok" class="form-control" value="<?= htmlspecialchars($santri['alasan_mondok'] ?? '') ?>"></div>
            <div class="col-md-6">
                <label class="form-label">Atas Keinginan</label>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="atas_keinginan" value="SENDIRI" id="atas-1" <?= $atasKeinginanSelected === 'SENDIRI' ? 'checked' : '' ?>><label class="form-check-label" for="atas-1">Sendiri</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="atas_keinginan" value="ORANGTUA_WALI" id="atas-2" <?= $atasKeinginanSelected === 'ORANGTUA_WALI' ? 'checked' : '' ?>><label class="form-check-label" for="atas-2">Orangtua/Wali</label></div>
                </div>
            </div>
            <div class="col-md-6"><label class="form-label">Mengapa Nailul</label><input type="text" name="mengapa_nailul" class="form-control" value="<?= htmlspecialchars($santri['mengapa_nailul'] ?? '') ?>"></div>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success">Update</button>
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
        const keluarWraps = Array.from(document.querySelectorAll('.status-keluar-wrap'));
        const alasan = document.querySelector('input[name="alasan_keluar"]');
        const tanggal = document.querySelector('input[name="tanggal_keluar"]');
        const nonaktifFields = Array.from(document.querySelectorAll('.status-nonaktif-only, .status-tanggal-wrap, .status-jenis-wrap'));
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
        const initialKamar = <?= (int) $selectedKamarId ?>;
        const initialRanjang = <?= (int) $selectedRanjangId ?>;
        function refillRanjang(preserveId) {
            const kid = parseInt(selKamar.value, 10) || 0;
            const cur = parseInt(preserveId != null ? preserveId : selRanjang.value, 10) || 0;
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
        if (initialKamar > 0) {
            selKamar.value = String(initialKamar);
        }
        refillRanjang(initialRanjang > 0 ? initialRanjang : 0);
    })();
</script>
<?php endif; ?>

<?php
if ($embed) {
    sdm_embed_layout_end();
}
require_once __DIR__ . '/../includes/footer.php';
?>
