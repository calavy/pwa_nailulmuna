<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/keuangan_bos.php';

require_login();
require_roles(['admin', 'pengurus']);

bos_ensure_schema($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$taMulai = (int) $keuanganTa['mulai'];
$taSelesai = (int) $keuanganTa['selesai'];
$periodeMasehi = bos_resolve_periode_masehi($_GET);
$bulanMasehi = (int) $periodeMasehi['bulan'];
$tahunMasehi = (int) $periodeMasehi['tahun'];
$bulanLabel = (string) $periodeMasehi['label'];
$periodeRange = bos_resolve_periode_range($_GET);
$dashKeuangan = bos_dashboard_keuangan($pdo, $periodeRange);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $bulanPost = max(1, min(12, (int) ($_POST['bulan'] ?? $bulanMasehi)));
    $tahunPost = (int) ($_POST['tahun'] ?? $tahunMasehi);

    if ($action === 'catat_wustho') {
        $result = bos_catat_penerimaan_bulk($pdo, BOS_JENJANG_WUSTHO, $bulanPost, $tahunPost, $userId, null, $taMulai, $taSelesai);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'catat_ulya') {
        $result = bos_catat_penerimaan_bulk($pdo, BOS_JENJANG_ULYA, $bulanPost, $tahunPost, $userId, null, $taMulai, $taSelesai);
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    }

    header('Location: ' . app_href('/keuangan-bos/index.php?bulan=' . $bulanPost . '&tahun=' . $tahunPost));
    exit;
}

$rekap = bos_rekap_santri_per_tingkatan($pdo);
$sudahWustho = bos_bulk_sudah_dicatat($pdo, BOS_JENJANG_WUSTHO, BOS_SUMBER_BOS_WUSTHO, $bulanMasehi, $tahunMasehi);
$sudahUlya = bos_bulk_sudah_dicatat($pdo, BOS_JENJANG_ULYA, BOS_SUMBER_BOS_ULYA, $bulanMasehi, $tahunMasehi);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Dashboard Keuangan BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Keuangan BOS · PKPPS</p>
    <h1 class="h4 mb-1">Dashboard Keuangan BOS</h1>
    <p class="text-muted mb-0">
        Periode dihitung dengan <strong>kalender Masehi</strong> (Januari–Desember). Modul terpisah dari keuangan pondok.
        <a href="<?= htmlspecialchars(app_href('/keuangan-bos/pengaturan.php')) ?>">Pengaturan nominal</a>
        · <a href="<?= htmlspecialchars(app_href('/keuangan-bos/laporan-lra.php?bulan_mulai=' . (int) $periodeRange['bulan_mulai'] . '&tahun_mulai=' . (int) $periodeRange['tahun_mulai'] . '&bulan_selesai=' . (int) $periodeRange['bulan_selesai'] . '&tahun_selesai=' . (int) $periodeRange['tahun_selesai'])) ?>">Laporan LRA</a>
    </p>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Ringkasan Keuangan — <?= htmlspecialchars((string) $periodeRange['label']) ?></div>
    <div class="card-body pb-2">
        <?php
        $formAction = app_href('/keuangan-bos/index.php');
        $hiddenParams = [
            'bulan_mulai' => (int) $periodeRange['bulan_mulai'],
            'tahun_mulai' => (int) $periodeRange['tahun_mulai'],
            'bulan_selesai' => (int) $periodeRange['bulan_selesai'],
            'tahun_selesai' => (int) $periodeRange['tahun_selesai'],
        ];
        require __DIR__ . '/partials/filter_periode_range.php';
        ?>
    </div>
    <div class="card-body pt-0">
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="border rounded-3 p-3 h-100 border-primary bg-primary bg-opacity-10">
                    <div class="text-muted small">Saldo Awal</div>
                    <div class="h5 mb-0 text-primary"><?= htmlspecialchars($fmt((int) $dashKeuangan['saldo_awal'])) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="border rounded-3 p-3 h-100 border-success bg-success bg-opacity-10">
                    <div class="text-muted small">Total Masuk</div>
                    <div class="h5 mb-0 text-success"><?= htmlspecialchars($fmt((int) $dashKeuangan['total_masuk'])) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="border rounded-3 p-3 h-100 border-danger bg-danger bg-opacity-10">
                    <div class="text-muted small">Total Keluar</div>
                    <div class="h5 mb-0 text-danger"><?= htmlspecialchars($fmt((int) $dashKeuangan['total_keluar'])) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="border rounded-3 p-3 h-100 border-info bg-info bg-opacity-10">
                    <div class="text-muted small">Saldo Akhir</div>
                    <div class="h5 mb-0 text-info"><?= htmlspecialchars($fmt((int) $dashKeuangan['saldo_akhir'])) ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="fw-semibold small mb-2">Rekap per sumber dana</div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sumber Dana</th>
                                <th class="text-end">Masuk</th>
                                <th class="text-end">Keluar</th>
                                <th class="text-end">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashKeuangan['per_sumber_dana'] as $sd): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($sd['label'] ?? '')) ?></td>
                                    <td class="text-end text-success"><?= htmlspecialchars($fmt((int) ($sd['masuk'] ?? 0))) ?></td>
                                    <td class="text-end text-danger"><?= htmlspecialchars($fmt((int) ($sd['keluar'] ?? 0))) ?></td>
                                    <td class="text-end fw-semibold"><?= htmlspecialchars($fmt((int) ($sd['saldo'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="fw-semibold small mb-2">Pengeluaran per kategori</div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kategori</th>
                                <th>Jenis</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($dashKeuangan['per_kategori'] === []): ?>
                                <tr><td colspan="3" class="text-muted text-center">Belum ada pengeluaran.</td></tr>
                            <?php else: ?>
                                <?php foreach ($dashKeuangan['per_kategori'] as $kat): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($kat['nama'] ?? '')) ?></td>
                                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars((string) ($kat['jenis'] ?? '')) ?></span></td>
                                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($kat['total'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-12">
                <div class="fw-semibold small mb-2">Per akun bank</div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Akun</th>
                                <th class="text-end">Saldo Awal</th>
                                <th class="text-end">Mutasi</th>
                                <th class="text-end">Saldo Akhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashKeuangan['per_akun'] as $ak): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($ak['nama_akun'] ?? '')) ?></td>
                                    <td class="text-end"><?= htmlspecialchars($fmt((int) ($ak['saldo_awal'] ?? 0))) ?></td>
                                    <td class="text-end"><?= htmlspecialchars($fmt((int) ($ak['mutasi'] ?? 0))) ?></td>
                                    <td class="text-end fw-semibold"><?= htmlspecialchars($fmt((int) ($ak['saldo_akhir'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <?php
        $formAction = app_href('/keuangan-bos/index.php');
        $hiddenParams = [
            'bulan_mulai' => (int) $periodeRange['bulan_mulai'],
            'tahun_mulai' => (int) $periodeRange['tahun_mulai'],
            'bulan_selesai' => (int) $periodeRange['bulan_selesai'],
            'tahun_selesai' => (int) $periodeRange['tahun_selesai'],
        ];
        require __DIR__ . '/partials/filter_periode_masehi.php';
        ?>
        <p class="small text-muted mb-0 mt-2">Referensi TA pondok: <?= (int) $taMulai ?>/<?= (int) $taSelesai ?> (hanya informasi, periode BOS mengikuti Masehi).</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm h-100 border-primary">
            <div class="card-body">
                <div class="text-muted small">Total santri PKPPS</div>
                <div class="h4 mb-0"><?= (int) $rekap['grand_count'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Estimasi BOS Wustho / bulan</div>
                <div class="h5 mb-0"><?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_WUSTHO]['total'] ?? 0))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Estimasi BOS Ulya / bulan</div>
                <div class="h5 mb-0"><?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_ULYA]['total'] ?? 0))) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Rekap santri per tingkatan</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Tingkatan</th>
                    <th>Jenjang</th>
                    <th class="text-end">Jumlah Santri</th>
                    <th class="text-end">Nominal/Santri</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rekap['rows'] === []): ?>
                    <tr><td colspan="5" class="text-muted text-center py-4">Belum ada santri PKPPS aktif.</td></tr>
                <?php else: ?>
                    <?php foreach ($rekap['rows'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['nama_tingkatan'] ?? '')) ?></td>
                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars((string) ($row['jenjang_label'] ?? '')) ?></span></td>
                            <td class="text-end"><?= (int) ($row['jumlah_santri'] ?? 0) ?></td>
                            <td class="text-end"><?= htmlspecialchars($fmt((int) ($row['nominal_per_santri'] ?? 0))) ?></td>
                            <td class="text-end fw-semibold"><?= htmlspecialchars($fmt((int) ($row['total'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-semibold">
                        <td colspan="2">Subtotal Wustho</td>
                        <td class="text-end"><?= (int) ($rekap['subtotals'][BOS_JENJANG_WUSTHO]['jumlah_santri'] ?? 0) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_WUSTHO]['nominal_per_santri'] ?? 0))) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_WUSTHO]['total'] ?? 0))) ?></td>
                    </tr>
                    <tr class="table-light fw-semibold">
                        <td colspan="2">Subtotal Ulya</td>
                        <td class="text-end"><?= (int) ($rekap['subtotals'][BOS_JENJANG_ULYA]['jumlah_santri'] ?? 0) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_ULYA]['nominal_per_santri'] ?? 0))) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_ULYA]['total'] ?? 0))) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Input penerimaan satu klik — <?= htmlspecialchars($bulanLabel) ?></div>
    <div class="card-body">
        <p class="small text-muted">Mencatat penerimaan BOS sekaligus: jumlah santri × nominal pengaturan untuk bulan Masehi yang dipilih.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="fw-semibold">BOS Wustho</div>
                            <div class="small text-muted"><?= (int) ($rekap['subtotals'][BOS_JENJANG_WUSTHO]['jumlah_santri'] ?? 0) ?> santri · <?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_WUSTHO]['total'] ?? 0))) ?></div>
                        </div>
                        <?php if ($sudahWustho): ?>
                            <span class="badge bg-success">Sudah dicatat</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Belum dicatat</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" onsubmit="return confirm('Catat penerimaan BOS Wustho untuk <?= htmlspecialchars($bulanLabel) ?>?');">
                        <input type="hidden" name="action" value="catat_wustho">
                        <input type="hidden" name="bulan" value="<?= $bulanMasehi ?>">
                        <input type="hidden" name="tahun" value="<?= $tahunMasehi ?>">
                        <button type="submit" class="btn btn-primary btn-sm" <?= $sudahWustho ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-bolt me-1"></i> Catat Penerimaan BOS Wustho
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="fw-semibold">BOS Ulya</div>
                            <div class="small text-muted"><?= (int) ($rekap['subtotals'][BOS_JENJANG_ULYA]['jumlah_santri'] ?? 0) ?> santri · <?= htmlspecialchars($fmt((int) ($rekap['subtotals'][BOS_JENJANG_ULYA]['total'] ?? 0))) ?></div>
                        </div>
                        <?php if ($sudahUlya): ?>
                            <span class="badge bg-success">Sudah dicatat</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Belum dicatat</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" onsubmit="return confirm('Catat penerimaan BOS Ulya untuk <?= htmlspecialchars($bulanLabel) ?>?');">
                        <input type="hidden" name="action" value="catat_ulya">
                        <input type="hidden" name="bulan" value="<?= $bulanMasehi ?>">
                        <input type="hidden" name="tahun" value="<?= $tahunMasehi ?>">
                        <button type="submit" class="btn btn-success btn-sm" <?= $sudahUlya ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-bolt me-1"></i> Catat Penerimaan BOS Ulya
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
