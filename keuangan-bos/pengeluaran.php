<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_bos.php';

require_login();
require_roles(['admin', 'pengurus']);

bos_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pengeluaran_bos') {
    $result = bos_save_pengeluaran($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . app_href('/keuangan-bos/pengeluaran.php'));
    exit;
}

$akunRows = bos_fetch_akun_aktif($pdo);
$bebanRows = bos_fetch_coa_beban($pdo);
$posRows = bos_fetch_pos_pengeluaran($pdo);
$posGrouped = bos_pos_grouped_by_jenjang_kelompok($pdo);
$defaultAkunId = 0;
foreach ($akunRows as $ar) {
    if ((int) ($ar['is_default'] ?? 0) === 1) {
        $defaultAkunId = (int) $ar['id'];
        break;
    }
}
if ($defaultAkunId <= 0 && $akunRows !== []) {
    $defaultAkunId = (int) ($akunRows[0]['id'] ?? 0);
}

$pageTitle = 'Input Pengeluaran BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>">Keuangan BOS</a></p>
    <h1 class="h4 mb-1">Input Pengeluaran BOS</h1>
    <p class="text-muted mb-0">Pilih jalur <strong>Standar COA</strong> atau <strong>Pos lain</strong>. Beban Wustho tidak boleh memakai sumber dana BOS Ulya, dan sebaliknya.</p>
</div>

<?php if ($akunRows === [] || ($bebanRows === [] && $posRows === [])): ?>
<div class="alert alert-warning">Akun atau kategori beban belum siap. Buka <a href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan.php')) ?>">Pengaturan BOS</a> atau <a href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan-pos.php')) ?>">Pos Pengeluaran Lain</a>.</div>
<?php else: ?>
<form method="post" class="card shadow-sm" style="max-width:42rem" id="form-pengeluaran-bos">
    <input type="hidden" name="action" value="save_pengeluaran_bos">
    <div class="card-body row g-3">
        <div class="col-12">
            <label class="form-label">Jalur pengeluaran</label>
            <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" name="jalur_pengeluaran" id="jalur-standar" value="standar" checked>
                <label class="btn btn-outline-primary" for="jalur-standar">Standar COA</label>
                <input type="radio" class="btn-check" name="jalur_pengeluaran" id="jalur-lain" value="lain" <?= $posRows === [] ? 'disabled' : '' ?>>
                <label class="btn btn-outline-secondary" for="jalur-lain">Pos lain</label>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
            <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
            <input type="number" name="nominal" class="form-control" min="1" step="1000" required>
        </div>
        <div class="col-12">
            <label class="form-label">Akun sumber dana <span class="text-danger">*</span></label>
            <select name="bos_akun_id" class="form-select" required>
                <?php foreach ($akunRows as $ar): ?>
                    <option value="<?= (int) $ar['id'] ?>" <?= (int) $ar['id'] === $defaultAkunId ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $ar['nama_akun']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12" id="field-beban-standar">
            <label class="form-label">Akun beban <span class="text-danger">*</span></label>
            <select name="kode_akun_beban" class="form-select">
                <option value="">— pilih beban —</option>
                <?php foreach ($bebanRows as $br): ?>
                    <option value="<?= htmlspecialchars((string) $br['kode_akun']) ?>">
                        <?= htmlspecialchars((string) $br['kode_akun']) ?> — <?= htmlspecialchars((string) $br['nama_akun']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-none" id="field-pos-lain">
            <label class="form-label">Pos pengeluaran <span class="text-danger">*</span></label>
            <select name="pos_pengeluaran_id" class="form-select">
                <option value="">— pilih pos —</option>
                <?php foreach ($posGrouped as $jenjangKey => $kelompokMap): ?>
                    <?php foreach ($kelompokMap as $kelompokNama => $items): ?>
                        <optgroup label="<?= htmlspecialchars(bos_label_jenjang_section($jenjangKey) . ' › ' . $kelompokNama) ?>">
                            <?php foreach ($items as $pr): ?>
                                <option value="<?= (int) $pr['id'] ?>">
                                    <?= htmlspecialchars((string) $pr['nama_pos']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
            <p class="small text-muted mt-1 mb-0">Format RAB BOS PKPPS — diposting ke COA 5199 (Beban Operasional Lain-lain).</p>
        </div>
        <div class="col-md-6">
            <label class="form-label">Jenjang <span class="text-danger">*</span></label>
            <select name="jenjang" class="form-select" required>
                <?php foreach (bos_jenjang_options() as $j): ?>
                    <option value="<?= htmlspecialchars($j) ?>"><?= htmlspecialchars(bos_label_jenjang($j)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Sumber dana <span class="text-danger">*</span></label>
            <select name="sumber_dana" class="form-select" required>
                <?php foreach (bos_sumber_dana_options() as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars(bos_label_sumber_dana($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Uraian</label>
            <textarea name="keterangan" class="form-control" rows="2" placeholder="Keterangan transaksi"></textarea>
        </div>
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-primary">Simpan pengeluaran</button>
        <a href="<?= htmlspecialchars(app_href('/keuangan-bos/riwayat.php')) ?>" class="btn btn-outline-secondary">Riwayat</a>
    </div>
</form>
<script>
(function () {
    var form = document.getElementById('form-pengeluaran-bos');
    if (!form) return;
    var standar = document.getElementById('field-beban-standar');
    var lain = document.getElementById('field-pos-lain');
    var selBeban = form.querySelector('[name="kode_akun_beban"]');
    var selPos = form.querySelector('[name="pos_pengeluaran_id"]');
    function sync() {
        var isLain = form.querySelector('[name="jalur_pengeluaran"]:checked')?.value === 'lain';
        standar.classList.toggle('d-none', isLain);
        lain.classList.toggle('d-none', !isLain);
        if (selBeban) selBeban.required = !isLain;
        if (selPos) selPos.required = isLain;
    }
    form.querySelectorAll('[name="jalur_pengeluaran"]').forEach(function (el) {
        el.addEventListener('change', sync);
    });
    sync();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
