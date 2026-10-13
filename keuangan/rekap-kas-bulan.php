<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_kas_bulan.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_tagihan_bulan.php';
require_once __DIR__ . '/../helpers/keuangan_validasi_pesan.php';

require_login();
require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
keuangan_ensure_schema_deferred($pdo);

$periode = keuangan_periode_berjalan($pdo);
$tmInput = (int) ($_GET['tm'] ?? $periode['mulai']);
$tsInput = (int) ($_GET['ts'] ?? $periode['selesai']);
$bulanInput = (int) ($_GET['bulan'] ?? $periode['bulan']);
$view = (string) ($_GET['view'] ?? 'pondok');
if (!in_array($view, ['pondok', 'full'], true)) {
    $view = 'pondok';
}
$showSaku = $view === 'full';
$print = isset($_GET['print']) && (string) $_GET['print'] === '1';

if (!$print && isset($_GET['detail']) && (string) $_GET['detail'] === '1') {
    $qs = $_GET;
    unset($qs['detail']);
    $target = app_href('/keuangan/rekap-kas-bulan.php?' . http_build_query($qs) . '#rekap-rincian-pos');
    header('Location: ' . $target, true, 302);
    exit;
}

$rekap = keuangan_build_rekap_kas_bulanan($pdo, $tmInput, $tsInput, $bulanInput);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

$posMatrix = keuangan_rekap_tagihan_matrix_by_pos(
    $pdo,
    (int) $rekap['tahun_mulai'],
    (int) $rekap['tahun_selesai'],
    (int) $rekap['bulan_berjalan']
);
$mutasiByPos = keuangan_rekap_kas_mutasi_by_pos_from_rekap($rekap, $showSaku);

if ($print) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Rekap Kas Bulanan — ' . htmlspecialchars((string) $rekap['nama_lembaga']) . '</title>';
    echo keuangan_typography_font_links();
    echo '<style>' . keuangan_typography_print_css() . keuangan_rekap_kas_bulan_css() . '</style></head><body class="' . htmlspecialchars(keuangan_body_class('rekap-kas-bulan-page')) . '">';
    echo '<div class="noprint" style="margin-bottom:12px"><button onclick="window.print()">Cetak / PDF</button> <a href="/keuangan/rekap-kas-bulan.php">Kembali</a></div>';
    echo '<h1 style="text-align:center;font-size:1.2rem;margin:0 0 4px">Rekap Kas Masuk &amp; Keluar</h1>';
    echo '<p style="text-align:center;margin:0 0 4px"><strong>' . htmlspecialchars((string) $rekap['nama_lembaga']) . '</strong></p>';
    echo '<p style="text-align:center;color:#64748b;margin:0 0 16px">TA ' . htmlspecialchars((string) $rekap['ta_label']) . ' — bulan 1 s.d. ' . htmlspecialchars((string) $rekap['bulan_berjalan_label']) . '</p>';
    keuangan_rekap_kas_bulan_render_tabel($rekap, $fmt, $showSaku, 'ringkas');
    keuangan_rekap_kas_bulan_render_pos_sections($rekap, $posMatrix, $mutasiByPos, $fmt, $showSaku, $pdo);
    keuangan_rekap_kas_bulan_render_rekonsiliasi($rekap, $fmt);
    echo '<p style="margin-top:12px;font-size:0.85rem">Saldo awal TA: <strong>' . htmlspecialchars($fmt((int) $rekap['saldo_awal_ta'])) . '</strong>';
    echo ' · Saldo akhir (uang nyata): <strong>' . htmlspecialchars($fmt((int) ($rekap['saldo_akhir_uang_nyata'] ?? $rekap['saldo_akhir_fisik'] ?? 0))) . '</strong>';
    echo ' · Hitung buku: <strong>' . htmlspecialchars($fmt((int) ($rekap['saldo_akhir_hitung'] ?? $rekap['saldo_akhir'] ?? 0))) . '</strong></p>';
    echo '</body></html>';
    exit;
}

$pageTitle = 'Rekap Kas Bulanan';
$bodyClass = keuangan_body_class('rekap-kas-bulan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<?php
$keuUiHeader = [
    'kicker' => '<a href="/keuangan/index.php">Keuangan</a> · Laporan',
    'title' => 'Rekap Kas Bulanan',
    'subtitle' => 'TA ' . htmlspecialchars((string) $rekap['ta_label'])
        . ' · s.d. ' . htmlspecialchars((string) $rekap['bulan_berjalan_label'])
        . ' · mutasi kas operasional' . ($showSaku ? ' (+ titipan saku)' : '') . '.',
];
require __DIR__ . '/partials/ui_page_header.php';
?>

<details class="small text-muted mb-2">
    <summary class="fw-semibold text-body">Petunjuk baca tabel</summary>
    <ul class="mb-0 ps-3 mt-1">
        <li>Baris <strong>Awal Tahun (TA)</strong> = kewajiban awal tahun (bukan bulan tagihan).</li>
        <li>Footer <strong>Total TA</strong> = bulanan + awal tahun.</li>
        <li>Kolom <strong>Tagihan</strong> (target/terbayar/sisa) ≠ kas masuk/keluar.</li>
        <li>Rincian per POS ada di bawah tabel inti — gunakan navigasi loncat.</li>
    </ul>
</details>

<?php
$baseQs = 'tm=' . (int) $rekap['tahun_mulai'] . '&ts=' . (int) $rekap['tahun_selesai'] . '&bulan=' . (int) $rekap['bulan_berjalan'];
require __DIR__ . '/partials/ui_rekap_toolbar.php';
?>

<?php
$selisihRekap = (int) ($rekap['selisih_saldo'] ?? 0);
$analisisSelisih = abs($selisihRekap) >= 1000
    ? keuangan_rekap_kas_analisis_selisih($pdo, $rekap)
    : [];
if (abs($selisihRekap) >= 1000): ?>
<div class="alert alert-warning mb-2 py-2">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    Selisih saldo akhir TA: uang nyata <strong><?= htmlspecialchars($fmt((int) ($rekap['saldo_akhir_uang_nyata'] ?? $rekap['saldo_akhir_fisik'] ?? 0))) ?></strong>
    vs hitung buku <strong><?= htmlspecialchars($fmt((int) ($rekap['saldo_akhir_hitung'] ?? $rekap['saldo_akhir'] ?? 0))) ?></strong>
    (selisih <?= htmlspecialchars($fmt(abs($selisihRekap))) ?>).
    <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-kas.php')) ?>" class="alert-link">Perbaiki transaksi kas</a>.
    <?php if ($analisisSelisih !== []): ?>
    <hr class="my-2">
    <div class="small mb-0"><strong>Kemungkinan penyebab:</strong></div>
    <ul class="small mb-0 ps-3">
        <?php foreach ($analisisSelisih as $item): ?>
            <li>
                <strong><?= htmlspecialchars((string) ($item['judul'] ?? '')) ?></strong>
                <?php if ((int) ($item['jumlah'] ?? 0) > 0): ?>
                    — <?= (int) $item['jumlah'] ?> transaksi
                    <?php if ((int) ($item['nominal'] ?? 0) > 0): ?>
                        (<?= htmlspecialchars((string) ($item['nominal_fmt'] ?? '')) ?>)
                    <?php endif; ?>
                <?php endif; ?>
                <br><span class="text-muted"><?= htmlspecialchars((string) ($item['keterangan'] ?? '')) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    Periksa transaksi tanpa akun kas atau entri ganda.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$tagihanTa = is_array($rekap['tagihan'] ?? null) ? $rekap['tagihan'] : [];
$tagTot = is_array($tagihanTa['total'] ?? null) ? $tagihanTa['total'] : [];
$wajibSet = is_array($tagihanTa['pengaturan'] ?? null) ? $tagihanTa['pengaturan'] : [];
?>
<?php require __DIR__ . '/partials/ui_rekap_kas_ringkasan_ta.php'; ?>

<?php if ($wajibSet !== []): ?>
<details class="small text-muted mb-2">
    <summary class="fw-semibold text-body">Pengaturan tagihan aktif</summary>
    <p class="mb-0 mt-1">
        Bulanan wajib <?= htmlspecialchars(implode(', ', (array) ($wajibSet['wajib_bulanan'] ?? [])) ?: '—') ?>.
        <?php if (!empty($wajibSet['tagihan_mulai_masuk'])): ?>
            Santri baru ditagih mulai bulan masuk.
        <?php endif; ?>
        <?php if (!empty($wajibSet['bedakan_awal_tahun'])): ?>
            Awal tahun beda tarif/komponen baru vs lama.
        <?php endif; ?>
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php')) ?>">Ubah pengaturan</a>
    </p>
</details>
<?php endif; ?>

<div class="card shadow-sm border-0" id="rekap-tabel">
    <div class="card-header bg-white border-bottom py-2">
        <span class="fw-semibold small mb-0">Rekap kas bulanan (ringkas)</span>
    </div>
    <div class="card-body p-2 p-md-3">
        <?php keuangan_rekap_kas_bulan_render_tabel($rekap, $fmt, $showSaku, 'ringkas'); ?>
        <p class="small text-muted mt-2 mb-0">
            <span class="d-inline-block me-2"><span class="badge bg-success-subtle text-success">Hijau</span> kas masuk</span>
            <span class="d-inline-block me-2"><span class="badge text-bg-light border">Ungu muda</span> tagihan</span>
            <span class="d-inline-block me-2"><span class="badge bg-danger-subtle text-danger">Merah</span> kas keluar</span>
        </p>
    </div>
</div>

<?php
keuangan_rekap_kas_bulan_render_pos_sections($rekap, $posMatrix, $mutasiByPos, $fmt, $showSaku, $pdo);
keuangan_rekap_kas_bulan_render_rekonsiliasi($rekap, $fmt);
?>

<style><?= keuangan_rekap_kas_bulan_css() ?></style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
