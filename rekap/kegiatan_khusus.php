<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/kegiatan_khusus.php';

require_roles(['admin', 'pengurus']);
kegiatan_khusus_ensure_schema($pdo);

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if ($from > $to) { $t = $from; $from = $to; $to = $t; }

$rows = [];
$st = $pdo->prepare('
    SELECT k.id, k.tanggal, k.nama_kegiatan, k.kategori_kegiatan, k.tingkatan, k.jam_mulai, k.jam_selesai,
           COUNT(p.id) AS total_scan
    FROM kegiatan_khusus k
    LEFT JOIN presensi_kegiatan_khusus p ON p.kegiatan_khusus_id = k.id
    WHERE k.tanggal BETWEEN :f AND :t
    GROUP BY k.id, k.tanggal, k.nama_kegiatan, k.kategori_kegiatan, k.tingkatan, k.jam_mulai, k.jam_selesai
    ORDER BY k.tanggal DESC, k.jam_mulai DESC, k.id DESC
');
$st->execute(['f' => $from, 't' => $to]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Rekap Kegiatan Khusus';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/index.php')) ?>">Rekap</a></p>
    <h1 class="h4 mb-1">Rekap absensi kegiatan khusus</h1>
    <p class="text-muted mb-0 small">Rekap scan untuk kegiatan sekali pakai di luar jadwal rutin.</p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Dari</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Sampai</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-md-6">
                <button class="btn btn-primary" type="submit">Tampilkan</button>
                <a class="btn btn-outline-secondary ms-1" href="<?= htmlspecialchars(app_href('/presensi/kegiatan_khusus.php')) ?>">Kelola kegiatan khusus</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tanggal</th><th>Kegiatan</th><th>Kategori</th><th>Tingkatan</th><th>Waktu</th><th>Total Scan</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-3">Belum ada data.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars((string) ($r['tanggal'] ?? '')) ?></td>
                    <td class="small fw-semibold"><?= htmlspecialchars((string) ($r['nama_kegiatan'] ?? '')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['kategori_kegiatan'] ?? 'TAALIM')) ?></td>
                    <td class="small"><?= htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                    <td class="small"><?= htmlspecialchars(substr((string) ($r['jam_mulai'] ?? ''), 0, 5)) ?> - <?= htmlspecialchars(substr((string) ($r['jam_selesai'] ?? ''), 0, 5)) ?></td>
                    <td class="small fw-semibold"><?= (int) ($r['total_scan'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

