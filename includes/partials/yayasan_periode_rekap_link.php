<?php

declare(strict_types=1);

/**
 * Tautan ke modul Rekap untuk ganti periode (tidak di portal Yayasan).
 *
 * @var string $ypRekapLabel
 * @var string $ypRekapHref
 * @var string $ypRekapNote
 */
if (!function_exists('yayasan_rekap_keaktifan_href')) {
    require_once __DIR__ . '/../../helpers/yayasan.php';
}
$label = $ypRekapLabel ?? 'Rekap keaktifan lengkap';
$href = $ypRekapHref ?? yayasan_rekap_keaktifan_href();
$note = $ypRekapNote ?? 'Ganti bulan/tahun dan lihat detail per santri & kegiatan di modul Rekap — portal Yayasan hanya menampilkan ringkasan bulan berjalan.';
?>
<div class="alert alert-light border small mb-3 py-2 yp-periode-rekap-hint">
    <i class="fa-solid fa-calendar-check text-primary me-1"></i>
    <strong><?= htmlspecialchars((string) ($periodeLabel ?? 'Bulan berjalan')) ?></strong>
    — <?= htmlspecialchars($note) ?>
    <a class="alert-link ms-1" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?> <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
</div>
