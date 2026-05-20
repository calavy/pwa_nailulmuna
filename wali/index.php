<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

$berjalan = keuangan_periode_berjalan($pdo);
$bulanIni = $berjalan['bulan'];
$periodeMulai = $berjalan['mulai'];
$periodeSelesai = $berjalan['selesai'];
$tagihanBulanIni = tagihan_wajib_status_for_month($pdo, $waliSantriId, $bulanIni, $periodeMulai, $periodeSelesai, $waliKelasKategori);
$tagihanExpected = (int) ($tagihanBulanIni['expected_total'] ?? 0);
$tagihanPaid = (int) ($tagihanBulanIni['paid_total'] ?? 0);
$tagihanSisa = (int) ($tagihanBulanIni['sisa_total'] ?? 0);
$perPosBulan = (array) ($tagihanBulanIni['per_pos'] ?? []);

$bulanNama = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

$presensiRingkas = ['HADIR' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];
if (table_exists($pdo, 'presensi')) {
    $d1 = date('Y-m-01');
    $d2 = date('Y-m-t');
    $ps = $pdo->prepare('
        SELECT status_presensi, COUNT(*) AS c
        FROM presensi
        WHERE santri_id = :sid AND tanggal_presensi BETWEEN :d1 AND :d2
        GROUP BY status_presensi
    ');
    $ps->execute(['sid' => $waliSantriId, 'd1' => $d1, 'd2' => $d2]);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = strtoupper((string) ($r['status_presensi'] ?? ''));
        if (isset($presensiRingkas[$k])) {
            $presensiRingkas[$k] = (int) $r['c'];
        }
    }
}

$cashlessSaldo = null;
if (table_exists($pdo, 'cashless_accounts')) {
    $cs = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $cs->execute(['id' => $waliSantriId]);
    $rowC = $cs->fetch(PDO::FETCH_ASSOC);
    if ($rowC) {
        $cashlessSaldo = (float) ($rowC['balance'] ?? 0);
    } else {
        $cashlessSaldo = 0.0;
    }
}

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Beranda Wali', true, 'beranda');

$namaAnak = (string) ($waliSantriRow['nama_tampil'] ?? '');
$anakCount = isset($waliAnakRows) ? count($waliAnakRows) : 1;
?>
        <?php require __DIR__ . '/partials/greeting.php'; ?>
        <div class="wali-hero mb-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="font-monospace small text-muted mb-0">NIS <?= htmlspecialchars((string) $waliSantriRow['nis']) ?></div>
                </div>
                <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="/wali/logout.php">Keluar</a>
            </div>
            <p class="small text-muted mb-0 mt-2">
                Ringkasan tagihan, presensi, dan informasi anak Anda<?= $anakCount > 1 ? ' — ganti anak lewat menu di atas.' : '.' ?>
            </p>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <a href="/wali/keuangan.php" class="wali-tile-link h-100">
                    <span class="wali-tile-ico"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span>
                    <div class="wali-tile-title">Keuangan</div>
                    <div class="wali-tile-desc">Tagihan &amp; pembayaran</div>
                </a>
            </div>
            <div class="col-6">
                <a href="/wali/keaktifan.php" class="wali-tile-link h-100">
                    <span class="wali-tile-ico"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span>
                    <div class="wali-tile-title">Keaktifan</div>
                    <div class="wali-tile-desc">Presensi bulan ini</div>
                </a>
            </div>
            <div class="col-6">
                <a href="/wali/riwayat.php" class="wali-tile-link h-100">
                    <span class="wali-tile-ico"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                    <div class="wali-tile-title">Riwayat</div>
                    <div class="wali-tile-desc">Domisili, khidmah &amp; pelanggaran</div>
                </a>
            </div>
        </div>

        <div class="card shadow-sm wali-card mb-3">
            <div class="card-body">
                <div class="wali-kicker mb-2">Tagihan bulan ini</div>
                <div class="small text-muted mb-2">
                    <span class="badge text-bg-primary me-1" style="font-size:.65rem">Bulan ini</span>
                    <?= htmlspecialchars($berjalan['bulan_label']) ?> <?= (int) $berjalan['tahun_kalender'] ?> · TA <?= htmlspecialchars($berjalan['ta_label']) ?> · Syahriyah + Makan
                </div>
                <div class="d-flex justify-content-between mb-1 small">
                    <span class="wali-stat-label">Syahriyah</span>
                    <span class="font-monospace">Rp <?= number_format((int) (($perPosBulan['syahriyah']['paid'] ?? 0)), 0, ',', '.') ?> / <?= number_format((int) (($perPosBulan['syahriyah']['expected'] ?? 0)), 0, ',', '.') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="wali-stat-label">Makan</span>
                    <span class="font-monospace">Rp <?= number_format((int) (($perPosBulan['makan']['paid'] ?? 0)), 0, ',', '.') ?> / <?= number_format((int) (($perPosBulan['makan']['expected'] ?? 0)), 0, ',', '.') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="wali-stat-label">Total tagihan</span>
                    <span class="font-monospace wali-stat-value" style="font-size:1rem">Rp <?= number_format($tagihanExpected, 0, ',', '.') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="wali-stat-label">Terbayar</span>
                    <span class="font-monospace text-success fw-bold">Rp <?= number_format($tagihanPaid, 0, ',', '.') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <span class="wali-stat-label">Sisa</span>
                    <span class="font-monospace fw-bold <?= $tagihanSisa > 0 ? 'text-danger' : 'text-success' ?>">Rp <?= number_format($tagihanSisa, 0, ',', '.') ?></span>
                </div>
                <a class="btn btn-sm btn-teal w-100" href="/wali/pembayaran.php">Riwayat & bukti pembayaran</a>
                <a class="btn btn-sm btn-outline-secondary w-100 mt-2" href="/wali/tagihan.php">Tabel 12 bulan</a>
                <p class="small text-muted mt-2 mb-0">Pembayaran dilakukan melalui pengurus pondok.</p>
            </div>
        </div>

        <?php if ($cashlessSaldo !== null): ?>
        <div class="card shadow-sm wali-card mb-3">
            <div class="card-body py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="wali-stat-label">Saldo tabungan</div>
                    <div class="small fw-semibold">Cashless</div>
                </div>
                <span class="font-monospace wali-stat-value">Rp <?= number_format((int) round($cashlessSaldo), 0, ',', '.') ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm wali-card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="wali-kicker mb-0">Presensi bulan ini</div>
                    <a class="small fw-semibold" href="/wali/keaktifan.php">Detail</a>
                </div>
                <div class="row g-2 text-center small">
                    <div class="col-3">
                        <div class="rounded-2 bg-light py-2">
                            <div class="text-muted">Hadir</div>
                            <div class="fw-bold text-success fs-6"><?= (int) $presensiRingkas['HADIR'] ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="rounded-2 bg-light py-2">
                            <div class="text-muted">Izin</div>
                            <div class="fw-bold text-warning fs-6"><?= (int) $presensiRingkas['IZIN'] ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="rounded-2 bg-light py-2">
                            <div class="text-muted">Sakit</div>
                            <div class="fw-bold text-primary fs-6"><?= (int) $presensiRingkas['SAKIT'] ?></div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="rounded-2 bg-light py-2">
                            <div class="text-muted">Alpa</div>
                            <div class="fw-bold text-danger fs-6"><?= (int) $presensiRingkas['ALPA'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm wali-card mb-3">
            <div class="card-body small">
                <div class="text-muted mb-1">Tingkatan / kelas</div>
                <div class="fw-semibold"><?= htmlspecialchars(trim((string) ($waliSantriRow['tingkatan'] ?? '')) !== '' ? (string) $waliSantriRow['tingkatan'] : '—') ?></div>
                <?php if (trim((string) ($waliSantriRow['kategori_kelas'] ?? '')) !== ''): ?>
                    <div class="mt-2 text-muted mb-1">Kategori keuangan</div>
                    <div><?= htmlspecialchars(kelas_keuangan_label_for_kode($pdo, (string) $waliSantriRow['kategori_kelas'])) ?></div>
                <?php endif; ?>
                <?php if (trim((string) ($waliSantriRow['no_wa_wali'] ?? '')) !== ''): ?>
                    <div class="mt-2 text-muted mb-1">Kontak WA tercatat</div>
                    <div class="font-monospace"><?= htmlspecialchars((string) $waliSantriRow['no_wa_wali']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <p class="text-center small text-muted mb-0">Butuh ubah PIN atau data? Hubungi pengurus pondok.</p>
<?php
wali_layout_foot(true, 'beranda');
