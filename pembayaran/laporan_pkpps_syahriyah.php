<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/bendahara_ui.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/keuangan_pkpps_syahriyah.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';

require_roles(['admin', 'pengurus']);
pkpps_ensure_schema($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$tahunAjaranMulai = (int) $keuanganTa['mulai'];
$tahunAjaranSelesai = (int) $keuanganTa['selesai'];
$berjalan = keuangan_periode_berjalan($pdo);
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
$rekapBulan = max(1, min(12, (int) ($_GET['rekap_bulan'] ?? (int) ($berjalan['bulan'] ?? 1))));

$filterStatus = strtolower(trim((string) ($_GET['status'] ?? 'sudah_bayar')));
if (!in_array($filterStatus, ['sudah_bayar', 'lunas', 'belum_bayar'], true)) {
    $filterStatus = 'sudah_bayar';
}

$laporan = keuangan_pkpps_syahriyah_laporan_bulan(
    $pdo,
    $rekapBulan,
    $tahunAjaranMulai,
    $tahunAjaranSelesai,
    $filterStatus
);
$rows = $laporan['rows'] ?? [];
$totals = is_array($laporan['totals'] ?? null) ? $laporan['totals'] : ['tagihan' => 0, 'bayar' => 0, 'harus_masuk' => 0, 'masuk' => 0];
$countPkpps = (int) ($laporan['count_pkpps'] ?? 0);
$countBayar = (int) ($laporan['count_bayar'] ?? 0);
$countBelumBayar = (int) ($laporan['count_belum_bayar'] ?? 0);

$bulanLabel = '';
foreach ($bulanSlots as $slot) {
    if ((int) ($slot['bulan_tagihan'] ?? 0) === $rekapBulan) {
        $bulanLabel = pondok_bulan_slot_label_tampilan($pdo, $slot);
        break;
    }
}

$pkppsKomponen = keuangan_pkpps_alokasi_komponen_nama($pdo);

if (($_GET['export'] ?? '') === 'csv' && $rows !== []) {
    $fn = sprintf('laporan_pkpps_syahriyah_%d_%d_bulan_%d.csv', $tahunAjaranMulai, $tahunAjaranSelesai, $rekapBulan);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Nama',
        'NIS',
        'Tingkatan PKPPS',
        'Total tagihan',
        'Bayar syahriyah',
        'Dana PKPPS harus masuk',
        'Dana PKPPS masuk',
        'Sisa PKPPS',
        'Status',
        'Potongan %',
    ], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['nama_santri'] ?? ''),
            (string) ($r['nis'] ?? ''),
            (string) ($r['pkpps_tingkatan'] ?? ''),
            (string) ((int) ($r['tagihan'] ?? 0)),
            (string) ((int) ($r['bayar'] ?? 0)),
            (string) ((int) ($r['harus_masuk'] ?? 0)),
            (string) ((int) ($r['masuk'] ?? 0)),
            (string) ((int) ($r['sisa_pkpps'] ?? 0)),
            (string) ($r['status_label'] ?? ''),
            $r['potongan'] > 0 ? number_format((float) $r['potongan'], 1, '.', '') : '',
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle = 'Laporan Syahriyah PKPPS';
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
        Laporan tambahan syahriyah PKPPS
    </h1>
    <p class="text-muted small mb-0">
        Santri PKPPS per bulan tagihan — filter <strong>sudah bayar</strong>, <strong>belum bayar</strong>, atau <strong>lunas</strong>.
        <strong>Dana PKPPS harus masuk</strong> = tambahan PKPPS menurut tagihan;
        <strong>Dana PKPPS masuk</strong> = bagian PKPPS dari pembayaran aktual (alokasi ke
        <strong><?= htmlspecialchars($pkppsKomponen) ?></strong>).
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=tarif#tambahan-pkpps')) ?>">Pengaturan nominal</a>
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php')) ?>">Laporan syahriyah</a>
    </p>
</div>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>

<form method="get" class="row g-2 align-items-end mb-3 bendahara-toolbar">
    <div class="col-auto">
        <label class="form-label small mb-0">Bulan tagihan</label>
        <select name="rekap_bulan" class="form-select form-select-sm">
            <?php foreach ($bulanSlots as $slot): ?>
                <?php $b = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                <?php if ($b < 1 || $b > 12) { continue; } ?>
                <option value="<?= $b ?>" <?= $rekapBulan === $b ? 'selected' : '' ?>>
                    <?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0">Santri</label>
        <select name="status" class="form-select form-select-sm">
            <option value="sudah_bayar" <?= $filterStatus === 'sudah_bayar' ? 'selected' : '' ?>>Sudah bayar</option>
            <option value="belum_bayar" <?= $filterStatus === 'belum_bayar' ? 'selected' : '' ?>>Belum bayar</option>
            <option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Lunas saja</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Tampilkan</button>
    </div>
    <?php if ($rows !== []): ?>
    <div class="col-auto">
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php?export=csv&rekap_bulan=' . $rekapBulan . '&status=' . urlencode($filterStatus))) ?>">
            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
        </a>
    </div>
    <?php endif; ?>
</form>

<div class="alert alert-info py-2 small">
    <?= htmlspecialchars($bulanLabel !== '' ? $bulanLabel : 'Bulan ' . $rekapBulan) ?>
    · <?= $countPkpps ?> santri PKPPS aktif
    · <?= $countBayar ?> sudah bayar · <?= $countBelumBayar ?> belum bayar
    · Dana PKPPS harus masuk: <strong><?= keuangan_format_rupiah((int) ($totals['harus_masuk'] ?? 0)) ?></strong>
    <?php if ($filterStatus !== 'belum_bayar'): ?>
        · Dana PKPPS masuk: <strong><?= keuangan_format_rupiah((int) ($totals['masuk'] ?? 0)) ?></strong>
    <?php endif; ?>
</div>

<div class="table-responsive">
    <table class="table table-sm table-bordered align-middle bg-white shadow-sm">
        <thead class="table-light">
        <tr>
            <th>Santri</th>
            <th>Tingkatan PKPPS</th>
            <th class="text-end">Total tagihan</th>
            <th class="text-end">Bayar syahriyah</th>
            <th class="text-end">Dana PKPPS harus masuk</th>
            <th class="text-end">Dana PKPPS masuk</th>
            <th class="text-end">Sisa PKPPS</th>
            <th>Status</th>
            <th class="text-end">Potongan %</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <?php if ($filterStatus === 'lunas'): ?>
                        Belum ada santri PKPPS lunas syahriyah pada bulan ini.
                    <?php elseif ($filterStatus === 'belum_bayar'): ?>
                        Semua santri PKPPS sudah membayar syahriyah pada bulan ini.
                    <?php else: ?>
                        Belum ada santri PKPPS yang membayar syahriyah pada bulan ini.
                    <?php endif; ?>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <?php
                $status = (string) ($r['status'] ?? '');
                $badgeClass = match ($status) {
                    'lunas' => 'text-bg-success',
                    'sebagian' => 'text-bg-warning text-dark',
                    'belum_bayar' => 'text-bg-danger',
                    default => 'text-bg-secondary',
                };
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                    </td>
                    <td class="small"><?= htmlspecialchars((string) ($r['pkpps_tingkatan'] ?? '')) ?></td>
                    <td class="text-end"><?= keuangan_format_rupiah((int) ($r['tagihan'] ?? 0)) ?></td>
                    <td class="text-end fw-semibold<?= (int) ($r['bayar'] ?? 0) <= 0 ? ' text-danger' : '' ?>">
                        <?= (int) ($r['bayar'] ?? 0) > 0 ? keuangan_format_rupiah((int) $r['bayar']) : '—' ?>
                    </td>
                    <td class="text-end"><?= keuangan_format_rupiah((int) ($r['harus_masuk'] ?? 0)) ?></td>
                    <td class="text-end text-success"><?= keuangan_format_rupiah((int) ($r['masuk'] ?? 0)) ?></td>
                    <td class="text-end<?= (int) ($r['sisa_pkpps'] ?? 0) > 0 ? ' text-danger' : ' text-muted' ?>">
                        <?= (int) ($r['sisa_pkpps'] ?? 0) > 0 ? keuangan_format_rupiah((int) $r['sisa_pkpps']) : '—' ?>
                    </td>
                    <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars((string) ($r['status_label'] ?? '')) ?></span></td>
                    <td class="text-end"><?= ($r['potongan'] ?? 0) > 0 ? number_format((float) $r['potongan'], 1) . '%' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-secondary fw-semibold">
                <td colspan="2">Total</td>
                <td class="text-end"><?= keuangan_format_rupiah((int) ($totals['tagihan'] ?? 0)) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah((int) ($totals['bayar'] ?? 0)) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah((int) ($totals['harus_masuk'] ?? 0)) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah((int) ($totals['masuk'] ?? 0)) ?></td>
                <td class="text-end"><?= keuangan_format_rupiah(max(0, (int) ($totals['harus_masuk'] ?? 0) - (int) ($totals['masuk'] ?? 0))) ?></td>
                <td colspan="2"></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
