<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_status.php';

$santriId = $santriPortalId;
$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $santriId]);
$santri = $st->fetch(PDO::FETCH_ASSOC);
if (!$santri) {
    set_flash('error', 'Data tidak ditemukan.');
    header('Location: ' . app_href('/santri_portal/index.php'));
    exit;
}

ensure_santri_riwayat_tables($pdo);
$filterTa = (int) ($_GET['th'] ?? 0);
$section = trim((string) ($_GET['bagian'] ?? 'semua'));
if (!in_array($section, ['semua', 'domisili', 'khidmah', 'pelanggaran'], true)) {
    $section = 'semua';
}

santri_riwayat_backfill_asrama_from_santri($pdo, $santriId, $santri);
santri_riwayat_domisili_ensure_for_santri($pdo, $santriId, $santri);
$domisiliMengaji = santri_riwayat_domisili_list($pdo, $santriId, 'MENGAJI');
$domisiliKhidmah = santri_riwayat_domisili_list($pdo, $santriId, 'KHIDMAH');
$hidmahRows = santri_riwayat_hidmah_list($pdo, $santriId);
$pelanggaranRows = santri_riwayat_pelanggaran_list($pdo, $santriId, null);
$ringkasan = santri_riwayat_ringkasan($pdo, $santri);
$taAktif = santri_tahun_ajaran_for_date($pdo);
$statusRiwayat = santri_status_from_row($santri);

require_once __DIR__ . '/includes/layout.php';
santri_portal_layout_head('Riwayat — Portal Santri', 'riwayat');
?>
<h1 class="h5 fw-bold mb-1">Riwayat saya</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) $santri['nama_santri']) ?> · NIS <?= htmlspecialchars((string) $santri['nis']) ?></p>

<div class="row g-2 mb-3">
    <div class="col-4">
        <div class="card border-0 shadow-sm wali-card">
            <div class="card-body py-2 small">
                <div class="text-muted">Status</div>
                <span class="badge <?= santri_status_badge_class($statusRiwayat) ?>"><?= htmlspecialchars(santri_status_label($statusRiwayat)) ?></span>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm wali-card">
            <div class="card-body py-2 small">
                <div class="text-muted">Tingkatan</div>
                <strong><?= htmlspecialchars((string) ($ringkasan['tingkatan_saat_ini'] ?: '—')) ?></strong>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm wali-card">
            <div class="card-body py-2 small">
                <div class="text-muted">TA berjalan</div>
                <strong><?= htmlspecialchars(santri_tahun_ajaran_label($taAktif, $pdo)) ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="btn-group btn-group-sm flex-wrap w-100 mb-3" role="group" aria-label="Bagian riwayat">
    <?php
    foreach (['semua' => 'Semua', 'domisili' => 'Domisili', 'khidmah' => 'Khidmah', 'pelanggaran' => 'Pelanggaran'] as $k => $label):
        $href = '/santri_portal/riwayat.php?bagian=' . urlencode($k);
        if ($filterTa > 0) {
            $href .= '&th=' . $filterTa;
        }
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="btn btn-outline-secondary<?= $section === $k ? ' active' : '' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</div>

<p class="mb-3">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/keaktifan.php')) ?>" class="btn btn-sm btn-teal">
        <i class="fa-solid fa-star-half-stroke me-1"></i> Lihat nilai keaktifan saya
    </a>
</p>

<?php
$readOnly = true;
$filterExtraGet = $section !== 'semua' ? ['bagian' => $section] : [];
$filterFormAction = '/santri_portal/riwayat.php' . ($section !== 'semua' ? '?bagian=' . urlencode($section) : '');
if ($section === 'domisili' || $section === 'semua') {
    require __DIR__ . '/../includes/partials/santri_riwayat_domisili.php';
}
if ($section === 'khidmah' || $section === 'semua') {
    if ($section === 'semua') {
        echo '<hr class="my-3">';
    }
    require __DIR__ . '/../includes/partials/santri_riwayat_hidmah.php';
}
if ($section === 'pelanggaran' || $section === 'semua') {
    if ($section === 'semua') {
        echo '<hr class="my-3">';
    }
    require __DIR__ . '/../includes/partials/santri_riwayat_pelanggaran.php';
}
?>

<?php
santri_portal_layout_foot('riwayat');
