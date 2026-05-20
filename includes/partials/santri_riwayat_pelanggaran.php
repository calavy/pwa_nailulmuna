<?php

declare(strict_types=1);

/**
 * Riwayat pelanggaran (read-only) — wali, santri sendiri, atau staff.
 *
 * @var PDO $pdo
 * @var int $santriId
 * @var list<array<string, mixed>> $pelanggaranRows
 * @var int $filterTa
 * @var string $filterFormAction
 */

$filterTa = (int) ($filterTa ?? 0);
$santriId = (int) ($santriId ?? 0);
$filterFormAction = (string) ($filterFormAction ?? '');
$filterExtraGet = is_array($filterExtraGet ?? null) ? $filterExtraGet : [];
$pelanggaranRows = $pelanggaranRows ?? [];
$pelanggaranShow = $filterTa > 0
    ? santri_riwayat_pelanggaran_list_buku($pdo, $santriId, $filterTa)
    : $pelanggaranRows;
$totalPoinPel = 0;
foreach ($pelanggaranShow as $pl) {
    $totalPoinPel += (int) ($pl['point_delta'] ?? 0);
}
$taOptions = santri_riwayat_tahun_filter_options($pdo, $santriId);
?>
<link href="/assets/css/santri-timeline.css" rel="stylesheet">

<div class="santri-buku-induk">
    <form method="get" action="<?= htmlspecialchars($filterFormAction) ?>" class="buku-filter card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <?php if ($santriId > 0 && str_contains($filterFormAction, 'santri/riwayat')): ?>
                    <input type="hidden" name="id" value="<?= $santriId ?>">
                <?php endif; ?>
                <?php foreach ($filterExtraGet as $fk => $fv): ?>
                    <?php if ($fv !== '' && $fv !== null): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string) $fk) ?>" value="<?= htmlspecialchars((string) $fv) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small mb-0">Filter tahun ajaran</label>
                    <select name="th" class="form-select form-select-sm">
                        <option value="0">Semua tahun</option>
                        <?php foreach ($taOptions as $y): ?>
                            <option value="<?= (int) $y ?>"<?= $filterTa === (int) $y ? ' selected' : '' ?>><?= (int) $y ?>/<?= (int) $y + 1 ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex flex-wrap gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
                    <?php if ($filterTa > 0): ?>
                        <a href="<?= htmlspecialchars($filterFormAction) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <section class="buku-section" aria-labelledby="riwayat-pelanggaran">
        <h2 class="buku-section-title" id="riwayat-pelanggaran">Riwayat pelanggaran <span class="fw-normal text-muted">(kedisiplinan)</span></h2>
        <p class="small text-muted px-1 mb-2">Catatan pelanggaran dan poin kedisiplinan. Poin dari presensi harian tidak ditampilkan di sini.</p>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama pelanggaran</th>
                        <th>Kategori</th>
                        <th class="text-end">Poin</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pelanggaranShow as $pl): ?>
                    <?php $namaPel = santri_riwayat_pelanggaran_nama($pl); ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars((string) $pl['tanggal']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($namaPel) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($pl['kategori'] ?? '—')) ?></td>
                        <td class="text-end text-danger fw-semibold">+<?= (int) $pl['point_delta'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pelanggaranShow === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada pelanggaran<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($pelanggaranShow !== []): ?>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="3" class="text-end fw-semibold">Total poin (tampilan ini)</td>
                        <td class="text-end fw-bold text-danger">+<?= $totalPoinPel ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </section>
</div>
