<?php

declare(strict_types=1);

/**
 * Dua kartu ringkas: uang tagihan yang harus dibayar vs sisa saku yang harus dikembalikan.
 *
 * @var array<string, mixed> $ringkasan
 * @var bool $compact
 */
$ringkasan = is_array($ringkasan ?? null) ? $ringkasan : [];
$compact = !empty($compact);
$nominalBayar = (int) ($ringkasan['nominal_harus_dibayar'] ?? ($ringkasan['total_sisa_tagihan'] ?? 0));
$nominalKembali = (int) ($ringkasan['nominal_harus_dikembalikan'] ?? ($ringkasan['proyeksi_sisa_uang_setelah_tagihan'] ?? 0));
$cashless = (int) ($ringkasan['cashless_saldo'] ?? 0);
$proyeksiCashlessKeTagihan = (int) ($ringkasan['proyeksi_cashless_ke_tagihan'] ?? min($cashless, $nominalBayar));
$proyeksiSisaTagihan = (int) ($ringkasan['proyeksi_sisa_tagihan_setelah_cashless'] ?? max(0, $nominalBayar - $cashless));
$titleClass = $compact ? 'h6' : 'h5';
$amountClass = $compact ? 'fs-5' : 'fs-4';
?>
<div class="santri-keuangan-exit-verdict mb-3">
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100 border-warning shadow-sm">
                <div class="card-body">
                    <p class="text-muted text-uppercase small mb-1" style="font-size:0.65rem;letter-spacing:0.06em;">Uang yang harus dibayar</p>
                    <h3 class="<?= htmlspecialchars($titleClass) ?> mb-2">Tagihan (keuangan pesantren)</h3>
                    <div class="font-monospace fw-bold <?= htmlspecialchars($amountClass) ?> text-dark">Rp <?= number_format($nominalBayar, 0, ',', '.') ?></div>
                    <p class="small text-muted mb-0 mt-2">
                        <?php if ($nominalBayar <= 0): ?>
                            Tidak ada tagihan bulanan yang masih kurang.
                        <?php elseif ($proyeksiCashlessKeTagihan > 0): ?>
                            Dari saku cashless otomatis dipotong
                            <span class="font-monospace">Rp <?= number_format($proyeksiCashlessKeTagihan, 0, ',', '.') ?></span>
                            ke pembayaran tagihan.
                            <?php if ($proyeksiSisaTagihan > 0): ?>
                                Sisa tagihan
                                <span class="font-monospace">Rp <?= number_format($proyeksiSisaTagihan, 0, ',', '.') ?></span>
                                dilunasi saat administrasi keluar (tercatat di keuangan).
                            <?php else: ?>
                                Setelah potong cashless, tagihan lunas.
                            <?php endif; ?>
                        <?php else: ?>
                            Tidak ada saldo cashless; tagihan diselesaikan lewat administrasi keluar (tercatat di keuangan pesantren).
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 border-success shadow-sm">
                <div class="card-body">
                    <p class="text-muted text-uppercase small mb-1" style="font-size:0.65rem;letter-spacing:0.06em;">Uang yang harus dikembalikan</p>
                    <h3 class="<?= htmlspecialchars($titleClass) ?> mb-2">Sisa saku (cashless)</h3>
                    <div class="font-monospace fw-bold <?= htmlspecialchars($amountClass) ?> text-success">Rp <?= number_format($nominalKembali, 0, ',', '.') ?></div>
                    <p class="small text-muted mb-0 mt-2">
                        <?php if ($cashless <= 0): ?>
                            Tidak ada saldo saku cashless.
                        <?php elseif ($nominalBayar <= 0): ?>
                            Saldo saku saat ini
                            <span class="font-monospace">Rp <?= number_format($cashless, 0, ',', '.') ?></span>
                            — seluruhnya <strong>dikembalikan</strong> saat administrasi keluar.
                        <?php elseif ($nominalKembali > 0): ?>
                            Estimasi setelah tagihan dipotong dari saku; saldo saku saat ini
                            <span class="font-monospace">Rp <?= number_format($cashless, 0, ',', '.') ?></span>.
                        <?php else: ?>
                            Saldo saku dipakai untuk tagihan; tidak ada sisa uang untuk dikembalikan sebelum tagihan lunas.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <p class="small text-muted mb-0 mt-2">Penyelesaian otomatis saat <strong>Administrasi keluar</strong> — tercatat di modul keuangan pesantren dan cashless.</p>
</div>
