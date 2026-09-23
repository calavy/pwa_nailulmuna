<?php

declare(strict_types=1);

/** @var bool $isMunawibPortal */
/** @var array<string,mixed>|null $munawibPortalKonteks */

if (empty($isMunawibPortal) || !is_array($munawibPortalKonteks ?? null)) {
    return;
}
?>
<div class="alert alert-info py-2 px-3 mb-2 small d-flex flex-wrap justify-content-between align-items-center gap-2 pb-dash-simple-munawib">
    <span>
        <i class="fa-solid fa-user-clock me-1"></i>
        Menggantikan <strong><?= htmlspecialchars((string) ($munawibPortalKonteks['pembimbing_nama'] ?? 'pembimbing')) ?></strong>
    </span>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/munawib_portal.php?reset=1')) ?>" class="btn btn-sm btn-outline-primary">Ganti pembimbing</a>
</div>
