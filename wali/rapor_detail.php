<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/akademik_rapor.php';

$tab = trim((string) ($_GET['tab'] ?? 'rapor_pesantren'));
if ($tab === 'rapor') {
    $tab = 'rapor_pesantren';
}
if (!in_array($tab, ['rapor_pesantren', 'rapor_pkpps', 'hafalan'], true)) {
    $tab = 'rapor_pesantren';
}

$raporJenisFilter = $tab === 'rapor_pkpps' ? 'pkpps' : 'pesantren';
$raporId = (int) ($_GET['id'] ?? 0);

$rapor = null;
if ($raporId > 0) {
    $rapor = akademik_rapor_fetch_for_wali($pdo, $raporId, $waliSantriId);
    if ($rapor === null) {
        set_flash('error', 'Rapor tidak ditemukan atau belum diterbitkan.');
        header('Location: ' . app_href('/wali/akademik.php?tab=' . rawurlencode($tab === 'rapor_pkpps' ? 'rapor_pkpps' : 'rapor_pesantren')));
        exit;
    }
    if (akademik_rapor_jenis_normalize((string) ($rapor['jenis_rapor'] ?? '')) !== $raporJenisFilter) {
        set_flash('error', 'Rapor tidak sesuai jenis tab.');
        header('Location: ' . app_href('/wali/akademik.php?tab=' . rawurlencode($raporJenisFilter === 'pkpps' ? 'rapor_pkpps' : 'rapor_pesantren')));
        exit;
    }
}

$adaPdf = $rapor && trim((string) ($rapor['pdf_path'] ?? '')) !== '';
$konten = ($rapor && !$adaPdf) ? akademik_rapor_konten_from_row($pdo, $rapor) : null;

require_once __DIR__ . '/includes/layout.php';
$judul = (string) ($rapor['judul_periode'] ?? 'Rapor');
wali_layout_head($judul . ' — Portal Wali', true, 'akademik');
?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/wali/akademik.php?tab=' . rawurlencode($raporJenisFilter === 'pkpps' ? 'rapor_pkpps' : 'rapor_pesantren'))) ?>"><i class="fa-solid fa-arrow-left me-1"></i>Kembali</a>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>

        <?php if ($rapor): ?>
            <?php
            $r = $rapor;
            $isPkpps = $raporJenisFilter === 'pkpps';
            if ($konten) {
                $raporPeriodeLabel = $konten['periode_label'];
                $raporPresensi = $konten['presensi'];
                $raporSetoran = $konten['setoran'];
                $raporTugas = $konten['tugas'];
                $raporSectionLabels = $konten['section_labels'];
            }
            $raporCompact = true;
            $raporId = (int) ($r['id'] ?? 0);
            $pdfLihatUrl = app_href('/wali/rapor_pdf.php?id=' . $raporId);
            $pdfUnduhUrl = app_href('/wali/rapor_pdf.php?id=' . $raporId . '&dl=1');
            $raporShowDetailLink = false;
            require __DIR__ . '/partials/akademik_rapor_card.php';
            ?>
        <?php endif; ?>
<?php
wali_layout_foot(true, 'akademik');
