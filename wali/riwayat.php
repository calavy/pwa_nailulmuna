<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/santri_riwayat.php';
require_once __DIR__ . '/../helpers/santri_status.php';

$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $waliSantriId]);
$santri = $st->fetch(PDO::FETCH_ASSOC);
if (!$santri) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/wali/index.php'));
    exit;
}

ensure_santri_riwayat_tables($pdo);
$filterTa = (int) ($_GET['th'] ?? 0);
$section = trim((string) ($_GET['bagian'] ?? 'semua'));
if (!in_array($section, ['semua', 'domisili', 'khidmah', 'pelanggaran'], true)) {
    $section = 'semua';
}

santri_riwayat_backfill_asrama_from_santri($pdo, $waliSantriId, $santri);
santri_riwayat_domisili_ensure_for_santri($pdo, $waliSantriId, $santri);

$domisiliMengaji = santri_riwayat_domisili_list($pdo, $waliSantriId, 'MENGAJI');
$domisiliKhidmah = santri_riwayat_domisili_list($pdo, $waliSantriId, 'KHIDMAH');
$hidmahRows = santri_riwayat_hidmah_list($pdo, $waliSantriId);
$pelanggaranRows = santri_riwayat_pelanggaran_list($pdo, $waliSantriId, null);
$ringkasan = santri_riwayat_ringkasan($pdo, $santri);
$taAktif = santri_tahun_ajaran_for_date($pdo);
$statusRiwayat = santri_status_from_row($santri);

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Riwayat santri — Portal Wali', true, 'riwayat');
?>
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="flex-grow-1">
                <h1 class="h5 mb-1 wali-brand fw-bold">Riwayat Santri</h1>
                <p class="small text-muted mb-0"><?= htmlspecialchars((string) $santri['nama_santri']) ?> · NIS <?= htmlspecialchars((string) $santri['nis']) ?> · <a href="/wali/pembayaran.php">Riwayat Keuangan</a></p>
            </div>
            <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="/wali/logout.php">Keluar</a>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-4">
                <div class="card shadow-sm border-0 wali-card">
                    <div class="card-body py-2 small">
                        <div class="text-muted">Status</div>
                        <span class="badge <?= santri_status_badge_class($statusRiwayat) ?>"><?= htmlspecialchars(santri_status_label($statusRiwayat)) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="card shadow-sm border-0 wali-card">
                    <div class="card-body py-2 small">
                        <div class="text-muted">Tingkatan</div>
                        <strong><?= htmlspecialchars((string) ($ringkasan['tingkatan_saat_ini'] ?: '—')) ?></strong>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="card shadow-sm border-0 wali-card">
                    <div class="card-body py-2 small">
                        <div class="text-muted">TA berjalan</div>
                        <strong><?= htmlspecialchars(santri_tahun_ajaran_label($taAktif, $pdo)) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="btn-group btn-group-sm flex-wrap w-100 mb-3" role="group" aria-label="Bagian riwayat">
            <?php
            $bagianOpts = ['semua' => 'Semua', 'domisili' => 'Domisili', 'khidmah' => 'Khidmah', 'pelanggaran' => 'Pelanggaran'];
            foreach ($bagianOpts as $k => $label):
                $href = '/wali/riwayat.php?bagian=' . urlencode($k);
                if ($filterTa > 0) {
                    $href .= '&th=' . $filterTa;
                }
            ?>
            <a href="<?= htmlspecialchars($href) ?>" class="btn btn-outline-secondary<?= $section === $k ? ' active' : '' ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="alert alert-light border small mb-3">
            <i class="fa-solid fa-circle-info me-1 text-primary"></i>
            <strong>Nilai keaktifan</strong> (Baik/Sedang/Buruk) ditetapkan pengasuh dan dapat dilihat santri di portal.
        </div>

<?php
$santriId = $waliSantriId;
$readOnly = true;
$filterExtraGet = $section !== 'semua' ? ['bagian' => $section] : [];
$filterFormAction = '/wali/riwayat.php' . ($section !== 'semua' ? '?bagian=' . urlencode($section) : '');
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
wali_layout_foot(true, 'riwayat');
