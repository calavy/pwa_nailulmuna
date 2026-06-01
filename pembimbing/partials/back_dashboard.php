<?php

declare(strict_types=1);

/** @var string $homeUrl */
/** @var string|null $backLabel */
$backLabel = isset($backLabel) && trim((string) $backLabel) !== '' ? (string) $backLabel : 'Kembali ke dashboard';
?>
<a href="<?= htmlspecialchars($homeUrl) ?>" class="pb-back-dashboard">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
    <span><?= htmlspecialchars($backLabel) ?></span>
</a>
