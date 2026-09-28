<?php

declare(strict_types=1);

/**
 * Kartu ringkasan keuangan sebelum/sesudah keluar.
 *
 * @var array<string, mixed> $ringkasan from santri_keuangan_ringkasan_exit()
 * @var int $santriId
 * @var string $context optional: nonaktif|edit|mukimin
 */
$ringkasan = is_array($ringkasan ?? null) ? $ringkasan : [];
$santriId = (int) ($santriId ?? ($ringkasan['santri_id'] ?? 0));
$context = (string) ($context ?? 'nonaktif');
$totalSisa = (int) ($ringkasan['total_sisa_tagihan'] ?? 0);
$cashless = (int) ($ringkasan['cashless_saldo'] ?? 0);
$adaSisa = !empty($ringkasan['ada_sisa']);
$settled = trim((string) ($ringkasan['keluar_settled_at'] ?? '')) !== '';
$keluarHref = app_href('/santri/keluar.php?id=' . $santriId);
$riwayatHref = app_href('/keuangan/riwayat_pembayaran.php?santri_id=' . $santriId);
$suratHref = app_href('/santri/surat_keluar.php?id=' . $santriId);
?>
<div class="card shadow-sm mb-3 border-0 <?= $adaSisa && !$settled ? 'border-warning border' : '' ?>">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <h2 class="h6 mb-0">Ringkasan keuangan</h2>
            <?php if ($settled): ?>
                <span class="badge text-bg-success">Keuangan selesai</span>
            <?php elseif ($adaSisa): ?>
                <span class="badge text-bg-warning text-dark">Masih ada sisa</span>
            <?php else: ?>
                <span class="badge text-bg-secondary">Belum administrasi keluar</span>
            <?php endif; ?>
        </div>
        <?php if (!$settled): ?>
            <?php require __DIR__ . '/santri_keuangan_exit_verdict_cards.php'; ?>
        <div class="alert alert-warning py-2 small mb-3">
            <?php if ($context !== 'mukimin'): ?>
                Riwayat pembayaran tetap tersimpan di modul keuangan.
            <?php endif; ?>
            <strong>Kekurangan</strong> tagihan dilunasi otomatis (cashless ke keuangan pesantren, sisanya administrasi keluar).
            <strong>Sisa uang saku</strong> cashless <strong>dikembalikan</strong>.
            Proses resmi lewat <a href="<?= htmlspecialchars($keluarHref) ?>">Administrasi keluar</a> — semua tercatat di keuangan &amp; cashless.
        </div>
        <?php endif; ?>
        <div class="row g-2 small">
            <div class="col-sm-4">
                <div class="p-2 rounded border bg-light h-100">
                    <div class="text-muted text-uppercase" style="font-size:0.65rem;letter-spacing:0.06em;">Kekurangan tagihan</div>
                    <div class="fs-6 fw-bold font-monospace">Rp <?= number_format($totalSisa, 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="p-2 rounded border bg-light h-100">
                    <div class="text-muted text-uppercase" style="font-size:0.65rem;letter-spacing:0.06em;">Sisa uang saku (cashless)</div>
                    <div class="fs-6 fw-bold font-monospace">Rp <?= number_format($cashless, 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="p-2 rounded border bg-light h-100">
                    <div class="text-muted text-uppercase" style="font-size:0.65rem;letter-spacing:0.06em;">Baris tunggakan</div>
                    <div class="fs-6 fw-bold"><?= (int) ($ringkasan['outstanding_count'] ?? 0) ?></div>
                </div>
            </div>
        </div>
        <?php if (!$settled): ?>
            <?php
            $showPrintLink = ($context === 'mukimin' || $context === 'nonaktif' || $context === 'edit');
            $showDetailHeader = false;
            require __DIR__ . '/santri_keuangan_exit_detail.php';
            ?>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($riwayatHref) ?>">Riwayat pembayaran</a>
            <?php if (!$settled): ?>
                <a class="btn btn-sm btn-outline-danger" href="<?= htmlspecialchars($keluarHref) ?>">Administrasi keluar</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= htmlspecialchars($suratHref) ?>">Surat keluar</a>
            <?php endif; ?>
        </div>
        <?php if ($settled && trim((string) ($ringkasan['keluar_ringkasan_keuangan'] ?? '')) !== ''): ?>
        <div class="mt-3 p-2 rounded border bg-white small">
            <strong>Catatan penutupan</strong>
            <pre class="mb-0 mt-1 small" style="white-space:pre-wrap;font-family:inherit;"><?= htmlspecialchars((string) $ringkasan['keluar_ringkasan_keuangan']) ?></pre>
        </div>
        <?php endif; ?>
    </div>
</div>
