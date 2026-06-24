<?php

declare(strict_types=1);

/**
 * Breadcrumb satu jalur: semua halaman Yayasan kembali ke operasional.
 *
 * @var string $yayasanCrumbTail label halaman saat ini (kosong = hanya "Yayasan")
 */
if (!function_exists('yayasan_home_href')) {
    require_once __DIR__ . '/../../helpers/yayasan.php';
}
$tail = isset($yayasanCrumbTail) ? trim((string) $yayasanCrumbTail) : '';
?>
<p class="page-intro-kicker mb-1">
    <a href="<?= htmlspecialchars(yayasan_home_href()) ?>">Yayasan</a><?php if ($tail !== ''): ?> · <?= htmlspecialchars($tail) ?><?php endif; ?>
</p>
