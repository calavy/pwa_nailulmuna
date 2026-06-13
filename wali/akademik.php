<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_rapor.php';

ensure_akademik_rapor_columns($pdo);
ensure_akademik_hafalan_setoran_table($pdo);

$tab = trim((string) ($_GET['tab'] ?? 'rapor'));
if (!in_array($tab, ['rapor', 'hafalan'], true)) {
    $tab = 'rapor';
}

require_once __DIR__ . '/includes/layout.php';

$tabTitles = [
    'rapor' => 'Rapor akademik',
    'hafalan' => 'Setoran hafalan',
];
wali_layout_head(($tabTitles[$tab] ?? 'Akademik') . ' — Portal Wali', true, 'akademik');
?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h1 class="h5 mb-0 wali-brand fw-bold">Akademik</h1>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>

        <?php wali_portal_render_hub_tabs(wali_akademik_hub_tabs($tab), $tab); ?>

        <?php if ($tab === 'rapor'): ?>
            <?php require __DIR__ . '/partials/akademik_tab_rapor.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/partials/akademik_tab_hafalan.php'; ?>
        <?php endif; ?>
<?php
wali_layout_foot(true, 'akademik');
