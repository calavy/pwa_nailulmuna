<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';

$tagihanKumulatif = wali_portal_tagihan_sampai_bulan_berjalan($pdo, $waliSantriId, $waliKelasKategori);
$berjalan = (array) ($tagihanKumulatif['berjalan'] ?? []);
$bulanIni = (int) ($berjalan['bulan'] ?? 1);

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

?>
        <?php require __DIR__ . '/partials/greeting.php'; ?>
        <?php
        $portalProfileRow = $waliSantriRow;
        $portalProfileContext = 'wali';
        $portalProfileShowLogout = true;
        $anakCount = isset($waliAnakRows) ? count($waliAnakRows) : 1;
        $portalProfileExtraHtml = 'Ringkasan tagihan, presensi, dan informasi anak Anda'
            . ($anakCount > 1 ? ' — ganti anak lewat menu di atas.' : '.');
        require __DIR__ . '/../includes/partials/portal_profile_hero.php';
        ?>

        <?php $compact = true; require __DIR__ . '/partials/tagihan_ringkasan.php'; ?>

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
                    <a class="small fw-semibold" href="/wali/keaktifan.php">Detail presensi</a>
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
                <?php
                if (!function_exists('santri_resolve_no_wa_wali')) {
                    require_once __DIR__ . '/../helpers/santri_wa.php';
                }
                $waliWaTampil = santri_resolve_no_wa_wali($pdo, $waliSantriRow);
                if ($waliWaTampil !== ''): ?>
                    <div class="mt-2 text-muted mb-1">Kontak WA tercatat</div>
                    <div class="font-monospace"><?= htmlspecialchars($waliWaTampil) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <p class="text-center small text-muted mb-0">Butuh ubah PIN atau data? Hubungi pengurus pondok.</p>
<?php
wali_layout_foot(true, 'beranda');
