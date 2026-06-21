<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/santri_izin_tetap.php';
require_once __DIR__ . '/../helpers/izin_tetap_hidmah_kategori.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';

require_login();
require_roles(['admin', 'pengurus', 'petugas_absensi']);

ensure_santri_izin_tetap_tables($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$defaultPenanggungJawab = trim((string) ($_SESSION['user']['nama'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['id'] ?? 0);
$hariMap = santri_izin_tetap_hari_map();

$redirect = static function (int $id = 0) use ($q): void {
    $url = '/perizinan/izin_tetap.php';
    if ($id > 0) {
        $url .= '?id=' . $id;
    }
    if ($q !== '') {
        $url .= ($id > 0 ? '&' : '?') . 'q=' . urlencode($q);
    }
    header('Location: ' . app_href($url));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan') {
        $slots = santri_izin_tetap_slots_dari_post($_POST);
        $result = santri_izin_tetap_simpan($pdo, $_POST, $slots, $userId);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok'] && (int) ($result['count'] ?? 1) > 1) {
            header('Location: ' . app_href('/perizinan/izin_tetap.php' . ($q !== '' ? '?q=' . urlencode($q) : '')));
            exit;
        }
        $redirect($result['ok'] ? (int) ($result['id'] ?? $_POST['id'] ?? 0) : (int) ($_POST['id'] ?? 0));
    }
    if ($action === 'toggle_aktif') {
        $result = santri_izin_tetap_set_aktif($pdo, (int) ($_POST['id'] ?? 0), (int) ($_POST['aktif'] ?? 0) === 1);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        $redirect((int) ($_POST['id'] ?? 0));
    }
    if ($action === 'hapus') {
        $result = santri_izin_tetap_hapus($pdo, (int) ($_POST['id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/perizinan/izin_tetap.php'));
        exit;
    }
}

$santriAktif = [];
if (table_exists($pdo, 'santri')) {
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' WHERE COALESCE(is_aktif, 1) = 1 ' : '';
    $santriAktif = $pdo->query("SELECT id, nis, {$nameExpr} AS nama FROM santri {$activeExpr} ORDER BY {$nameExpr} ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$editRow = $editId > 0 ? santri_izin_tetap_by_id($pdo, $editId) : null;
$editSlotsRaw = $editRow ? santri_izin_tetap_slots($pdo, $editId) : [];
$izinTetapSlotBloks = santri_izin_tetap_slots_ke_blok_form($editSlotsRaw);
if ($izinTetapSlotBloks === []) {
    $izinTetapSlotBloks = [['hari' => [1], 'jam_mulai' => '08:00', 'jam_selesai' => '12:00']];
}
$editSlots = santri_izin_tetap_blok_form_ke_slots($izinTetapSlotBloks);

$listRows = santri_izin_tetap_list($pdo, $q);
$jumlahAktif = count(array_filter($listRows, static fn(array $r): bool => (int) ($r['is_aktif'] ?? 0) === 1));
$izinTetapSantriGrouped = perizinan_rombongan_santri_aktif_grouped($pdo);
$editKegiatanDitinggalkan = trim((string) ($editRow['kegiatan_ditinggalkan'] ?? ''));
$editTingkatanList = $editRow
    ? santri_izin_tetap_tingkatan_for_santri_ids($pdo, [(int) ($editRow['santri_id'] ?? 0)])
    : [];
$kegiatanOtomatis = santri_izin_tetap_kegiatan_overlap_dari_jadwal($pdo, $editSlots, $editTingkatanList);
$editKegiatanTerpilih = $editKegiatanDitinggalkan !== ''
    ? santri_izin_tetap_kegiatan_ditinggalkan_terpilih($editKegiatanDitinggalkan, array_column($kegiatanOtomatis, 'nama'))
    : array_column($kegiatanOtomatis, 'nama');
$editKegiatanManual = santri_izin_tetap_kegiatan_ditinggalkan_manual(
    $editKegiatanDitinggalkan,
    array_column($kegiatanOtomatis, 'nama')
);
$hidmahKategoriList = izin_tetap_hidmah_kategori_list_aktif($pdo);
$editKategoriHidmah = '';
if ($editRow) {
    $editKategoriHidmah = izin_tetap_hidmah_kategori_normalize_kode($pdo, (string) ($editRow['kategori_hidmah'] ?? ''));
    if ($editKategoriHidmah === '' && $hidmahKategoriList !== []) {
        $editKategoriHidmah = (string) ($hidmahKategoriList[0]['kode'] ?? '');
    }
} elseif ($hidmahKategoriList !== []) {
    $editKategoriHidmah = (string) ($hidmahKategoriList[0]['kode'] ?? '');
}

$pageTitle = 'Izin Tetap Hidmah';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="/perizinan/index.php">Perizinan</a> · Santri
    </p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-calendar-check text-primary me-1"></i> Izin Tetap (Hidmah)</h1>
    <p class="text-muted mb-0">
        Santri hidmah yang keluar pada <strong>hari &amp; jam tertentu</strong> dapat dicetak surat izin tetap resmi.
        Presensi kegiatan <strong>Jama'ah</strong> pada jadwal ini dicatat <strong>IZIN</strong> (bukan ALPA).
        Kegiatan Ta'lim tetap mengikuti aturan presensi biasa. Dapat diubah atau dihentikan kapan saja.
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">
                <?= $editRow ? 'Ubah izin tetap' : 'Tambah izin tetap' ?>
            </div>
            <div class="card-body">
                <form method="post" id="form-izin-tetap"<?= !$editRow ? ' data-rombongan-min="1" data-rombongan-target="izin-tetap-pick"' : '' ?> data-kegiatan-url="<?= htmlspecialchars(app_href('/perizinan/izin_tetap_kegiatan.php')) ?>">
                    <input type="hidden" name="action" value="simpan">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Santri <span class="text-danger">*</span></label>
                        <?php if ($editRow): ?>
                        <select class="form-select form-select-sm" name="santri_id" required>
                            <option value="">— pilih —</option>
                            <?php foreach ($santriAktif as $s): ?>
                                <?php $sid = (int) ($s['id'] ?? 0); ?>
                                <option value="<?= $sid ?>" <?= (int) ($editRow['santri_id'] ?? 0) === $sid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($s['nis'] ?? '') . ' — ' . ($s['nama'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <div class="form-text mb-1">Ketik NIS atau nama di pencarian, lalu centang santri yang muncul.</div>
                        <input type="search" class="form-control form-control-sm mb-2" id="izin-tetap-cari-santri" placeholder="Cari NIS atau nama santri…" autocomplete="off">
                        <div id="izin-tetap-santri-terpilih" class="d-flex flex-wrap gap-1 mb-2"></div>
                        <?php if ($izinTetapSantriGrouped === [] && $santriAktif !== []): ?>
                        <div class="rombongan-santri-picker border rounded" id="izin-tetap-pick-wrap" hidden>
                            <div class="d-flex flex-wrap gap-2 p-2 border-bottom bg-light">
                                <button type="button" class="btn btn-sm btn-outline-primary js-rombongan-pilih-semua" data-target="izin-tetap-pick">
                                    <i class="fa-solid fa-check-double me-1"></i> Pilih semua
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-rombongan-bersihkan" data-target="izin-tetap-pick">
                                    Bersihkan
                                </button>
                            </div>
                            <div class="rombongan-santri-picker__scroll p-2" style="max-height:min(22rem,50vh);overflow-y:auto">
                                <?php foreach ($santriAktif as $s):
                                    $sid = (int) ($s['id'] ?? 0);
                                    if ($sid <= 0) {
                                        continue;
                                    }
                                    ?>
                                    <div class="rombongan-santri-picker__row px-1 py-1 border-top"
                                         data-search="<?= htmlspecialchars(strtolower((string) ($s['nis'] ?? '') . ' ' . (string) ($s['nama'] ?? ''))) ?>"
                                         data-nis="<?= htmlspecialchars((string) ($s['nis'] ?? '')) ?>">
                                        <div class="form-check mb-0">
                                            <input class="form-check-input rombongan-santri-cb" type="checkbox" name="santri_ids[]"
                                                   id="izin-tetap-pick-<?= $sid ?>" value="<?= $sid ?>">
                                            <label class="form-check-label small" for="izin-tetap-pick-<?= $sid ?>">
                                                <span class="font-monospace fw-semibold"><?= htmlspecialchars((string) ($s['nis'] ?? '')) ?></span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php elseif ($izinTetapSantriGrouped === []): ?>
                        <p class="text-muted small mb-0">Tidak ada santri aktif.</p>
                        <?php else: ?>
                        <?php
                        $rombonganSantriGrouped = $izinTetapSantriGrouped;
                        $rombonganPickerName = 'santri_ids[]';
                        $rombonganPickerId = 'izin-tetap-pick';
                        $rombonganPickerHideBelumKembali = true;
                        $rombonganPickerHideNamaInList = true;
                        $rombonganPickerStartHidden = true;
                        require __DIR__ . '/partials/rombongan_santri_picker.php';
                        ?>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Berlaku mulai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_mulai" required
                                value="<?= htmlspecialchars($editRow ? (string) ($editRow['tanggal_mulai'] ?? date('Y-m-d')) : date('Y-m-d')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Berlaku sampai</label>
                            <input type="date" class="form-control form-control-sm" name="tanggal_selesai"
                                value="<?= htmlspecialchars($editRow && !empty($editRow['tanggal_selesai']) ? (string) $editRow['tanggal_selesai'] : '') ?>">
                            <div class="form-text">Kosongkan = tanpa batas</div>
                        </div>
                    </div>
                    <label class="form-label">Hari hidmah &amp; jam <span class="text-danger">*</span></label>
                    <div class="form-text mb-1">Centang hari yang sama bisa satu blok waktu. Sistem mendeteksi kegiatan Jama'ah yang ditinggalkan otomatis.</div>
                    <?php require __DIR__ . '/partials/izin_tetap_slot_picker.php'; ?>
                    <div class="mb-2" id="blok-kegiatan-ditinggalkan">
                        <label class="form-label">Kegiatan pondok yang ditinggalkan</label>
                        <?php
                        $izinTetapKegiatanList = $kegiatanOtomatis;
                        $izinTetapKegiatanChecked = $editKegiatanTerpilih;
                        require __DIR__ . '/partials/izin_tetap_kegiatan_picker.php';
                        ?>
                        <input type="text" class="form-control form-control-sm mt-2" name="kegiatan_ditinggalkan" id="kegiatan-ditinggalkan-manual"
                            maxlength="500"
                            placeholder="Kegiatan lain (opsional), pisahkan koma jika lebih dari satu"
                            value="<?= htmlspecialchars($editKegiatanManual) ?>">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-5" id="blok-kategori-hidmah">
                            <label class="form-label">Kategori hidmah <span class="text-danger">*</span></label>
                            <?php if ($hidmahKategoriList === []): ?>
                                <div class="alert alert-warning small py-2 mb-0">
                                    Belum ada kategori aktif. Atur di <a href="<?= htmlspecialchars(app_href('/settings/perizinan.php')) ?>">Pengaturan → Perizinan</a>.
                                </div>
                            <?php else: ?>
                                <select class="form-select form-select-sm" name="kategori_hidmah" id="izin-tetap-kategori-hidmah" required>
                                    <?php foreach ($hidmahKategoriList as $hk): ?>
                                        <?php $hkode = (string) ($hk['kode'] ?? ''); ?>
                                        <option value="<?= htmlspecialchars($hkode) ?>" <?= $editKategoriHidmah === $hkode ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) ($hk['label'] ?? $hkode)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" id="izin-tetap-uraian-label">Uraian hidmah (sebagai …) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="judul" id="izin-tetap-uraian-input" required maxlength="120"
                                placeholder="Mis. membantu Koperasi di Pondok"
                                value="<?= htmlspecialchars($editRow ? (string) ($editRow['judul'] ?? '') : 'membantu Koperasi di Pondok') ?>">
                            <div class="form-text" id="izin-tetap-uraian-help">Surat: <em>hidmah sebagai …</em> — isi bagian setelah kata &ldquo;sebagai&rdquo;.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Jenis</label>
                            <select class="form-select form-select-sm" name="jenis" id="izin-tetap-jenis">
                                <option value="HIDMAH" <?= !$editRow || ($editRow['jenis'] ?? '') === 'HIDMAH' ? 'selected' : '' ?>>Hidmah</option>
                                <option value="TUGAS" <?= $editRow && ($editRow['jenis'] ?? '') === 'TUGAS' ? 'selected' : '' ?>>Tugas</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Penanggung jawab (tanda tangan surat)</label>
                        <input type="text" class="form-control form-control-sm" name="penanggung_jawab" maxlength="120"
                            placeholder="Nama penanggung jawab santri / hidmah"
                            value="<?= htmlspecialchars($editRow ? (string) ($editRow['penanggung_jawab'] ?? $defaultPenanggungJawab) : $defaultPenanggungJawab) ?>">
                        <div class="form-text">Muncul di kolom tanda tangan surat cetak. Kosongkan untuk garis tanda tangan kosong.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Keterangan</label>
                        <textarea class="form-control form-control-sm" name="keterangan" rows="2"><?= htmlspecialchars($editRow ? (string) ($editRow['keterangan'] ?? '') : '') ?></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_aktif" value="1" id="is_aktif_izin"
                            <?= !$editRow || (int) ($editRow['is_aktif'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_aktif_izin">Izin tetap aktif</label>
                    </div>
                    <div class="alert alert-light border small py-2 mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i>
                        Setelah disimpan, cetak surat izin tetap (A4). Presensi: status <strong>IZIN</strong> pada kegiatan Jama'ah yang ditinggalkan.
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-save me-1"></i> Simpan</button>
                        <?php if ($editRow): ?>
                            <a href="<?= htmlspecialchars(app_href('/perizinan/surat_izin_tetap.php?id=' . (int) $editRow['id'])) ?>" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener"><i class="fa-solid fa-print me-1"></i> Cetak surat A4</a>
                            <a href="/perizinan/izin_tetap.php" class="btn btn-outline-secondary btn-sm">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($editRow): ?>
                    <form method="post" class="mt-3" onsubmit="return confirm('Hapus izin tetap ini?');">
                        <input type="hidden" name="action" value="hapus">
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Hapus permanen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Daftar izin tetap</span>
                <span class="badge text-bg-success"><?= $jumlahAktif ?> aktif</span>
            </div>
            <div class="card-body border-bottom">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col">
                        <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama, NIS, judul">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Santri</th>
                                <th>Judul</th>
                                <th>Jadwal</th>
                                <th>Periode</th>
                                <th class="text-center">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($listRows === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($listRows as $r):
                            $iid = (int) ($r['id'] ?? 0);
                            $aktif = (int) ($r['is_aktif'] ?? 0) === 1;
                            ?>
                            <tr class="<?= $aktif ? '' : 'table-secondary' ?>">
                                <td class="small">
                                    <span class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></span><br>
                                    <span class="text-muted font-monospace"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></span>
                                </td>
                                <td class="small">
                                    <?= htmlspecialchars((string) ($r['judul'] ?? '')) ?><br>
                                    <?php
                                    $katKode = trim((string) ($r['kategori_hidmah'] ?? ''));
                                    if ($katKode !== '' && strtoupper((string) ($r['jenis'] ?? '')) === 'HIDMAH'):
                                        ?>
                                        <span class="badge text-bg-secondary" style="font-size:.65rem"><?= htmlspecialchars(izin_tetap_hidmah_kategori_label($pdo, $katKode)) ?></span>
                                    <?php endif; ?>
                                    <?php
                                    $kegTampil = santri_izin_tetap_kegiatan_ditinggalkan_efektif($pdo, $r);
                                    if ($kegTampil !== ''):
                                        ?>
                                        <span class="text-muted">Tinggalkan: <?= htmlspecialchars($kegTampil) ?></span><br>
                                    <?php endif; ?>
                                    <span class="badge text-bg-info" style="font-size:.65rem"><?= htmlspecialchars(santri_izin_tetap_jenis_label((string) ($r['jenis'] ?? 'HIDMAH'))) ?></span>
                                </td>
                                <td class="small"><?= htmlspecialchars(santri_izin_tetap_slot_ringkas($pdo, $iid)) ?></td>
                                <td class="small text-nowrap">
                                    <?= htmlspecialchars((string) ($r['tanggal_mulai'] ?? '')) ?>
                                    <?php if (!empty($r['tanggal_selesai'])): ?>
                                        <br>s/d <?= htmlspecialchars((string) $r['tanggal_selesai']) ?>
                                    <?php else: ?>
                                        <br><span class="text-muted">tanpa batas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($aktif): ?>
                                        <span class="badge text-bg-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($aktif): ?>
                                        <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars(app_href('/perizinan/surat_izin_tetap.php?id=' . $iid)) ?>" target="_blank" rel="noopener" title="Cetak surat A4"><i class="fa-solid fa-print"></i></a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="?id=<?= $iid ?>">Ubah</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_aktif">
                                        <input type="hidden" name="id" value="<?= $iid ?>">
                                        <input type="hidden" name="aktif" value="<?= $aktif ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $aktif ? 'warning' : 'success' ?>"><?= $aktif ? 'Stop' : 'Aktif' ?></button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus izin tetap ini permanen?');">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id" value="<?= $iid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus permanen"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const cari = document.getElementById('izin-tetap-cari-santri');
    const pickWrap = document.getElementById('izin-tetap-pick-wrap');
    const terpilihWrap = document.getElementById('izin-tetap-santri-terpilih');

    function rowSearchHaystack(row) {
        const ds = (row.getAttribute('data-search') || '').toLowerCase();
        if (ds) {
            return ds;
        }
        const label = row.querySelector('.form-check-label');
        return (label ? label.textContent : row.textContent || '').toLowerCase();
    }

    function syncSantriTerpilih() {
        if (!terpilihWrap || !pickWrap) {
            return;
        }
        terpilihWrap.innerHTML = '';
        pickWrap.querySelectorAll('.rombongan-santri-cb:checked').forEach(function (cb) {
            const row = cb.closest('.rombongan-santri-picker__row');
            const nis = row ? (row.getAttribute('data-nis') || '').trim() : '';
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-primary';
            badge.textContent = nis !== '' ? nis : ('#' + cb.value);
            terpilihWrap.appendChild(badge);
        });
    }

    function filterSantriPicker() {
        if (!cari || !pickWrap) {
            return;
        }
        const q = (cari.value || '').trim().toLowerCase();
        pickWrap.hidden = q.length < 1;
        if (q.length < 1) {
            return;
        }
        pickWrap.querySelectorAll('.rombongan-santri-picker__row').forEach(function (row) {
            row.style.display = rowSearchHaystack(row).indexOf(q) !== -1 ? '' : 'none';
        });
        pickWrap.querySelectorAll('.rombongan-santri-picker__group').forEach(function (grp) {
            const visible = Array.prototype.some.call(
                grp.querySelectorAll('.rombongan-santri-picker__row'),
                function (r) { return r.style.display !== 'none'; }
            );
            grp.style.display = visible ? '' : 'none';
        });
    }

    if (cari && pickWrap) {
        cari.addEventListener('input', filterSantriPicker);
        pickWrap.addEventListener('change', syncSantriTerpilih);
        syncSantriTerpilih();
    }

    const jenisSel = document.getElementById('izin-tetap-jenis');
    const blokKeg = document.getElementById('blok-kegiatan-ditinggalkan');
    const blokKat = document.getElementById('blok-kategori-hidmah');
    const katSel = document.getElementById('izin-tetap-kategori-hidmah');
    const uraianLabel = document.getElementById('izin-tetap-uraian-label');
    const uraianInput = document.getElementById('izin-tetap-uraian-input');
    const uraianHelp = document.getElementById('izin-tetap-uraian-help');
    function syncBlokKegiatan() {
        if (!jenisSel) return;
        const isHidmah = jenisSel.value === 'HIDMAH';
        if (blokKeg) blokKeg.style.display = isHidmah ? '' : 'none';
        if (blokKat) blokKat.style.display = isHidmah ? '' : 'none';
        if (katSel) katSel.required = isHidmah;
        if (uraianLabel) {
            uraianLabel.innerHTML = (isHidmah ? 'Uraian hidmah (sebagai …)' : 'Tujuan tugas (ke …)') + ' <span class="text-danger">*</span>';
        }
        if (uraianInput) {
            uraianInput.placeholder = isHidmah
                ? 'Mis. membantu Koperasi di Pondok'
                : 'Mis. Muntilan';
        }
        if (uraianHelp) {
            uraianHelp.innerHTML = isHidmah
                ? 'Surat: <em>hidmah sebagai …</em> — isi bagian setelah kata &ldquo;sebagai&rdquo;.'
                : 'Surat: <em>tugas ke …</em> — isi tujuan setelah kata &ldquo;ke&rdquo;.';
        }
    }
    jenisSel?.addEventListener('change', syncBlokKegiatan);
    syncBlokKegiatan();
})();
</script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/izin-tetap-slot-picker.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/izin-tetap-kegiatan.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-rombongan-picker.js')) ?>" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
