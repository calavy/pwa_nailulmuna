<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_skbt.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';

require_roles(['admin', 'pengurus', 'kiai']);
ensure_santri_identity_columns($pdo);

$tahunSyawal = (int) ($_GET['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
if ($tahunSyawal < 1300 || $tahunSyawal > 1500) {
    $tahunSyawal = skbt_tahun_syawal_default($pdo);
}

$santriId = (int) ($_GET['santri_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$periodeKe = max(0, (int) ($_GET['periode_ke'] ?? 0));

$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$cariRows = [];
if ($q !== '' && strlen($q) >= 2) {
    $aktifSql = santri_sql_aktif_only('s');
    $st = $pdo->prepare('
        SELECT id, ' . $namaCol . ' AS nama_santri, nis, tingkatan
        FROM santri s
        WHERE ' . $aktifSql . '
          AND (s.' . $namaCol . ' LIKE :q OR s.nis LIKE :q OR s.qr LIKE :q)
        ORDER BY s.' . $namaCol . ' ASC
        LIMIT 25
    ');
    $st->execute(['q' => '%' . $q . '%']);
    $cariRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$santriPick = $santriId > 0 ? skbt_santri_profil($pdo, $santriId, false) : null;
$previewRingkas = null;
if ($santriPick && $santriId > 0) {
    $previewRingkas = skbt_preview_counts($pdo, $santriId, $tahunSyawal);
}

$pageTitle = 'SKBT — Surat Keterangan Belajar & Tingkatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Akademik</p>
    <h1 class="h4 mb-1">SKBT — Laporan keaktivan kegiatan</h1>
    <p class="text-muted small mb-0">
        Surat Keterangan Belajar dan Tingkatan dari rekap presensi (jama'ah, ta'lim, dan kegiatan terkait).
        Cetak ukuran <strong>A4</strong>. Alur data: nama santri → tingkatan → kegiatan jadwal → presensi.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-0">Cari santri</label>
                <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>"
                       placeholder="Nama / NIS / QR" autofocus>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Tahun Syawal (awal TA)</label>
                <input type="number" name="tahun_syawal" class="form-control" min="1300" max="1500"
                       value="<?= (int) $tahunSyawal ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Periode ke</label>
                <input type="number" name="periode_ke" class="form-control" min="0" max="99"
                       value="<?= $periodeKe > 0 ? $periodeKe : '' ?>" placeholder="Opsional">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Cari</button>
            </div>
        </form>

        <?php if ($cariRows !== [] && !$santriPick): ?>
            <div class="list-group list-group-flush border rounded mt-3">
                <?php foreach ($cariRows as $cr): ?>
                    <a class="list-group-item list-group-item-action py-2"
                       href="<?= htmlspecialchars(app_href('/akademik/skbt.php?santri_id=' . (int) ($cr['id'] ?? 0) . '&tahun_syawal=' . $tahunSyawal . ($periodeKe > 0 ? '&periode_ke=' . $periodeKe : ''))) ?>">
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($cr['nama_santri'] ?? '-')) ?></div>
                        <div class="small text-muted">NIS <?= htmlspecialchars((string) ($cr['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($cr['tingkatan'] ?? '')) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php elseif ($q !== '' && strlen($q) >= 2): ?>
            <p class="small text-muted mt-2 mb-0">Santri tidak ditemukan.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($santriPick && $previewRingkas): ?>
    <?php
    $cetakQs = [
        'santri_id' => $santriId,
        'tahun_syawal' => $tahunSyawal,
    ];
    if ($periodeKe > 0) {
        $cetakQs['periode_ke'] = $periodeKe;
    }
    $cetakUrl = app_href('/akademik/skbt_cetak.php?' . http_build_query($cetakQs));
    ?>
    <div class="card shadow-sm border-success mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 class="h5 mb-1"><?= htmlspecialchars((string) ($santriPick['nama_santri'] ?? '-')) ?></h2>
                    <div class="text-muted small">
                        NIS <?= htmlspecialchars((string) ($santriPick['nis'] ?? '')) ?>
                        · <?= htmlspecialchars((string) ($santriPick['tingkatan'] ?? '')) ?>
                        · <?= htmlspecialchars((string) ($previewRingkas['periode']['label'] ?? '')) ?>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-success" href="<?= htmlspecialchars($cetakUrl) ?>" target="_blank">
                        <i class="fa-solid fa-print me-1"></i> Cetak SKBT (A4)
                    </a>
                    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($cetakUrl . '&preview=1') ?>" target="_blank">Pratinjau</a>
                </div>
            </div>
            <div class="row g-2 mt-3 small">
                <div class="col-md-4">
                    <span class="badge text-bg-primary"><?= (int) ($previewRingkas['disiplin_kelas'] ?? 0) ?> ta'lim / disiplin kelas</span>
                </div>
                <div class="col-md-4">
                    <span class="badge text-bg-info"><?= (int) ($previewRingkas['presensi_jamaah'] ?? 0) ?> jama'ah</span>
                </div>
                <div class="col-md-4">
                    <span class="badge text-bg-secondary"><?= (int) ($previewRingkas['lainnya'] ?? 0) ?> kegiatan lain</span>
                </div>
            </div>
            <p class="small text-muted mt-2 mb-0">
                <?= htmlspecialchars((string) ($santriPick['nama_santri'] ?? '')) ?>
                → tingkatan <strong><?= htmlspecialchars((string) ($previewRingkas['tingkatan'] ?? $santriPick['tingkatan'] ?? '-')) ?></strong>
                → kegiatan jadwal → presensi.
                Nilai <strong>BAIK / SEDANG / BURUK</strong> dihitung otomatis dari jumlah GHOIB (kriteria pondok).
            </p>
        </div>
    </div>
<?php elseif ($santriId > 0): ?>
    <div class="alert alert-warning">Santri tidak ditemukan.</div>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fa-solid fa-file-lines fa-2x mb-2 opacity-50"></i>
        <p class="mb-0 small">Cari dan pilih santri untuk membuat SKBT.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
