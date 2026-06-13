<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var string $q */
/** @var list<array<string, mixed>> $anakRows */
/** @var list<array<string, mixed>> $waliAnakRows */
/** @var int $waliSantriId */
/** @var array<string, mixed> $detail */
/** @var array<string, mixed> $berjalan */
/** @var int $periodeMulai */
/** @var int $periodeSelesai */
/** @var int $totalTagihanTa */
/** @var int $totalBayarTa */
/** @var int $kurang */
/** @var list<array<string, mixed>> $ringkasanPosTa */
/** @var float|null $cashlessSaldo */
/** @var string $keuQuerySuffix */

?>
<p class="small text-muted">Cari nama atau NIS untuk memilih santri lain.</p>

<form method="get" class="input-group input-group-sm mb-3">
    <input type="hidden" name="tab" value="ringkasan">
    <input type="text" name="q" class="form-control" placeholder="NIS atau nama" value="<?= htmlspecialchars($q) ?>">
    <button class="btn btn-outline-secondary" type="submit">Cari</button>
</form>

<?php
$keuRedir = app_href('/wali/keuangan.php?tab=ringkasan' . ($q !== '' ? ('&q=' . rawurlencode($q)) : ''));
?>
<?php if (count($waliAnakRows) > 1 && $anakRows !== []): ?>
    <div class="list-group mb-3 small shadow-sm">
        <?php foreach ($anakRows as $a): ?>
            <form method="post" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                <input type="hidden" name="wali_pilih_anak" value="1">
                <input type="hidden" name="santri_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($keuRedir) ?>">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($a['nama_tampil'] ?? $a['nama_santri'] ?? '')) ?></div>
                    <div class="text-muted font-monospace"><?= htmlspecialchars((string) ($a['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($a['tingkatan'] ?? '')) ?></div>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-primary"><?= (int) $a['id'] === $waliSantriId ? 'Aktif' : 'Pilih' ?></button>
            </form>
        <?php endforeach; ?>
    </div>
<?php elseif ($q !== ''): ?>
    <div class="alert alert-light border small mb-3">Tidak ada santri yang cocok dalam data anak Anda.</div>
<?php endif; ?>

<div class="card shadow-sm wali-card mb-3 border-primary border-opacity-25">
    <div class="card-body">
        <div class="small text-uppercase text-muted fw-semibold mb-2" style="letter-spacing:0.06em;">Ringkasan keuangan</div>
        <div class="fw-bold"><?= htmlspecialchars((string) ($detail['nama_tampil'] ?? '')) ?></div>
        <div class="font-monospace small text-muted mb-3">NIS <?= htmlspecialchars((string) ($detail['nis'] ?? '')) ?></div>

        <div class="row g-2 text-center">
            <div class="col-4">
                <div class="rounded-3 bg-light py-2 px-1 h-100">
                    <div class="small text-muted">Tagihan s.d. bulan ini</div>
                    <div class="font-monospace fw-semibold" style="font-size:0.85rem;">Rp <?= number_format($totalTagihanTa, 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="rounded-3 bg-success bg-opacity-10 py-2 px-1 h-100">
                    <div class="small text-muted">Sudah dibayar</div>
                    <div class="font-monospace fw-semibold text-success" style="font-size:0.85rem;">Rp <?= number_format($totalBayarTa, 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="rounded-3 bg-danger bg-opacity-10 py-2 px-1 h-100">
                    <div class="small text-muted">Sisa</div>
                    <div class="font-monospace fw-semibold text-danger" style="font-size:0.85rem;">Rp <?= number_format($kurang, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0">
            Kumulatif bulan 1 s.d. <?= htmlspecialchars((string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'])) ?>
            · TA <?= (int) $periodeMulai ?>/<?= (int) $periodeSelesai ?> (Syahriyah wajib, Makan opsional).
        </p>
    </div>
</div>

<?php if ($ringkasanPosTa !== []): ?>
<div class="card shadow-sm wali-card mb-3">
    <div class="card-body">
        <div class="wali-kicker mb-2">Komponen terbayar (TA ini)</div>
        <ul class="list-unstyled small mb-0">
            <?php foreach ($ringkasanPosTa as $rp): ?>
                <li class="d-flex justify-content-between py-1 border-bottom">
                    <span><?= htmlspecialchars((string) ($rp['pos_nama'] ?? '')) ?></span>
                    <span class="font-monospace"><?= htmlspecialchars(wali_portal_format_rupiah((int) round((float) ($rp['total'] ?? 0)))) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($cashlessSaldo !== null): ?>
<div class="card shadow-sm wali-card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center py-3">
        <div>
            <div class="small text-muted">Saldo cashless (tabungan digital)</div>
            <div class="small">Top-up dari pembayaran Saku · batas belanja harian di pondok</div>
        </div>
        <span class="font-monospace fw-bold fs-5">Rp <?= number_format((int) round($cashlessSaldo), 0, ',', '.') ?></span>
    </div>
    <a class="btn btn-sm btn-outline-primary w-100 border-top rounded-0" href="/wali/cashless.php">
        <i class="fa-solid fa-list me-1"></i> Lihat log jajan &amp; top-up
    </a>
</div>
<?php endif; ?>

<div class="d-grid gap-2">
    <a class="btn btn-sm btn-teal" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=tagihan' . $keuQuerySuffix)) ?>"><i class="fa-solid fa-file-invoice me-1"></i> Lihat tagihan per bulan</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=bayar' . $keuQuerySuffix)) ?>"><i class="fa-solid fa-receipt me-1"></i> Riwayat pembayaran</a>
</div>
