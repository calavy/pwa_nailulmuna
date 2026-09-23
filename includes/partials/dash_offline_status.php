<?php

declare(strict_types=1);

/**
 * Pill status online/offline + antrian sinkron (diisi assets/js/offline-sync.js).
 *
 * @var bool $dashOfflineCompact satu baris untuk home portal pembimbing
 */
$dashOfflineCompact = !empty($dashOfflineCompact);
?>
<?php if (!$dashOfflineCompact): ?>
<div class="dash-status-strip dash-status-strip--offline-sync mt-3">
    <span class="dash-status-pill dash-status-pill--ok" id="dash-system-pill">
        <i class="fa-solid fa-signal" aria-hidden="true"></i>
        Status sistem: <strong id="dash-system-status">Normal Online</strong>
    </span>
</div>
<?php else: ?>
<span class="visually-hidden" id="dash-system-pill" aria-hidden="true"></span>
<span class="visually-hidden" id="dash-system-status">Normal Online</span>
<?php endif; ?>
<div class="dash-sync-footer<?= $dashOfflineCompact ? ' dash-status-compact' : '' ?>" id="dash-sync-footer">
    <?php if ($dashOfflineCompact): ?>
    <span class="dash-sync-compact-dot" id="dash-sync-compact-dot" aria-hidden="true"></span>
    <?php endif; ?>
    <span id="dash-sync-text"><?= htmlspecialchars($dashOfflineCompact ? 'Sistem Online & Sinkron Otomatis' : 'Sistem sinkronisasi otomatis aktif · data real-time') ?></span>
    <?php if (!$dashOfflineCompact): ?>
    <span class="dash-sync-footer__badge" id="dash-sync-badge"><i class="fa-solid fa-circle" aria-hidden="true"></i> Connected</span>
    <?php endif; ?>
</div>
