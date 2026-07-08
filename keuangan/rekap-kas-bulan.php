<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_kas_bulan.php';
require_once __DIR__ . '/../helpers/keuangan_validasi_pesan.php';

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
    echo ' · Saldo akhir (uang nyata): <strong>' . htmlspecialchars($fmt((int) ($rekap['saldo_akhir_uang_nyata'] ?? $rekap['saldo_akhir_fisik'] ?? 0))) . '</strong>';
    echo ' · Hitung buku: <strong>' . htmlspecialchars($fmt((int) ($rekap['saldo_akhir_hitung'] ?? $rekap['saldo_akhir'] ?? 0))) . '</strong></p>';
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
        sampai <strong><?= htmlspecialchars((string) $rekap['bulan_berjalan_label']) ?></strong>.
        Kas masuk &amp; keluar diperinci <strong>Syahriyah</strong>, <strong>Makan</strong>, <strong>Saku</strong>, <strong>Awal Tahun</strong>.
        Klik angka untuk melihat detail transaksi.
        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php')) ?>">Pengaturan keuangan</a>
        · <a href="<?= htmlspecialchars(app_href('/pembayaran/rekap_pos.php')) ?>">Rekap per POS</a>
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
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_href()) ?>">Riwayat masuk &amp; keluar</a>
                <a class="btn btn-outline-primary" href="/keuangan/neraca.php">Neraca</a>
            </div>
        </form>
    </div>
</div>

<?php
$selisihRekap = (int) ($rekap['selisih_saldo'] ?? 0);
$analisisSelisih = abs($selisihRekap) >= 1000
    ? keuangan_rekap_kas_analisis_selisih($pdo, $rekap)
    : [];
if (abs($selisihRekap) >= 1000): ?>
<div class="alert alert-warning mb-3">
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
$tagAwal = is_array($tagihanTa['awal_tahun'] ?? null) ? $tagihanTa['awal_tahun'] : [];
$wajibSet = is_array($tagihanTa['pengaturan'] ?? null) ? $tagihanTa['pengaturan'] : [];
?>
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="app-mini-stat border-start border-4 border-purple" style="border-color:#7c3aed!important">
            <div class="app-mini-stat-label">Target tagihan TA</div>
            <div class="app-mini-stat-value" style="color:#5b21b6"><?= htmlspecialchars($fmt((int) ($tagTot['expected'] ?? 0))) ?></div>
            <div class="small text-muted">Bulanan + awal tahun</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Terbayar tagihan</div>
            <div class="app-mini-stat-value text-success"><?= htmlspecialchars($fmt((int) ($tagTot['paid'] ?? 0))) ?></div>
            <div class="small text-muted">Capai <?= (int) ($tagTot['pct'] ?? 0) ?>%</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Sisa tagihan</div>
            <div class="app-mini-stat-value text-warning"><?= htmlspecialchars($fmt((int) ($tagTot['sisa'] ?? 0))) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-mini-stat">
            <div class="app-mini-stat-label">Awal tahun (target)</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) ($tagAwal['expected'] ?? 0))) ?></div>
            <div class="small text-muted">Terbayar <?= htmlspecialchars($fmt((int) ($tagAwal['paid'] ?? 0))) ?></div>
        </div>
    </div>
</div>

<?php if ($wajibSet !== []): ?>
<div class="alert alert-light border small mb-3 py-2">
    <strong>Pengaturan aktif:</strong>
    Bulanan wajib <?= htmlspecialchars(implode(', ', (array) ($wajibSet['wajib_bulanan'] ?? [])) ?: '—') ?>.
    <?php if (!empty($wajibSet['tagihan_mulai_masuk'])): ?>
        Santri baru ditagih mulai bulan masuk.
    <?php endif; ?>
    <?php if (!empty($wajibSet['bedakan_awal_tahun'])): ?>
        Awal tahun beda tarif/komponen baru vs lama.
    <?php endif; ?>
    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php')) ?>">Ubah pengaturan</a>
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
            <div class="app-mini-stat-label">Saldo akhir (uang nyata)</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars($fmt((int) ($rekap['saldo_akhir_uang_nyata'] ?? $rekap['saldo_akhir_fisik'] ?? 0))) ?></div>
            <?php if ((int) ($rekap['selisih_saldo'] ?? 0) !== 0): ?>
                <div class="small text-warning">Hitung buku <?= htmlspecialchars($fmt((int) ($rekap['saldo_akhir_hitung'] ?? $rekap['saldo_akhir'] ?? 0))) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php keuangan_rekap_kas_bulan_render_tabel($rekap, $fmt); ?>
        <p class="small text-muted mt-3 mb-0">
            <strong>Petunjuk:</strong>
            <span class="d-inline-block me-2"><span class="badge" style="background:#dcfce7;color:#166534">Hijau</span> = kas masuk riil</span>
            <span class="d-inline-block me-2"><span class="badge" style="background:#ede9fe;color:#5b21b6">Ungu</span> = status tagihan (terbayar / sisa)</span>
            <span class="d-inline-block me-2"><span class="badge" style="background:#fee2e2;color:#b91c1c">Merah</span> = kas keluar per sumber dana</span>
        </p>
    </div>
</div>

<style><?= keuangan_rekap_kas_bulan_css() ?></style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
