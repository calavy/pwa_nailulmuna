<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var int $waliSantriId */

require_once __DIR__ . '/../../helpers/tagihan_khusus_wali.php';
require_once __DIR__ . '/../../helpers/keuangan_typography.php';

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$rows = tagihan_khusus_list_wali($pdo, $waliSantriId);
$ringkasan = tagihan_khusus_wali_ringkasan($pdo, $waliSantriId);
?>

<p class="small text-muted mb-3">
    Tagihan pengembalian dana yang dipinjamkan dari <strong>alokasi syahriyah</strong> pondok
    (mis. biaya berobat, obat, transport) — bukan tagihan syahriyah bulanan.
    Untuk tagihan syahriyah &amp; makan lihat tab <a href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=tagihan')) ?>">Tagihan bulanan</a>.
</p>

<?php if ($ringkasan['count'] > 0): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <strong><?= (int) $ringkasan['count'] ?></strong> tagihan belum lunas · total sisa
        <strong><?= $formatRupiah((int) $ringkasan['total_sisa']) ?></strong>
    </div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="card shadow-sm wali-card">
        <div class="card-body text-muted small text-center py-4">Belum ada tagihan khusus untuk anak Anda.</div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($rows as $r):
            $sisa = tagihan_khusus_sisa($r);
            $paid = (int) round((float) ($r['nominal_dibayar'] ?? 0));
            $nom = (int) round((float) ($r['nominal'] ?? 0));
            ?>
            <div class="card shadow-sm wali-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($r['judul'] ?? '')) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars(tagihan_khusus_kategori_label((string) ($r['kategori'] ?? ''))) ?> · <?= htmlspecialchars((string) ($r['tanggal_tagihan'] ?? '')) ?></div>
                        </div>
                        <span class="badge text-bg-<?= tagihan_khusus_status_badge_class($r) ?>"><?= htmlspecialchars(tagihan_khusus_status_label($r)) ?></span>
                    </div>
                    <div class="row g-2 small mb-2">
                        <div class="col-4">
                            <div class="text-muted">Tagihan</div>
                            <div class="fw-semibold"><?= $formatRupiah($nom) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="text-muted">Dibayar</div>
                            <div><?= $formatRupiah($paid) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="text-muted">Sisa</div>
                            <div class="fw-semibold <?= $sisa > 0 ? 'text-danger' : 'text-success' ?>"><?= $formatRupiah($sisa) ?></div>
                        </div>
                    </div>
                    <?php if (trim((string) ($r['alokasi_nama'] ?? '')) !== ''): ?>
                        <p class="small text-muted mb-2">Dana dipinjamkan dari alokasi: <strong><?= htmlspecialchars((string) $r['alokasi_nama']) ?></strong></p>
                    <?php endif; ?>
                    <?php if (trim((string) ($r['keterangan'] ?? '')) !== ''): ?>
                        <p class="small border-start border-3 border-secondary ps-2 mb-0"><?= nl2br(htmlspecialchars((string) $r['keterangan'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
