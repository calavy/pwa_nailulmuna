<?php

declare(strict_types=1);

/**
 * Filter rentang periode Masehi (dari bulan X s/d bulan Y).
 *
 * @var string $formAction
 * @var array<string,mixed> $periodeRange
 * @var bool $filterInline
 * @var array<string,scalar> $hiddenParams
 */
$formAction = $formAction ?? app_href('/keuangan-bos/index.php');
$filterInline = !empty($filterInline);
$hiddenParams = $hiddenParams ?? [];
$periodeRange = $periodeRange ?? bos_resolve_periode_range($_GET ?? null);
$bulanMap = bos_bulan_masehi_map();
$tahunMin = (int) date('Y') - 2;
$tahunMax = (int) date('Y') + 1;

$renderFields = static function () use ($bulanMap, $periodeRange, $tahunMin, $tahunMax): void {
    ?>
    <div class="col-auto">
        <label class="form-label small mb-0">Dari bulan</label>
        <select name="bulan_mulai" class="form-select form-select-sm">
            <?php foreach ($bulanMap as $num => $nama): ?>
                <option value="<?= (int) $num ?>" <?= (int) ($periodeRange['bulan_mulai'] ?? 1) === (int) $num ? 'selected' : '' ?>>
                    <?= htmlspecialchars($nama) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">Tahun</label>
        <select name="tahun_mulai" class="form-select form-select-sm">
            <?php for ($y = $tahunMax; $y >= $tahunMin; $y--): ?>
                <option value="<?= $y ?>" <?= (int) ($periodeRange['tahun_mulai'] ?? 0) === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-auto d-flex align-items-end pb-1">
        <span class="small text-muted">s/d</span>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">Sampai bulan</label>
        <select name="bulan_selesai" class="form-select form-select-sm">
            <?php foreach ($bulanMap as $num => $nama): ?>
                <option value="<?= (int) $num ?>" <?= (int) ($periodeRange['bulan_selesai'] ?? 0) === (int) $num ? 'selected' : '' ?>>
                    <?= htmlspecialchars($nama) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">Tahun</label>
        <select name="tahun_selesai" class="form-select form-select-sm">
            <?php for ($y = $tahunMax; $y >= $tahunMin; $y--): ?>
                <option value="<?= $y ?>" <?= (int) ($periodeRange['tahun_selesai'] ?? 0) === $y ? 'selected' : '' ?>><?= $y ?></option>
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
        <span class="badge bg-secondary">Rentang Masehi</span>
    </div>
</form>
