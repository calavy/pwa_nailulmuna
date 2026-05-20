<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'perizinan')) {
    exit('Tabel perizinan tidak ada.');
}

$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$tahun = max(2020, min(2100, (int) ($_GET['tahun'] ?? app_tahun_masehi_default($pdo))));
$jRaw = trim((string) ($_GET['jenis'] ?? 'semua'));
$filterJenis = 'SEMUA';
if (strcasecmp($jRaw, 'semua') === 0 || $jRaw === '') {
    $filterJenis = 'SEMUA';
} elseif (in_array(strtoupper($jRaw), ['KELUAR', 'TUGAS', 'PULANG', 'SAKIT'], true)) {
    $filterJenis = strtoupper($jRaw);
}

$sql = '
    SELECT i.id, i.nomor_surat, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai,
           i.approval_status, i.created_at, s.nis, s.nama_santri, s.tingkatan
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id
    WHERE YEAR(COALESCE(i.tanggal_mulai, DATE(i.created_at))) = :tahun
      AND i.approval_status = \'DISETUJUI\'
';
$params = ['tahun' => $tahun];
if ($filterJenis !== 'SEMUA') {
    if ($filterJenis === 'TUGAS') {
        $sql .= " AND i.jenis_izin IN ('TUGAS','PULANG') ";
    } else {
        $sql .= ' AND i.jenis_izin = :jenis ';
        $params['jenis'] = $filterJenis;
    }
}
$sql .= ' ORDER BY i.id DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($export) {
    $suffix = $filterJenis === 'SEMUA' ? '' : '_' . strtolower($filterJenis);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rekap_surat_izin_' . $tahun . $suffix . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nomor surat', 'NIS', 'Nama', 'Tingkatan', 'Jenis', 'Mulai', 'Selesai', 'Jam mulai', 'Jam selesai', 'Status', 'Dicatat'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['nomor_surat'] ?? ''),
            (string) ($r['nis'] ?? ''),
            (string) ($r['nama_santri'] ?? ''),
            (string) ($r['tingkatan'] ?? ''),
            (string) ($r['jenis_izin'] ?? ''),
            (string) ($r['tanggal_mulai'] ?? ''),
            (string) ($r['tanggal_selesai'] ?? ''),
            (string) ($r['jam_mulai'] ?? ''),
            (string) ($r['jam_selesai'] ?? ''),
            (string) ($r['approval_status'] ?? ''),
            (string) ($r['created_at'] ?? ''),
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle = 'Administrasi — Rekap surat izin';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <p class="text-muted small mb-1">Administrasi</p>
        <h1 class="h3 mb-0">Rekapan surat izin (disetujui)</h1>
        <p class="text-muted small mb-0">Nomor surat tercatat setelah dicetak dari menu perizinan. Filter <strong>Keluar</strong> untuk izin keluar (kode SIZN.S), <strong>Tugas</strong> untuk izin tugas.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <label class="small text-muted mb-0">Tahun</label>
            <input type="number" name="tahun" class="form-control form-control-sm" style="width:6rem" value="<?= (int) $tahun ?>" min="2020" max="2100">
            <label class="small text-muted mb-0">Jenis</label>
            <select name="jenis" class="form-select form-select-sm" style="width:10rem">
                <option value="semua" <?= $filterJenis === 'SEMUA' ? 'selected' : '' ?>>Semua</option>
                <option value="KELUAR" <?= $filterJenis === 'KELUAR' ? 'selected' : '' ?>>Keluar</option>
                <option value="TUGAS" <?= in_array($filterJenis, ['TUGAS', 'PULANG'], true) ? 'selected' : '' ?>>Tugas</option>
                <option value="SAKIT" <?= $filterJenis === 'SAKIT' ? 'selected' : '' ?>>Sakit</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Tampilkan</button>
        </form>
        <a class="btn btn-sm btn-success" href="?tahun=<?= (int) $tahun ?>&amp;jenis=<?= htmlspecialchars($filterJenis === 'SEMUA' ? 'semua' : $filterJenis) ?>&amp;export=csv">Download CSV (Excel)</a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nomor surat</th>
                    <th>Santri</th>
                    <th>Jenis</th>
                    <th>Tanggal</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Tidak ada data izin disetujui pada tahun ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="font-monospace small"><?= htmlspecialchars((string) ($r['nomor_surat'] ?: '— belum dicetak —')) ?></td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                        <div class="small text-muted font-monospace"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                    </td>
                    <td><span class="badge text-bg-light border"><?= htmlspecialchars((string) ($r['jenis_izin'] ?? '')) ?></span></td>
                    <td class="small">
                        <?= htmlspecialchars((string) ($r['tanggal_mulai'] ?? '')) ?>
                        <?php if (($r['tanggal_selesai'] ?? '') !== ''): ?>
                            <span class="text-muted">s.d</span> <?= htmlspecialchars((string) $r['tanggal_selesai']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-dark" target="_blank" href="/pwa_nailulmuna/perizinan/surat.php?id=<?= (int) $r['id'] ?>">Cetak</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-3">
    <a href="/pwa_nailulmuna/admin/surat_nomor.php">Pengaturan nomor surat</a>
    · <a href="/pwa_nailulmuna/admin/rekap_surat_sp.php">Rekap surat SP (poin)</a>
    · <a href="/pwa_nailulmuna/poin/rekap.php">Cetak SP dari rekap poin</a>
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
