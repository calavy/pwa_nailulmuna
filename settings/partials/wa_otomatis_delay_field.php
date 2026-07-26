<?php

declare(strict_types=1);

/** @var string $delayFieldName */
/** @var string $delayFieldValue */
/** @var string|null $delayFieldLabel */

$delayFieldLabel = $delayFieldLabel ?? 'Delay antar kirim Fonnte (detik)';
?>
<div class="col-md-6">
    <label class="form-label" for="<?= htmlspecialchars($delayFieldName) ?>"><?= htmlspecialchars($delayFieldLabel) ?></label>
    <input type="text" class="form-control font-monospace" id="<?= htmlspecialchars($delayFieldName) ?>"
        name="<?= htmlspecialchars($delayFieldName) ?>"
        value="<?= htmlspecialchars($delayFieldValue) ?>"
        placeholder="3 atau 3-8 — kosong = default Gateway">
    <div class="form-text">
        Parameter <code>delay</code> di API Fonnte untuk kategori ini.
        Contoh: <code>3</code> (tetap) atau <code>5-10</code> (acak).
        Kosongkan untuk memakai default di tab Gateway.
    </div>
</div>
