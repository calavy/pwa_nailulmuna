<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_kas_bulan.php';

require_login();
require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
keuangan_ensure_schema_deferred($pdo);

$periode = keuangan_periode_berjalan($pdo);
$tmInput = (int) ($_GET['tm'] ?? $periode['mulai']);
$tsInput = (int) ($_GET['ts'] ?? $periode['selesai']);
$bulanInput = (int) ($_GET['bulan'] ?? $periode['bulan']);
$print = isset($_GET['print']) && (string) $_GET['print'] === '1';

$rekap = keuangan_build_rekap_kas_bulanan($pdo, $tmInput, $tsInput, $bulanInput);
$fmt = static fn(int $n): string => keuangan_format_rupiah($n);

if ($print) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Rekap Kas Bulanan — ' . htmlspecialchars((string) $rekap['nama_lembaga']) . '</title>';
    echo keuangan_typography_font_links();
    echo '<style>' . keuangan_typography_print_css() . keuangan_rekap_kas_bulan_css() . '</style></head><body class="' . htmlspecialchars(keuangan_body_class('rekap-kas-bulan-page')) . '">';
    echo '<div class="noprint" style="margin-bottom:12px"><button onclick="window.print()">Cetak / PDF</button> <a href="/keuangan/rekap-kas-bulan.php">Kembali</a></div>';
    echo '<h1 style="text-align:center;font-size:1.2rem;margin:0 0 4px">Rekap Kas Masuk &amp; Keluar</h1>';
    echo '<p style="text-align:center;margin:0 0 4px"><strong>' . htmlspecialchars((string) $rekap['nama_lembaga']) . '</strong></p>';
    echo '<p style="text-align:center;color:#64748b;margin:0 0 16px">TA ' . htmlspecialchars((string) $rekap['ta_label']) . ' — bulan 1 s.d. ' . htmlspecialchars((string) $rekap['bulan_berjalan_label']) . '</p>';
    keuangan_rekap_kas_bulan_render_tabel($rekap, $fmt);
    echo '<p style="margin-top:12px;font-size:0.85rem">Saldo awal TA: <strong>' . htmlspecialchars($fmt((int) $rekap['saldo_awal_ta'])) . '</strong>';
    echo ' · Saldo akhir (hitung): <strong>' . htmlspecialchars($fmt((int) $rekap['saldo_akhir'])) . '</strong>';
    echo ' · Saldo fisik: <strong>' . htmlspecialchars($fmt((int) $rekap['saldo_akhir_fisik'])) . '</strong></p>';
    echo '</body></html>';
    exit;
}

$pageTitle = 'Rekap Kas Bulanan';
$bodyClass = keuangan_body_class('rekap-kas-bulan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Laporan</p>
    <h1 class="h4 mb-1">Rekap Kas Masuk &amp; Keluar</h1>
    <p class="text-muted mb-0">
        Ringkasan kas per bulan tagihan TA <?= htmlspecialchars((string) $rekap['ta_label']) ?>
        sampai <strong><?= htmlspecialchars((string) $rekap['bulan_berjalan_label']) ?></strong>
        (bulan berjalan).
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">TA mulai</label>
                <input type="number" name="tm" class="form-control" value="<?= (int) $rekap['tahun_mulai'] ?>" min="1300" max="2105">
            </div>
            <div class="col-md-2">
                <label class="form-label small">TA selesai</label>
                <input type="number" name="ts" class="form-control" value="<?= (int) $rekap['tahun_selesai'] ?>" min="1300" max="2105">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Sampai bulan</label>
                <select name="bulan" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int) $rekap['bulan_berjalan'] ? 'selected' : '' ?>>
                            Bulan <?= $m ?><?= $m === (int) $periode['bulan'] ? ' (berjalan)' : '' ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-6 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Tampilkan</button>
                <a class="btn btn-outline-secondary" href="/keuangan/rekap-kas-bulan.php?tm=<?= (int) $rekap['tahun_mulai'] ?>&amp;ts=<?= (int) $rekap['tahun_selesai'] ?>&amp;bulan=<?= (int) $rekap['bulan_berjalan'] ?>&amp;print=1" target="_blank">Cetak / PDF</a>
                <a class="btn btn-outline-primary" href="/keuangan/arus-kas.php">Arus kas</a>
                <a class="btn btn-outline-primary" href="/keuangan/neraca.php">Neraca</a>
            </div>
        </form>
    </div>
</div>

<?php
$selisihRekap = (int) ($rekap['selisih_saldo'] ?? 0);
if (abs($selisihRekap) >= 1000): ?>
<div class="alert alert-warning mb-3">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    Selisih saldo akhir TA: hitung <strong><?= htmlspecialchars($fmt((int) $rekap['saldo_akhir'])) ?></strong>
    vs fisik <strong><?= htmlspecialchars($fmt((int) $rekap['saldo_akhir_fisik'])) ?></strong>
    (selisih <?= htmlspecialchars($fmt(abs($selisihRekap))) ?>).
    Periksa transaksi tanpa akun kas atau entri ganda.
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Saldo awal TA</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $rekap['saldo_awal_ta'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total masuk</div>
            <div class="app-mini-stat-value text-success"><?= htmlspecialchars($fmt((int) ($rekap['total']['masuk_total'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Total keluar</div>
            <div class="app-mini-stat-value text-danger"><?= htmlspecialchars($fmt((int) ($rekap['total']['keluar'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Saldo akhir</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) $rekap['saldo_akhir'])) ?></div>
            <?php if ((int) ($rekap['selisih_saldo'] ?? 0) !== 0): ?>
                <div class="small text-warning">Fisik <?= htmlspecialchars($fmt((int) $rekap['saldo_akhir_fisik'])) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php keuangan_rekap_kas_bulan_render_tabel($rekap, $fmt); ?>
        <p class="small text-muted mt-3 mb-0">
            <strong>Petunjuk baca tabel:</strong>
            <span class="d-inline-block me-2"><span class="badge" style="background:#dcfce7;color:#166534">Hijau</span> = kas masuk</span>
            <span class="d-inline-block me-2"><span class="badge" style="background:#fee2e2;color:#b91c1c">Merah</span> = kas keluar</span>
            <span class="d-inline-block me-2">— = nol</span>
            Baris hijau = bulan berjalan. Kolom <strong>Selisih</strong> sehat jika — atau mendekati nol.
        </p>
    </div>
</div>

<style><?= keuangan_rekap_kas_bulan_css() ?></style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
