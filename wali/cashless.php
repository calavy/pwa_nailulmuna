<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/wali_portal.php';

$dailyLimit = (int) app_setting($pdo, 'cashless_daily_limit', '10000');

$saldo = wali_portal_cashless_saldo($pdo, $waliSantriId);
$debitHariIni = wali_portal_cashless_debit_hari_ini($pdo, $waliSantriId);
$txRows = wali_portal_cashless_transactions($pdo, $waliSantriId, 100);

$nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$detailStmt = $pdo->prepare('SELECT id, nis, ' . $nameCol . ' AS nama_tampil FROM santri WHERE id = :id LIMIT 1');
$detailStmt->execute(['id' => $waliSantriId]);
$detail = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: [];

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Log jajan cashless — Portal Wali', true, 'keuangan');
require __DIR__ . '/partials/greeting.php';
?>

        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Log jajan (cashless)</h1>
                <p class="small text-muted mb-0">Transparansi top-up saku dan belanja di pondok.</p>
            </div>
            <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="/wali/logout.php">Keluar</a>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="/wali/keuangan.php"><i class="fa-solid fa-wallet me-1"></i> Ringkasan keuangan</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=bayar')) ?>"><i class="fa-solid fa-receipt me-1"></i> Riwayat Keuangan</a>
        </div>

        <div class="card shadow-sm wali-card mb-3 border-primary border-opacity-25">
            <div class="card-body">
                <div class="fw-bold mb-1"><?= htmlspecialchars((string) ($detail['nama_tampil'] ?? '')) ?></div>
                <div class="font-monospace small text-muted mb-3">NIS <?= htmlspecialchars((string) ($detail['nis'] ?? '')) ?></div>
                <div class="row g-2 text-center">
                    <div class="col-6">
                        <div class="rounded-3 bg-light py-2">
                            <div class="small text-muted">Saldo saku</div>
                            <div class="font-monospace fw-bold fs-5"><?= htmlspecialchars(wali_portal_format_rupiah((int) round((float) ($saldo ?? 0)))) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rounded-3 bg-warning bg-opacity-10 py-2">
                            <div class="small text-muted">Belanja hari ini</div>
                            <div class="font-monospace fw-semibold"><?= htmlspecialchars(wali_portal_format_rupiah($debitHariIni)) ?></div>
                            <div class="text-muted" style="font-size:0.7rem">Batas <?= htmlspecialchars(wali_portal_format_rupiah($dailyLimit)) ?>/hari</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm wali-card">
            <div class="card-header bg-white small fw-semibold">Riwayat transaksi</div>
            <div class="card-body p-0">
                <?php if (!table_exists($pdo, 'cashless_transactions')): ?>
                    <p class="small text-muted p-3 mb-0">Fitur cashless belum aktif di sistem.</p>
                <?php elseif ($txRows === []): ?>
                    <p class="small text-muted p-3 mb-0">Belum ada transaksi cashless untuk santri ini.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($txRows as $tx):
                            $jenis = strtoupper((string) ($tx['jenis'] ?? ''));
                            $nom = (int) round((float) ($tx['nominal'] ?? 0));
                            $isTopup = $jenis === 'TOPUP';
                            $tgl = (string) ($tx['tanggal'] ?? '');
                            $tglLabel = $tgl !== '' ? date('d/m/Y H:i', strtotime($tgl)) : '—';
                            $ket = trim((string) ($tx['keterangan'] ?? ''));
                            ?>
                            <div class="list-group-item py-2">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small">
                                        <span class="badge text-bg-<?= $isTopup ? 'success' : 'secondary' ?> me-1"><?= $isTopup ? 'Top-up' : 'Belanja' ?></span>
                                        <span class="text-muted"><?= htmlspecialchars($tglLabel) ?></span>
                                        <?php if ($ket !== ''): ?>
                                            <p class="text-muted mt-1 mb-0"><?= htmlspecialchars($ket) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <span class="font-monospace fw-semibold <?= $isTopup ? 'text-success' : 'text-danger' ?> flex-shrink-0">
                                        <?= $isTopup ? '+' : '−' ?><?= htmlspecialchars(wali_portal_format_rupiah($nom)) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<?php
wali_layout_foot(true, 'keuangan');
