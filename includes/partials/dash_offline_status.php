<?php

declare(strict_types=1);

/**
 * Pill status online/offline + antrian sinkron (diisi assets/js/offline-sync.js).
 */
?>
<div class="dash-status-strip dash-status-strip--offline-sync mt-3">
    <span class="dash-status-pill dash-status-pill--ok" id="dash-system-pill">
        <i class="fa-solid fa-signal" aria-hidden="true"></i>
        Status sistem: <strong id="dash-system-status">Normal Online</strong>
    </span>
</div>
<div class="dash-sync-footer" id="dash-sync-footer">
    <span id="dash-sync-text">Sistem sinkronisasi otomatis aktif · data real-time</span>
    <span class="dash-sync-footer__badge" id="dash-sync-badge"><i class="fa-solid fa-circle" aria-hidden="true"></i> Connected</span>
</div>
