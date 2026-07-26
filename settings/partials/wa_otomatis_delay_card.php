<?php

declare(strict_types=1);

/** @var string $delayKind */
/** @var array<string, string> $values */

$delayMeta = wa_otomatis_delay_kinds()[$delayKind] ?? null;
if ($delayMeta === null) {
    return;
}
$delayFieldName = $delayMeta['key'];
$delayFieldValue = (string) ($values[$delayFieldName] ?? '');
?>
<div class="card shadow-sm border-0 mb-3 border-secondary-subtle">
    <div class="card-body py-3">
        <form method="post" class="row g-3 align-items-end mb-0">
            <input type="hidden" name="action" value="save_wa_delay">
            <input type="hidden" name="delay_kind" value="<?= htmlspecialchars($delayKind) ?>">
            <input type="hidden" name="redirect_tab" value="<?= htmlspecialchars($delayMeta['tab']) ?>">
            <?php
            require __DIR__ . '/wa_otomatis_delay_field.php';
            ?>
            <div class="col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Simpan delay</button>
            </div>
        </form>
    </div>
</div>
