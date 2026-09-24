<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengasuh_laporan_hari.php';

require_pengasuh_dashboard();

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

$penepianRows = pengasuh_laporan_hari_penepian($pdo, $tanggal, 200);
$konteks = pengasuh_laporan_hari_konteks($pdo, $tanggal, 0);
$tglLabel = (string) ($konteks['tgl_label'] ?? $tanggal);

$pageTitle = 'Santri menepi keaktifan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/pengasuh/dashboard.php')) ?>">Pengasuh</a> · Menepi
    </p>
    <h1 class="h4 mb-1">Santri menepi keaktifan</h1>
    <p class="text-muted mb-0">
        Daftar santri yang sedang menepi pada <strong><?= htmlspecialchars($tglLabel) ?></strong>.
        Kolom <em>Menepi</em> = jumlah hari sejak tanggal mulai penepian (inklusif hari ini).
        <?php if (user_can_manage_santri_penepian()): ?>
            · <a href="<?= htmlspecialchars(app_href('/perizinan/penepian_keaktifan.php')) ?>">Kelola penepian</a>
        <?php endif; ?>
    </p>
</div>

<?php
$penepianTanggalAcuan = $tanggal;
require __DIR__ . '/../includes/partials/pengasuh_penepian_daftar.php';
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
