<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var array<string, mixed> $tagihanKumulatif */
/** @var array<string, mixed> $berjalan */
/** @var list<array<string, mixed>> $rowsTagihan */
/** @var array<string, int> $totalsRow */

$compact = false;
$hideTagihanLink = true;
require __DIR__ . '/tagihan_ringkasan.php';
?>
<div class="card shadow-sm wali-card">
    <div class="card-header bg-white small fw-semibold text-muted">
        Rincian per bulan (s.d. <?= htmlspecialchars((string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'] ?? '')) ?>)
    </div>
    <div class="card-body p-0">
        <?php $mode = 'wali'; require __DIR__ . '/../../includes/partials/tagihan_bulanan_tabel.php'; ?>
    </div>
</div>
<p class="small text-muted mt-2 mb-0">Bulan setelah periode berjalan belum ditagihkan. Wajib: Syahriyah. Makan &amp; Saku opsional.</p>
