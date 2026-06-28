<?php

declare(strict_types=1);

/**
 * Kartu satu rapor (wali) — dipakai daftar ringkas & halaman detail.
 *
 * @var array<string, mixed> $r
 * @var bool $isPkpps
 * @var bool $adaPdf
 * @var string $pdfLihatUrl
 * @var string $pdfUnduhUrl
 * @var bool $raporCompact
 * @var bool $raporShowDetailLink Tampilkan link ke halaman rincian (daftar)
 * @var string $raporDetailUrl
 */

$raporShowDetailLink = !empty($raporShowDetailLink);
$raporDetailUrl = (string) ($raporDetailUrl ?? '');
$raporCompact = !empty($raporCompact);
?>
<div class="card shadow-sm wali-card">
    <div class="card-body">
        <div class="d-flex justify-content-between gap-2 mb-2">
            <span class="fw-semibold"><?= htmlspecialchars((string) ($r['judul_periode'] ?? '')) ?></span>
            <span class="badge text-bg-<?= !empty($isPkpps) ? 'info' : 'success' ?>"><?= !empty($isPkpps) ? 'PKPPS' : 'Pesantren' ?></span>
        </div>
        <div class="small text-muted mb-2"><?= htmlspecialchars((string) ($r['tanggal_terbit'] ?? '')) ?></div>
        <?php if ($adaPdf): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a class="btn btn-sm btn-teal flex-grow-1" href="<?= htmlspecialchars($pdfLihatUrl) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-file-pdf me-1"></i> Lihat PDF
                </a>
                <a class="btn btn-sm btn-outline-secondary flex-grow-1" href="<?= htmlspecialchars($pdfUnduhUrl) ?>">
                    <i class="fa-solid fa-download me-1"></i> Unduh PDF
                </a>
            </div>
        <?php endif; ?>
        <?php if (trim((string) ($r['predikat_akhlak'] ?? '')) !== ''): ?>
            <div class="mb-2"><span class="badge text-bg-info text-dark"><?= htmlspecialchars((string) $r['predikat_akhlak']) ?></span></div>
        <?php endif; ?>
        <?php if (trim((string) ($r['narasi'] ?? '')) !== ''): ?>
            <div class="small text-body-secondary mb-2" style="white-space:pre-wrap;"><?= htmlspecialchars((string) $r['narasi']) ?></div>
        <?php endif; ?>
        <?php if (trim((string) ($r['catatan_pondok'] ?? '')) !== ''): ?>
            <div class="small border-start border-3 border-success ps-2 mb-2"><?= nl2br(htmlspecialchars((string) $r['catatan_pondok'])) ?></div>
        <?php endif; ?>
        <?php if ($raporShowDetailLink && $raporDetailUrl !== ''): ?>
            <a class="btn btn-sm btn-outline-primary w-100" href="<?= htmlspecialchars($raporDetailUrl) ?>">Lihat rincian nilai di aplikasi</a>
        <?php elseif (!$adaPdf && isset($raporPeriodeLabel)): ?>
            <?php require __DIR__ . '/../../includes/partials/rapor_isi.php'; ?>
        <?php elseif ($adaPdf): ?>
            <p class="small text-muted mb-0">Dokumen resmi rapor ada pada file PDF di atas.</p>
        <?php endif; ?>
    </div>
</div>
