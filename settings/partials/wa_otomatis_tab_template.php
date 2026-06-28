<?php

declare(strict_types=1);

/** @var array<string, array<string, string>> $tplDefs */
/** @var array<string, string> $tplValues */
/** @var bool $waRaporPesantrenOn */
/** @var bool $waRaporPkppsOn */

$raporSlugs = ['rapor_terbit_pesantren', 'rapor_terbit_pkpps'];

?>
<form method="post">
    <input type="hidden" name="action" value="save_wa_templates">
    <div class="card shadow-sm border-0 mb-3 border-start border-4 border-success">
        <div class="card-body">
            <h2 class="h6 mb-2">WA otomatis rapor ke wali</h2>
            <p class="small text-muted mb-2">Dikirim sekali saat rapor diterbitkan ke portal wali. Butuh <strong>Master WA otomatis</strong> aktif (tab Gateway).</p>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="wa_rapor_pesantren_enabled" id="wa_rapor_pesantren_enabled" value="1" <?= !empty($waRaporPesantrenOn) ? 'checked' : '' ?>>
                <label class="form-check-label" for="wa_rapor_pesantren_enabled">Rapor pesantren — kirim otomatis</label>
            </div>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="wa_rapor_pkpps_enabled" id="wa_rapor_pkpps_enabled" value="1" <?= !empty($waRaporPkppsOn) ? 'checked' : '' ?>>
                <label class="form-check-label" for="wa_rapor_pkpps_enabled">Rapor PKPPS — kirim otomatis</label>
            </div>
        </div>
    </div>
    <?php foreach ($tplDefs as $slug => $meta): ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h2 class="h6 mb-1"><?= htmlspecialchars((string) $meta['label']) ?></h2>
            <p class="small text-muted mb-2"><?= htmlspecialchars((string) $meta['hint']) ?></p>
            <p class="small mb-2"><strong>Placeholder:</strong> <code><?= htmlspecialchars((string) $meta['placeholders']) ?></code></p>
            <textarea class="form-control font-monospace" name="wa_tpl_<?= htmlspecialchars($slug) ?>" rows="<?= in_array($slug, $raporSlugs, true) ? 6 : 5 ?>"><?= htmlspecialchars($tplValues[$slug]) ?></textarea>
        </div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan semua template</button>
</form>
