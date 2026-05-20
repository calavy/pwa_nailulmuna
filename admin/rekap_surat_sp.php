<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/surat_nomor.php';

require_roles(['admin', 'pengurus']);

ensure_surat_nomor_schema($pdo);

if (!table_exists($pdo, 'surat_nomor_cache')) {
    exit('Tabel surat_nomor_cache belum ada.');
}

$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$tahun = max(2020, min(2100, (int) ($_GET['tahun'] ?? app_tahun_masehi_default($pdo))));
$filterLevel = strtolower(trim((string) ($_GET['level'] ?? 'semua')));
if (!in_array($filterLevel, ['semua', 'sp1', 'sp2'], true)) {
    $filterLevel = 'semua';
}

$sql = "
    SELECT c.id, c.jenis_kode, c.ref_key, c.nomor_surat, c.created_at,
           CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.ref_key, ':', 2), ':', -1) AS UNSIGNED) AS santri_id_parsed
    FROM surat_nomor_cache c
    WHERE c.jenis_kode IN ('sp1', 'sp2')
      AND c.ref_key LIKE CONCAT('%:', :tahun, ':%')
";
$params = ['tahun' => $tahun];
if ($filterLevel !== 'semua') {
    $sql .= ' AND c.jenis_kode = :lvl ';
    $params['lvl'] = $filterLevel;
}
$sql .= ' ORDER BY c.created_at DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$rawRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$rows = [];
foreach ($rawRows as $r) {
    $sid = (int) ($r['santri_id_parsed'] ?? 0);
    $nis = '';
    $nama = '';
    $tingkat = '';
    if ($sid > 0) {
        $sst = $pdo->prepare('SELECT nis, ' . $nameCol . ' AS nama_santri, tingkatan FROM santri WHERE id = :id LIMIT 1');
        $sst->execute(['id' => $sid]);
        $sr = $sst->fetch(PDO::FETCH_ASSOC);
        if ($sr) {
            $nis = (string) ($sr['nis'] ?? '');
            $nama = (string) ($sr['nama_santri'] ?? '');
            $tingkat = (string) ($sr['tingkatan'] ?? '');
        }
    }
    $parts = explode(':', (string) ($r['ref_key'] ?? ''), 5);
    $bulanPeriode = isset($parts[3]) ? (int) $parts[3] : 0;
    $rows[] = array_merge($r, [
        'nis' => $nis,
        'nama_santri' => $nama,
        'tingkatan' => $tingkat,
        'bulan_periode' => $bulanPeriode,
        'tahun_periode' => $tahun,
    ]);
}

if ($export) {
    $suffix = $filterLevel === 'semua' ? '' : '_' . $filterLevel;
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rekap_surat_sp_' . $tahun . $suffix . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nomor surat', 'Jenis', 'NIS', 'Nama', 'Tingkatan', 'Bulan poin', 'Tahun poin', 'Ref key', 'Dicatat'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['nomor_surat'] ?? ''),
            strtoupper((string) ($r['jenis_kode'] ?? '')),
            (string) ($r['nis'] ?? ''),
            (string) ($r['nama_santri'] ?? ''),
            (string) ($r['tingkatan'] ?? ''),
            (string) ($r['bulan_periode'] ?? ''),
            (string) ($r['tahun_periode'] ?? ''),
            (string) ($r['ref_key'] ?? ''),
            (string) ($r['created_at'] ?? ''),
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle = 'Administrasi — Rekap surat SP';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <p class="text-muted small mb-1">Administrasi</p>
        <h1 class="h3 mb-0">Rekap surat SP (SP1 / SP2)</h1>
        <p class="text-muted small mb-0">Nomor tercatat saat surat dicetak dari <a href="/pwa_nailulmuna/poin/rekap.php">rekap poin</a>. Penomoran berkesinambungan lewat <a href="/pwa_nailulmuna/admin/surat_nomor.php">Nomor surat</a>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <label class="small text-muted mb-0">Tahun poin</label>
            <input type="number" name="tahun" class="form-control form-control-sm" style="width:6rem" value="<?= (int) $tahun ?>" min="2020" max="2100">
            <label class="small text-muted mb-0">Level</label>
            <select name="level" class="form-select form-select-sm" style="width:8rem">
                <option value="semua" <?= $filterLevel === 'semua' ? 'selected' : '' ?>>Semua</option>
                <option value="sp1" <?= $filterLevel === 'sp1' ? 'selected' : '' ?>>SP1</option>
                <option value="sp2" <?= $filterLevel === 'sp2' ? 'selected' : '' ?>>SP2</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Tampilkan</button>
        </form>
        <a class="btn btn-sm btn-success" href="?tahun=<?= (int) $tahun ?>&amp;level=<?= htmlspecialchars($filterLevel) ?>&amp;export=csv">Download CSV (Excel)</a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nomor surat</th>
                    <th>Jenis</th>
                    <th>Santri</th>
                    <th>Periode poin</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Belum ada surat SP tercatat untuk tahun ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <?php
                $sid = (int) ($r['santri_id_parsed'] ?? 0);
                $m = (int) ($r['bulan_periode'] ?? 0);
                $periodeTeks = ($m >= 1 && $m <= 12) ? ('Bulan ' . $m . '/' . (int) $tahun) : '—';
                $spParam = strtoupper((string) ($r['jenis_kode'] ?? 'sp1')) === 'SP2' ? 'SP2' : 'SP1';
                ?>
                <tr>
                    <td class="font-monospace small"><?= htmlspecialchars((string) ($r['nomor_surat'] ?? '')) ?></td>
                    <td><span class="badge text-bg-light border"><?= htmlspecialchars(strtoupper((string) ($r['jenis_kode'] ?? ''))) ?></span></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?: '—')) ?></div>
                        <div class="small text-muted font-monospace"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                    </td>
                    <td class="small"><?= htmlspecialchars($periodeTeks) ?></td>
                    <td class="text-end">
                        <?php if ($sid > 0 && $m >= 1 && $m <= 12): ?>
                            <a class="btn btn-sm btn-outline-dark" target="_blank" href="/pwa_nailulmuna/poin/surat.php?santri_id=<?= $sid ?>&amp;month=<?= $m ?>&amp;year=<?= (int) $tahun ?>&amp;sp=<?= htmlspecialchars($spParam) ?>">Cetak ulang</a>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-3">
    <a href="/pwa_nailulmuna/admin/surat_nomor.php">Pengaturan nomor surat</a>
    · <a href="/pwa_nailulmuna/admin/rekap_surat_izin.php">Rekap surat izin</a>
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
