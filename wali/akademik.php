<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';

$tab = trim((string) ($_GET['tab'] ?? 'rapor_pesantren'));
if ($tab === 'rapor') {
    $tab = 'rapor_pesantren';
}
if (!in_array($tab, ['rapor_pesantren', 'rapor_pkpps', 'nilai_tugas', 'hafalan'], true)) {
    $tab = 'rapor_pesantren';
}

if ($tab === 'hafalan') {
    require_once __DIR__ . '/../helpers/akademik.php';
    ensure_akademik_hafalan_setoran_table($pdo);
}

require_once __DIR__ . '/includes/layout.php';

$tabTitles = [
    'rapor_pesantren' => 'Rapor Pesantren',
    'rapor_pkpps' => 'Rapor PKPPS',
    'nilai_tugas' => 'Nilai Tugas',
    'hafalan' => 'Setoran hafalan',
];
wali_layout_head(($tabTitles[$tab] ?? 'Akademik') . ' — Portal Wali', true, 'akademik');
?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h1 class="h5 mb-0 wali-brand fw-bold">Akademik</h1>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>

        <?php wali_portal_render_hub_tabs(wali_akademik_hub_tabs($tab), $tab); ?>

        <?php if ($tab === 'hafalan'): ?>
            <?php require __DIR__ . '/partials/akademik_tab_hafalan.php'; ?>
        <?php elseif ($tab === 'nilai_tugas'): ?>
            <?php require __DIR__ . '/partials/akademik_tab_tugas.php'; ?>
        <?php else: ?>
            <?php
            $raporJenisFilter = $tab === 'rapor_pkpps' ? 'pkpps' : 'pesantren';
            require __DIR__ . '/partials/akademik_tab_rapor.php';
            ?>
        <?php endif; ?>
<?php
wali_layout_foot(true, 'akademik');
