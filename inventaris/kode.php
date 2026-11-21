<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/inventaris_barang.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

migrate_inventaris_barang_permission($pdo);
require_roles(['admin', 'pengurus']);
ensure_inventaris_barang_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = trim((string) ($_POST['action'] ?? 'simpan'));
    if ($aksi === 'bawaan') {
        $hasil = inventaris_barang_kode_sheet_simpan(
            $pdo,
            inventaris_barang_lokasi_bawaan(),
            inventaris_barang_kategori_bawaan(),
            2
        );
    } else {
        $lokasi = inventaris_barang_lokasi_dari_form(
            (array) ($_POST['lokasi_kode'] ?? []),
            (array) ($_POST['lokasi_nama'] ?? [])
        );
        $kategori = $lokasi['ok']
            ? inventaris_barang_kategori_dari_form(
                (array) ($_POST['kategori_kode'] ?? []),
                (array) ($_POST['kategori_label'] ?? []),
                (array) ($_POST['kategori_footer'] ?? [])
            )
            : ['ok' => false, 'message' => '', 'data' => []];
        if (!$lokasi['ok']) {
            $hasil = ['ok' => false, 'message' => $lokasi['message']];
        } elseif (!$kategori['ok']) {
            $hasil = ['ok' => false, 'message' => $kategori['message']];
        } else {
            $hasil = inventaris_barang_kode_sheet_simpan(
                $pdo,
                $lokasi['data'],
                $kategori['data'],
                (int) ($_POST['digit'] ?? 2)
            );
        }
    }
    set_flash($hasil['ok'] ? 'success' : 'error', $hasil['message']);
    header('Location: ' . app_href('/inventaris/kode.php'));
    exit;
}

$sheet = inventaris_barang_kode_sheet_muat($pdo, true);
$terpakai = inventaris_barang_kode_terpakai($pdo);
$lokasiBaris = [];
foreach ($sheet['lokasi'] as $kode => $nama) {
    $lokasiBaris[] = ['kode' => $kode, 'nama' => $nama];
}
$kategoriBaris = [];
foreach ($sheet['kategori'] as $kode => $meta) {
    $kategoriBaris[] = ['kode' => $kode, 'label' => $meta['label'], 'footer' => $meta['footer']];
}
$contohLokasi = (string) array_key_first($sheet['lokasi']);
$contohKategori = (string) array_key_first($sheet['kategori']);
$contoh = inventaris_barang_susun_kode($contohLokasi, $contohKategori, '1');

$pageTitle = 'Kode Sheet Barang';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/inventaris/index.php')) ?>">Kelengkapan barang</a></p>
    <h1 class="h4 mb-1">Setingan kode sheet</h1>
    <p class="text-muted mb-0">Format kode barang adalah <strong>LOKASI-KATEGORI-URUT</strong>, misalnya <code><?= htmlspecialchars($contoh) ?></code>. Daftar ini dipakai pada pencatatan dan stiker.</p>
</div>

<form method="post" class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Kode lokasi</h2>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="tambah-lokasi">Tambah</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:7rem">Kode</th>
                                <th>Nama lokasi</th>
                                <th style="width:4rem"></th>
                            </tr>
                        </thead>
                        <tbody id="daftar-lokasi">
                            <?php foreach ($lokasiBaris as $baris): ?>
                                <?php $kunci = in_array($baris['kode'], $terpakai['lokasi'], true); ?>
                                <tr>
                                    <td><input type="text" name="lokasi_kode[]" class="form-control form-control-sm text-uppercase" maxlength="8" value="<?= htmlspecialchars($baris['kode']) ?>"<?= $kunci ? ' readonly' : '' ?> required></td>
                                    <td><input type="text" name="lokasi_nama[]" class="form-control form-control-sm" maxlength="80" value="<?= htmlspecialchars($baris['nama']) ?>" required></td>
                                    <td><?php if ($kunci): ?><span class="badge text-bg-light border">Dipakai</span><?php else: ?><button type="button" class="btn btn-sm btn-outline-danger hapus-baris">Hapus</button><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Kode kategori</h2>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="tambah-kategori">Tambah</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:7rem">Kode</th>
                                <th>Nama kategori</th>
                                <th>Teks di stiker</th>
                                <th style="width:4rem"></th>
                            </tr>
                        </thead>
                        <tbody id="daftar-kategori">
                            <?php foreach ($kategoriBaris as $baris): ?>
                                <?php $kunci = in_array($baris['kode'], $terpakai['kategori'], true); ?>
                                <tr>
                                    <td><input type="text" name="kategori_kode[]" class="form-control form-control-sm text-uppercase" maxlength="8" value="<?= htmlspecialchars($baris['kode']) ?>"<?= $kunci ? ' readonly' : '' ?> required></td>
                                    <td><input type="text" name="kategori_label[]" class="form-control form-control-sm" maxlength="80" value="<?= htmlspecialchars($baris['label']) ?>" required></td>
                                    <td><input type="text" name="kategori_footer[]" class="form-control form-control-sm text-uppercase" maxlength="40" value="<?= htmlspecialchars($baris['footer']) ?>" required></td>
                                    <td><?php if ($kunci): ?><span class="badge text-bg-light border">Dipakai</span><?php else: ?><button type="button" class="btn btn-sm btn-outline-danger hapus-baris">Hapus</button><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="form-text mb-0 mt-2">Teks di stiker ditulis setelah “ASET INVENTARIS”. Contoh: MEJA menjadi ASET INVENTARIS MEJA.</p>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-end gap-3">
                <div>
                    <label class="form-label small mb-1" for="digit">Panjang nomor urut</label>
                    <select name="digit" id="digit" class="form-select form-select-sm" style="width:12rem">
                        <?php foreach ([2 => '2 digit · 01', 3 => '3 digit · 001', 4 => '4 digit · 0001'] as $angka => $labelDigit): ?>
                            <option value="<?= $angka ?>"<?= (int) $sheet['digit'] === $angka ? ' selected' : '' ?>><?= htmlspecialchars($labelDigit) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Nomor baru mengikuti panjang ini. Kode barang yang sudah tersimpan tidak diubah.</div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_href('/inventaris/index.php')) ?>">Kembali</a>
                    <button type="submit" class="btn btn-outline-secondary" name="action" value="bawaan" formnovalidate onclick="return confirm('Kembalikan daftar lokasi, kategori, dan nomor urut ke bawaan? Kode yang masih dipakai barang tidak akan hilang.');">Kembalikan bawaan</button>
                    <button type="submit" class="btn btn-success" name="action" value="simpan">Simpan setingan</button>
                </div>
            </div>
        </div>
    </div>
</form>

<template id="template-lokasi">
    <tr>
        <td><input type="text" name="lokasi_kode[]" class="form-control form-control-sm text-uppercase" maxlength="8" required></td>
        <td><input type="text" name="lokasi_nama[]" class="form-control form-control-sm" maxlength="80" required></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger hapus-baris">Hapus</button></td>
    </tr>
</template>
<template id="template-kategori">
    <tr>
        <td><input type="text" name="kategori_kode[]" class="form-control form-control-sm text-uppercase" maxlength="8" required></td>
        <td><input type="text" name="kategori_label[]" class="form-control form-control-sm" maxlength="80" required></td>
        <td><input type="text" name="kategori_footer[]" class="form-control form-control-sm text-uppercase" maxlength="40" required></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger hapus-baris">Hapus</button></td>
    </tr>
</template>
<script>
(function () {
    function pasang(tombolId, daftarId, templateId) {
        var tombol = document.getElementById(tombolId);
        var daftar = document.getElementById(daftarId);
        var template = document.getElementById(templateId);
        if (!tombol || !daftar || !template) return;
        tombol.addEventListener('click', function () {
            daftar.appendChild(template.content.cloneNode(true));
            var kolom = daftar.lastElementChild && daftar.lastElementChild.querySelector('input');
            if (kolom) kolom.focus();
        });
        daftar.addEventListener('click', function (event) {
            var hapus = event.target.closest('.hapus-baris');
            if (!hapus) return;
            var baris = hapus.closest('tr');
            if (baris) baris.remove();
        });
    }
    pasang('tambah-lokasi', 'daftar-lokasi', 'template-lokasi');
    pasang('tambah-kategori', 'daftar-kategori', 'template-kategori');
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
