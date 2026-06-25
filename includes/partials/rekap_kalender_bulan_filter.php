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

if (!isset($masehiMonths)) {
    $masehiMonths = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
}
$yearMin = $mode === 'hijriyah' ? 1300 : 1900;
$yearMax = $mode === 'hijriyah' ? 1700 : 2100;
if ($year <= 0) {
    $year = (int) date('Y');
}
$year = max($yearMin, min($yearMax, $year));
$monthOptions = $mode === 'hijriyah' ? $hijriMonths : $masehiMonths;

$openCard = $wrapCard ? '<div class="' . htmlspecialchars($cardClass) . ' rekap-periode-card"><div class="card-body">' : '';
$closeCard = $wrapCard ? '</div></div>' : '';
echo $openCard;
?>
<form method="get" action="<?= htmlspecialchars($formAction) ?>" class="rekap-periode-form"<?php
if (!empty($periodAjaxMount) && !empty($periodAjaxApi)) {
    echo ' data-yp-period-ajax="1" data-yp-period-mount="' . htmlspecialchars((string) $periodAjaxMount) . '" data-yp-period-api="' . htmlspecialchars((string) $periodAjaxApi) . '"';
}
?>>
    <div class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label class="form-label small mb-0">Kalender</label>
            <select class="form-select form-select-sm" name="mode" id="rekap-periode-mode">
                <option value="masehi" <?= $mode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                <option value="hijriyah" <?= $mode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-0">Bulan</label>
            <select class="form-select form-select-sm" name="month" id="rekap-periode-month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>"<?= $month === $m ? ' selected' : '' ?>>
                        <?= htmlspecialchars((string) ($monthOptions[$m] ?? (string) $m)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-0">Tahun</label>
            <input class="form-control form-control-sm" type="number"
                   id="rekap-periode-year"
                   min="<?= (int) $yearMin ?>" max="<?= (int) $yearMax ?>"
                   name="year" value="<?= htmlspecialchars((string) $year) ?>">
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
<?php if ($wrapCard): ?>
<script>
(function () {
    if (window.__rekapPeriodeYearBoundsInit) {
        return;
    }
    window.__rekapPeriodeYearBoundsInit = true;
    document.addEventListener('change', function (ev) {
        if (!ev.target || ev.target.id !== 'rekap-periode-mode') {
            return;
        }
        var modeEl = ev.target;
        var form = modeEl.closest('form');
        if (!form) {
            return;
        }
        var yearEl = form.querySelector('#rekap-periode-year');
        if (!yearEl) {
            return;
        }
        if (modeEl.value === 'hijriyah') {
            yearEl.min = '1300';
            yearEl.max = '1700';
        } else {
            yearEl.min = '1900';
            yearEl.max = '2100';
        }
        yearEl.readOnly = false;
        var y = parseInt(yearEl.value || '0', 10);
        var minY = parseInt(yearEl.min, 10);
        var maxY = parseInt(yearEl.max, 10);
        if (!y || y < minY || y > maxY) {
            yearEl.value = String(new Date().getFullYear());
        }
    });
})();
</script>
<?php endif; ?>
<?php
echo $closeCard;
