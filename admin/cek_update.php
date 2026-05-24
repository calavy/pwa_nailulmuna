<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_cache.php';

require_roles(['admin', 'pengurus']);

$cacheReport = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string) ($_POST['action'] ?? '')) === 'clear_cache') {
    require_super_admin();
    $withSchema = !empty($_POST['include_schema']);
    $withOpcache = !empty($_POST['include_opcache']);
    $cacheReport = app_performance_cache_clear($pdo, [
        'schema_flags' => $withSchema,
        'opcache' => $withOpcache,
        'all_users_acl' => true,
    ]);
    set_flash('success', sprintf(
        'Cache dibersihkan: %d entri dihapus, %d kedaluwarsa dipangkas%s.',
        (int) ($cacheReport['cleared'] ?? 0),
        (int) ($cacheReport['pruned'] ?? 0),
        !empty($cacheReport['opcache']) ? ', OPcache di-reset' : ''
    ));
    header('Location: ' . app_href('/admin/cek_update.php'));
    exit;
}

$checks = [];

$checkOk = static function (string $label, bool $ok, string $detail): array {
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

$santriCols = ['status_santri', 'alasan_keluar', 'tanggal_keluar', 'nama_kamar', 'no_ranjang'];
$missingSantriCols = [];
foreach ($santriCols as $col) {
    if (!column_exists($pdo, 'santri', $col)) {
        $missingSantriCols[] = $col;
    }
}
$checks[] = $checkOk(
    'Kolom santri update terbaru',
    $missingSantriCols === [],
    $missingSantriCols === [] ? 'Semua kolom baru santri sudah ada.' : 'Kolom belum ada: ' . implode(', ', $missingSantriCols)
);

$jenisIzinRows = [];
try {
    $jenisIzinRows = $pdo->query("SHOW COLUMNS FROM perizinan LIKE 'jenis_izin'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $jenisIzinRows = [];
}
$jenisType = (string) (($jenisIzinRows[0]['Type'] ?? ''));
$checks[] = $checkOk(
    'Enum jenis_izin perizinan mendukung TUGAS',
    str_contains(strtoupper($jenisType), "'TUGAS'"),
    $jenisType !== '' ? ('Tipe saat ini: ' . $jenisType) : 'Kolom jenis_izin tidak ditemukan.'
);

$izinPembCols = column_exists($pdo, 'perizinan_pembimbing', 'kegiatan_id');
$checks[] = $checkOk(
    'Perizinan pembimbing punya kegiatan_id',
    $izinPembCols,
    $izinPembCols ? 'Kolom kegiatan_id ditemukan.' : 'Kolom kegiatan_id belum ada di perizinan_pembimbing.'
);

$gajiTableExists = table_exists($pdo, 'keuangan_gaji_pembimbing');
$checks[] = $checkOk(
    'Tabel gaji pembimbing tersedia',
    $gajiTableExists,
    $gajiTableExists ? 'Tabel keuangan_gaji_pembimbing tersedia.' : 'Tabel keuangan_gaji_pembimbing belum dibuat.'
);

$hasUnique = false;
if ($gajiTableExists) {
    try {
        $idxRows = $pdo->query("SHOW INDEX FROM keuangan_gaji_pembimbing")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($idxRows as $idx) {
            if ((string) ($idx['Key_name'] ?? '') === 'uk_gaji_pembimbing_periode') {
                $hasUnique = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $hasUnique = false;
    }
}
$checks[] = $checkOk(
    'Unique key anti dobel gaji aktif',
    $hasUnique,
    $hasUnique ? 'Unique key uk_gaji_pembimbing_periode ditemukan.' : 'Unique key belum ditemukan.'
);

$dupes = [];
if ($gajiTableExists) {
    $dupStmt = $pdo->query('
        SELECT pembimbing_id, periode_mode, tahun, bulan, COUNT(*) AS total
        FROM keuangan_gaji_pembimbing
        GROUP BY pembimbing_id, periode_mode, tahun, bulan
        HAVING COUNT(*) > 1
        ORDER BY total DESC, tahun DESC, bulan DESC
        LIMIT 20
    ');
    $dupes = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$checks[] = $checkOk(
    'Data gaji duplikat per periode',
    $dupes === [],
    $dupes === [] ? 'Tidak ditemukan data gaji duplikat.' : ('Ditemukan ' . count($dupes) . ' grup duplikat.')
);

$allGood = array_reduce($checks, static fn(bool $carry, array $c): bool => $carry && $c['ok'], true);

$pageTitle = 'Administrasi — Cek Update Sistem';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <p class="text-muted small mb-1">Administrasi</p>
        <h1 class="h3 mb-0">Cek Update Sistem</h1>
        <p class="text-muted small mb-0">Validasi cepat hasil migrasi dan perubahan modul terbaru.</p>
    </div>
    <a href="/admin/cek_update.php" class="btn btn-outline-secondary">Refresh cek</a>
</div>

<div class="alert <?= $allGood ? 'alert-success' : 'alert-warning' ?> mb-3">
    <strong><?= $allGood ? 'Semua cek utama lulus.' : 'Ada cek yang perlu ditindaklanjuti.' ?></strong>
    <div class="small mt-1">Silakan jalankan ulang SQL update bila ada status gagal.</div>
</div>

<div class="card shadow-sm mb-3">
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Pemeriksaan</th>
                <th style="width: 130px;">Status</th>
                <th>Detail</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars((string) $c['label']) ?></td>
                    <td>
                        <span class="badge text-bg-<?= $c['ok'] ? 'success' : 'danger' ?>">
                            <?= $c['ok'] ? 'OK' : 'Gagal' ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars((string) $c['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($dupes !== []): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light"><strong>Duplikat gaji pembimbing</strong></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                <tr>
                    <th>Pembimbing ID</th>
                    <th>Mode</th>
                    <th>Tahun</th>
                    <th>Bulan</th>
                    <th>Total Duplikat</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($dupes as $d): ?>
                    <tr>
                        <td><?= (int) ($d['pembimbing_id'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($d['periode_mode'] ?? '-')) ?></td>
                        <td><?= (int) ($d['tahun'] ?? 0) ?></td>
                        <td><?= (int) ($d['bulan'] ?? 0) ?></td>
                        <td><?= (int) ($d['total'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (is_super_admin()): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header bg-light py-2">
        <strong><i class="fa-solid fa-broom me-1"></i> Bersihkan cache kinerja</strong>
    </div>
    <div class="card-body small">
        <p class="mb-2 text-muted">
            Cache sesi (menu ACL, snapshot keuangan, pengaturan, realisasi alokasi) kadang membuat data tampak basi atau membesarkan file sesi PHP.
            Gunakan setelah deploy atau ubah pengaturan besar.
        </p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="clear_cache">
            <div class="col-12 col-md-auto">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="include_schema" value="1" id="cacheSchema">
                    <label class="form-check-label" for="cacheSchema">Reset flag migrasi skema <span class="text-muted">(1× request berikutnya sedikit lebih lambat)</span></label>
                </div>
            </div>
            <?php if (function_exists('opcache_reset')): ?>
            <div class="col-12 col-md-auto">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="include_opcache" value="1" id="cacheOpcache">
                    <label class="form-check-label" for="cacheOpcache">Reset OPcache PHP</label>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Bersihkan cache sesi untuk user yang sedang login?');">
                    Bersihkan cache sekarang
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="small text-muted">
    Tip: jika ada gagal, jalankan file <code>update_import_2026_05_santri_pembimbing_gaji.sql</code> di phpMyAdmin.
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
