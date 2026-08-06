<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_bos.php';
require_once __DIR__ . '/../helpers/excel.php';

require_login();
require_roles(['admin', 'pengurus']);

bos_ensure_schema($pdo);

$periodeRange = bos_resolve_periode_range($_GET);
$tglMulai = (string) $periodeRange['tgl_mulai'];
$tglSelesai = (string) $periodeRange['tgl_selesai'];
$periodeLabel = (string) $periodeRange['label'];
$print = isset($_GET['print']) && (string) $_GET['print'] === '1';

$rows = bos_laporan_bku_rows_range($pdo, $tglMulai, $tglSelesai);
$saldoAwal = bos_saldo_awal_periode($pdo, $tglMulai);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);
$namaLembaga = trim((string) app_setting($pdo, 'nama_pesantren', 'PKPPS'));

$rangeQs = http_build_query([
    'bulan_mulai' => (int) $periodeRange['bulan_mulai'],
    'tahun_mulai' => (int) $periodeRange['tahun_mulai'],
    'bulan_selesai' => (int) $periodeRange['bulan_selesai'],
    'tahun_selesai' => (int) $periodeRange['tahun_selesai'],
]);
$exportName = sprintf(
    'bku_bos_%d_%02d_%d_%02d.xlsx',
    (int) $periodeRange['tahun_mulai'],
    (int) $periodeRange['bulan_mulai'],
    (int) $periodeRange['tahun_selesai'],
    (int) $periodeRange['bulan_selesai']
);

if (($_GET['export'] ?? '') === 'xlsx') {
    $xlsxRows = [
        ['Buku Kas Umum (BKU) — Keuangan BOS'],
        ['PERIODE: ' . $periodeLabel],
        ['Saldo Awal Periode', (string) $saldoAwal],
        [],
        ['Tanggal', 'Kode Akun', 'Uraian Transaksi', 'Jenjang', 'Sumber Dana', 'Debet (Rp)', 'Kredit (Rp)'],
    ];
    foreach ($rows as $r) {
        $xlsxRows[] = [
            (string) ($r['tanggal'] ?? ''),
            (string) ($r['kode_akun'] ?? ''),
            (string) ($r['uraian'] ?? ''),
            (string) ($r['jenjang'] ?? ''),
            (string) ($r['sumber_dana'] ?? ''),
            (string) ((int) ($r['debit'] ?? 0)),
            (string) ((int) ($r['kredit'] ?? 0)),
        ];
    }
    send_xlsx_download($exportName, $xlsxRows, 'BKU BOS');
    exit;
}

if ($print) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>BKU BOS</title>';
    echo keuangan_typography_font_links();
    echo '<style>' . keuangan_typography_print_css() . '</style></head><body>';
    echo '<div class="noprint" style="margin-bottom:12px"><button onclick="window.print()">Cetak / PDF</button></div>';
    echo '<h1 style="text-align:center;font-size:1.2rem">Buku Kas Umum (BKU) — Keuangan BOS</h1>';
    echo '<p style="text-align:center">' . htmlspecialchars($namaLembaga) . '<br>PERIODE: ' . htmlspecialchars($periodeLabel) . '</p>';
    echo '<p style="text-align:center"><strong>Saldo Awal Periode:</strong> ' . htmlspecialchars($fmt($saldoAwal)) . '</p>';
    echo '<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:10pt">';
    echo '<tr><th>Tanggal</th><th>Kode</th><th>Uraian</th><th>Jenjang</th><th>Sumber Dana</th><th>Debet</th><th>Kredit</th></tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) $r['tanggal']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['kode_akun']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['uraian']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['jenjang']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $r['sumber_dana']) . '</td>';
        echo '<td style="text-align:right">' . htmlspecialchars($fmt((int) $r['debit'])) . '</td>';
        echo '<td style="text-align:right">' . htmlspecialchars($fmt((int) $r['kredit'])) . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

$pageTitle = 'Laporan BKU BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>">Keuangan BOS</a></p>
    <h1 class="h4 mb-1">Buku Kas Umum (BKU)</h1>
    <p class="text-muted mb-0">Arus penerimaan &amp; pengeluaran modul BOS — terpisah dari keuangan pondok.</p>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
    <?php
    $formAction = app_href('/keuangan-bos/laporan-bku.php');
    require __DIR__ . '/partials/filter_periode_range.php';
    ?>
    <a class="btn btn-sm btn-success" href="<?= htmlspecialchars(app_href('/keuangan-bos/laporan-bku.php?' . $rangeQs . '&export=xlsx')) ?>"><i class="fa-solid fa-file-excel me-1"></i> Excel</a>
    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= htmlspecialchars(app_href('/keuangan-bos/laporan-bku.php?' . $rangeQs . '&print=1')) ?>"><i class="fa-solid fa-print me-1"></i> Cetak / PDF</a>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">
        PERIODE: <?= htmlspecialchars($periodeLabel) ?>
        <span class="float-end small fw-normal">Saldo Awal Periode: <?= htmlspecialchars($fmt($saldoAwal)) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Tanggal</th>
                    <th>Kode Akun</th>
                    <th>Uraian Transaksi</th>
                    <th>Jenjang</th>
                    <th>Sumber Dana</th>
                    <th class="text-end">Debet</th>
                    <th class="text-end">Kredit</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-muted text-center py-4">Belum ada jurnal untuk periode ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $r['tanggal']) ?></td>
                            <td><?= htmlspecialchars((string) $r['kode_akun']) ?></td>
                            <td><?= htmlspecialchars((string) $r['uraian']) ?></td>
                            <td><?= htmlspecialchars((string) $r['jenjang']) ?></td>
                            <td><?= htmlspecialchars((string) $r['sumber_dana']) ?></td>
                            <td class="text-end"><?= (int) $r['debit'] > 0 ? htmlspecialchars($fmt((int) $r['debit'])) : '—' ?></td>
                            <td class="text-end"><?= (int) $r['kredit'] > 0 ? htmlspecialchars($fmt((int) $r['kredit'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
