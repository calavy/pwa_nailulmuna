<?php

declare(strict_types=1);

/**
 * Tampilan ringkas syarat ALPA untuk tabel perizinan.
 *
 * @var array<string, mixed> $alpaCek
 * @var string $mode table|detail
 */
$mode = (string) ($mode ?? 'table');
$alpaCek = is_array($alpaCek ?? null) ? $alpaCek : [];

if (empty($alpaCek['subject'])) {
    echo '<span class="text-muted small izin-alpa-na">—</span>';
    return;
}

$allowed = !empty($alpaCek['allowed']);
$status = (string) ($alpaCek['status'] ?? ($allowed ? 'ok' : 'blocked'));
$statusClass = $status === 'ok' ? 'success' : 'danger';
$statusLabel = (string) ($alpaCek['status_label'] ?? ($allowed ? 'Masih boleh' : 'Terhalang'));
$jumlahTeks = (string) ($alpaCek['jumlah_teks'] ?? ((int) ($alpaCek['alpa_count'] ?? 0) . ' kali ALPA'));
$periodeTeks = (string) ($alpaCek['periode_teks'] ?? ((int) ($alpaCek['hari'] ?? 0) . ' hari'));
$aturanSingkat = (string) ($alpaCek['aturan_singkat'] ?? '');
$aturanBlokir = (string) ($alpaCek['aturan_blokir'] ?? '');
$progressPct = (int) ($alpaCek['progress_pct'] ?? 0);
$progressLabel = (string) ($alpaCek['progress_label'] ?? '');
$catatan = trim((string) ($alpaCek['catatan'] ?? ''));
$penjelasanPlain = perizinan_alpa_penjelasan_plain($alpaCek);
?>
<div class="izin-alpa-cell izin-alpa-cell--<?= htmlspecialchars($mode) ?>" title="<?= htmlspecialchars($penjelasanPlain) ?>">
    <div class="izin-alpa-cell__head">
        <span class="badge text-bg-<?= $statusClass ?> izin-alpa-cell__badge">
            <?php if ($status === 'ok'): ?>
                <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
            <?php else: ?>
                <i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>
            <?php endif; ?>
            <?= htmlspecialchars($statusLabel) ?>
        </span>
    </div>
    <div class="izin-alpa-cell__stat">
        <strong><?= htmlspecialchars($jumlahTeks) ?></strong>
        <span class="text-muted">dalam <?= htmlspecialchars($periodeTeks) ?></span>
    </div>
    <?php if ($aturanSingkat !== ''): ?>
        <div class="izin-alpa-cell__rule text-muted"><?= htmlspecialchars($aturanSingkat) ?></div>
        <?php if ($aturanBlokir !== ''): ?>
            <div class="izin-alpa-cell__rule text-muted"><?= htmlspecialchars($aturanBlokir) ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($progressLabel !== '' && (int) ($alpaCek['max'] ?? 0) > 0): ?>
        <div class="izin-alpa-cell__progress mt-1" role="presentation">
            <div class="progress izin-alpa-progress" style="height:6px">
                <div class="progress-bar bg-<?= $statusClass ?>" style="width:<?= max(4, min(100, $progressPct)) ?>%"></div>
            </div>
            <div class="izin-alpa-cell__progress-label text-muted"><?= htmlspecialchars($progressLabel) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($mode === 'detail' && $catatan !== ''): ?>
        <div class="izin-alpa-cell__note small mt-1 <?= $allowed ? 'text-warning-emphasis' : 'text-danger' ?>">
            <?= htmlspecialchars($catatan) ?>
        </div>
    <?php endif; ?>
</div>
