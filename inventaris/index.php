<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/inventaris_barang.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

migrate_inventaris_barang_permission($pdo);
require_roles(['admin', 'pengurus']);
ensure_inventaris_barang_tables($pdo);

$filter = [
    'q' => trim((string) ($_REQUEST['q'] ?? '')),
    'status' => trim((string) ($_REQUEST['status'] ?? '')),
    'status_pakai' => trim((string) ($_REQUEST['status_pakai'] ?? '')),
    'kondisi' => trim((string) ($_REQUEST['kondisi'] ?? '')),
    'nonaktif' => (string) ($_REQUEST['nonaktif'] ?? '') === '1' ? '1' : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create' || $action === 'update') {
        $id = $action === 'update' ? (int) ($_POST['id'] ?? 0) : 0;
        $result = inventaris_barang_save($pdo, $_POST, $id);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok'] && $action === 'create' && $result['id'] > 0) {
            header('Location: ' . app_href('/inventaris/label.php?id=' . $result['id']));
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (inventaris_barang_delete($pdo, $id)) {
            set_flash('success', 'Barang dan riwayat pengecekannya dihapus.');
        } else {
            set_flash('error', 'Barang tidak ditemukan.');
        }
    }
    $back = array_filter([
        'q' => $filter['q'],
        'status' => $filter['status'],
        'status_pakai' => $filter['status_pakai'],
        'kondisi' => $filter['kondisi'],
        'nonaktif' => $filter['nonaktif'],
    ], static fn($v) => $v !== '');
    $qs = $back === [] ? '' : ('?' . http_build_query($back));
    header('Location: ' . app_href('/inventaris/index.php') . $qs);
    exit;
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId > 0 ? inventaris_barang_find($pdo, $editId) : null;
$komponenEdit = is_array($edit) ? inventaris_barang_komponen_list($pdo, (int) $edit['id']) : [];
$kodeUsulan = is_array($edit) ? (string) $edit['kode'] : inventaris_barang_generate_kode($pdo);
$rows = inventaris_barang_list($pdo, $filter);
$ringkas = inventaris_barang_ringkas($pdo);
$qsKeep = array_filter([
    'q' => $filter['q'],
    'status' => $filter['status'],
    'status_pakai' => $filter['status_pakai'],
    'kondisi' => $filter['kondisi'],
    'nonaktif' => $filter['nonaktif'],
], static fn($v) => $v !== '');

$val = static function (string $key, string $default = '') use ($edit): string {
    if (!is_array($edit)) {
        return $default;
    }

    return (string) ($edit[$key] ?? $default);
};

$pageTitle = 'Kelengkapan Barang';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/menu/menu_hub.php?id=menu-grp-santri')) ?>">SDM</a></p>
    <h1 class="h4 mb-1">Kelengkapan barang inventaris</h1>
    <p class="text-muted mb-0">Catat data barang beserta komponennya, unduh kode QR, lalu tempelkan pada barang. Memindai kode menampilkan kelengkapan barang.</p>
</div>

<div class="row g-2 mb-3">
    <div class="col-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Barang aktif</div>
            <div class="app-mini-stat-value"><?= (int) $ringkas['aktif'] ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Lengkap</div>
            <div class="app-mini-stat-value"><?= (int) $ringkas['lengkap'] ?></div>
        </div>
    </div>
    <div class="col-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tidak lengkap</div>
            <div class="app-mini-stat-value"><?= (int) $ringkas['kurang'] ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0"><?= $edit ? 'Ubah barang' : 'Tambah barang' ?></h2>
            <?php if ($edit): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/inventaris/index.php') . ($qsKeep === [] ? '' : ('?' . http_build_query($qsKeep)))) ?>">Batal</a>
            <?php endif; ?>
        </div>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <?php if ($edit): ?>
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <?php endif; ?>
            <?php foreach ($qsKeep as $keepKey => $keepVal): ?>
                <input type="hidden" name="<?= htmlspecialchars((string) $keepKey) ?>" value="<?= htmlspecialchars((string) $keepVal) ?>">
            <?php endforeach; ?>
            <div class="col-md-4">
                <label class="form-label small">Kode barang</label>
                <input type="text" name="kode" class="form-control" maxlength="64" value="<?= htmlspecialchars($kodeUsulan) ?>" placeholder="Contoh: LEMARI-03">
                <div class="form-text">Kosongkan untuk kode otomatis. Huruf, angka, minus, dan garis bawah. Mengubah kode membuat QR lama tidak lagi cocok.</div>
            </div>
            <div class="col-md-8">
                <label class="form-label small">Nama barang</label>
                <input type="text" name="nama" class="form-control" maxlength="160" required value="<?= htmlspecialchars($val('nama')) ?>" placeholder="Contoh: Lemari pakaian kamar 3">
            </div>
            <div class="col-12">
                <label class="form-label small" for="nama_label">Nama label</label>
                <input type="text" name="nama_label" id="nama_label" class="form-control" maxlength="160" value="<?= htmlspecialchars($val('nama_label')) ?>" placeholder="Teks yang tercetak di label QR">
                <div class="form-text">Diisi manual. Kosongkan untuk memakai nama barang. Teks ini tertulis di label dan menjadi nama berkas JPG.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Kategori</label>
                <select name="kategori" class="form-select">
                    <?php foreach (inventaris_barang_kategori_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $val('kategori', 'Lainnya') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Lokasi</label>
                <input type="text" name="lokasi" class="form-control" maxlength="160" value="<?= htmlspecialchars($val('lokasi')) ?>" placeholder="Kamar, ruang, gedung">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Merk</label>
                <input type="text" name="merk" class="form-control" maxlength="120" value="<?= htmlspecialchars($val('merk')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Asal barang</label>
                <input type="text" name="asal_barang" class="form-control" maxlength="160" value="<?= htmlspecialchars($val('asal_barang')) ?>" placeholder="Contoh: pembelian, hibah, wakaf">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Penanggung jawab</label>
                <input type="text" name="penanggung_jawab" class="form-control" maxlength="120" value="<?= htmlspecialchars($val('penanggung_jawab')) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">Jumlah</label>
                <input type="number" name="jumlah" class="form-control" min="1" max="99999" value="<?= (int) ($edit['jumlah'] ?? 1) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">Satuan</label>
                <input type="text" name="satuan" class="form-control" maxlength="40" value="<?= htmlspecialchars($val('satuan', 'unit')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Status barang</label>
                <select name="status_barang" class="form-select">
                    <?php foreach (inventaris_barang_status_pakai_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $val('status_barang', 'Tersedia') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Kondisi</label>
                <select name="kondisi" class="form-select">
                    <?php foreach (inventaris_barang_kondisi_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $val('kondisi', 'Baik') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Kelengkapan</label>
                <select name="status_kelengkapan" class="form-select">
                    <?php foreach (inventaris_barang_status_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $val('status_kelengkapan', 'Lengkap') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Tanggal cek</label>
                <input type="date" name="tanggal_cek" class="form-control" value="<?= htmlspecialchars($val('tanggal_cek')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small">Catatan kekurangan</label>
                <input type="text" name="catatan_kekurangan" class="form-control" maxlength="500" value="<?= htmlspecialchars($val('catatan_kekurangan')) ?>" placeholder="Apa yang kurang, jika tidak lengkap">
            </div>
            <div class="col-md-6">
                <label class="form-label small">Keterangan</label>
                <input type="text" name="keterangan" class="form-control" maxlength="500" value="<?= htmlspecialchars($val('keterangan')) ?>">
            </div>
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">Komponen barang</label>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="tambah-komponen">Tambah komponen</button>
                </div>
                <p class="form-text mt-0">Jika ada komponen, status kelengkapan barang mengikuti komponen: lengkap hanya bila semuanya lengkap.</p>
                <div id="daftar-komponen" class="d-flex flex-column gap-2">
                    <?php foreach ($komponenEdit as $komponen): ?>
                        <div class="row g-2 komponen-baris">
                            <div class="col-md-5">
                                <input type="text" name="komponen_nama[]" class="form-control form-control-sm" maxlength="160" placeholder="Nama komponen" value="<?= htmlspecialchars((string) $komponen['nama']) ?>">
                            </div>
                            <div class="col-md-3">
                                <select name="komponen_status[]" class="form-select form-select-sm">
                                    <?php foreach (inventaris_barang_status_options() as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"<?= (string) $komponen['status'] === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="komponen_catatan[]" class="form-control form-control-sm" maxlength="255" placeholder="Catatan" value="<?= htmlspecialchars((string) ($komponen['catatan'] ?? '')) ?>">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-sm btn-outline-danger w-100 hapus-komponen">Hapus</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12">
                <input type="hidden" name="is_aktif" value="0">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_aktif" value="1" id="is_aktif"<?= !is_array($edit) || (int) ($edit['is_aktif'] ?? 1) === 1 ? ' checked' : '' ?>>
                    <label class="form-check-label" for="is_aktif">Barang aktif</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success"><?= $edit ? 'Simpan perubahan' : 'Simpan dan buat kode QR' ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0">Daftar barang</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/inventaris/scan.php')) ?>"><i class="fa-solid fa-qrcode me-1"></i>Pindai barang</a>
        </div>
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label small">Cari</label>
                <input type="text" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($filter['q']) ?>" placeholder="Nama, kode, lokasi, asal">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Kelengkapan</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach (inventaris_barang_status_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $filter['status'] === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status barang</label>
                <select name="status_pakai" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach (inventaris_barang_status_pakai_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $filter['status_pakai'] === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Kondisi</label>
                <select name="kondisi" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach (inventaris_barang_kondisi_options() as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $filter['kondisi'] === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="nonaktif" value="1" id="nonaktif"<?= $filter['nonaktif'] === '1' ? ' checked' : '' ?>>
                    <label class="form-check-label small" for="nonaktif">Termasuk nonaktif</label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary">Terapkan</button>
            </div>
        </form>

        <form id="form-cetak-label" method="post" action="<?= htmlspecialchars(app_href('/inventaris/label.php')) ?>"></form>
        <div class="d-flex justify-content-end mb-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary" form="form-cetak-label">Kode QR terpilih</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th style="width:2rem;"><input type="checkbox" id="pilih-semua-barang" aria-label="Pilih semua"></th>
                        <th>Barang</th>
                        <th>Lokasi</th>
                        <th>Status</th>
                        <th>Kondisi</th>
                        <th>Kelengkapan</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowId = (int) $row['id'];
                        $editHref = app_href('/inventaris/index.php') . '?' . http_build_query($qsKeep + ['edit' => $rowId]);
                        ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= $rowId ?>" form="form-cetak-label" class="pilih-barang" aria-label="Pilih <?= htmlspecialchars((string) $row['nama']) ?>"></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) $row['nama']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string) $row['kode']) ?> · <?= htmlspecialchars((string) $row['kategori']) ?><?= (int) $row['is_aktif'] === 1 ? '' : ' · nonaktif' ?></div>
                            </td>
                            <td class="small">
                                <?= htmlspecialchars((string) ($row['lokasi'] ?: '—')) ?>
                                <?php if ((string) ($row['asal_barang'] ?? '') !== ''): ?>
                                    <div class="text-muted">Asal: <?= htmlspecialchars((string) $row['asal_barang']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= inventaris_barang_badge_status_pakai((string) ($row['status_barang'] ?? 'Tersedia')) ?>"><?= htmlspecialchars((string) ($row['status_barang'] ?? 'Tersedia')) ?></span></td>
                            <td><span class="badge <?= inventaris_barang_badge_kondisi((string) $row['kondisi']) ?>"><?= htmlspecialchars((string) $row['kondisi']) ?></span></td>
                            <td>
                                <span class="badge <?= inventaris_barang_badge_status((string) $row['status_kelengkapan']) ?>"><?= htmlspecialchars((string) $row['status_kelengkapan']) ?></span>
                                <?php if ((int) ($row['komponen_total'] ?? 0) > 0): ?>
                                    <div class="small text-muted"><?= (int) $row['komponen_lengkap'] ?>/<?= (int) $row['komponen_total'] ?> komponen</div>
                                <?php endif; ?>
                                <?php if ((string) ($row['tanggal_cek'] ?? '') !== ''): ?>
                                    <div class="small text-muted"><?= htmlspecialchars(app_format_tanggal_id((string) $row['tanggal_cek'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/inventaris/label.php?id=' . $rowId)) ?>">Kode QR</a>
                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($editHref) ?>">Ubah</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus barang ini beserta riwayat pengecekannya?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $rowId ?>">
                                    <?php foreach ($qsKeep as $keepKey => $keepVal): ?>
                                        <input type="hidden" name="<?= htmlspecialchars((string) $keepKey) ?>" value="<?= htmlspecialchars((string) $keepVal) ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-muted">Belum ada data.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<template id="template-komponen">
    <div class="row g-2 komponen-baris">
        <div class="col-md-5">
            <input type="text" name="komponen_nama[]" class="form-control form-control-sm" maxlength="160" placeholder="Nama komponen">
        </div>
        <div class="col-md-3">
            <select name="komponen_status[]" class="form-select form-select-sm">
                <?php foreach (inventaris_barang_status_options() as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <input type="text" name="komponen_catatan[]" class="form-control form-control-sm" maxlength="255" placeholder="Catatan">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-sm btn-outline-danger w-100 hapus-komponen">Hapus</button>
        </div>
    </div>
</template>
<script>
(function () {
    var master = document.getElementById('pilih-semua-barang');
    if (master) {
        master.addEventListener('change', function () {
            document.querySelectorAll('.pilih-barang').forEach(function (box) {
                box.checked = master.checked;
            });
        });
    }
    var daftar = document.getElementById('daftar-komponen');
    var template = document.getElementById('template-komponen');
    var tambah = document.getElementById('tambah-komponen');
    if (!daftar || !template || !tambah) return;
    tambah.addEventListener('click', function () {
        daftar.appendChild(template.content.cloneNode(true));
    });
    daftar.addEventListener('click', function (event) {
        var btn = event.target.closest('.hapus-komponen');
        if (!btn) return;
        var row = btn.closest('.komponen-baris');
        if (row) row.remove();
    });
    if (!daftar.children.length) {
        daftar.appendChild(template.content.cloneNode(true));
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
