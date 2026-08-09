<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_ikhtibar_tables($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$mapelGroups = ikhtibar_nilai_hasil_per_mapel($pdo, $userId, null);

$pageTitle = 'Nilai Ikhtibar per Mapel';
$bodyClass = 'ikhtibar-rekap-page';
require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/ikhtibar-hasil.css')) ?>" rel="stylesheet">

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/akademik/ikhtibar.php')) ?>">Tugas Ikhtibar</a> · Nilai
    </p>
    <h1 class="h4 mb-1"><i class="fa-solid fa-chart-column text-primary me-1"></i> Nilai Ikhtibar per Mapel</h1>
    <p class="text-muted mb-0">Rekap hasil tugas kajian seluruh pembimbing, dikelompokkan per mapel.</p>
</div>

<?php if ($mapelGroups === []): ?>
    <div class="alert alert-light border">Belum ada data nilai.</div>
<?php else: ?>
    <?php foreach ($mapelGroups as $grp): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong><?= htmlspecialchars((string) $grp['mapel']) ?></strong>
                <span class="small text-muted">
                    <?= (int) ($grp['total_selesai'] ?? 0) ?> sesi selesai
                    <?php if ($grp['rata_nilai'] !== null): ?>
                        · Rata <?= htmlspecialchars((string) $grp['rata_nilai']) ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Pembimbing</th>
                                <th>Kelompok</th>
                                <th>Judul</th>
                                <th>Tingkatan</th>
                                <th>Periode</th>
                                <th class="text-center">Selesai</th>
                                <th class="text-center">Rata</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($grp['tugas'] as $t):
                            $tid = (int) ($t['id'] ?? 0);
                            $tgl = (string) ($t['tanggal'] ?? '');
                            $tglAkhir = trim((string) ($t['tanggal_selesai'] ?? ''));
                            $periode = $tglAkhir !== '' && $tglAkhir !== $tgl ? $tgl . ' – ' . $tglAkhir : $tgl;
                            ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars((string) ($t['pembimbing_nama'] ?? '—')) ?></td>
                                <td><?= htmlspecialchars(trim((string) ($t['kelompok_label'] ?? '')) ?: '—') ?></td>
                                <td><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></td>
                                <td><?= htmlspecialchars(trim((string) ($t['filter_tingkatan'] ?? '')) ?: 'Semua') ?></td>
                                <td class="small"><?= htmlspecialchars($periode) ?></td>
                                <td class="text-center"><?= (int) ($t['jumlah_selesai'] ?? 0) ?></td>
                                <td class="text-center"><?= $t['rata_nilai'] !== null ? htmlspecialchars((string) $t['rata_nilai']) : '—' ?></td>
                                <td class="text-end">
                                    <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/nilai.php?tugas_id=' . $tid)) ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
