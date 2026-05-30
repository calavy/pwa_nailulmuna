<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';

require_roles(['admin', 'pengurus']);

$rombonganId = (int) ($_GET['id'] ?? $_POST['rombongan_id'] ?? 0);
$meta = perizinan_rombongan_meta($pdo, $rombonganId);
if (!$meta) {
    set_flash('error', 'Data izin rombongan tidak ditemukan.');
    header('Location: ' . app_href('/perizinan/kembali.php'));
    exit;
}

$message = null;
$type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kembali_rombongan') {
    $santriIds = array_map('intval', (array) ($_POST['santri_kembali'] ?? []));
    $res = perizinan_rombongan_proses_kembali($pdo, $rombonganId, $santriIds, (int) ($_SESSION['user']['id'] ?? 0));
    $type = $res['ok'] ? 'success' : 'warning';
    $message = $res['message'];
    if ($res['ok']) {
        $belum = $pdo->prepare('SELECT COUNT(*) FROM perizinan WHERE rombongan_id = :rid AND rombongan_kembali = 0');
        $belum->execute(['rid' => $rombonganId]);
        if ((int) $belum->fetchColumn() === 0) {
            set_flash('success', $res['message'] . ' Semua anggota rombongan sudah kembali.');
            header('Location: ' . app_href('/perizinan/index.php'));
            exit;
        }
    }
}

$anggotaGrouped = perizinan_rombongan_anggota_grouped($pdo, $rombonganId);
$totalAnggota = 0;
$belumKembali = 0;
foreach ($anggotaGrouped as $rows) {
    foreach ($rows as $r) {
        $totalAnggota++;
        if ((int) ($r['rombongan_kembali'] ?? 0) !== 1) {
            $belumKembali++;
        }
    }
}

$pageTitle = 'Centang Santri Kembali — Rombongan';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.rombongan-santri-picker__group-head { background: rgba(15, 118, 110, 0.08); z-index: 2; }
[data-theme="dark"] .rombongan-santri-picker__group-head { background: rgba(30, 41, 59, 0.95); }
</style>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/perizinan/kembali.php')) ?>">Scan izin</a></p>
    <h1 class="h4 mb-1">Izin rombongan — centang yang sudah kembali</h1>
    <p class="text-muted mb-0">
        <?= $totalAnggota ?> santri · belum kembali <strong><?= $belumKembali ?></strong>
        · <?= htmlspecialchars(jenis_izin_label((string) ($meta['jenis_izin'] ?? 'KELUAR'))) ?>
        · <?= htmlspecialchars(app_format_izin_rentang(
            (string) ($meta['tanggal_mulai'] ?? ''),
            (string) ($meta['tanggal_selesai'] ?? ''),
            (string) ($meta['jam_mulai'] ?? ''),
            (string) ($meta['jam_selesai'] ?? '')
        )) ?>
    </p>
</div>
<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<div class="card shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap gap-2">
        <a class="btn btn-outline-dark btn-sm" target="_blank" href="<?= htmlspecialchars(app_href('/perizinan/surat_rombongan.php?id=' . $rombonganId)) ?>">
            <i class="fa-solid fa-print me-1"></i> Cetak surat izin (A4)
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>">Daftar izin</a>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" data-rombongan-min="1" data-rombongan-target="rombongan-kembali">
            <input type="hidden" name="action" value="kembali_rombongan">
            <input type="hidden" name="rombongan_id" value="<?= (int) $rombonganId ?>">
            <p class="small text-muted mb-2">
                Centang <strong>satu atau banyak</strong> santri yang sudah tiba di asrama, lalu simpan.
                Urutan: tingkatan → NIS.
            </p>
            <?php
            $rombonganSantriGrouped = $anggotaGrouped;
            $rombonganPickerName = 'santri_kembali[]';
            $rombonganPickerId = 'rombongan-kembali';
            $rombonganPickerShowToolbar = true;
            require __DIR__ . '/partials/rombongan_santri_picker.php';
            ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check-double me-1"></i> Simpan yang dicentang</button>
            </div>
        </form>
    </div>
</div>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-rombongan-picker.js')) ?>" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
