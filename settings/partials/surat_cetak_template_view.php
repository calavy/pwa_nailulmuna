<?php

declare(strict_types=1);

/** @var array<string, array<string, array{group:string, label:string, hint:string, placeholders:string, default:string}>> $tplGroups */
/** @var array<string, string> $tplValues */

?>
<form method="post">
    <input type="hidden" name="action" value="save_templates">
    <p class="text-muted small">Kosongkan isian lalu simpan untuk kembali ke teks bawaan. Placeholder dalam kurung kurawal diganti otomatis saat cetak.</p>
    <?php foreach ($tplGroups as $groupLabel => $fields): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?= htmlspecialchars($groupLabel) ?></h2>
            </div>
            <div class="card-body">
                <?php foreach ($fields as $slug => $meta): ?>
                    <div class="mb-4<?= $slug !== array_key_last($fields) ? '' : ' mb-0' ?>">
                        <label class="form-label fw-semibold"><?= htmlspecialchars((string) $meta['label']) ?></label>
                        <p class="small text-muted mb-1"><?= htmlspecialchars((string) $meta['hint']) ?></p>
                        <p class="small mb-2"><strong>Placeholder:</strong> <code><?= htmlspecialchars((string) $meta['placeholders']) ?></code></p>
                        <textarea class="form-control" name="surat_tpl_<?= htmlspecialchars($slug) ?>" rows="<?= str_contains($slug, 'pembuka') || str_contains($slug, 'penutup') ? '3' : '2' ?>"><?= htmlspecialchars($tplValues[$slug] ?? '') ?></textarea>
                        <details class="small mt-1">
                            <summary class="text-muted" style="cursor:pointer">Lihat teks bawaan</summary>
                            <pre class="small bg-light border rounded p-2 mt-1 mb-0"><?= htmlspecialchars((string) $meta['default']) ?></pre>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-success mb-4"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan semua template</button>
</form>
