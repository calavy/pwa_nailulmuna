
<div class="card shadow-sm mb-3 border-success border-opacity-25">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2 class="h5 mb-1"><i class="fa-solid fa-money-bill-transfer me-1 text-success"></i> Arus Kas — <?= htmlspecialchars((string) $lakRingkas['nama_lembaga']) ?></h2>
            <p class="small text-muted mb-0">Periode <?= htmlspecialchars((string) $lakRingkas['periode_label']) ?> · kenaikan kas <?= htmlspecialchars($formatRupiah((int) $lakRingkas['kenaikan_kas'])) ?>.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/pwa_nailulmuna/keuangan/arus-kas.php" class="btn btn-success">Arus kas lengkap</a>
            <a href="/pwa_nailulmuna/keuangan/arus-kas.php?dari=<?= urlencode((string) $lakRingkas['date_from']) ?>&amp;sampai=<?= urlencode((string) $lakRingkas['date_to']) ?>&amp;print=1" target="_blank" class="btn btn-outline-secondary">Cetak / PDF</a>
        </div>
    </div>
</div>
