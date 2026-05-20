<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_status.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
require_once __DIR__ . '/../helpers/santri_keluar.php';
require_once __DIR__ . '/../helpers/kelas_ruangan.php';
ensure_santri_keluar_columns($pdo);
ensure_kelas_ruangan_table($pdo);

$extraRuanganSelect = '';
if (column_exists($pdo, 'santri', 'kelas_ruangan_id') && table_exists($pdo, 'kelas_ruangan')) {
    $extraRuanganSelect = ', (SELECT kr.nama_ruangan FROM kelas_ruangan kr WHERE kr.id = santri.kelas_ruangan_id LIMIT 1) AS nama_ruangan_kelas';
}

$santri = $pdo->query('
    SELECT id, qr, nis, nama_santri, nik, jenis_kelamin, tingkatan, kategori_kelas, no_wa_wali, is_aktif, status_santri, keluar_kategori, alasan_keluar, tanggal_keluar, nama_kamar, no_ranjang, keluar_settled_at' . $extraRuanganSelect . '
    FROM santri
    WHERE ' . santri_sql_aktif_only('santri') . '
    ORDER BY nama_santri ASC
')->fetchAll();
$totalSantri = count($santri);
$totalAktif = $totalSantri;
$totalNonAktif = (int) ($pdo->query('
    SELECT COUNT(*) FROM santri WHERE NOT (' . santri_sql_aktif_only('santri') . ')
')->fetchColumn() ?: 0);

$kegiatanFilter = (int) ($_GET['kegiatan_id'] ?? 0);
$periodeKeaktifan = rekap_resolve_periode($pdo, [
    'mode' => $_GET['mode'] ?? 'masehi',
    'month' => $_GET['month'] ?? date('n'),
    'year' => $_GET['year'] ?? date('Y'),
]);
$keaktifanMap = [];
if (table_exists($pdo, 'presensi')) {
    $kgSql = $kegiatanFilter > 0 ? ' AND p.kegiatan_id = :kg' : '';
    $paramsKg = ['s' => $periodeKeaktifan['start_date'], 'e' => $periodeKeaktifan['end_date']];
    if ($kegiatanFilter > 0) {
        $paramsKg['kg'] = $kegiatanFilter;
    }
    $stKg = $pdo->prepare('
        SELECT p.santri_id,
            SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
            COUNT(p.id) AS total
        FROM presensi p
        WHERE p.tanggal_presensi BETWEEN :s AND :e' . $kgSql . '
        GROUP BY p.santri_id
    ');
    $stKg->execute($paramsKg);
    foreach ($stKg->fetchAll(PDO::FETCH_ASSOC) as $kr) {
        $tot = (int) ($kr['total'] ?? 0);
        $had = (int) ($kr['hadir'] ?? 0);
        $keaktifanMap[(int) $kr['santri_id']] = $tot > 0 ? round($had / $tot * 100, 1) : null;
    }
}
$kegiatanList = table_exists($pdo, 'kegiatan')
    ? $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE is_active = 1 ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$pageTitle = 'Data Santri Aktif';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="page-intro w-100 me-3">
        <p class="page-intro-kicker mb-1">Manajemen SDM</p>
        <h1 class="h3 mb-1">Santri Aktif</h1>
        <p class="text-muted mb-0">Santri yang masih mondok. Non aktifkan dari tabel — otomatis masuk <a href="/pwa_nailulmuna/santri/mukimin.php">Data Mukimin</a>. Penyelesaian keuangan &amp; surat lewat <strong>Administrasi keluar</strong>. Biodata lengkap di <a href="/pwa_nailulmuna/santri/semua_jati.php">Data induk santri</a>.</p>
        <p class="small text-muted mt-2 mb-0">Unduh daftar: file <strong>CSV UTF-8</strong> (titik koma) — cocok dibuka di Excel; berisi kolom biodata, orang tua, kafil, dan alamat.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/pwa_nailulmuna/santri/semua_jati.php" class="btn btn-outline-primary btn-sm">Data induk</a>
        <a href="/pwa_nailulmuna/santri/mukimin.php" class="btn btn-outline-secondary btn-sm">Data Mukimin</a>
        <a href="/pwa_nailulmuna/santri/keluar.php" class="btn btn-outline-danger btn-sm" data-sdm-modal="/pwa_nailulmuna/santri/keluar.php" data-sdm-title="Administrasi keluar">Administrasi keluar</a>
        <a href="/pwa_nailulmuna/santri/export_excel.php" class="btn btn-outline-primary btn-sm" title="CSV UTF-8">Export</a>
        <a href="/pwa_nailulmuna/santri/create.php" class="btn btn-success btn-sm" data-sdm-modal="/pwa_nailulmuna/santri/create.php" data-sdm-title="Tambah santri">+ Tambah</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total santri</div>
            <div class="app-mini-stat-value"><?= $totalSantri ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Status aktif</div>
            <div class="app-mini-stat-value text-success"><?= $totalAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Non aktif (muqim/boyong)</div>
            <div class="app-mini-stat-value text-secondary"><?= $totalNonAktif ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4 col-lg-3">
                <label class="form-label small text-muted mb-1" for="santri-aktif-cari">Cari nama atau NIS</label>
                <input type="search" id="santri-aktif-cari" class="form-control" placeholder="Ketik nama atau NIS…" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Kegiatan (keaktifan)</label>
                <select name="kegiatan_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Semua</option>
                    <?php foreach ($kegiatanList as $kg): ?>
                        <option value="<?= (int) $kg['id'] ?>"<?= $kegiatanFilter === (int) $kg['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $kg['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Periode</label>
                <select name="mode" class="form-select form-select-sm">
                    <option value="masehi" <?= $periodeKeaktifan['mode'] === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                    <option value="hijriyah" <?= $periodeKeaktifan['mode'] === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
                </select>
            </div>
            <div class="col-4 col-md-1">
                <input type="number" name="month" class="form-control form-control-sm" min="1" max="12" value="<?= (int) $periodeKeaktifan['month'] ?>" title="Bulan">
            </div>
            <div class="col-4 col-md-1">
                <input type="number" name="year" class="form-control form-control-sm" value="<?= (int) $periodeKeaktifan['year'] ?>" title="Tahun">
            </div>
            <div class="col-4 col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Filter</button>
            </div>
            <div class="col-12">
                <p class="small text-muted mb-0">Menampilkan <strong id="santri-aktif-visible"><?= $totalSantri ?></strong> santri · keaktifan <?= htmlspecialchars($periodeKeaktifan['label']) ?><?= $kegiatanFilter > 0 ? ' per kegiatan' : '' ?></p>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0" id="santri-aktif-table">
                <thead>
                <tr>
                    <th>QR</th>
                    <th>NIS</th>
                    <th>Nama</th>
                    <th>NIK</th>
                    <th>JK</th>
                    <th>Tingkatan</th>
                    <th>Kamar/Ranjang</th>
                    <th>Kelas Keuangan</th>
                    <th>Ruangan kelas</th>
                    <th>WA Wali</th>
                    <th>Status</th>
                    <th>Keaktifan</th>
                    <th class="text-end">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($santri): ?>
                    <?php foreach ($santri as $item): ?>
                        <?php
                        $haystack = mb_strtolower(
                            trim((string) ($item['nama_santri'] ?? '')) . ' '
                            . trim((string) ($item['nis'] ?? '')) . ' '
                            . trim((string) ($item['qr'] ?? '')) . ' '
                            . trim((string) ($item['nik'] ?? ''))
                        );
                        ?>
                        <tr class="santri-aktif-row" data-cari="<?= htmlspecialchars($haystack) ?>">
                            <td><?= htmlspecialchars($item['qr'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['nis']) ?></td>
                            <td><?= htmlspecialchars($item['nama_santri']) ?></td>
                            <td><?= htmlspecialchars($item['nik'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['jenis_kelamin'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['tingkatan'] ?: '-') ?></td>
                            <td>
                                <?php
                                $kamar = trim((string) ($item['nama_kamar'] ?? ''));
                                $ranjang = trim((string) ($item['no_ranjang'] ?? ''));
                                echo htmlspecialchars(($kamar !== '' ? $kamar : '-') . ($ranjang !== '' ? ' / ' . $ranjang : ''));
                                ?>
                            </td>
                            <td><?= htmlspecialchars(($item['kategori_kelas'] ?? '') !== '' ? kelas_keuangan_label_for_kode($pdo, (string) $item['kategori_kelas']) : '-') ?></td>
                            <td><?= htmlspecialchars(trim((string) ($item['nama_ruangan_kelas'] ?? '')) !== '' ? (string) $item['nama_ruangan_kelas'] : '-') ?></td>
                            <td><?= htmlspecialchars($item['no_wa_wali'] ?: '-') ?></td>
                            <td>
                                <?php $status = santri_status_from_row($item); ?>
                                <span class="badge <?= santri_status_badge_class($status) ?>">
                                    <?= htmlspecialchars(santri_status_label($status)) ?>
                                </span>
                                <?php if (!santri_status_is_aktif_list($status) && (trim((string) ($item['alasan_keluar'] ?? '')) !== '' || trim((string) ($item['tanggal_keluar'] ?? '')) !== '')): ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars((string) ($item['tanggal_keluar'] ?? '-')) ?> · <?= htmlspecialchars((string) ($item['alasan_keluar'] ?? '-')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php
                                $kid = (int) $item['id'];
                                $pct = $keaktifanMap[$kid] ?? null;
                                if ($pct === null) {
                                    echo '<span class="text-muted">—</span>';
                                } else {
                                    echo '<strong>' . htmlspecialchars((string) $pct) . '%</strong> hadir';
                                }
                                ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="/pwa_nailulmuna/santri/riwayat.php?id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-outline-info">Riwayat</a>
                                <a href="/pwa_nailulmuna/santri/edit.php?id=<?= (int) $item['id'] ?>"
                                   class="btn btn-sm btn-warning"
                                   data-sdm-modal="/pwa_nailulmuna/santri/edit.php?id=<?= (int) $item['id'] ?>"
                                   data-sdm-title="Edit santri">Edit</a>
                                <a href="/pwa_nailulmuna/santri/nonaktif_cepat.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger">Ubah status</a>
                                <a href="/pwa_nailulmuna/santri/delete.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus data ini?')">Hapus</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" class="text-center text-muted">Belum ada data santri aktif.</td>
                    </tr>
                <?php endif; ?>
                <tr id="santri-aktif-no-match" class="d-none">
                    <td colspan="12" class="text-center text-muted">Tidak ada santri yang cocok dengan pencarian.</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var inp = document.getElementById('santri-aktif-cari');
    var rows = document.querySelectorAll('.santri-aktif-row');
    var visibleEl = document.getElementById('santri-aktif-visible');
    var noMatch = document.getElementById('santri-aktif-no-match');
    if (!inp || !rows.length) return;
    function norm(s) { return (s || '').toLowerCase().trim(); }
    function applyFilter() {
        var q = norm(inp.value);
        var shown = 0;
        rows.forEach(function (tr) {
            var hay = tr.getAttribute('data-cari') || '';
            var ok = q === '' || hay.indexOf(q) !== -1;
            tr.classList.toggle('d-none', !ok);
            if (ok) shown++;
        });
        if (visibleEl) visibleEl.textContent = String(shown);
        if (noMatch) noMatch.classList.toggle('d-none', q === '' || shown > 0);
    }
    inp.addEventListener('input', applyFilter);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
