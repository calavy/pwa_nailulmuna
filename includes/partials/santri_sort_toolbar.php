<?php

declare(strict_types=1);

/**
 * Toolbar urutan daftar santri (NIS / tingkatan / nama) — preferensi disimpan di sesi.
 *
 * @var array<string, scalar|null> $santriSortPreserve GET yang dipertahankan saat ganti urutan
 */
if (!function_exists('santri_list_sort_mode')) {
    require_once __DIR__ . '/../../helpers/santri_list_sort.php';
}

$currentSort = santri_list_sort_mode($_GET['santri_sort'] ?? null);
$preserve = [];
if (!empty($santriSortPreserve) && is_array($santriSortPreserve)) {
    foreach ($santriSortPreserve as $k => $v) {
        if (!is_string($k) || $k === 'santri_sort' || !is_scalar($v)) {
            continue;
        }
        $preserve[$k] = $v;
    }
} elseif (isset($santriSortPreserve)) {
    $preserve = is_array($santriSortPreserve) ? $santriSortPreserve : [];
} else {
    foreach ($_GET as $k => $v) {
        if (!is_string($k) || $k === 'santri_sort' || !is_scalar($v)) {
            continue;
        }
        $preserve[$k] = $v;
    }
}
?>
<div class="santri-sort-toolbar d-flex flex-wrap align-items-center gap-2 mb-2" role="group" aria-label="Urutan daftar santri">
    <span class="small text-muted text-uppercase fw-semibold">Urut:</span>
    <?php foreach (santri_list_sort_modes() as $mode): ?>
        <?php
        $active = $mode === $currentSort;
        $qs = santri_list_sort_query($mode, $preserve);
        $href = $qs !== '' ? ('?' . $qs) : ('?santri_sort=' . rawurlencode($mode));
        ?>
        <a href="<?= htmlspecialchars($href) ?>"
           class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
           <?= $active ? 'aria-current="true"' : '' ?>>
            <?= htmlspecialchars(santri_list_sort_label($mode)) ?>
        </a>
    <?php endforeach; ?>
</div>
