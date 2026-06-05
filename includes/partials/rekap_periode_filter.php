<?php

declare(strict_types=1);

/**
 * Filter periode rekap (hari / bulan TA / rentang).
 *
 * @var array<string,mixed> $periode hasil rekap_periode_resolve()
 * @var string $formAction URL action form
 * @var array<string,string> $extraHidden query tambahan
 */
$periode = $periode ?? rekap_periode_resolve($pdo, $_GET);
$formAction = $formAction ?? '';
$extraHidden = $extraHidden ?? [];
$mode = (string) ($periode['mode'] ?? 'hari');
?>
<form method="get" action="<?= htmlspecialchars($formAction) ?>" class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php foreach (['hari' => 'Satu hari', 'bulan' => 'Per bulan (TA)', 'rentang' => 'Rentang tanggal'] as $m => $lbl): ?>
                <label class="btn btn-sm <?= $mode === $m ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <input type="radio" name="periode_mode" value="<?= $m ?>" class="d-none" <?= $mode === $m ? 'checked' : '' ?> onchange="this.form.submit()">
                    <?= htmlspecialchars($lbl) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="row g-2 align-items-end">
            <?php if ($mode === 'hari'): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Tanggal</label>
                    <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $periode['tanggal']) ?>">
                </div>
            <?php elseif ($mode === 'bulan'): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-0">Bulan tagihan (tahun ajaran <?= (int) $periode['ta_mulai'] ?>/<?= (int) $periode['ta_selesai'] ?>)</label>
                    <select name="rekap_bulan" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($periode['bulan_slots'] as $slot): ?>
                            <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                            <?php if ($b < 1) { continue; } ?>
                            <option value="<?= $b ?>" <?= (int) $periode['bulan_tagihan'] === $b ? 'selected' : '' ?>>
                                <?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <div class="small text-muted">
                        Rentang: <?= htmlspecialchars((string) $periode['dari']) ?> s/d <?= htmlspecialchars((string) $periode['sampai']) ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Dari</label>
                    <input type="date" name="dari" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $periode['dari']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Sampai</label>
                    <input type="date" name="sampai" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $periode['sampai']) ?>">
                </div>
            <?php endif; ?>
            <?php foreach ($extraHidden as $hk => $hv): ?>
                <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
            <?php endforeach; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Terapkan</button>
            </div>
        </div>
    </div>
</form>
