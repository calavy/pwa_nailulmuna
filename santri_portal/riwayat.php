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
    header('Location: /santri_portal/index.php');
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

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
require_once __DIR__ . '/../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => 'Riwayat — Portal Santri',
    'welcome' => 'Riwayat saya',
    'subtitle' => htmlspecialchars((string) $santri['nama_santri']) . ' · NIS ' . htmlspecialchars((string) $santri['nis']),
    'nama_ponpes' => $namaPonpes,
    'max_width' => '640px',
    'accent' => 'teal',
]);
?>
<p class="mb-2"><a href="/santri_portal/index.php" class="small">&larr; Beranda</a></p>

<div class="row g-2 mb-3">
    <div class="col-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 small">
                <div class="text-muted">Status</div>
                <span class="badge <?= santri_status_badge_class($statusRiwayat) ?>"><?= htmlspecialchars(santri_status_label($statusRiwayat)) ?></span>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 small">
                <div class="text-muted">Tingkatan</div>
                <strong><?= htmlspecialchars((string) ($ringkasan['tingkatan_saat_ini'] ?: '—')) ?></strong>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 small">
                <div class="text-muted">TA berjalan</div>
                <strong><?= htmlspecialchars(santri_tahun_ajaran_label($taAktif)) ?></strong>
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

<div class="alert alert-light border small mb-3">
    <i class="fa-solid fa-circle-info me-1 text-primary"></i>
    <strong>Nilai keaktifan</strong> (Baik/Sedang/Buruk) hanya untuk pengasuh pondok.
</div>

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

<p class="text-center mt-3 mb-0"><a href="/santri_portal/logout.php" class="btn btn-sm btn-outline-secondary">Keluar</a></p>
<?php
auth_portal_layout_end([], true);
