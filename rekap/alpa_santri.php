<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../helpers/presensi_jadwal.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$periode = rekap_resolve_periode($pdo, $_GET);
$startDate = $periode['start_date'];
$endDate = $periode['end_date'];
$periodeLabel = $periode['label'];
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$santriId = (int) ($_GET['santri_id'] ?? 0);

$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');

$rawRows = presensi_fetch_rows_rekap_periode($pdo, $periode, 0);
if ($tingkatan !== '') {
    $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($tingkatan): bool {
        return strtolower((string) ($row['tingkatan'] ?? '')) === strtolower($tingkatan);
    }));
}
if ($santriId > 0) {
    $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($santriId): bool {
        return (int) ($row['santri_id'] ?? 0) === $santriId;
    }));
}

$ranked = rekap_keaktifan_build_per_santri($rawRows, $goodMax, $mediumMax);
usort($ranked, static fn (array $a, array $b): int => ((int) ($b['alpa'] ?? 0)) <=> ((int) ($a['alpa'] ?? 0))
    ?: strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? '')));

santri_list_sort_mode($_GET['santri_sort'] ?? null);
$santriList = $pdo->query('SELECT id, nama_santri, nis, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri'))->fetchAll();
$tingkatanList = $pdo->query(
    'SELECT DISTINCT TRIM(tingkatan) AS t FROM santri WHERE tingkatan IS NOT NULL AND TRIM(tingkatan)<>"" ORDER BY t'
)->fetchAll(PDO::FETCH_COLUMN) ?: [];

$kategoriBadge = static function (string $kat): string {
    return match (strtolower($kat)) {
        'baik', 'bagus' => 'success',
        'sedang' => 'warning',
        default => 'danger',
    };
};

$pageTitle = 'Laporan ALPA per Santri';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker text-muted mb-1">Rekap Presensi</p>
    <h1 class="h4 mb-1">Laporan ALPA per Santri</h1>
    <p class="text-muted mb-0">
        Kategori dihitung dari jumlah ALPA terhadap seluruh kegiatan terjadwal dalam periode.
        Baik ≤ <?= $goodMax ?> ALPA · Sedang ≤ <?= $mediumMax ?> ALPA · Buruk &gt; <?= $mediumMax ?> ALPA.
    </p>
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
require __DIR__ . '/../includes/partials/rekap_kalender_bulan_filter.php';
unset($rekapPeriodeExtraSlot);
?>

<div class="card shadow-sm">
    <div class="card-header fw-semibold small d-flex justify-content-between align-items-center">
        <span>Periode: <?= htmlspecialchars($periodeLabel) ?></span>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
