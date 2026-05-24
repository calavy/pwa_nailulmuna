<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';

require_roles(['admin', 'pengurus']);
require_koreksi_pembayaran();

keuangan_ensure_schema_deferred($pdo);

$pembayaranId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($pembayaranId <= 0) {
    set_flash('error', 'ID pembayaran tidak valid.');
    header('Location: ' . app_url('pembayaran/riwayat.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $alasan = trim((string) ($_POST['alasan'] ?? ''));

    if ($action === 'delete_pembayaran') {
        $result = keuangan_delete_pembayaran($pdo, $pembayaranId, $userId, $alasan);
        if ($result['ok']) {
            set_flash('success', $result['message']);
        } else {
            set_flash('error', $result['message']);
        }
        header('Location: ' . app_url('pembayaran/riwayat.php'));
        exit;
    }

    if ($action === 'update_pembayaran') {
        $result = keuangan_update_pembayaran($pdo, $pembayaranId, $_POST, $userId, $alasan);
        if ($result['ok']) {
            set_flash('success', $result['message']);
            header('Location: ' . app_url('pembayaran/riwayat.php'));
            exit;
        }
        set_flash('error', $result['message']);
        header('Location: ' . app_url('pembayaran/riwayat_edit.php?id=' . $pembayaranId));
        exit;
    }
}

$row = keuangan_pembayaran_fetch($pdo, $pembayaranId);
if ($row === null) {
    set_flash('error', 'Pembayaran tidak ditemukan.');
    header('Location: ' . app_url('pembayaran/riwayat.php'));
    exit;
}

$biayaDefinitions = keuangan_biaya_definitions();
$akunRows = keuangan_fetch_akun_aktif($pdo);
$jenisPeriode = strtoupper((string) ($row['jenis_periode'] ?? 'BULANAN'));
$tmEdit = (int) ($row['tahun_ajaran_mulai'] ?? 0);
$tsEdit = (int) ($row['tahun_ajaran_selesai'] ?? 0);
$bulanSlotsEdit = pondok_bulan_slots_tahun_ajaran($pdo, $tmEdit, $tsEdit);
$kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';

$detailBySlug = [];
foreach ((array) ($row['details'] ?? []) as $d) {
    $detailBySlug[(string) ($d['pos_slug'] ?? '')] = $d;
}

$pageTitle = 'Koreksi Pembayaran #' . $pembayaranId;
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconRiwayat = bendahara_page_icon('riwayat');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_url('pembayaran/riwayat.php')) ?>">Riwayat pembayaran</a>
        · Admin
    </p>
    <h1 class="h3 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($iconRiwayat) ?>"></i></span>
        Koreksi pembayaran #<?= $pembayaranId ?>
    </h1>
    <p class="text-muted mb-0">
        Santri: <strong><?= htmlspecialchars((string) $row['nama_santri']) ?></strong>
        (<?= htmlspecialchars((string) $row['nis']) ?>).
        Perubahan dicatat di <a href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php?modul=keuangan_pembayaran&entity_id=' . $pembayaranId)) ?>">log audit operasional</a> (super admin).
    </p>
</div>

<div class="alert alert-warning small">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    Hanya <strong>admin</strong> yang dapat mengedit atau menghapus. Jurnal &amp; saldo saku (jika ada) disesuaikan otomatis.
</div>

<form method="post" class="card shadow-sm mb-4" id="form-koreksi-pembayaran">
    <input type="hidden" name="action" value="update_pembayaran">
    <input type="hidden" name="id" value="<?= $pembayaranId ?>">
    <div class="card-header fw-semibold">Ubah data pembayaran</div>
    <div class="card-body row g-3">
        <div class="col-md-4">
            <label class="form-label">Jenis periode</label>
            <select name="jenis_periode" class="form-select" id="jenis_periode">
                <option value="BULANAN" <?= $jenisPeriode === 'BULANAN' ? 'selected' : '' ?>>Bulanan</option>
                <option value="AWAL_TAHUN" <?= $jenisPeriode === 'AWAL_TAHUN' ? 'selected' : '' ?>>Awal tahun</option>
            </select>
        </div>
        <div class="col-md-4" id="wrap-bulan">
            <label class="form-label">Bulan tagihan</label>
            <select name="bulan_tagihan" class="form-select">
                <?php foreach ($bulanSlotsEdit as $slot): ?>
                    <?php $m = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                    <option value="<?= $m ?>" <?= (int) ($row['bulan_tagihan'] ?? 0) === $m ? 'selected' : '' ?>><?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        $taMetaEdit = pondok_ta_form_meta($pdo);
        $taMulaiEdit = (int) ($row['tahun_ajaran_mulai'] ?? 0);
        $taSelesaiEdit = (int) ($row['tahun_ajaran_selesai'] ?? 0);
        ?>
        <div class="col-md-2 pondok-ta-field" data-ta-hijri="<?= pondok_kalender_hijriyah($pdo) ? '1' : '0' ?>">
            <label class="form-label"><?= htmlspecialchars($taMetaEdit['label_mulai']) ?></label>
            <input type="number" name="tahun_ajaran_mulai" class="form-control pondok-ta-mulai" value="<?= $taMulaiEdit ?>" min="<?= (int) $taMetaEdit['min'] ?>" max="<?= (int) $taMetaEdit['max'] ?>">
        </div>
        <div class="col-md-2 pondok-ta-field">
            <label class="form-label"><?= htmlspecialchars($taMetaEdit['label_selesai']) ?></label>
            <input type="number" name="tahun_ajaran_selesai" class="form-control pondok-ta-selesai" value="<?= $taSelesaiEdit ?>" min="<?= (int) $taMetaEdit['min'] ?>" max="<?= (int) $taMetaEdit['max'] ?>" <?= pondok_kalender_hijriyah($pdo) ? 'readonly' : '' ?>>
        </div>
        <div class="col-md-4">
            <label class="form-label">Tanggal bayar</label>
            <input type="date" name="tanggal_bayar" class="form-control" value="<?= htmlspecialchars((string) ($row['tanggal_bayar'] ?? date('Y-m-d'))) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Metode bayar</label>
            <select name="metode_bayar" class="form-select">
                <option value="KAS" <?= strtoupper((string) ($row['metode_bayar'] ?? 'KAS')) === 'KAS' ? 'selected' : '' ?>>Kas</option>
                <option value="TRANSFER" <?= strtoupper((string) ($row['metode_bayar'] ?? '')) === 'TRANSFER' ? 'selected' : '' ?>>Transfer</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Akun penerimaan</label>
            <select name="akun_id" class="form-select" required>
                <option value="">— Pilih —</option>
                <?php foreach ($akunRows as $ar): ?>
                    <option value="<?= (int) $ar['id'] ?>" <?= (int) ($row['akun_id'] ?? 0) === (int) $ar['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $ar['nama_akun']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">No. referensi (transfer)</label>
            <input type="text" name="no_referensi" class="form-control" value="<?= htmlspecialchars((string) ($row['no_referensi'] ?? '')) ?>" maxlength="100">
        </div>
        <div class="col-12">
            <label class="form-label">Keterangan</label>
            <textarea name="keterangan" class="form-control" rows="2" maxlength="500"><?= htmlspecialchars((string) ($row['keterangan'] ?? '')) ?></textarea>
        </div>

        <div class="col-12">
            <h2 class="h6 mb-2">Komponen &amp; nominal</h2>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:3rem;">✓</th>
                            <th>Komponen</th>
                            <th class="text-end" style="width:12rem;">Nominal (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($biayaDefinitions as $def): ?>
                            <?php if (($def['kategori'] ?? '') !== $kategoriFilter) {
                                continue;
                            } ?>
                            <?php
                            $slug = (string) ($def['slug'] ?? '');
                            $checked = isset($detailBySlug[$slug]);
                            $nomVal = $checked ? (int) round((float) ($detailBySlug[$slug]['nominal'] ?? 0)) : 0;
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="bayar_pos[]" value="<?= htmlspecialchars($slug) ?>" class="form-check-input" <?= $checked ? 'checked' : '' ?>>
                                </td>
                                <td><?= htmlspecialchars((string) $def['nama']) ?></td>
                                <td>
                                    <input type="text" name="nominal_<?= htmlspecialchars($slug) ?>" class="form-control form-control-sm text-end font-monospace" inputmode="numeric" value="<?= $nomVal > 0 ? number_format($nomVal, 0, ',', '.') : '' ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-12">
            <label class="form-label">Alasan koreksi <span class="text-danger">*</span></label>
            <textarea name="alasan" class="form-control" rows="2" required placeholder="Contoh: Salah input nominal syahriyah, koreksi sesuai bukti transfer."></textarea>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan perubahan</button>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(app_url('pembayaran/riwayat.php')) ?>">Batal</a>
            <a class="btn btn-outline-info" href="<?= htmlspecialchars(app_url('keuangan/kuitansi.php?id=' . $pembayaranId)) ?>" target="_blank">Lihat kuitansi</a>
        </div>
    </div>
</form>

<div class="card shadow-sm border-danger">
    <div class="card-header bg-danger bg-opacity-10 text-danger fw-semibold">Hapus pembayaran</div>
    <div class="card-body">
        <p class="small text-muted mb-3">Menghapus transaksi ini juga membatalkan jurnal terkait dan top-up saku (jika ada). Tindakan tidak dapat dibatalkan.</p>
        <form method="post" onsubmit="return confirm('Yakin hapus pembayaran #<?= $pembayaranId ?>? Tindakan ini dicatat di log audit.');">
            <input type="hidden" name="action" value="delete_pembayaran">
            <input type="hidden" name="id" value="<?= $pembayaranId ?>">
            <div class="mb-3">
                <label class="form-label">Alasan penghapusan <span class="text-danger">*</span></label>
                <textarea name="alasan" class="form-control" rows="2" required></textarea>
            </div>
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-1"></i> Hapus pembayaran</button>
        </form>
    </div>
</div>

<script>
(function () {
    var jenis = document.getElementById('jenis_periode');
    var wrapBulan = document.getElementById('wrap-bulan');
    function sync() {
        if (!jenis || !wrapBulan) return;
        wrapBulan.style.display = jenis.value === 'BULANAN' ? '' : 'none';
    }
    if (jenis) {
        jenis.addEventListener('change', sync);
        sync();
    }
})();
</script>

<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
