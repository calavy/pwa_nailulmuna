<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/tagihan_khusus_wali.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';

require_login();
require_roles(['admin', 'pengurus']);

ensure_keuangan_tagihan_khusus_table($pdo);
keuangan_ensure_schema_deferred($pdo);

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$kategoriOpsi = tagihan_khusus_kategori_opsi();
$alokasiSyahriyahOpts = tagihan_khusus_alokasi_syahriyah_options($pdo);
$userNama = trim((string) ($_SESSION['user']['nama'] ?? 'Bendahara'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $uid = (int) ($_SESSION['user']['id'] ?? 0);

    if ($action === 'simpan_tagihan') {
        $result = tagihan_khusus_save($pdo, $_POST, $uid);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        $q = [];
        if (!empty($result['id'])) {
            $q['edit'] = (int) $result['id'];
        }
        header('Location: ' . app_href('/keuangan/tagihan_wali.php' . ($q !== [] ? '?' . http_build_query($q) . '#form-tagihan' : '#form-tagihan')));
        exit;
    }
    if ($action === 'catat_bayar') {
        $result = tagihan_khusus_catat_bayar($pdo, $_POST);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/keuangan/tagihan_wali.php?edit=' . (int) ($_POST['tagihan_id'] ?? 0) . '#form-tagihan'));
        exit;
    }
    if ($action === 'batalkan_tagihan') {
        $result = tagihan_khusus_batalkan($pdo, (int) ($_POST['tagihan_id'] ?? 0));
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . app_href('/keuangan/tagihan_wali.php'));
        exit;
    }
    if ($action === 'kirim_wa_tagihan') {
        $tid = (int) ($_POST['tagihan_id'] ?? 0);
        $wa = tagihan_khusus_kirim_wa($pdo, $tid, true);
        set_flash($wa !== '' && !str_starts_with($wa, 'WA gagal') && !str_starts_with($wa, 'WA tidak') ? 'success' : 'warning', $wa !== '' ? $wa : 'Tidak ada aksi WA.');
        header('Location: ' . app_href('/keuangan/tagihan_wali.php?edit=' . $tid . '#form-tagihan'));
        exit;
    }
}

$filterSantri = (int) ($_GET['santri_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? tagihan_khusus_fetch($pdo, $editId) : null;
$daftar = tagihan_khusus_list_admin($pdo, $filterSantri);

$pageTitle = 'Tagihan Khusus ke Wali';
$bodyClass = keuangan_body_class('keuangan-form-page');
$loadSantriSelectJs = true;
require_once __DIR__ . '/../includes/header.php';

$formSid = $editRow ? (int) $editRow['santri_id'] : ($filterSantri > 0 ? $filterSantri : 0);
$formKat = (string) ($editRow['kategori'] ?? 'berobat');
$formJudul = (string) ($editRow['judul'] ?? '');
$formKet = (string) ($editRow['keterangan'] ?? '');
$formNom = $editRow ? (int) round((float) ($editRow['nominal'] ?? 0)) : 0;
$formTgl = (string) ($editRow['tanggal_tagihan'] ?? date('Y-m-d'));
$formPub = $editRow ? (int) ($editRow['is_published'] ?? 1) : 1;
$formPinjam = $editRow ? trim((string) ($editRow['alokasi_nama'] ?? '')) !== '' : true;
$formAlokasi = (string) ($editRow['alokasi_nama'] ?? tagihan_khusus_alokasi_default_kategori($pdo, $formKat));
if ($formAlokasi === '' && $alokasiSyahriyahOpts !== []) {
    $formAlokasi = (string) ($alokasiSyahriyahOpts[0]['value'] ?? '');
}
$formPengeluaranId = $editRow ? (int) ($editRow['pengeluaran_id'] ?? 0) : 0;
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Keuangan · Tagihan ke wali</p>
    <h1 class="h3 mb-1">Tagihan khusus ke wali</h1>
    <p class="text-muted small mb-0">
        Catat tagihan di luar syahriyah bulanan — misalnya <strong>biaya berobat</strong>, obat, atau keperluan khusus lain.
        Dana dicatat sebagai <strong>pinjaman dari alokasi syahriyah</strong> (pengeluaran virtual komponen) dan ditagihkan ke wali untuk pengembalian.
        Wali melihat di portal <strong>Keuangan → Tagihan lain</strong>. Pembayaran bulanan tetap di
        <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a>.
        Template WA di <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=template')) ?>">WA Otomatis → Template</a>.
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-5" id="form-tagihan">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= $editRow ? 'Edit tagihan' : 'Tambah tagihan' ?></h2>
                <?php if ($editRow): ?>
                    <p class="small mb-2"><a href="<?= htmlspecialchars(app_href('/keuangan/tagihan_wali.php')) ?>">Batal edit</a></p>
                <?php endif; ?>
                <form method="post" class="d-grid gap-2" id="form-tagihan-khusus">
                    <input type="hidden" name="action" value="simpan_tagihan">
                    <input type="hidden" name="penanggung_jawab" value="<?= htmlspecialchars($userNama) ?>">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="tagihan_id" value="<?= (int) $editRow['id'] ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label">Santri <span class="text-danger">*</span></label>
                        <select name="santri_id" class="form-select santri-select-searchable" required
                            data-santri-ajax="1"
                            data-santri-search-url="<?= htmlspecialchars(app_href('/api/keuangan/santri_search.php')) ?>"
                            data-search-placeholder="Ketik nama atau NIS…">
                            <option value="">— Pilih santri —</option>
                            <?php if ($formSid > 0 && $editRow): ?>
                                <option value="<?= $formSid ?>" selected><?= htmlspecialchars((string) ($editRow['nama_santri'] ?? '') . ' (' . (string) ($editRow['nis'] ?? '') . ')') ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                        <select name="kategori" class="form-select" required>
                            <?php foreach ($kategoriOpsi as $slug => $label): ?>
                                <option value="<?= htmlspecialchars($slug) ?>" <?= $formKat === $slug ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Judul tagihan <span class="text-danger">*</span></label>
                        <input type="text" name="judul" class="form-control" required maxlength="200" placeholder="Mis. Biaya berobat RS dr. …" value="<?= htmlspecialchars($formJudul) ?>">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="nominal" class="form-control" min="1" step="1" required value="<?= $formNom > 0 ? $formNom : '' ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Tanggal tagihan <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_tagihan" class="form-control" required value="<?= htmlspecialchars($formTgl) ?>">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Keterangan (opsional)</label>
                        <textarea name="keterangan" class="form-control" rows="2" placeholder="Rincian singkat untuk wali"><?= htmlspecialchars($formKet) ?></textarea>
                    </div>
                    <div class="border rounded p-2 bg-light">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="pinjam_alokasi" id="pinjamAlokasi" value="1" <?= $formPinjam ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pinjamAlokasi">
                                <strong>Pinjam dari alokasi syahriyah</strong>
                                <span class="d-block small text-muted fw-normal">Otomatis catat pengeluaran ke komponen alokasi (mengurangi saldo virtual di laporan syahriyah).</span>
                            </label>
                        </div>
                        <div id="wrapAlokasiSyahriyah" class="<?= $formPinjam ? '' : 'd-none' ?>">
                            <label class="form-label small mb-1">Komponen alokasi <span class="text-danger">*</span></label>
                            <select name="alokasi_nama" class="form-select form-select-sm" id="selectAlokasiSyahriyah">
                                <option value="">— Pilih komponen —</option>
                                <?php foreach ($alokasiSyahriyahOpts as $opt): ?>
                                    <option value="<?= htmlspecialchars((string) $opt['value']) ?>" <?= $formAlokasi === (string) $opt['value'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($alokasiSyahriyahOpts === []): ?>
                                <div class="form-text text-warning mb-0">Atur komponen di <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">Alokasi syahriyah</a>.</div>
                            <?php else: ?>
                                <div class="form-text mb-0">Biaya berobat/obat biasanya dari komponen <em>Kesehatan</em>.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($formPengeluaranId > 0): ?>
                            <p class="small mb-0 mt-2">
                                Pengeluaran terkait:
                                <a href="<?= htmlspecialchars(app_href('/keuangan/riwayat_pengeluaran.php?edit=' . $formPengeluaranId)) ?>">#<?= $formPengeluaranId ?></a>
                                <?= $formAlokasi !== '' ? '· ' . htmlspecialchars($formAlokasi) : '' ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_published" id="pubtkh" value="1" <?= $formPub === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pubtkh"><strong>Tampilkan di portal wali</strong></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="kirim_wa" id="watkh" value="1" <?= !$editRow ? 'checked' : '' ?>>
                        <label class="form-check-label" for="watkh">Kirim notifikasi WA ke wali</label>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editRow ? 'Simpan perubahan' : 'Simpan tagihan' ?></button>
                </form>

                <?php if ($editRow && (string) ($editRow['status'] ?? '') !== 'batal'):
                    $sisaEdit = tagihan_khusus_sisa($editRow);
                    $paidEdit = (int) round((float) ($editRow['nominal_dibayar'] ?? 0));
                    ?>
                    <hr class="my-3">
                    <h3 class="h6">Pembayaran tagihan ini</h3>
                    <p class="small text-muted mb-2">
                        Dibayar: <strong><?= $formatRupiah($paidEdit) ?></strong>
                        · Sisa: <strong class="text-<?= $sisaEdit > 0 ? 'danger' : 'success' ?>"><?= $formatRupiah($sisaEdit) ?></strong>
                        · <?= htmlspecialchars(tagihan_khusus_status_label($editRow)) ?>
                    </p>
                    <?php if ($sisaEdit > 0): ?>
                        <form method="post" class="row g-2 align-items-end">
                            <input type="hidden" name="action" value="catat_bayar">
                            <input type="hidden" name="tagihan_id" value="<?= (int) $editRow['id'] ?>">
                            <div class="col-7">
                                <label class="form-label small">Catat pembayaran (Rp)</label>
                                <input type="number" name="nominal_bayar" class="form-control form-control-sm" min="1" max="<?= $sisaEdit ?>" value="<?= $sisaEdit ?>" required>
                            </div>
                            <div class="col-5">
                                <button type="submit" class="btn btn-sm btn-success w-100">Catat bayar</button>
                            </div>
                        </form>
                        <p class="form-text small mb-0">Pengembalian pinjaman alokasi — bukan pembayaran syahriyah bulanan.</p>
                    <?php endif; ?>
                    <form method="post" class="mt-2 d-inline" onsubmit="return confirm('Kirim ulang WA ke wali?');">
                        <input type="hidden" name="action" value="kirim_wa_tagihan">
                        <input type="hidden" name="tagihan_id" value="<?= (int) $editRow['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success">Kirim WA</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Daftar tagihan</h2>
                    <form method="get" class="d-flex gap-2">
                        <select name="santri_id" class="form-select form-select-sm" style="min-width:10rem;" onchange="this.form.submit()">
                            <option value="0">Semua santri</option>
                            <?php
                            $santriOpts = [];
                            foreach ($daftar as $d) {
                                $sid = (int) ($d['santri_id'] ?? 0);
                                if ($sid > 0) {
                                    $santriOpts[$sid] = (string) ($d['nama_santri'] ?? '');
                                }
                            }
                            asort($santriOpts);
                            foreach ($santriOpts as $sid => $nama):
                                ?>
                                <option value="<?= (int) $sid ?>" <?= $filterSantri === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Tgl</th><th>Santri</th><th>Judul</th><th>Alokasi</th><th>Nominal</th><th>Sisa</th><th>Portal</th><th>Status</th><th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($daftar === []): ?>
                            <tr><td colspan="9" class="text-muted small">Belum ada tagihan khusus.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($daftar as $d):
                            $sisa = tagihan_khusus_sisa($d);
                            ?>
                            <tr>
                                <td class="text-nowrap small"><?= htmlspecialchars((string) ($d['tanggal_tagihan'] ?? '')) ?></td>
                                <td class="small"><?= htmlspecialchars((string) ($d['nama_santri'] ?? '')) ?></td>
                                <td class="small">
                                    <?= htmlspecialchars((string) ($d['judul'] ?? '')) ?>
                                    <div class="text-muted"><?= htmlspecialchars(tagihan_khusus_kategori_label((string) ($d['kategori'] ?? ''))) ?></div>
                                </td>
                                <td class="small text-muted"><?= trim((string) ($d['alokasi_nama'] ?? '')) !== '' ? htmlspecialchars((string) $d['alokasi_nama']) : '—' ?></td>
                                <td class="small text-nowrap"><?= $formatRupiah((int) round((float) ($d['nominal'] ?? 0))) ?></td>
                                <td class="small text-nowrap <?= $sisa > 0 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= $formatRupiah($sisa) ?></td>
                                <td><?= (int) ($d['is_published'] ?? 0) === 1 ? '<span class="badge text-bg-primary">Ya</span>' : '—' ?></td>
                                <td><span class="badge text-bg-<?= tagihan_khusus_status_badge_class($d) ?>"><?= htmlspecialchars(tagihan_khusus_status_label($d)) ?></span></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/tagihan_wali.php?edit=' . (int) $d['id'])) ?>#form-tagihan">Edit</a>
                                    <?php if ((string) ($d['status'] ?? '') !== 'batal'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Batalkan tagihan ini?');">
                                            <input type="hidden" name="action" value="batalkan_tagihan">
                                            <input type="hidden" name="tagihan_id" value="<?= (int) $d['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Batal</button>
                                        </form>
                                    <?php endif; ?>
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
    var cb = document.getElementById('pinjamAlokasi');
    var wrap = document.getElementById('wrapAlokasiSyahriyah');
    var sel = document.getElementById('selectAlokasiSyahriyah');
    if (!cb || !wrap) return;
    function sync() {
        var on = cb.checked;
        wrap.classList.toggle('d-none', !on);
        if (sel) sel.required = on;
    }
    cb.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
