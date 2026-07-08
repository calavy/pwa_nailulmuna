<?php

declare(strict_types=1);

/** @var array<string, mixed> $snapshot */

$kasBank = is_array($snapshot['kas_bank'] ?? null) ? $snapshot['kas_bank'] : [];
$rekapKas = is_array($snapshot['rekap_kas'] ?? null) ? $snapshot['rekap_kas'] : [];
$bulanan = is_array($snapshot['bulanan'] ?? null) ? $snapshot['bulanan'] : [];
$awal = is_array($snapshot['awal_tahun'] ?? null) ? $snapshot['awal_tahun'] : [];
$rekapTa = is_array($snapshot['rekap_ta']['total'] ?? null) ? $snapshot['rekap_ta']['total'] : [];

?>
<div class="row g-2 mb-3">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100 border-primary">
            <div class="card-body">
                <div class="small text-muted mb-1">
                    <i class="fa-solid fa-vault me-1"></i>Saldo kas & bank terkini
                    <span class="text-muted">(per <?= htmlspecialchars((string) ($snapshot['as_of_label'] ?? '')) ?>)</span>
                </div>
                <div class="h4 mb-2 text-primary">Rp <?= number_format((int) ($kasBank['total'] ?? 0), 0, ',', '.') ?></div>
                <div class="small">
                    Kas: <strong>Rp <?= number_format((int) ($kasBank['total_kas'] ?? 0), 0, ',', '.') ?></strong>
                    · Bank: <strong>Rp <?= number_format((int) ($kasBank['total_bank'] ?? 0), 0, ',', '.') ?></strong>
                </div>
                <?php if (!empty($kasBank['akun'])): ?>
                    <ul class="list-unstyled small mb-2 mt-2">
                        <?php foreach ($kasBank['akun'] as $ak): ?>
                            <li class="d-flex justify-content-between gap-2">
                                <span class="text-truncate"><?= htmlspecialchars((string) ($ak['nama'] ?? '-')) ?></span>
                                <span class="text-nowrap">Rp <?= number_format((int) ($ak['saldo'] ?? 0), 0, ',', '.') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>" class="small">Rekap kas bulanan →</a>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-tagihan">
                    <div class="app-mini-stat-label">Piutang bulanan<br><span class="fw-normal"><?= htmlspecialchars((string) ($snapshot['bulan_label'] ?? '')) ?></span></div>
                    <div class="app-mini-stat-value text-danger">Rp <?= number_format((int) ($bulanan['sisa'] ?? 0), 0, ',', '.') ?></div>
                    <div class="small text-muted">dari Rp <?= number_format((int) ($bulanan['expected'] ?? 0), 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-belum">
                    <div class="app-mini-stat-label">Piutang awal tahun</div>
                    <div class="app-mini-stat-value text-danger">Rp <?= number_format((int) ($awal['sisa'] ?? 0), 0, ',', '.') ?></div>
                    <div class="small text-muted">dari Rp <?= number_format((int) ($awal['expected'] ?? 0), 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-sebagian">
                    <div class="app-mini-stat-label">Total piutang tagihan</div>
                    <div class="app-mini-stat-value text-warning">Rp <?= number_format((int) ($snapshot['piutang_total'] ?? 0), 0, ',', '.') ?></div>
                    <div class="small text-muted">bulanan + awal tahun</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="app-mini-stat h-100 bendahara-stat-icon bendahara-stat-bayar">
                    <div class="app-mini-stat-label">Tertagih TA s.d. bulan ini</div>
                    <div class="app-mini-stat-value text-success"><?= (int) ($rekapTa['pct'] ?? 0) ?>%</div>
                    <div class="small text-muted">Rp <?= number_format((int) ($rekapTa['paid'] ?? 0), 0, ',', '.') ?> / <?= number_format((int) ($rekapTa['expected'] ?? 0), 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body py-2 px-3 small d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <span>
                            <i class="fa-solid fa-scale-balanced me-1 text-muted"></i>
                            Saldo rekap kas TA: <strong>Rp <?= number_format((int) ($rekapKas['saldo_akhir'] ?? 0), 0, ',', '.') ?></strong>
                            <?php if ((int) ($rekapKas['selisih_saldo'] ?? 0) !== 0): ?>
                                <span class="badge text-bg-warning ms-1">selisih Rp <?= number_format(abs((int) $rekapKas['selisih_saldo']), 0, ',', '.') ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="text-muted">
                            Masuk: Rp <?= number_format((int) ($rekapKas['masuk_total'] ?? 0), 0, ',', '.') ?>
                            · Keluar: Rp <?= number_format((int) ($rekapKas['keluar'] ?? 0), 0, ',', '.') ?>
                        </span>
                        <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>" class="text-nowrap">Dashboard keuangan →</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
