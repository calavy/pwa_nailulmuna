<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var array<int, array<string, mixed>> $waliAnakRows */
/** @var int $waliSantriId */
/** @var string|null $waliSwitcherRedirect */
/** @var string $waliSwitcherLayout strip|list */

if (!isset($waliAnakRows) || count($waliAnakRows) < 2) {
    return;
}
if (!function_exists('santri_foto_render_avatar')) {
    require_once __DIR__ . '/../../helpers/santri_foto.php';
}
$redir = isset($waliSwitcherRedirect) && is_string($waliSwitcherRedirect)
    ? wali_portal_safe_redirect_path($waliSwitcherRedirect)
    : wali_portal_safe_redirect_path((string) ($_SERVER['REQUEST_URI'] ?? '/wali/index.php'));
$layout = (($waliSwitcherLayout ?? 'strip') === 'list') ? 'list' : 'strip';
?>
<?php if ($layout === 'list'): ?>
<div class="wali-anak-list card shadow-sm border-0 mb-3 overflow-hidden">
    <div class="card-body py-3 px-3">
        <div class="small text-muted text-uppercase fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.7rem;">Pilih santri</div>
        <p class="small text-muted mb-2">Anda memiliki lebih dari satu anak. Pilih santri yang ingin dilihat.</p>
        <div class="list-group list-group-flush wali-anak-list__items">
            <?php foreach ($waliAnakRows as $an): ?>
                <?php
                $aid = (int) ($an['id'] ?? 0);
                $anRow = [
                    'nama_tampil' => (string) ($an['nama_tampil'] ?? ''),
                    'nama_santri' => (string) ($an['nama_tampil'] ?? ''),
                    'foto_profil' => $an['foto_profil'] ?? '',
                    'jenis_kelamin' => $an['jenis_kelamin'] ?? null,
                ];
                $isActive = $aid === $waliSantriId;
                $tingkat = trim((string) ($an['tingkatan'] ?? ''));
                ?>
                <form method="post" class="m-0">
                    <input type="hidden" name="wali_pilih_anak" value="1">
                    <input type="hidden" name="santri_id" value="<?= $aid ?>">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                    <button type="submit" class="list-group-item list-group-item-action d-flex align-items-center gap-2 px-2 py-2 wali-anak-list__btn<?= $isActive ? ' active' : '' ?>">
                        <?= santri_foto_render_avatar($anRow, 'app-user-avatar--table portal-avatar--pill') ?>
                        <span class="flex-grow-1 min-w-0 text-start">
                            <span class="d-block fw-semibold"><?= htmlspecialchars((string) ($an['nama_tampil'] ?? '')) ?></span>
                            <span class="d-block small text-muted">
                                NIS <?= htmlspecialchars((string) ($an['nis'] ?? '—')) ?>
                                <?php if ($tingkat !== ''): ?> · <?= htmlspecialchars($tingkat) ?><?php endif; ?>
                            </span>
                        </span>
                        <?php if ($isActive): ?>
                            <i class="fa-solid fa-check text-success flex-shrink-0" aria-hidden="true"></i>
                            <span class="visually-hidden">Sedang dipilih</span>
                        <?php endif; ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="wali-anak-strip card shadow-sm border-0 mb-3 overflow-hidden">
    <div class="card-body py-2 px-3">
        <div class="small text-muted text-uppercase fw-semibold mb-2" style="letter-spacing:0.06em;font-size:0.7rem;">Anak Anda</div>
        <div class="d-flex gap-2 flex-nowrap overflow-auto pb-1" style="-webkit-overflow-scrolling:touch;scrollbar-width:thin;">
            <?php foreach ($waliAnakRows as $an): ?>
                <?php
                $aid = (int) ($an['id'] ?? 0);
                $anRow = [
                    'nama_tampil' => (string) ($an['nama_tampil'] ?? ''),
                    'nama_santri' => (string) ($an['nama_tampil'] ?? ''),
                    'foto_profil' => $an['foto_profil'] ?? '',
                    'jenis_kelamin' => $an['jenis_kelamin'] ?? null,
                ];
                ?>
                <form method="post" class="flex-shrink-0 m-0">
                    <input type="hidden" name="wali_pilih_anak" value="1">
                    <input type="hidden" name="santri_id" value="<?= $aid ?>">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redir) ?>">
                    <button type="submit" class="btn btn-sm rounded-pill px-2 py-1 <?= $aid === $waliSantriId ? 'btn-teal text-white' : 'btn-outline-secondary' ?> wali-anak-pill">
                        <?= santri_foto_render_avatar($anRow, 'app-user-avatar--table portal-avatar--pill') ?>
                        <span>
                            <span class="d-block fw-semibold small text-start" style="max-width:9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string) ($an['nama_tampil'] ?? '')) ?></span>
                            <span class="d-block font-monospace" style="font-size:0.65rem;opacity:.9"><?= htmlspecialchars((string) ($an['nis'] ?? '')) ?></span>
                        </span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
