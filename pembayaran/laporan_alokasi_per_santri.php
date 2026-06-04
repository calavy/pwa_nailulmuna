<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_rekap.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';
require_once __DIR__ . '/../helpers/keuangan_pkpps_syahriyah.php';
require_roles(['admin', 'pengurus']);
keuangan_ensure_schema_deferred($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];
$berjalan = keuangan_periode_berjalan($pdo);
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$rekapBulan = max(1, min(12, (int) ($_GET['rekap_bulan'] ?? (int) ($berjalan['bulan'] ?? 1))));

$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
$alokasiKomponen = keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_SYAHRIYAH);
$pkppsTarget = keuangan_pkpps_alokasi_komponen_nama($pdo);

$rows = [];
$totalMasuk = 0;
$totalsPerKomponen = [];

if ($alokasiKomponen !== [] && table_exists($pdo, 'santri')) {
    $paidMap = tagihan_paid_map_for_month($pdo, $rekapBulan, $tahunAjaranMulai, $tahunAjaranSelesai, ['syahriyah']);
    $aktifSql = santri_sql_aktif_only('s');
    $stSantri = $pdo->query('
        SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.kategori_kelas
        FROM santri s
        WHERE ' . $aktifSql . '
        ORDER BY s.' . $namaCol . ' ASC
    ');
    $santriList = $stSantri ? ($stSantri->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($alokasiKomponen as $k) {
        $nama = trim((string) ($k['nama_komponen'] ?? ''));
        if ($nama !== '') {
            $totalsPerKomponen[$nama] = 0;
        }
    }

    foreach ($santriList as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $bayar = (int) ($paidMap[$sid]['syahriyah'] ?? 0);
        if ($bayar <= 0) {
            continue;
        }
        $totalMasuk += $bayar;
        $kat = trim((string) ($s['kategori_kelas'] ?? ''));
        $split = keuangan_syahriyah_split_pembayaran_tambahan(
            $pdo,
            $sid,
            $kat,
            $bayar,
            $rekapBulan,
            $tahunAjaranMulai,
            $tahunAjaranSelesai
        );
        $dasarBayar = (int) ($split['dasar'] ?? $bayar);
        $pkppsBayar = (int) ($split['umum'] ?? 0);

        $komponen = [];
        foreach ($alokasiKomponen as $k) {
            $nama = trim((string) ($k['nama_komponen'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $persen = (float) ($k['persen'] ?? 0);
            $nom = (int) floor($dasarBayar * $persen / 100);
            if ($nama === $pkppsTarget) {
                $nom += $pkppsBayar;
            }
            $komponen[] = [
                'nama' => $nama,
                'persen' => round($persen, 2),
                'nominal' => $nom,
            ];
            $totalsPerKomponen[$nama] = ($totalsPerKomponen[$nama] ?? 0) + $nom;
        }
        if ($pkppsBayar > 0 && !array_key_exists($pkppsTarget, $totalsPerKomponen)) {
            $totalsPerKomponen[$pkppsTarget] = 0;
        }
        if ($pkppsBayar > 0 && !in_array($pkppsTarget, array_map(static fn(array $k): string => trim((string) ($k['nama_komponen'] ?? '')), $alokasiKomponen), true)) {
            $komponen[] = [
                'nama' => $pkppsTarget,
                'persen' => 0.0,
                'nominal' => $pkppsBayar,
            ];
            $totalsPerKomponen[$pkppsTarget] = ($totalsPerKomponen[$pkppsTarget] ?? 0) + $pkppsBayar;
        }
        $rows[] = [
            'nama_santri' => (string) ($s['nama_santri'] ?? '-'),
            'nis' => (string) ($s['nis'] ?? ''),
            'tier' => (string) ($s['kategori_kelas'] ?? ''),
            'bayar' => $bayar,
            'komponen' => $komponen,
        ];
    }
}

$bulanLabel = '';
foreach ($bulanSlots as $slot) {
    if ((int) ($slot['bulan_tagihan'] ?? 0) === $rekapBulan) {
        $bulanLabel = pondok_bulan_slot_label_tampilan($pdo, $slot);
        break;
    }
}

$pageTitle = 'Alokasi Syahriyah per Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Laporan alokasi syahriyah per santri</h1>
    <p class="text-muted small mb-0">
        Cicilan dialokasikan <strong>PKPPS dulu</strong>, sisanya ke dasar × % alokasi.
        Tambahan PKPPS masuk komponen <strong><?= htmlspecialchars($pkppsTarget) ?></strong> (gaji guru).
        <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php')) ?>">Laporan syahriyah</a>
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php')) ?>">Laporan PKPPS</a>
    </p>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select name="rekap_bulan" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($bulanSlots as $slot): ?>
                <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                <?php if ($b < 1 || $b > 12) { continue; } ?>
                <option value="<?= $b ?>" <?= $rekapBulan === $b ? 'selected' : '' ?>>
                    <?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if ($alokasiKomponen === []): ?>
    <div class="alert alert-warning">Belum ada komponen alokasi syahriyah. Atur di <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">pengaturan alokasi</a>.</div>
<?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2 small">
            <strong><?= htmlspecialchars($bulanLabel !== '' ? $bulanLabel : 'Bulan ' . $rekapBulan) ?></strong>
            · <?= count($rows) ?> santri membayar syahriyah
            · Total masuk: <?= keuangan_format_rupiah($totalMasuk) ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-white shadow-sm">
            <thead class="table-light">
            <tr>
                <th>Santri</th>
                <th>Tier</th>
                <th class="text-end">Bayar syahriyah</th>
                <?php foreach ($alokasiKomponen as $k): ?>
                    <?php $namaK = trim((string) ($k['nama_komponen'] ?? '')); ?>
                    <th class="text-end small">
                        <?= htmlspecialchars($namaK) ?><?= $namaK === $pkppsTarget ? ' <span class="text-muted fw-normal">(+PKPPS)</span>' : '' ?><br>
                        <span class="text-muted fw-normal"><?= number_format((float) ($k['persen'] ?? 0), 2) ?>%</span>
                    </th>
                <?php endforeach; ?>
                <?php if ($pkppsTarget !== '' && !in_array($pkppsTarget, array_map(static fn(array $k): string => trim((string) ($k['nama_komponen'] ?? '')), $alokasiKomponen), true)): ?>
                    <th class="text-end small"><?= htmlspecialchars($pkppsTarget) ?><br><span class="text-muted fw-normal">PKPPS</span></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= 3 + count($alokasiKomponen) + ($pkppsTarget !== '' && !in_array($pkppsTarget, array_map(static fn(array $k): string => trim((string) ($k['nama_komponen'] ?? '')), $alokasiKomponen), true) ? 1 : 0) ?>" class="text-center text-muted py-4">Belum ada pembayaran syahriyah bulan ini.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($r['nama_santri']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($r['nis']) ?></div>
                        </td>
                        <td class="small"><?= htmlspecialchars($r['tier']) ?></td>
                        <td class="text-end"><?= keuangan_format_rupiah($r['bayar']) ?></td>
                        <?php
                        $byName = [];
                        foreach ($r['komponen'] as $c) {
                            $byName[$c['nama']] = $c['nominal'];
                        }
                        foreach ($alokasiKomponen as $k):
                            $nama = trim((string) ($k['nama_komponen'] ?? ''));
                            ?>
                            <td class="text-end small"><?= keuangan_format_rupiah((int) ($byName[$nama] ?? 0)) ?></td>
                        <?php endforeach; ?>
                        <?php if ($pkppsTarget !== '' && !in_array($pkppsTarget, array_map(static fn(array $k): string => trim((string) ($k['nama_komponen'] ?? '')), $alokasiKomponen), true)): ?>
                            <td class="text-end small"><?= keuangan_format_rupiah((int) ($byName[$pkppsTarget] ?? 0)) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-secondary fw-semibold">
                    <td colspan="2">Total alokasi</td>
                    <td class="text-end"><?= keuangan_format_rupiah($totalMasuk) ?></td>
                    <?php foreach ($alokasiKomponen as $k): ?>
                        <?php $nama = trim((string) ($k['nama_komponen'] ?? '')); ?>
                        <td class="text-end"><?= keuangan_format_rupiah((int) ($totalsPerKomponen[$nama] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <?php if ($pkppsTarget !== '' && !in_array($pkppsTarget, array_map(static fn(array $k): string => trim((string) ($k['nama_komponen'] ?? '')), $alokasiKomponen), true)): ?>
                        <td class="text-end"><?= keuangan_format_rupiah((int) ($totalsPerKomponen[$pkppsTarget] ?? 0)) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
