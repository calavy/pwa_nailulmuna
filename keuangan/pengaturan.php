<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_pengaturan.php';
require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

ensure_keuangan_transaksi_tables($pdo);
ensure_kelas_keuangan_table($pdo);

$section = trim((string) ($_GET['bagian'] ?? 'umum'));
$validSections = ['umum', 'tarif', 'akun', 'alokasi', 'alokasi_awal'];
if (!in_array($section, $validSections, true)) {
    $section = 'umum';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $result = match ($action) {
        'save_periode' => keuangan_save_periode_settings($pdo, $_POST),
        'save_tarif' => keuangan_save_tarif_settings($pdo, $_POST),
        'save_akun' => keuangan_save_akun($pdo, $_POST),
        'save_alokasi' => keuangan_save_alokasi($pdo, $_POST),
        default => ['ok' => false, 'message' => 'Aksi tidak dikenali.'],
    };
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    $redirectSection = match ($action) {
        'save_tarif' => 'tarif',
        'save_akun' => 'akun',
        'save_alokasi' => keuangan_alokasi_section_for_jenis((string) ($_POST['jenis_dana'] ?? KEUNGAN_ALOKASI_JENIS_SYAHRIYAH)),
        default => 'umum',
    };
    header('Location: ' . app_rewrite_internal_url('/keuangan/pengaturan.php?bagian=' . urlencode($redirectSection)));
    exit;
}

$periode = pondok_tahun_ajaran_aktif($pdo);
$taMeta = pondok_ta_form_meta($pdo);
$biayaDefs = keuangan_biaya_definitions();
$tiers = ['muadalah' => 'Muadalah', 'wustho' => 'Wustho', 'ulya' => 'Ulya'];
$akunRows = keuangan_fetch_akun_all($pdo);
$alokasiRows = keuangan_fetch_alokasi_all($pdo);
$editAkunId = (int) ($_GET['edit_akun'] ?? 0);
$editAlokasiId = (int) ($_GET['edit_alokasi'] ?? 0);
$editAkun = null;
$editAlokasi = null;
foreach ($akunRows as $ar) {
    if ((int) ($ar['id'] ?? 0) === $editAkunId) {
        $editAkun = $ar;
        break;
    }
}
foreach ($alokasiRows as $al) {
    if ((int) ($al['id'] ?? 0) === $editAlokasiId) {
        $editAlokasi = $al;
        break;
    }
}

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Pengaturan Keuangan';
$bodyClass = keuangan_body_class('keuangan-pengaturan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Pengaturan</p>
    <h1 class="h4 mb-1">Pengaturan Keuangan &amp; Syahriyah</h1>
    <p class="text-muted mb-0">
        Satu tempat untuk tahun ajaran, tarif, akun kas/bank, dan alokasi dana.
        Lainnya di menu pengaturan:
        <a href="/settings/kelas_keuangan.php">Kelas keuangan</a>,
        <a href="/keuangan/inventaris.php">Inventaris aset</a>,
        <a href="/keuangan/cashless_pin.php">Cashless &amp; uang saku</a>,
        <a href="/keuangan/potongan_syahriyah.php">Potongan syahriyah per santri</a>.
    </p>
</div>

<ul class="nav nav-tabs mb-3 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $section === 'umum' ? 'active' : '' ?>" href="?bagian=umum">Umum &amp; periode</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'tarif' ? 'active' : '' ?>" href="?bagian=tarif">Tarif biaya</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'akun' ? 'active' : '' ?>" href="?bagian=akun">Akun kas/bank</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'alokasi' ? 'active' : '' ?>" href="?bagian=alokasi">Alokasi syahriyah</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'alokasi_awal' ? 'active' : '' ?>" href="?bagian=alokasi_awal">Alokasi awal tahun</a>
    </li>
</ul>

<?php if ($section === 'umum'): ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tahun ajaran keuangan</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_periode">
                    <?php
                    $taMulai = (int) $periode['mulai'];
                    $taSelesai = (int) $periode['selesai'];
                    $taColClass = 'col-md-6';
                    $nameMulai = 'keuangan_periode_mulai';
                    $nameSelesai = 'keuangan_periode_selesai';
                    require __DIR__ . '/../includes/partials/pondok_ta_fields.php';
                    ?>
                    <div class="col-12">
                        <p class="small text-muted mb-2">
                            Tahun ajaran <?= $taMeta['suffix'] !== '' ? 'Hijriyah' : 'Masehi' ?> (12 bulan = Muharram–Dzulhijjah bila Hijriyah).
                            Dipakai di tagihan, pembayaran, rekap presensi, dan laporan:
                            <strong><?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, $periode)) ?></strong>.
                        </p>
                        <?php if (pondok_kalender_hijriyah($pdo)): ?>
                            <p class="small mb-2">
                                <a href="/settings/kalender.php#backfill-hijriyah">Sesuaikan data lama Masehi → Hijriyah</a>
                                bila server sudah berisi input tahun/bulan Masehi.
                            </p>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Simpan periode</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Syahriyah &amp; operasional</div>
            <div class="card-body d-grid gap-2">
                <a class="btn btn-outline-primary text-start" href="/settings/kelas_keuangan.php">
                    <i class="fa-solid fa-layer-group me-2"></i>Kelas / kategori keuangan santri
                </a>
                <a class="btn btn-warning text-start" href="/keuangan/potongan_syahriyah.php">
                    <i class="fa-solid fa-percent me-2"></i>Potongan syahriyah per santri (%)
                </a>
                <a class="btn btn-outline-primary text-start" href="/pembayaran/tagihan_syahriyah.php">
                    <i class="fa-solid fa-receipt me-2"></i>Tagihan syahriyah per bulan
                </a>
                <a class="btn btn-outline-secondary text-start" href="/pembayaran/laporan.php">
                    <i class="fa-solid fa-chart-column me-2"></i>Laporan syahriyah
                </a>
                <a class="btn btn-outline-secondary text-start" href="/keuangan/cashless_pin.php">
                    <i class="fa-solid fa-key me-2"></i>Cashless &amp; uang saku
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($section === 'tarif'): ?>
<?php
    $byKategori = [];
    foreach ($biayaDefs as $def) {
        $kat = (string) ($def['kategori'] ?? 'Lainnya');
        $byKategori[$kat][] = $def;
    }
?>
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Tarif komponen biaya (per tier Muadalah / Wustho / Ulya)</div>
    <div class="card-body">
        <p class="small text-muted">Tier diambil dari <strong>Kelas keuangan</strong> tiap santri. Nominal Rupiah tanpa titik — contoh: 200000.</p>
        <form method="post">
            <input type="hidden" name="action" value="save_tarif">
            <?php foreach ($byKategori as $katNama => $defsKat): ?>
                <h2 class="h6 mt-3 mb-2 text-secondary"><?= htmlspecialchars($katNama) ?></h2>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Komponen</th>
                                <?php foreach ($tiers as $tl): ?>
                                    <th class="text-end" style="min-width:7rem"><?= htmlspecialchars($tl) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($defsKat as $def):
                            $slug = (string) $def['slug'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $def['nama']) ?></td>
                                <?php foreach ($tiers as $tk => $tl):
                                    $val = keuangan_fee_nominal_for_tier($pdo, $def, $tk);
                                    ?>
                                    <td>
                                        <input type="text" class="form-control form-control-sm text-end"
                                               name="fee[<?= htmlspecialchars($slug) ?>][<?= htmlspecialchars($tk) ?>]"
                                               value="<?= htmlspecialchars((string) $val) ?>" inputmode="numeric">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary">Simpan semua tarif</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($section === 'akun'): ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold"><?= $editAkun ? 'Ubah akun' : 'Tambah akun kas/bank' ?></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_akun">
                    <input type="hidden" name="akun_id" value="<?= (int) ($editAkun['id'] ?? 0) ?>">
                    <div class="col-md-6">
                        <label class="form-label">Jenis</label>
                        <select class="form-select" name="jenis_akun">
                            <?php foreach (['KAS' => 'Kas', 'BANK' => 'Bank', 'E-WALLET' => 'E-Wallet'] as $jv => $jl): ?>
                                <option value="<?= $jv ?>" <?= (($editAkun['jenis_akun'] ?? 'KAS') === $jv) ? 'selected' : '' ?>><?= $jl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama akun <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama_akun" required value="<?= htmlspecialchars((string) ($editAkun['nama_akun'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama bank</label>
                        <input class="form-control" name="nama_bank" value="<?= htmlspecialchars((string) ($editAkun['nama_bank'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">No. rekening</label>
                        <input class="form-control" name="no_rekening" value="<?= htmlspecialchars((string) ($editAkun['no_rekening'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Atas nama</label>
                        <input class="form-control" name="atas_nama" value="<?= htmlspecialchars((string) ($editAkun['atas_nama'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Saldo awal</label>
                        <input class="form-control" name="opening_balance" inputmode="numeric"
                               value="<?= htmlspecialchars((string) (int) round((float) ($editAkun['opening_balance'] ?? 0))) ?>">
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-end gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" value="1" id="akun-default"
                                <?= (int) ($editAkun['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="akun-default">Akun default</label>
                        </div>
                        <?php if ($editAkun): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="akun-aktif"
                                <?= (int) ($editAkun['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="akun-aktif">Aktif</label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Simpan akun</button>
                        <?php if ($editAkun): ?>
                            <a class="btn btn-outline-secondary" href="?bagian=akun">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Daftar akun</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Akun</th>
                                <th class="text-end">Saldo awal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($akunRows === []): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">Belum ada akun. Tambahkan kas bendahara.</td></tr>
                        <?php else: ?>
                            <?php foreach ($akunRows as $ar): ?>
                                <tr class="<?= (int) ($ar['is_active'] ?? 1) !== 1 ? 'table-secondary' : '' ?>">
                                    <td class="small">
                                        <strong><?= htmlspecialchars((string) $ar['nama_akun']) ?></strong>
                                        <div class="text-muted"><?= htmlspecialchars((string) $ar['jenis_akun']) ?>
                                            <?php if ((int) ($ar['is_default'] ?? 0) === 1): ?> · default<?php endif; ?>
                                            <?php if ((int) ($ar['is_active'] ?? 1) !== 1): ?> · nonaktif<?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars($formatRupiah((int) round((float) ($ar['opening_balance'] ?? 0)))) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="?bagian=akun&amp;edit_akun=<?= (int) $ar['id'] ?>">Ubah</a>
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
<?php endif; ?>

<?php if ($section === 'alokasi' || $section === 'alokasi_awal'): ?>
<?php
    $alokasiJenisDana = $section === 'alokasi_awal' ? KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN : KEUNGAN_ALOKASI_JENIS_SYAHRIYAH;
    $alokasiSectionBagian = $section;
    $alokasiRowsFiltered = keuangan_alokasi_rows_for_jenis($alokasiRows, $alokasiJenisDana);
    $editAlokasiScoped = keuangan_alokasi_edit_for_jenis($editAlokasi, $alokasiJenisDana);
    require __DIR__ . '/partials/alokasi_pengaturan_section.php';
?>
<?php endif; ?>

<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
