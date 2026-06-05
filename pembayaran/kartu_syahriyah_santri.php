<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_kartu_syahriyah.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';

require_roles(['admin', 'pengurus']);
keuangan_ensure_schema_deferred($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$taMulai = (int) $keuanganTa['mulai'];
$taSelesai = (int) $keuanganTa['selesai'];
$berjalan = keuangan_periode_berjalan($pdo);
$bulanBerjalan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));

$q = trim((string) ($_GET['q'] ?? ''));
$santriId = (int) ($_GET['santri_id'] ?? 0);
$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

$santriPick = null;
$bulanRows = [];
$totalBayar = 0;
$totalHarus = 0;

if ($santriId > 0 && table_exists($pdo, 'santri')) {
    $st = $pdo->prepare('SELECT id, ' . $namaCol . ' AS nama_santri, nis, tingkatan, kategori_kelas FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $santriPick = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($santriPick) {
        $bulanRows = keuangan_kartu_syahriyah_bulan_rows($pdo, $santriId, $taMulai, $taSelesai, $bulanBerjalan);
        foreach ($bulanRows as $br) {
            if (($br['status'] ?? '') === 'belum') {
                continue;
            }
            $totalHarus += (int) ($br['harus'] ?? 0);
            $totalBayar += (int) ($br['bayar'] ?? 0);
        }
    }
}

$cariRows = [];
if ($q !== '' && strlen($q) >= 2) {
    $aktifSql = santri_sql_aktif_only('s');
    $stC = $pdo->prepare('
        SELECT id, ' . $namaCol . ' AS nama_santri, nis, tingkatan
        FROM santri s
        WHERE ' . $aktifSql . '
          AND (s.' . $namaCol . ' LIKE :q OR s.nis LIKE :q OR s.qr LIKE :q)
        ORDER BY s.' . $namaCol . ' ASC
        LIMIT 20
    ');
    $stC->execute(['q' => '%' . $q . '%']);
    $cariRows = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Kartu Syahriyah Santri';
$pageStylesheets = [app_asset_href('/assets/css/kartu-syahriyah.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Kartu syahriyah santri</h1>
    <p class="text-muted small mb-0">
        Laporan pembayaran syahriyah per bulan — tahun ajaran <?= (int) $taMulai ?>/<?= (int) $taSelesai ?>.
        Data tampil hingga bulan berjalan; bulan berikutnya bertanda <em>belum</em>.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label class="form-label small mb-0">Cari santri (nama / NIS / QR)</label>
                <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>"
                       placeholder="Ketik minimal 2 huruf lalu cari" autofocus>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">Cari</button>
            </div>
        </form>
        <?php if ($cariRows !== [] && $santriPick === null): ?>
            <div class="list-group list-group-flush border rounded mt-3">
                <?php foreach ($cariRows as $cr): ?>
                    <a class="list-group-item list-group-item-action"
                       href="<?= htmlspecialchars(app_href('/pembayaran/kartu_syahriyah_santri.php?santri_id=' . (int) ($cr['id'] ?? 0))) ?>">
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($cr['nama_santri'] ?? '-')) ?></div>
                        <div class="small text-muted">NIS <?= htmlspecialchars((string) ($cr['nis'] ?? '-')) ?> · <?= htmlspecialchars((string) ($cr['tingkatan'] ?? '-')) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php elseif ($q !== '' && strlen($q) >= 2 && $cariRows === []): ?>
            <p class="small text-muted mt-2 mb-0">Santri tidak ditemukan.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($santriPick): ?>
    <div class="card shadow-sm border-primary mb-3 ks-santri-head">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 class="h5 mb-1"><?= htmlspecialchars((string) ($santriPick['nama_santri'] ?? '-')) ?></h2>
                    <div class="text-muted small">
                        NIS <?= htmlspecialchars((string) ($santriPick['nis'] ?? '-')) ?>
                        · <?= htmlspecialchars((string) ($santriPick['tingkatan'] ?? '-')) ?>
                        · <?= htmlspecialchars((string) ($santriPick['kategori_kelas'] ?? '-')) ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="small text-muted">Total terbayar (s/d bulan ini)</div>
                    <div class="fs-5 fw-bold text-success">Rp <?= number_format($totalBayar, 0, ',', '.') ?></div>
                    <?php if ($totalHarus > $totalBayar): ?>
                        <div class="small text-danger">Kurang Rp <?= number_format($totalHarus - $totalBayar, 0, ',', '.') ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-2">
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php?santri_id=' . $santriId)) ?>">Input pembayaran</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pembayaran/riwayat.php?santri_id=' . $santriId)) ?>">Riwayat</a>
            </div>
        </div>
    </div>

    <div class="row g-2 ks-bulan-grid">
        <?php foreach ($bulanRows as $br): ?>
            <?php
            $st = (string) ($br['status'] ?? 'belum');
            $cardClass = match ($st) {
                'lunas' => 'ks-month--lunas',
                'sebagian' => 'ks-month--sebagian',
                'belum_bayar' => 'ks-month--nunggak',
                'belum' => 'ks-month--future',
                default => 'ks-month--netral',
            };
            ?>
            <div class="col-6 col-md-4 col-lg-3">
                <article class="ks-month <?= $cardClass ?>">
                    <div class="ks-month__title"><?= htmlspecialchars((string) ($br['label'] ?? '')) ?></div>
                    <?php if ($st === 'belum'): ?>
                        <div class="ks-month__status">Belum</div>
                        <p class="ks-month__hint small mb-0">Periode belum berjalan</p>
                    <?php else: ?>
                        <div class="ks-month__bayar">Rp <?= number_format((int) ($br['bayar'] ?? 0), 0, ',', '.') ?></div>
                        <div class="ks-month__harus small">dari Rp <?= number_format((int) ($br['harus'] ?? 0), 0, ',', '.') ?></div>
                        <?php if ((int) ($br['pkpps'] ?? 0) > 0): ?>
                            <div class="ks-month__pkpps small">+PKPPS Rp <?= number_format((int) $br['pkpps'], 0, ',', '.') ?></div>
                        <?php endif; ?>
                        <span class="badge ks-month__badge"><?= htmlspecialchars((string) ($br['keterangan'] ?? '')) ?></span>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($santriId > 0): ?>
    <div class="alert alert-warning">Santri tidak ditemukan.</div>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fa-solid fa-id-card fa-2x mb-2 opacity-50"></i>
        <p class="mb-0 small">Cari dan pilih santri untuk menampilkan kartu pembayaran syahriyah.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
