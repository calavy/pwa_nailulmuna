<?php

declare(strict_types=1);

/** @var array<string, array<string, string>> $tplDefs */
/** @var array<string, string> $tplValues */

?>
<form method="post">
    <input type="hidden" name="action" value="save_wa_templates">
    <?php foreach ($tplDefs as $slug => $meta): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h2 class="h6 mb-1"><?= htmlspecialchars((string) $meta['label']) ?></h2>
            <p class="small text-muted mb-2"><?= htmlspecialchars((string) $meta['hint']) ?></p>
            <p class="small mb-2"><strong>Placeholder:</strong> <code><?= htmlspecialchars((string) $meta['placeholders']) ?></code></p>
            <textarea class="form-control font-monospace" name="wa_tpl_<?= htmlspecialchars($slug) ?>" rows="5"><?= htmlspecialchars($tplValues[$slug]) ?></textarea>
        </div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan semua template</button>
</form>
