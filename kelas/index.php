<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$tingkatanList = $pdo->query("
    SELECT tingkatan AS nama_tingkatan, COUNT(*) AS total_santri
    FROM santri
    WHERE tingkatan IS NOT NULL AND tingkatan <> ''
    GROUP BY tingkatan
    ORDER BY tingkatan ASC
")->fetchAll();
$totalTingkatan = count($tingkatanList);
$totalSantriDiTingkatan = array_sum(array_map(static fn(array $row): int => (int) ($row['total_santri'] ?? 0), $tingkatanList));

$pageTitle = 'Rekap Tingkatan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="page-intro mb-3 mb-md-4">
            <p class="page-intro-kicker mb-1">Modul Kelas</p>
            <h1 class="h4 mb-1">Rekap tingkatan santri</h1>
            <p class="text-muted mb-0">Ringkasan jumlah santri per tingkatan untuk pemantauan cepat.</p>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-4">
                <div class="app-mini-stat h-100">
                    <div class="app-mini-stat-label">Jumlah tingkatan</div>
                    <div class="app-mini-stat-value"><?= $totalTingkatan ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="app-mini-stat h-100">
                    <div class="app-mini-stat-label">Total santri</div>
                    <div class="app-mini-stat-value"><?= $totalSantriDiTingkatan ?></div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead>
                <tr>
                    <th>Tingkatan</th>
                    <th>Total Santri</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($tingkatanList): ?>
                    <?php foreach ($tingkatanList as $tingkatan): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($tingkatan['nama_tingkatan']) ?></td>
                            <td><span class="badge text-bg-primary"><?= (int) $tingkatan['total_santri'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="2" class="text-center text-muted">Belum ada data tingkatan pada tabel santri.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
