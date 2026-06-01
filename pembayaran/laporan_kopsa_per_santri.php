<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/keuangan_kopsa.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';

require_roles(['admin', 'pengurus']);
keuangan_ensure_schema_deferred($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_kopsa_komponen') {
    $nama = trim((string) ($_POST['kopsa_nama_komponen'] ?? ''));
    save_setting($pdo, 'keuangan_kopsa_nama_komponen', $nama);
    set_flash('success', 'Komponen KOPSA disimpan.');
    header('Location: ' . app_href('/pembayaran/laporan_kopsa_per_santri.php'));
    exit;
}

$rekap = keuangan_kopsa_rekap_per_santri_bulan_cached($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$komponen = $rekap['komponen'];
$persen = (float) ($rekap['persen'] ?? 0);
$bulanSlots = $rekap['bulan_slots'] ?? [];
$rows = $rekap['rows'] ?? [];

$totalsBulan = [];
foreach ($bulanSlots as $slot) {
    $b = (int) ($slot['bulan_tagihan'] ?? 0);
    if ($b >= 1 && $b <= 12) {
        $totalsBulan[$b] = 0;
    }
}
$grandTotal = 0;
foreach ($rows as $r) {
    foreach ($r['bulan'] ?? [] as $b => $nom) {
        if (isset($totalsBulan[$b])) {
            $totalsBulan[$b] += (int) $nom;
        }
    }
    $grandTotal += (int) ($r['total'] ?? 0);
}

$komponenNama = $komponen !== null ? trim((string) ($komponen['nama_komponen'] ?? 'KOPSA')) : '';
$komponenKat = $komponen !== null ? trim((string) ($komponen['kategori'] ?? '')) : '';

if (($_GET['export'] ?? '') === 'csv' && $rows !== []) {
    $fn = sprintf('laporan_kopsa_per_santri_%d_%d.csv', $tahunAjaranMulai, $tahunAjaranSelesai);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $header = ['Nama', 'NIS', 'Kelas keuangan'];
    foreach ($bulanSlots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $header[] = pondok_bulan_slot_label_tampilan($pdo, $slot);
        }
    }
    $header[] = 'Total KOPSA';
    fputcsv($out, $header, ';');
    foreach ($rows as $r) {
        $line = [
            (string) ($r['nama_santri'] ?? ''),
            (string) ($r['nis'] ?? ''),
            (string) ($r['kategori_kelas'] ?? ''),
        ];
        foreach ($bulanSlots as $slot) {
            $b = (int) ($slot['bulan_tagihan'] ?? 0);
            if ($b >= 1 && $b <= 12) {
                $line[] = (string) ((int) ($r['bulan'][$b] ?? 0));
            }
        }
        $line[] = (string) ((int) ($r['total'] ?? 0));
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

$pageTitle = 'Laporan KOPSA per Santri';
$bodyClass = keuangan_body_class('bendahara-page');
require_once __DIR__ . '/../includes/header.php';
$iconLaporan = bendahara_page_icon('laporan');
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>
        <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a>
    </p>
    <h1 class="h4 mb-1 d-flex align-items-center gap-2 flex-wrap">
        <span class="bendahara-page-icon" aria-hidden="true"><i class="fa-solid <?= htmlspecialchars($iconLaporan) ?>"></i></span>
        Laporan KOPSA (cicilan modal) per santri
    </h1>
    <p class="text-muted small mb-0">
        Nominal bagian <strong>KOPSA</strong> dari pembayaran syahriyah per santri, per bulan tagihan.
        Dihitung: (bayar syahriyah − bagian umum/PKPPS/tambahan kelas) × persen alokasi KOPSA.
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">Pengaturan alokasi syahriyah</a>
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php')) ?>">Laporan syahriyah</a>
    </p>
</div>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>

<?php if ($komponen === null): ?>
    <div class="alert alert-warning">
        Komponen <strong>KOPSA / cicilan modal</strong> belum ditemukan di alokasi syahriyah aktif.
        Pastikan ada komponen bernama mengandung &quot;KOPSA&quot; atau &quot;Cicilan Modal&quot;, atau tentukan nama di bawah.
    </div>
    <div class="card shadow-sm mb-3" style="max-width:28rem">
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="save_kopsa_komponen">
                <div class="col-12">
                    <label class="form-label small mb-0">Nama komponen alokasi (tepat seperti di pengaturan)</label>
                    <input type="text" name="kopsa_nama_komponen" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string) app_setting($pdo, 'keuangan_kopsa_nama_komponen', 'KOPSA')) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Gunakan komponen ini</button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info py-2 small mb-3">
        Komponen: <strong><?= htmlspecialchars($komponenNama) ?></strong>
        <?php if ($komponenKat !== ''): ?>
            (<?= htmlspecialchars($komponenKat) ?>)
        <?php endif; ?>
        · Persen alokasi: <strong><?= number_format($persen, 2, ',', '.') ?>%</strong>
        · TA <?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, ['mulai' => $tahunAjaranMulai, 'selesai' => $tahunAjaranSelesai])) ?>
        · <?= count($rows) ?> santri dengan pembayaran KOPSA
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pembayaran/laporan_kopsa_per_santri.php?export=csv')) ?>">
            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-white shadow-sm">
            <thead class="table-light">
            <tr>
                <th class="sticky-col">Santri</th>
                <th>NIS</th>
                <th>Kelas</th>
                <?php foreach ($bulanSlots as $slot): ?>
                    <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                    <?php if ($b < 1 || $b > 12) { continue; } ?>
                    <th class="text-end small" style="min-width:6.5rem">
                        <?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?>
                        <div class="text-muted fw-normal"><?= number_format($persen, 1) ?>%</div>
                    </th>
                <?php endforeach; ?>
                <th class="text-end bg-light">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="<?= 3 + count($totalsBulan) + 1 ?>" class="text-center text-muted py-4">
                        Belum ada pembayaran syahriyah yang menghasilkan bagian KOPSA pada bulan ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="sticky-col">
                            <div class="fw-semibold small"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string) ($r['kategori_kelas'] ?? '')) ?></td>
                        <?php foreach ($bulanSlots as $slot): ?>
                            <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                            <?php if ($b < 1 || $b > 12) { continue; } ?>
                            <?php $nom = (int) ($r['bulan'][$b] ?? 0); ?>
                            <td class="text-end small<?= $nom > 0 ? '' : ' text-muted' ?>">
                                <?= $nom > 0 ? keuangan_format_rupiah($nom) : '—' ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-end fw-semibold small"><?= keuangan_format_rupiah((int) ($r['total'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-secondary fw-semibold">
                    <td colspan="3">Total KOPSA</td>
                    <?php foreach ($bulanSlots as $slot): ?>
                        <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                        <?php if ($b < 1 || $b > 12) { continue; } ?>
                        <td class="text-end small"><?= keuangan_format_rupiah((int) ($totalsBulan[$b] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <td class="text-end"><?= keuangan_format_rupiah($grandTotal) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<style>
    .sticky-col { position: sticky; left: 0; background: #fff; z-index: 1; min-width: 10rem; }
    thead .sticky-col { background: var(--bs-light); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
