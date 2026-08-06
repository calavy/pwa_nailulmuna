<?php

declare(strict_types=1);

/**
 * Filter periode Masehi (Januari–Desember) untuk modul Keuangan BOS.
 *
 * @var PDO $pdo
 * @var array{bulan:int,tahun:int,label:string} $periodeMasehi
 * @var string $formAction
 * @var bool $showSemuaBulan
 * @var bool $filterInline
 * @var array<string,scalar> $hiddenParams
 */
$bulanMap = bos_bulan_masehi_map();
$formAction = $formAction ?? app_href('/keuangan-bos/index.php');
$showSemuaBulan = !empty($showSemuaBulan);
$filterInline = !empty($filterInline);
$hiddenParams = $hiddenParams ?? [];
$periodeMasehi = $periodeMasehi ?? bos_periode_masehi_berjalan();
$tahunMin = (int) date('Y') - 2;
$tahunMax = (int) date('Y') + 1;

$renderFields = static function () use ($bulanMap, $showSemuaBulan, $periodeMasehi, $tahunMin, $tahunMax): void {
    ?>
    <div class="col-auto">
        <label class="form-label small mb-0">Bulan (Masehi)</label>
        <select name="bulan" class="form-select form-select-sm">
            <?php if ($showSemuaBulan): ?>
                <option value="0" <?= (int) ($periodeMasehi['bulan'] ?? 0) === 0 ? 'selected' : '' ?>>Semua bulan</option>
            <?php endif; ?>
            <?php foreach ($bulanMap as $num => $nama): ?>
                <option value="<?= (int) $num ?>" <?= (int) ($periodeMasehi['bulan'] ?? 0) === (int) $num ? 'selected' : '' ?>>
                    <?= htmlspecialchars($nama) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">Tahun (Masehi)</label>
        <select name="tahun" class="form-select form-select-sm">
            <?php for ($y = $tahunMax; $y >= $tahunMin; $y--): ?>
                <option value="<?= $y ?>" <?= (int) ($periodeMasehi['tahun'] ?? 0) === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <?php
};

if ($filterInline) {
    $renderFields();
    return;
}
?>

<form method="get" action="<?= htmlspecialchars($formAction) ?>" class="row g-2 align-items-end">
    <?php foreach ($hiddenParams as $hk => $hv): ?>
        <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
    <?php endforeach; ?>
    <?php $renderFields(); ?>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-primary">Tampilkan</button>
    </div>
    <div class="col-auto">
        <span class="badge bg-secondary">Kalender Masehi</span>
    </div>
</form>
