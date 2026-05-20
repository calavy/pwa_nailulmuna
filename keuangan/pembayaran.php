<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

ensure_keuangan_transaksi_tables($pdo);
ensure_santri_identity_columns($pdo);
ensure_kelas_keuangan_table($pdo);

$biayaDefinitions = keuangan_biaya_definitions();
$bulanMap = keuangan_bulan_map();
$berjalan = keuangan_periode_berjalan($pdo);
$periode = keuangan_tahun_ajaran_aktif($pdo);
$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);

$prefillSantriId = (int) ($_GET['santri_id'] ?? 0);
$prefillBulan = max(1, min(12, (int) ($_GET['bulan'] ?? $berjalan['bulan'])));
$prefillTm = max(2000, min(2100, (int) ($_GET['tm'] ?? $berjalan['mulai'])));
$prefillTs = max($prefillTm, min(2105, (int) ($_GET['ts'] ?? $berjalan['selesai'])));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pembayaran') {
    $result = keuangan_save_pembayaran($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    if (!$result['ok']) {
        set_flash('error', $result['message']);
        header('Location: /pwa_nailulmuna/keuangan/pembayaran.php');
        exit;
    }
    set_flash('success', $result['message']);
    $newId = (int) ($result['id'] ?? 0);
    if ($newId > 0) {
        header('Location: /pwa_nailulmuna/keuangan/kuitansi.php?id=' . $newId);
        exit;
    }
    header('Location: /pwa_nailulmuna/keuangan/pembayaran.php');
    exit;
}

$santriRows = keuangan_fetch_santri_aktif($pdo);
$santriKeuanganById = keuangan_build_santri_keuangan_map($pdo, $biayaDefinitions);
$akunRows = keuangan_fetch_akun_aktif($pdo);
$defaultAkunId = 0;
foreach ($akunRows as $ar) {
    if ((int) ($ar['is_default'] ?? 0) === 1) {
        $defaultAkunId = (int) $ar['id'];
        break;
    }
}
if ($defaultAkunId <= 0 && $akunRows !== []) {
    $defaultAkunId = (int) ($akunRows[0]['id'] ?? 0);
}

$recentRows = keuangan_recent_pembayaran($pdo, 12);

$pageTitle = 'Input Pembayaran';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pemasukan</p>
    <h1 class="h4 mb-1">Formulir Pembayaran Santri</h1>
    <p class="text-muted mb-0">
        Catat penerimaan kas/bank per komponen biaya santri. Setelah simpan, kuitansi dapat dicetak.
        Untuk donasi/hibah/bantuan dari luar santri gunakan
        <a href="/pwa_nailulmuna/keuangan/pemasukan.php">Pemasukan lain</a>.
        <a href="/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php">Tagihan bulanan</a>
        · <a href="/pwa_nailulmuna/keuangan/index.php">Dashboard keuangan</a>
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm" id="formulir-pembayaran">
            <div class="card-header bg-success bg-opacity-10 fw-semibold text-success">Transaksi masuk</div>
            <div class="card-body">
                <?php if ($santriRows === []): ?>
                    <div class="alert alert-warning mb-0">Belum ada santri aktif. Tambahkan data santri terlebih dahulu.</div>
                <?php else: ?>
                <form method="post" class="row g-2" id="form-pembayaran" autocomplete="off">
                    <input type="hidden" name="action" value="save_pembayaran">
                    <div class="col-12">
                        <label class="form-label">Santri <span class="text-danger">*</span></label>
                        <select name="santri_id" id="santri_id" class="form-select" required data-search-placeholder="Ketik NIS atau nama santri…">
                            <option value="">— Pilih santri —</option>
                            <?php foreach ($santriRows as $s): ?>
                                <?php $sid = (int) $s['id']; ?>
                                <option value="<?= $sid ?>" <?= $sid === $prefillSantriId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($s['nis'] ?: '-')) ?> — <?= htmlspecialchars((string) $s['nama_santri']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="santri-tier-hint">Tarif mengikuti kelas keuangan santri yang dipilih.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Jenis periode</label>
                        <select class="form-select" name="jenis_periode" id="jenis_periode">
                            <option value="BULANAN">Bulanan</option>
                            <option value="AWAL_TAHUN">Awal tahun</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="wrap-bulan">
                        <label class="form-label">Bulan tagihan</label>
                        <select class="form-select" name="bulan_tagihan" id="bulan_tagihan">
                            <?php foreach ($bulanMap as $m => $label): ?>
                                <option value="<?= $m ?>" <?= $m === $prefillBulan ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tanggal bayar</label>
                        <input type="date" class="form-control" name="tanggal_bayar" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Th. ajaran mulai</label>
                        <input type="number" class="form-control" name="tahun_ajaran_mulai" value="<?= (int) $prefillTm ?>" min="2000" max="2100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Th. ajaran selesai</label>
                        <input type="number" class="form-control" name="tahun_ajaran_selesai" value="<?= (int) $prefillTs ?>" min="2000" max="2105">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Metode</label>
                        <select class="form-select" name="metode_bayar" id="metode_bayar">
                            <option value="KAS">Kas</option>
                            <option value="TRANSFER">Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status transaksi</label>
                        <input type="text" class="form-control" id="status_lunas_label" value="Lunas" readonly>
                        <input type="hidden" name="status_lunas" id="status_lunas" value="LUNAS">
                        <div class="form-text">Otomatis: Cicilan jika masih ada sisa tagihan.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Akun penerimaan <span class="text-danger">*</span></label>
                        <select class="form-select" name="akun_id" required>
                            <?php if ($akunRows === []): ?>
                                <option value="">Belum ada akun — buka Pengaturan Keuangan</option>
                            <?php else: ?>
                                <?php foreach ($akunRows as $ak): ?>
                                    <option value="<?= (int) $ak['id'] ?>" <?= (int) $ak['id'] === $defaultAkunId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $ak['jenis_akun']) ?> — <?= htmlspecialchars((string) $ak['nama_akun']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($akunRows === []): ?>
                            <div class="form-text"><a href="/pwa_nailulmuna/keuangan/pengaturan.php?bagian=akun">Tambah akun kas/bank</a> di pengaturan keuangan.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">No. referensi / bukti</label>
                        <input type="text" class="form-control" name="no_referensi" id="no_referensi" placeholder="Wajib untuk transfer">
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1">Komponen dibayar</label>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0" id="tabel-komponen">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:3rem">Pilih</th>
                                        <th>Pos</th>
                                        <th style="width:10rem">Tagihan / sisa</th>
                                        <th style="width:11rem">Bayar sekarang</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $wajibSlugs = keuangan_tagihan_wajib_slugs();
                                foreach ($biayaDefinitions as $def):
                                    $slug = (string) ($def['slug'] ?? '');
                                    $isWajibTagihan = $def['kategori'] === 'Bulanan' && in_array($slug, $wajibSlugs, true);
                                    $isSaku = $slug === 'saku';
                                ?>
                                    <tr data-kategori="<?= htmlspecialchars($def['kategori']) ?>" data-slug="<?= htmlspecialchars($slug) ?>">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input bayar-pos-check" name="bayar_pos[]" value="<?= htmlspecialchars($slug) ?>">
                                        </td>
                                        <td>
                                            <span class="badge text-bg-secondary me-1"><?= htmlspecialchars($def['kategori']) ?></span>
                                            <?= htmlspecialchars($def['nama']) ?>
                                            <?php if ($isWajibTagihan): ?>
                                                <span class="badge text-bg-warning ms-1">Tagihan wajib</span>
                                            <?php elseif ($isSaku): ?>
                                                <span class="badge text-bg-info ms-1">Opsional · cashless</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted paid-hint" data-slug="<?= htmlspecialchars($def['slug']) ?>">—</td>
                                        <td>
                                            <input type="text" inputmode="numeric" class="form-control form-control-sm nominal-pos"
                                                   name="nominal_<?= htmlspecialchars($def['slug']) ?>" value="0" data-slug="<?= htmlspecialchars($def['slug']) ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mt-2 mb-0" id="tagihan-summary-hint">
                            Pilih santri dan bulan untuk melihat sisa tagihan. Pembayaran cicilan dijumlahkan otomatis ke laporan tagihan.
                        </p>
                        <p class="small text-muted mb-0">
                            <strong>Syahriyah</strong> dan <strong>Makan</strong> = tagihan bulanan wajib.
                            <strong>Saku</strong> opsional — masuk saldo cashless.
                        </p>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Keterangan</label>
                        <input type="text" class="form-control" name="keterangan" placeholder="Catatan pembayaran (opsional)">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-success"><i class="fa-solid fa-check me-1"></i> Simpan &amp; buka kuitansi</button>
                        <a class="btn btn-outline-secondary" href="/pwa_nailulmuna/pembayaran/riwayat.php">Riwayat</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Pembayaran terakhir</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr><th>Tanggal</th><th>Santri</th><th class="text-end">Total</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php if ($recentRows === []): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada pembayaran.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRows as $r): ?>
                                <?php
                                $bl = (int) ($r['bulan_tagihan'] ?? 0);
                                $periodeShort = ($r['jenis_periode'] ?? '') === 'BULANAN' && $bl > 0
                                    ? ($bulanMap[$bl] ?? '')
                                    : 'Awal th.';
                                ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars((string) $r['tanggal_bayar']) ?></td>
                                    <td class="small">
                                        <?= htmlspecialchars((string) $r['nama_santri']) ?>
                                        <div class="text-muted"><?= htmlspecialchars($periodeShort) ?></div>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah((int) ((float) $r['total_nominal']))) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm py-0" href="/pwa_nailulmuna/keuangan/kuitansi.php?id=<?= (int) $r['id'] ?>">KW</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.keuanganSantriMap = <?= json_encode($santriKeuanganById, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/pwa_nailulmuna/assets/js/keuangan-pembayaran-form.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
