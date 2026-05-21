<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

$berjalan = keuangan_periode_berjalan($pdo);
$rowsTagihan = keuangan_tagihan_bulanan_rows($pdo, $waliSantriId, $waliKelasKategori);

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Tagihan bulanan — Portal Wali', true, 'tagihan');
require __DIR__ . '/partials/greeting.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h5 mb-0 wali-brand fw-bold">Tagihan TA <?= htmlspecialchars($berjalan['ta_label']) ?></h1>
            <a class="btn btn-sm btn-outline-secondary" href="/wali/logout.php">Keluar</a>
        </div>
        <p class="small text-muted mb-3">
            <span class="badge text-bg-primary me-1">Bulan berjalan</span>
            <strong><?= htmlspecialchars((string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'])) ?></strong>
            · Wajib: Syahriyah &amp; Makan. Saku opsional (cashless).
            <a href="/wali/pembayaran.php">Riwayat Keuangan</a>.
        </p>

        <div class="card shadow-sm wali-card">
            <div class="card-body p-0">
                <?php $mode = 'wali'; require __DIR__ . '/../includes/partials/tagihan_bulanan_tabel.php'; ?>
            </div>
        </div>
<?php
wali_layout_foot(true, 'tagihan');
