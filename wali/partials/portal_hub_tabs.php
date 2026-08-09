<?php

declare(strict_types=1);

/**
 * @param list<array{key:string,label:string,href?:string}> $tabs
 */
function wali_portal_render_hub_tabs(array $tabs, string $activeKey): void
{
    ?>
<nav class="wali-portal-tabs mb-3" aria-label="Sub-menu">
    <?php foreach ($tabs as $tab):
        $key = (string) ($tab['key'] ?? '');
        $href = (string) ($tab['href'] ?? '#');
        ?>
    <a href="<?= htmlspecialchars($href) ?>" class="<?= $activeKey === $key ? 'active' : '' ?>"><?= htmlspecialchars((string) ($tab['label'] ?? '')) ?></a>
    <?php endforeach; ?>
</nav>
    <?php
}

/** @return list<array{key:string,label:string,href:string}> */
function wali_keuangan_hub_tabs(string $activeTab, string $querySuffix = ''): array
{
    $q = $querySuffix !== '' ? ('&' . ltrim($querySuffix, '&')) : '';
    $base = app_href('/wali/keuangan.php');

    return [
        ['key' => 'ringkasan', 'label' => 'Ringkasan', 'href' => $base . '?tab=ringkasan' . $q],
        ['key' => 'tagihan', 'label' => 'Tagihan bulanan', 'href' => $base . '?tab=tagihan' . $q],
        ['key' => 'tagihan_lain', 'label' => 'Tagihan lain', 'href' => $base . '?tab=tagihan_lain' . $q],
        ['key' => 'bayar', 'label' => 'Riwayat bayar', 'href' => $base . '?tab=bayar' . $q],
    ];
}

/** @return list<array{key:string,label:string,href:string}> */
function wali_akademik_hub_tabs(string $activeTab): array
{
    $base = app_href('/wali/akademik.php');

    return [
        ['key' => 'rapor_pesantren', 'label' => 'Rapor Pesantren', 'href' => $base . '?tab=rapor_pesantren'],
        ['key' => 'rapor_pkpps', 'label' => 'Rapor PKPPS', 'href' => $base . '?tab=rapor_pkpps'],
        ['key' => 'nilai_tugas', 'label' => 'Nilai Tugas', 'href' => $base . '?tab=nilai_tugas'],
        ['key' => 'hafalan', 'label' => 'Hafalan', 'href' => $base . '?tab=hafalan'],
    ];
}
