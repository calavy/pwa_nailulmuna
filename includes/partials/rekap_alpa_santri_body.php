<?php

declare(strict_types=1);

/**
 * @var string $rekapAlpaKelompok
 * @var string $rekapAlpaKelompokLabel
 * @var string $rekapAlpaPagePath
 * @var string $rekapAlpaSiblingPath
 * @var string $rekapAlpaSiblingLabel
 * @var string $periodeLabel
 * @var string $tingkatan
 * @var int $santriId
 * @var int $goodMax
 * @var int $mediumMax
 * @var list<array<string, mixed>> $ranked
 * @var list<string> $tingkatanList
 * @var list<array<string, mixed>> $santriList
 */
$kategoriBadge = static function (string $kat): string {
    return match (strtolower($kat)) {
        'baik', 'bagus' => 'success',
        'sedang' => 'warning',
        default => 'danger',
    };
};

$siblingQs = $_GET;
unset($siblingQs['kelompok']);
$rekapAlpaSiblingHref = $rekapAlpaSiblingPath . ($siblingQs !== [] ? '?' . http_build_query($siblingQs) : '');
$kelompokBadgeClass = $rekapAlpaKelompok === 'putri' ? 'text-bg-warning' : 'text-bg-primary';
$formAction = $rekapAlpaPagePath;
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker text-muted mb-1">Rekap Presensi</p>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="h4 mb-0">Laporan ALPA <?= htmlspecialchars($rekapAlpaKelompokLabel) ?></h1>
        <span class="badge <?= htmlspecialchars($kelompokBadgeClass) ?>"><?= htmlspecialchars($rekapAlpaKelompokLabel) ?></span>
    </div>
    <p class="text-muted mb-2">
        Kategori dihitung dari jumlah ALPA terhadap seluruh kegiatan terjadwal dalam periode.
        Baik ≤ <?= $goodMax ?> ALPA · Sedang ≤ <?= $mediumMax ?> ALPA · Buruk &gt; <?= $mediumMax ?> ALPA.
    </p>
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($rekapAlpaSiblingHref) ?>">
        <i class="fa-solid fa-right-left me-1" aria-hidden="true"></i>
        Lihat ALPA <?= htmlspecialchars($rekapAlpaSiblingLabel) ?>
    </a>
</div>

<?php
$wrapCard = false;
$rekapPeriodeExtraSlot = '
        <div class="col-md-3">
            <label class="form-label small mb-0">Tingkatan</label>
            <select name="tingkatan" class="form-select form-select-sm">
                <option value="">Semua</option>';
foreach ($tingkatanList as $tk) {
    $rekapPeriodeExtraSlot .= '<option value="' . htmlspecialchars((string) $tk) . '"' . ($tingkatan === (string) $tk ? ' selected' : '') . '>' . htmlspecialchars((string) $tk) . '</option>';
}
$rekapPeriodeExtraSlot .= '
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-0">Santri</label>
            <select name="santri_id" class="form-select form-select-sm">
                <option value="0">Semua santri</option>';
foreach ($santriList as $s) {
    $rekapPeriodeExtraSlot .= '<option value="' . (int) $s['id'] . '"' . ((int) $s['id'] === $santriId ? ' selected' : '') . '>'
        . htmlspecialchars((string) $s['nama_santri']) . ' (' . htmlspecialchars((string) $s['nis']) . ')</option>';
}
$rekapPeriodeExtraSlot .= '
            </select>
        </div>';
require __DIR__ . '/rekap_kalender_bulan_filter.php';
unset($rekapPeriodeExtraSlot);
?>

<div class="card shadow-sm">
    <div class="card-header fw-semibold small d-flex justify-content-between align-items-center">
        <span>Periode: <?= htmlspecialchars($periodeLabel) ?> · <?= htmlspecialchars($rekapAlpaKelompokLabel) ?></span>
        <span class="text-muted"><?= count($ranked) ?> santri</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Santri</th>
                        <th>Tingkatan</th>
                        <th class="text-center">ALPA</th>
                        <th class="text-center">Total kegiatan</th>
                        <th class="text-center">% Hadir</th>
                        <th>Kategori</th>
                        <th>Per kegiatan (ALPA)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($ranked === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data pada filter ini.</td></tr>
                <?php else: foreach ($ranked as $row): ?>
                    <?php
                    $alpa = (int) ($row['alpa'] ?? 0);
                    $total = (int) ($row['total'] ?? 0);
                    $kat = (string) ($row['kategori'] ?? '-');
                    $kgAlpa = [];
                    foreach ((array) ($row['per_kegiatan'] ?? []) as $namaKg => $st) {
                        if ((int) ($st['alpa'] ?? 0) > 0) {
                            $kgAlpa[] = $namaKg . ' (' . (int) $st['alpa'] . '/' . (int) ($st['total'] ?? 0) . ')';
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($row['nama_santri'] ?? '-')) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars((string) ($row['nis'] ?? '')) ?></div>
                        </td>
                        <td><?= htmlspecialchars((string) ($row['tingkatan'] ?? '-')) ?></td>
                        <td class="text-center"><span class="badge text-bg-danger"><?= $alpa ?></span></td>
                        <td class="text-center"><?= $total ?></td>
                        <td class="text-center"><?= htmlspecialchars((string) ($row['persen_hadir'] ?? 0)) ?>%</td>
                        <td><span class="badge text-bg-<?= $kategoriBadge($kat) ?>"><?= htmlspecialchars(ucfirst($kat)) ?></span></td>
                        <td class="small"><?= $kgAlpa !== [] ? htmlspecialchars(implode(' · ', $kgAlpa)) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
