<?php

declare(strict_types=1);

/**
 * Tabel tagihan bulanan — wajib Syahriyah; kolom Makan opsional.
 *
 * @var list<array<string, mixed>> $rowsTagihan
 * @var string $mode 'wali'|'staff'
 */

$rowsTagihan = $rowsTagihan ?? [];
$mode = ($mode ?? 'wali') === 'staff' ? 'staff' : 'wali';
?>
<div class="table-responsive">
    <table class="table table-sm mb-0 align-middle<?= $mode === 'staff' ? ' table-striped table-hover' : '' ?>">
        <thead class="table-light">
            <tr>
                <th>Bulan</th>
                <?php if ($mode === 'wali'): ?>
                    <th class="text-end">Syahriyah</th>
                    <th class="text-end">Makan <span class="text-muted fw-normal">(ops.)</span></th>
                <?php else: ?>
                    <th class="text-end">Tagihan</th>
                    <th class="text-end">Bayar</th>
                <?php endif; ?>
                <th class="text-end">Sisa</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rowsTagihan as $rw): ?>
            <?php $isBulanIni = !empty($rw['is_bulan_ini']); ?>
            <tr class="<?= $isBulanIni ? 'table-primary' : '' ?>">
                <td class="small">
                    <?= htmlspecialchars((string) $rw['label']) ?>
                    <?php if ($isBulanIni): ?>
                        <span class="badge text-bg-primary ms-1" style="font-size:.65rem">Bulan ini</span>
                    <?php endif; ?>
                </td>
                <?php if ($mode === 'wali'): ?>
                    <td class="text-end font-monospace small">
                        <?php if ((int) ($rw['sy_expected'] ?? 0) > 0): ?>
                            <?= (int) ($rw['sy_paid'] ?? 0) > 0 ? 'Rp ' . number_format((int) $rw['sy_paid'], 0, ',', '.') : '—' ?>
                            <span class="text-muted">/ <?= number_format((int) $rw['sy_expected'], 0, ',', '.') ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end font-monospace small">
                        <?php if ((int) ($rw['mk_expected'] ?? 0) > 0): ?>
                            <?= (int) ($rw['mk_paid'] ?? 0) > 0 ? 'Rp ' . number_format((int) $rw['mk_paid'], 0, ',', '.') : '—' ?>
                            <span class="text-muted">/ <?= number_format((int) $rw['mk_expected'], 0, ',', '.') ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                <?php else: ?>
                    <td class="text-end font-monospace small"><?= (int) ($rw['tagihan'] ?? 0) > 0 ? 'Rp ' . number_format((int) $rw['tagihan'], 0, ',', '.') : '—' ?></td>
                    <td class="text-end font-monospace small text-success"><?= (int) ($rw['bayar'] ?? 0) > 0 ? 'Rp ' . number_format((int) $rw['bayar'], 0, ',', '.') : '—' ?></td>
                <?php endif; ?>
                <td class="text-end font-monospace small <?= (int) ($rw['sisa'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>">
                    <?= (int) ($rw['sisa'] ?? 0) > 0 ? 'Rp ' . number_format((int) $rw['sisa'], 0, ',', '.') : ((int) ($rw['tagihan'] ?? 0) > 0 ? '0' : '—') ?>
                </td>
                <td class="text-center"><span class="badge text-bg-<?= htmlspecialchars((string) ($rw['badge'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($rw['status'] ?? '—')) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
