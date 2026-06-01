<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/keuangan_pkpps_syahriyah.php';
require_once __DIR__ . '/../helpers/keuangan_syahriyah_potongan.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];
$berjalan = keuangan_periode_berjalan($pdo);
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$rekapBulan = max(1, min(12, (int) ($_GET['rekap_bulan'] ?? (int) ($berjalan['bulan'] ?? 1))));

$tambahanBulan = keuangan_pkpps_syahriyah_nominal($pdo, $rekapBulan, $tahunAjaranMulai, $tahunAjaranSelesai);
$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

$rows = [];
$st = $pdo->query('
    SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.kategori_kelas,
           t.nama_tingkatan AS pkpps_tingkatan
    FROM pkpps_santri ps
    INNER JOIN santri s ON s.id = ps.santri_id
    INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
    WHERE ps.is_aktif = 1
    ORDER BY t.urutan ASC, s.' . $namaCol . ' ASC
');
$pkppsSantri = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$totalDasar = 0;
$totalKelasSy = 0;
$totalPkpps = 0;
$totalGabungan = 0;

foreach ($pkppsSantri as $s) {
    $sid = (int) ($s['id'] ?? 0);
    $kat = (string) ($s['kategori_kelas'] ?? '');
    $sim = keuangan_syahriyah_expected_dengan_potongan($pdo, $sid, $kat, $rekapBulan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $pkppsT = (int) ($sim['pkpps_tambahan'] ?? 0);
    $ksT = (int) ($sim['kelas_syahriyah_tambahan'] ?? 0);
    $gabung = (int) ($sim['expected'] ?? 0);
    $dasar = max(0, $gabung - $pkppsT - $ksT);
    $totalDasar += $dasar;
    $totalKelasSy += $ksT;
    $totalPkpps += $pkppsT;
    $totalGabungan += $gabung;
    $rows[] = [
        'nama_santri' => (string) ($s['nama_santri'] ?? '-'),
        'nis' => (string) ($s['nis'] ?? ''),
        'pkpps_tingkatan' => (string) ($s['pkpps_tingkatan'] ?? ''),
        'dasar' => $dasar,
        'tambahan' => $pkppsT,
        'kelas_syahriyah' => $ksT,
        'gabung' => $gabung,
        'potongan' => (float) ($sim['persen'] ?? 0),
    ];
}

$bulanLabel = '';
foreach ($bulanSlots as $slot) {
    if ((int) ($slot['bulan_tagihan'] ?? 0) === $rekapBulan) {
        $bulanLabel = pondok_bulan_slot_label_tampilan($pdo, $slot);
        break;
    }
}

$pageTitle = 'Laporan Syahriyah PKPPS';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Laporan tambahan syahriyah PKPPS</h1>
    <p class="text-muted small mb-0">
        Hanya santri terdaftar PKPPS. Tambahan PKPPS per kelas keuangan (Wustho 1/2/3 = Wustho).
        Total tagihan syahriyah = dasar + tambahan PKPPS.
        Alokasi tambahan masuk <strong><?= htmlspecialchars(keuangan_pkpps_alokasi_umum_label()) ?></strong>.
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-pkpps')) ?>">Pengaturan nominal</a>
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

<div class="alert alert-info py-2 small">
    <?= htmlspecialchars($bulanLabel !== '' ? $bulanLabel : 'Bulan ' . $rekapBulan) ?>
    · Tambahan PKPPS per santri: <strong><?= keuangan_format_rupiah($tambahanBulan) ?></strong>
    · <?= count($rows) ?> santri PKPPS aktif
</div>

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle bg-white shadow-sm">
        <thead class="table-light">
        <tr>
            <th>Santri</th>
            <th>Tingkatan PKPPS</th>
            <th class="text-end">Syahriyah dasar</th>
            <th class="text-end">Tambahan kelas</th>
            <th class="text-end">Tambahan PKPPS</th>
            <th class="text-end">Total tagihan</th>
            <th class="text-end">Potongan %</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada santri PKPPS aktif.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($r['nama_santri']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($r['nis']) ?></div>
                    </td>
                    <td class="small"><?= htmlspecialchars($r['pkpps_tingkatan']) ?></td>
                    <td class="text-end"><?= keuangan_format_rupiah($r['dasar']) ?></td>
                    <td class="text-end"><?= keuangan_format_rupiah($r['kelas_syahriyah']) ?></td>
                    <td class="text-end"><?= keuangan_format_rupiah($r['tambahan']) ?></td>
                    <td class="text-end fw-semibold"><?= keuangan_format_rupiah($r['gabung']) ?></td>
                    <td class="text-end"><?= $r['potongan'] > 0 ? number_format($r['potongan'], 1) . '%' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-secondary fw-semibold">
                <td colspan="2">Total</td>
                <td class="text-end"><?= keuangan_format_rupiah($totalDasar) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah($totalKelasSy) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah($totalPkpps) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah($totalGabungan) ?></td>
                <td></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
