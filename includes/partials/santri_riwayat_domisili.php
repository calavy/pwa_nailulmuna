<?php

declare(strict_types=1);

/**
 * Riwayat domisili (mengaji & khidmah) — dapat dilihat wali / yang bersangkutan.
 *
 * @var PDO $pdo
 * @var int $santriId
 * @var list<array<string, mixed>> $domisiliMengaji
 * @var list<array<string, mixed>> $domisiliKhidmah
 * @var int $filterTa
 * @var string $filterFormAction
 * @var bool $readOnly
 */

$filterTa = (int) ($filterTa ?? 0);
$readOnly = (bool) ($readOnly ?? false);
$santriId = (int) ($santriId ?? 0);
$filterFormAction = (string) ($filterFormAction ?? '');
$filterExtraGet = is_array($filterExtraGet ?? null) ? $filterExtraGet : [];
$domisiliMengaji = santri_riwayat_filter_ta_mulai($domisiliMengaji ?? [], $filterTa);
$domisiliKhidmah = santri_riwayat_filter_ta_mulai($domisiliKhidmah ?? [], $filterTa);
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

    <section class="buku-section" aria-labelledby="dom-mengaji">
        <h2 class="buku-section-title" id="dom-mengaji">Domisili Mengaji (Mondok)</h2>
        <p class="small text-muted px-1">Riwayat penempatan kamar saat santri mengaji di pondok.</p>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Gedung</th>
                        <th>Kamar</th>
                        <th>Kasur / ranjang</th>
                        <th>Periode</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($domisiliMengaji as $dm): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dm['gedung'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string) ($dm['nama_kamar'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($dm['no_ranjang'] ?? '')) !== '' ? (string) $dm['no_ranjang'] : '—') ?></td>
                        <td><?= htmlspecialchars(santri_riwayat_domisili_periode_label($dm)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($domisiliMengaji === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Belum ada riwayat domisili mengaji<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="buku-section" aria-labelledby="dom-khidmah">
        <h2 class="buku-section-title" id="dom-khidmah">Domisili Khidmah / Pengabdian</h2>
        <p class="small text-muted px-1">Penempatan tinggal saat status khidmah (tetap di pondok membantu).</p>
        <div class="table-responsive">
            <table class="table table-buku table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>Gedung / lokasi</th>
                        <th>Kamar / unit</th>
                        <th>Kasur / ranjang</th>
                        <th>Periode</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($domisiliKhidmah as $dk): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dk['gedung'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string) ($dk['nama_kamar'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($dk['no_ranjang'] ?? '')) !== '' ? (string) $dk['no_ranjang'] : '—') ?></td>
                        <td><?= htmlspecialchars(santri_riwayat_domisili_periode_label($dk)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($domisiliKhidmah === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Belum ada riwayat domisili khidmah<?= $filterTa > 0 ? ' untuk filter ini' : '' ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if (!$readOnly): ?>
    <p class="small text-muted mb-0">
        Kelola lengkap di tab <a href="/santri/riwayat.php?id=<?= $santriId ?>&tab=domisili">Domisili</a> pada riwayat santri.
    </p>
    <?php endif; ?>
</div>
