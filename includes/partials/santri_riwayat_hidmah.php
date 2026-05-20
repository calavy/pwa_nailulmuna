<?php

declare(strict_types=1);

/**
 * Riwayat khidmah / peran organisasi (read-only) — wali & santri.
 * Tampil meskipun status santri masih Aktif.
 *
 * @var PDO $pdo
 * @var int $santriId
 * @var list<array<string, mixed>> $hidmahRows
 * @var int $filterTa
 * @var string $filterFormAction
 * @var array<string, string> $filterExtraGet
 */

$filterTa = (int) ($filterTa ?? 0);
$santriId = (int) ($santriId ?? 0);
$filterFormAction = (string) ($filterFormAction ?? '');
$filterExtraGet = is_array($filterExtraGet ?? null) ? $filterExtraGet : [];
$hidmahRows = $hidmahRows ?? [];
$hidmahShow = santri_riwayat_filter_ta_mulai($hidmahRows, $filterTa);
$taOptions = santri_riwayat_tahun_filter_options($pdo, $santriId);
?>
<link href="/pwa_nailulmuna/assets/css/santri-timeline.css" rel="stylesheet">

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

    <section class="buku-section" aria-labelledby="riwayat-hidmah">
        <h2 class="buku-section-title" id="riwayat-hidmah">Riwayat Khidmah <span class="fw-normal text-muted">(peran &amp; pengabdian)</span></h2>
        <p class="small text-muted px-1 mb-2">
            Catatan peran di pondok (pengurus santri, pembantu usaha, hidmah) — termasuk saat santri masih <strong>aktif</strong> mengaji.
        </p>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Nama / bagian</th>
                        <th>Jenis peran</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hidmahShow as $hr): ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars(santri_riwayat_hidmah_periode_label($hr)) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $hr['nama_hidmah']) ?></td>
                        <td>
                            <span class="badge text-bg-info" style="font-size:.7rem"><?= htmlspecialchars(santri_hidmah_jenis_label((string) $hr['jenis_peran'])) ?></span>
                        </td>
                        <td class="small"><?= htmlspecialchars(trim((string) ($hr['keterangan'] ?? '')) !== '' ? (string) $hr['keterangan'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($hidmahShow === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Belum ada riwayat khidmah<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
