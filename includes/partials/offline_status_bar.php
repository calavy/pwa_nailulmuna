<?php

declare(strict_types=1);

/**
 * Banner status offline + antrian belum terkirim (header app).
 */
$offlineBarPending = 0;
?>
<div id="pondok-offline-status-bar" class="pondok-offline-status-bar" hidden>
    <span class="pondok-offline-status-bar__icon" aria-hidden="true"><i class="fa-solid fa-wifi-slash"></i></span>
    <span class="pondok-offline-status-bar__text">Mode offline — input tersimpan lokal. Buka scan/poin sekali saat online agar halaman siap offline.</span>
    <span class="pondok-offline-status-bar__queue" id="pondok-offline-status-queue" hidden></span>
</div>
