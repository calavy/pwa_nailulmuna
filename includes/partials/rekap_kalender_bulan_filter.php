<?php

declare(strict_types=1);

/**
 * Filter bulan/tahun rekap (masehi atau hijriyah).
 *
 * @var array<string,mixed> $periode hasil rekap_resolve_periode()
 * @var string $formAction URL action (kosong = halaman ini)
 * @var array<string,string|int> $extraHidden query tambahan
 * @var bool $showRefresh tampilkan tombol refresh
 * @var string $refreshHref URL refresh
 * @var string $cardClass kelas wrapper card
 * @var bool $wrapCard apakah dibungkus card
 */
if (!isset($hijriMonths)) {
    require_once __DIR__ . '/../../helpers/hijri_kalender.php';
    $hijriMonths = hijri_nama_bulan_list();
}

$mode = (string) ($periode['mode'] ?? 'hijriyah');
$month = (int) ($periode['month'] ?? (int) date('n'));
$year = (int) ($periode['year'] ?? 0);
$periodeLabel = (string) ($periode['label'] ?? '');
$rentangTampilan = (string) ($periode['rentang_tampilan'] ?? '');
$formAction = $formAction ?? '';
$extraHidden = $extraHidden ?? [];
$showRefresh = !empty($showRefresh);
$refreshHref = $refreshHref ?? '';
$cardClass = $cardClass ?? 'card shadow-sm mb-4';
$wrapCard = $wrapCard ?? true;
$submitLabel = $submitLabel ?? 'Tampilkan';
$periodeNote = $periodeNote ?? '';

$openCard = $wrapCard ? '<div class="' . htmlspecialchars($cardClass) . ' rekap-periode-card"><div class="card-body">' : '';
$closeCard = $wrapCard ? '</div></div>' : '';
echo $openCard;
?>
<form method="get" action="<?= htmlspecialchars($formAction) ?>" class="rekap-periode-form">
    <div class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label class="form-label small mb-0">Kalender</label>
            <select class="form-select form-select-sm" name="mode">
                <option value="masehi" <?= $mode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                <option value="hijriyah" <?= $mode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-0">Bulan</label>
            <select class="form-select form-select-sm" name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>"<?= $month === $m ? ' selected' : '' ?>>
                        <?= htmlspecialchars($mode === 'hijriyah' ? ($hijriMonths[$m] ?? (string) $m) : sprintf('%02d', $m)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-0">Tahun</label>
            <input class="form-control form-control-sm" type="number" min="1300" max="2100" name="year" value="<?= htmlspecialchars((string) $year) ?>">
        </div>
        <?php foreach ($extraHidden as $hk => $hv): ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
        <?php endforeach; ?>
        <?php if (!empty($rekapPeriodeExtraSlot)) {
            echo $rekapPeriodeExtraSlot;
        } ?>
        <div class="col-md-auto col-6 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> <?= htmlspecialchars($submitLabel) ?></button>
            <?php if ($showRefresh && $refreshHref !== ''): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($refreshHref) ?>" title="Muat ulang data"><i class="fa-solid fa-rotate-right"></i></a>
            <?php endif; ?>
        </div>
        <div class="col-md">
            <p class="small text-muted mb-0">
                Periode: <strong><?= htmlspecialchars($periodeLabel) ?></strong>
                <?php if ($rentangTampilan !== ''): ?>
                    <span class="d-block d-md-inline text-muted">(<?= htmlspecialchars($rentangTampilan) ?>)</span>
                <?php endif; ?>
                <?php if ($periodeNote !== ''): ?>
                    · <?= $periodeNote ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
</form>
<?php
echo $closeCard;
