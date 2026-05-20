<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var array<int, array<string, mixed>> $waliAnakRows */
/** @var int $waliSantriId */
/** @var string|null $waliSwitcherRedirect */

if (!isset($waliAnakRows) || count($waliAnakRows) < 2) {
    return;
}
$redir = isset($waliSwitcherRedirect) && is_string($waliSwitcherRedirect)
    ? wali_portal_safe_redirect_path($waliSwitcherRedirect)
    : wali_portal_safe_redirect_path((string) ($_SERVER['REQUEST_URI'] ?? '/pwa_nailulmuna/wali/index.php'));
?>
<div class="wali-anak-strip card shadow-sm border-0 mb-3 overflow-hidden">
    <div class="card-body py-2 px-3">
        <div class="small text-muted text-uppercase fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.7rem;">Anak Anda</div>
        <div class="d-flex gap-2 flex-nowrap overflow-auto pb-1" style="-webkit-overflow-scrolling:touch;scrollbar-width:thin;">
            <?php foreach ($waliAnakRows as $an): ?>
                <?php $aid = (int) ($an['id'] ?? 0); ?>
                <form method="post" class="flex-shrink-0 m-0">
                    <input type="hidden" name="wali_pilih_anak" value="1">
                    <input type="hidden" name="santri_id" value="<?= $aid ?>">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                    <button type="submit" class="btn btn-sm rounded-pill px-3 <?= $aid === $waliSantriId ? 'btn-teal text-white' : 'btn-outline-secondary' ?> wali-anak-pill">
                        <span class="d-block fw-semibold small text-start" style="max-width:9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string) ($an['nama_tampil'] ?? '')) ?></span>
                        <span class="d-block font-monospace" style="font-size:0.65rem;opacity:.9"><?= htmlspecialchars((string) ($an['nis'] ?? '')) ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
