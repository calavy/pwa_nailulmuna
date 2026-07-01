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
require_once __DIR__ . '/../helpers/pembayaran_edit_token.php';

require_roles(['admin', 'pengurus']);
require_koreksi_pembayaran();

keuangan_ensure_schema_deferred($pdo);
pembayaran_edit_token_ensure_schema($pdo);

$pembayaranId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($pembayaranId <= 0) {
    set_flash('error', 'ID pembayaran tidak valid.');
    header('Location: ' . app_url('pembayaran/riwayat.php'));
    exit;
}

$returnRaw = trim((string) ($_GET['return'] ?? $_POST['return'] ?? ''));
$returnUrl = ($returnRaw !== '' && str_starts_with($returnRaw, '/') && !preg_match('#^https?://#i', $returnRaw))
    ? $returnRaw
    : app_url('pembayaran/riwayat.php');

// ---- Alur token: redeem terlebih dahulu jika user belum punya akses edit ----
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$tokenRequired = pembayaran_edit_token_required_for_current_user();
$tokenSessionAktif = pembayaran_edit_token_session_aktif($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'redeem_token') {
    $tokenInput = (string) ($_POST['token_plain'] ?? '');
    $result = pembayaran_edit_token_redeem($pdo, $currentUserId, $tokenInput);
    if ($result['ok']) {
        set_flash('success', $result['message']);
    } else {
        set_flash('error', $result['message']);
    }
    header('Location: ' . app_url('pembayaran/riwayat_edit.php?id=' . $pembayaranId));
    exit;
}

// Tolak aksi edit/hapus jika butuh token tapi belum redeem.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array((string) ($_POST['action'] ?? ''), ['update_pembayaran', 'delete_pembayaran'], true)
    && $tokenRequired
    && !$tokenSessionAktif
) {
    set_flash('error', 'Mode edit terkunci. Masukkan token dari super admin terlebih dahulu.');
    header('Location: ' . app_url('pembayaran/riwayat_edit.php?id=' . $pembayaranId));
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
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'update_pembayaran') {
        $result = keuangan_update_pembayaran($pdo, $pembayaranId, $_POST, $userId, $alasan);
        if ($result['ok']) {
            set_flash('success', $result['message']);
            header('Location: ' . $returnUrl);
            exit;
        }
        set_flash('error', $result['message']);
        header('Location: ' . app_url('pembayaran/riwayat_edit.php?id=' . $pembayaranId . '&return=' . rawurlencode($returnUrl)));
        exit;
    }
}

$row = keuangan_pembayaran_fetch($pdo, $pembayaranId);
if ($row === null) {
    set_flash('error', 'Pembayaran tidak ditemukan.');
    header('Location: ' . $returnUrl);
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
        <a href="<?= htmlspecialchars($returnUrl) ?>">Kembali ke riwayat</a>
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

<?php
$flashOk = get_flash('success');
$flashErr = get_flash('error');
?>
<?php if ($flashOk): ?><div class="alert alert-success py-2 small mb-3"><i class="fa-solid fa-circle-check me-1"></i><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2 small mb-3"><i class="fa-solid fa-circle-exclamation me-1"></i><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<?php if ($tokenRequired && !$tokenSessionAktif): ?>
    <div class="card shadow-sm border-warning-subtle mb-4">
        <div class="card-header bg-warning-subtle border-0 d-flex align-items-center gap-2 py-2">
            <i class="fa-solid fa-lock text-warning-emphasis"></i>
            <h2 class="h6 mb-0 text-warning-emphasis">Mode edit terkunci — masukkan token</h2>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Untuk membuka mode edit pembayaran, masukkan token sekali pakai yang diterbitkan oleh
                <strong>super admin</strong>. Setelah berhasil, Anda bisa mengedit/hapus pembayaran sebanyak yang dibutuhkan
                hingga Anda <strong>logout</strong> (atau token dibatalkan).
            </p>
            <form method="post" class="row g-2 align-items-end" autocomplete="off">
                <input type="hidden" name="action" value="redeem_token">
                <input type="hidden" name="id" value="<?= $pembayaranId ?>">
                <div class="col-12 col-md-8 col-lg-6">
                    <label class="form-label small text-muted mb-1">Token</label>
                    <input type="text" name="token_plain" class="form-control font-monospace text-uppercase" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="40" required autofocus>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fa-solid fa-lock-open me-1"></i>Buka mode edit
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($tokenRequired && $tokenSessionAktif): ?>
    <div class="alert alert-success py-2 small mb-3 d-flex align-items-center gap-2">
        <i class="fa-solid fa-lock-open"></i>
        <div>
            <strong>Mode edit terbuka.</strong>
            Token aktif untuk session Anda. Berlaku hingga Anda <strong>logout</strong>.
        </div>
    </div>
<?php endif; ?>

<?php
// Form edit hanya tampil jika user bypass (super admin) atau sudah redeem token.
$canEditNow = !$tokenRequired || $tokenSessionAktif;
?>

<?php if ($canEditNow): ?>
<form method="post" class="card shadow-sm mb-4" id="form-koreksi-pembayaran">
    <input type="hidden" name="action" value="update_pembayaran">
    <input type="hidden" name="id" value="<?= $pembayaranId ?>">
    <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl) ?>">
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
            <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl) ?>">
            <div class="mb-3">
                <label class="form-label">Alasan penghapusan <span class="text-danger">*</span></label>
                <textarea name="alasan" class="form-control" rows="2" required></textarea>
            </div>
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-1"></i> Hapus pembayaran</button>
        </form>
    </div>
</div>
<?php else: ?>
    <div class="card shadow-sm border-secondary-subtle">
        <div class="card-body text-center py-5">
            <i class="fa-solid fa-lock fa-3x text-muted mb-3 d-block"></i>
            <h2 class="h5 mb-2">Form edit dan tombol hapus terkunci</h2>
            <p class="text-muted small mb-0">Masukkan token dari super admin di kotak di atas untuk membuka mode edit.</p>
        </div>
    </div>
<?php endif; ?>

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
