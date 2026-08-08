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

$lra = bos_laporan_lra_range($pdo, $tglMulai, $tglSelesai);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);
$namaLembaga = trim((string) app_setting($pdo, 'nama_pesantren', 'PKPPS'));

$rangeQs = http_build_query([
    'bulan_mulai' => (int) $periodeRange['bulan_mulai'],
    'tahun_mulai' => (int) $periodeRange['tahun_mulai'],
    'bulan_selesai' => (int) $periodeRange['bulan_selesai'],
    'tahun_selesai' => (int) $periodeRange['tahun_selesai'],
]);
$exportName = sprintf(
    'lra_bos_%d_%02d_%d_%02d.xlsx',
    (int) $periodeRange['tahun_mulai'],
    (int) $periodeRange['bulan_mulai'],
    (int) $periodeRange['tahun_selesai'],
    (int) $periodeRange['bulan_selesai']
);

$renderSection = static function (array $items) use ($fmt): string {
    $html = '';
    foreach ($items as $item) {
        $html .= '<tr><td style="padding-left:1.5rem">' . htmlspecialchars((string) $item['kode']) . ' — ' . htmlspecialchars((string) $item['nama']) . '</td>';
        $html .= '<td style="text-align:right">' . htmlspecialchars($fmt((int) $item['nilai'])) . '</td></tr>';
    }

    return $html;
};

$renderPosBreakdown = static function (array $posBreakdown) use ($fmt): string {
    $html = '';
    $jenjangKeys = array_keys($posBreakdown);
    usort($jenjangKeys, static fn(string $a, string $b): int => bos_jenjang_sort_key($a) <=> bos_jenjang_sort_key($b));
    foreach ($jenjangKeys as $jenjang) {
        $html .= '<tr><td colspan="2" style="padding-left:1rem"><strong>' . htmlspecialchars(bos_label_jenjang_section($jenjang)) . '</strong></td></tr>';
        foreach ($posBreakdown[$jenjang] as $kelompok => $items) {
            $html .= '<tr><td colspan="2" style="padding-left:2rem"><em>' . htmlspecialchars($kelompok) . '</em></td></tr>';
            foreach ($items as $item) {
                $html .= '<tr><td style="padding-left:3rem">' . htmlspecialchars((string) $item['nama']) . '</td>';
                $html .= '<td style="text-align:right">' . htmlspecialchars($fmt((int) $item['nilai'])) . '</td></tr>';
            }
        }
    }

    return $html;
};

if (($_GET['export'] ?? '') === 'xlsx') {
    $xlsxRows = [
        ['LAPORAN REALISASI ANGGARAN & DANA OPERASIONAL PKPPS'],
        ['PERIODE MASEHI: ' . $periodeLabel],
        ['Saldo Awal Periode', (string) (int) $lra['saldo_awal_periode']],
        [],
        ['I. PENDAPATAN', ''],
    ];
    foreach ($lra['sections']['pendapatan'] as $item) {
        $xlsxRows[] = [$item['kode'] . ' — ' . $item['nama'], (string) (int) $item['nilai']];
    }
    $xlsxRows[] = ['TOTAL PENDAPATAN', (string) (int) $lra['total_pendapatan']];
    $xlsxRows[] = [];
    $xlsxRows[] = ['II. BEBAN JENJANG WUSTHO', ''];
    foreach ($lra['sections']['beban_wustho'] as $item) {
        $xlsxRows[] = [$item['kode'] . ' — ' . $item['nama'], (string) (int) $item['nilai']];
    }
    $xlsxRows[] = ['SUBTOTAL BEBAN WUSTHO', (string) (int) $lra['subtotal_wustho']];
    $xlsxRows[] = [];
    $xlsxRows[] = ['III. BEBAN JENJANG ULYA', ''];
    foreach ($lra['sections']['beban_ulya'] as $item) {
        $xlsxRows[] = [$item['kode'] . ' — ' . $item['nama'], (string) (int) $item['nilai']];
    }
    $xlsxRows[] = ['SUBTOTAL BEBAN ULYA', (string) (int) $lra['subtotal_ulya']];
    $xlsxRows[] = [];
    $xlsxRows[] = ['IV. BEBAN BERSAMA / OPERASIONAL UMUM', ''];
    foreach ($lra['sections']['beban_umum'] as $item) {
        $xlsxRows[] = [$item['kode'] . ' — ' . $item['nama'], (string) (int) $item['nilai']];
    }
    $xlsxRows[] = ['SUBTOTAL BEBAN BERSAMA', (string) (int) $lra['subtotal_umum']];
    $xlsxRows[] = [];
    $xlsxRows[] = ['V. BEBAN OPERASIONAL LAIN-LAIN', ''];
    foreach ($lra['sections']['beban_lain'] as $item) {
        $xlsxRows[] = [$item['kode'] . ' — ' . $item['nama'], (string) (int) $item['nilai']];
    }
    $posBreakdown = $lra['pos_breakdown'] ?? [];
    if ($posBreakdown !== []) {
        $xlsxRows[] = ['Rincian pos pengeluaran (RAB)', ''];
        $jenjangKeys = array_keys($posBreakdown);
        usort($jenjangKeys, static fn(string $a, string $b): int => bos_jenjang_sort_key($a) <=> bos_jenjang_sort_key($b));
        foreach ($jenjangKeys as $jenjang) {
            $xlsxRows[] = [bos_label_jenjang_section($jenjang), ''];
            foreach ($posBreakdown[$jenjang] as $kelompok => $items) {
                $xlsxRows[] = ['  ' . $kelompok, ''];
                foreach ($items as $item) {
                    $xlsxRows[] = ['    ' . ($item['nama'] ?? ''), (string) (int) ($item['nilai'] ?? 0)];
                }
            }
        }
    }
    $xlsxRows[] = ['SUBTOTAL BEBAN LAIN-LAIN', (string) (int) $lra['subtotal_lain']];
    $xlsxRows[] = [];
    $xlsxRows[] = ['TOTAL PENGELUARAN', (string) (int) $lra['total_pengeluaran']];
    $xlsxRows[] = ['SURPLUS / (DEFISIT)', (string) (int) $lra['surplus']];
    send_xlsx_download($exportName, $xlsxRows, 'LRA BOS');
    exit;
}

if (isset($_GET['print']) && (string) $_GET['print'] === '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>LRA BOS</title>';
    echo keuangan_typography_font_links();
    echo '<style>' . keuangan_typography_print_css() . ' table{width:100%;border-collapse:collapse} td{padding:4px 8px}</style></head><body>';
    echo '<div class="noprint"><button onclick="window.print()">Cetak / PDF</button></div>';
    echo '<h1 style="text-align:center;font-size:1.15rem">LAPORAN REALISASI ANGGARAN &amp; DANA OPERASIONAL PKPPS</h1>';
    echo '<p style="text-align:center">' . htmlspecialchars($namaLembaga) . '<br>PERIODE MASEHI: ' . htmlspecialchars(strtoupper($periodeLabel)) . '</p>';
    echo '<p style="text-align:center"><strong>Saldo Awal Periode:</strong> ' . htmlspecialchars($fmt((int) $lra['saldo_awal_periode'])) . '</p>';
    echo '<table>';
    echo '<tr><td colspan="2"><strong>I. PENDAPATAN</strong></td></tr>';
    echo $renderSection($lra['sections']['pendapatan']);
    echo '<tr><td><strong>TOTAL PENDAPATAN</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['total_pendapatan'])) . '</strong></td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2"><strong>II. BEBAN / PENGELUARAN JENJANG WUSTHO</strong></td></tr>';
    echo $renderSection($lra['sections']['beban_wustho']);
    echo '<tr><td><strong>SUBTOTAL BEBAN WUSTHO</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['subtotal_wustho'])) . '</strong></td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2"><strong>III. BEBAN / PENGELUARAN JENJANG ULYA</strong></td></tr>';
    echo $renderSection($lra['sections']['beban_ulya']);
    echo '<tr><td><strong>SUBTOTAL BEBAN ULYA</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['subtotal_ulya'])) . '</strong></td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2"><strong>IV. BEBAN BERSAMA / OPERASIONAL UMUM</strong></td></tr>';
    echo $renderSection($lra['sections']['beban_umum']);
    echo '<tr><td><strong>SUBTOTAL BEBAN BERSAMA</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['subtotal_umum'])) . '</strong></td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2"><strong>V. BEBAN OPERASIONAL LAIN-LAIN</strong></td></tr>';
    echo $renderSection($lra['sections']['beban_lain']);
    if (($lra['pos_breakdown'] ?? []) !== []) {
        echo '<tr><td colspan="2" style="padding-left:1rem"><strong>Rincian pos pengeluaran (RAB)</strong></td></tr>';
        echo $renderPosBreakdown($lra['pos_breakdown']);
    }
    echo '<tr><td><strong>SUBTOTAL BEBAN LAIN-LAIN</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['subtotal_lain'])) . '</strong></td></tr>';
    echo '<tr><td colspan="2"><hr></td></tr>';
    echo '<tr><td><strong>TOTAL PENGELUARAN</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['total_pengeluaran'])) . '</strong></td></tr>';
    $surplusLabel = (int) $lra['surplus'] >= 0 ? 'SURPLUS KEUANGAN' : 'DEFISIT KEUANGAN';
    echo '<tr><td><strong>' . $surplusLabel . '</strong></td><td style="text-align:right"><strong>' . htmlspecialchars($fmt((int) $lra['surplus'])) . '</strong></td></tr>';
    echo '</table></body></html>';
    exit;
}

$pageTitle = 'Laporan LRA BOS';
$bodyClass = keuangan_body_class('keuangan-bos-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan-bos/index.php')) ?>">Keuangan BOS</a></p>
    <h1 class="h4 mb-1">Laporan Realisasi Anggaran (LRA)</h1>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
    <?php
    $formAction = app_href('/keuangan-bos/laporan-lra.php');
    require __DIR__ . '/partials/filter_periode_range.php';
    ?>
    <a class="btn btn-sm btn-success" href="<?= htmlspecialchars(app_href('/keuangan-bos/laporan-lra.php?' . $rangeQs . '&export=xlsx')) ?>"><i class="fa-solid fa-file-excel me-1"></i> Excel</a>
    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= htmlspecialchars(app_href('/keuangan-bos/laporan-lra.php?' . $rangeQs . '&print=1')) ?>"><i class="fa-solid fa-print me-1"></i> Cetak / PDF</a>
</div>

<div class="card shadow-sm">
    <div class="card-header text-center">
        <div class="fw-bold">LAPORAN REALISASI ANGGARAN &amp; DANA OPERASIONAL PKPPS</div>
        <div class="small text-muted">PERIODE MASEHI: <?= htmlspecialchars(strtoupper($periodeLabel)) ?></div>
        <div class="small mt-1">Saldo Awal Periode: <strong><?= htmlspecialchars($fmt((int) $lra['saldo_awal_periode'])) ?></strong></div>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <tbody>
                <tr class="table-light"><td colspan="2" class="fw-semibold">I. PENDAPATAN</td></tr>
                <?php foreach ($lra['sections']['pendapatan'] as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) $item['kode']) ?> — <?= htmlspecialchars((string) $item['nama']) ?></td>
                        <td class="text-end" style="width:12rem"><?= htmlspecialchars($fmt((int) $item['nilai'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="fw-semibold border-top"><td>TOTAL PENDAPATAN</td><td class="text-end"><?= htmlspecialchars($fmt((int) $lra['total_pendapatan'])) ?></td></tr>

                <tr class="table-light"><td colspan="2" class="fw-semibold pt-3">II. BEBAN / PENGELUARAN JENJANG WUSTHO</td></tr>
                <?php foreach ($lra['sections']['beban_wustho'] as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) $item['kode']) ?> — <?= htmlspecialchars((string) $item['nama']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) $item['nilai'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="fw-semibold border-top"><td>SUBTOTAL BEBAN WUSTHO</td><td class="text-end"><?= htmlspecialchars($fmt((int) $lra['subtotal_wustho'])) ?></td></tr>

                <tr class="table-light"><td colspan="2" class="fw-semibold pt-3">III. BEBAN / PENGELUARAN JENJANG ULYA</td></tr>
                <?php foreach ($lra['sections']['beban_ulya'] as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) $item['kode']) ?> — <?= htmlspecialchars((string) $item['nama']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) $item['nilai'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="fw-semibold border-top"><td>SUBTOTAL BEBAN ULYA</td><td class="text-end"><?= htmlspecialchars($fmt((int) $lra['subtotal_ulya'])) ?></td></tr>

                <tr class="table-light"><td colspan="2" class="fw-semibold pt-3">IV. BEBAN BERSAMA / OPERASIONAL UMUM</td></tr>
                <?php foreach ($lra['sections']['beban_umum'] as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) $item['kode']) ?> — <?= htmlspecialchars((string) $item['nama']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) $item['nilai'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="fw-semibold border-top"><td>SUBTOTAL BEBAN BERSAMA</td><td class="text-end"><?= htmlspecialchars($fmt((int) $lra['subtotal_umum'])) ?></td></tr>

                <tr class="table-light"><td colspan="2" class="fw-semibold pt-3">V. BEBAN OPERASIONAL LAIN-LAIN</td></tr>
                <?php foreach ($lra['sections']['beban_lain'] as $item): ?>
                    <tr>
                        <td class="ps-4"><?= htmlspecialchars((string) $item['kode']) ?> — <?= htmlspecialchars((string) $item['nama']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($fmt((int) $item['nilai'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (($lra['pos_breakdown'] ?? []) !== []): ?>
                    <tr class="table-light"><td colspan="2" class="fw-semibold ps-4 pt-2">Rincian pos pengeluaran (RAB)</td></tr>
                    <?php
                    $posBreakdown = $lra['pos_breakdown'];
                    $jenjangKeys = array_keys($posBreakdown);
                    usort($jenjangKeys, static fn(string $a, string $b): int => bos_jenjang_sort_key($a) <=> bos_jenjang_sort_key($b));
                    foreach ($jenjangKeys as $jenjangKey):
                    ?>
                        <tr><td colspan="2" class="ps-4 fw-semibold"><?= htmlspecialchars(bos_label_jenjang_section($jenjangKey)) ?></td></tr>
                        <?php foreach ($posBreakdown[$jenjangKey] as $kelompokNama => $posItems): ?>
                            <tr><td colspan="2" class="ps-5 small text-muted fw-semibold"><?= htmlspecialchars($kelompokNama) ?></td></tr>
                            <?php foreach ($posItems as $posItem): ?>
                                <tr>
                                    <td class="ps-5"><?= htmlspecialchars((string) ($posItem['nama'] ?? '')) ?></td>
                                    <td class="text-end"><?= htmlspecialchars($fmt((int) ($posItem['nilai'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr class="fw-semibold border-top"><td>SUBTOTAL BEBAN LAIN-LAIN</td><td class="text-end"><?= htmlspecialchars($fmt((int) $lra['subtotal_lain'])) ?></td></tr>

                <tr class="table-dark fw-bold"><td>TOTAL PENGELUARAN</td><td class="text-end"><?= htmlspecialchars($fmt((int) $lra['total_pengeluaran'])) ?></td></tr>
                <tr class="table-primary fw-bold">
                    <td><?= (int) $lra['surplus'] >= 0 ? 'SURPLUS KEUANGAN' : 'DEFISIT KEUANGAN' ?></td>
                    <td class="text-end"><?= htmlspecialchars($fmt((int) $lra['surplus'])) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
