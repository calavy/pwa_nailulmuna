<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_defs.php';
require_once __DIR__ . '/../helpers/keuangan_pengaturan.php';
require_once __DIR__ . '/../helpers/keuangan_tarif_bulanan.php';
require_once __DIR__ . '/../helpers/keuangan_pkpps_syahriyah.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/tagihan_santri_masuk.php';
require_once __DIR__ . '/../helpers/keuangan_kelas_makan.php';

require_login();
require_roles(['admin', 'pengurus']);

$section = trim((string) ($_GET['bagian'] ?? 'umum'));
if ($section === 'tarif_bulan') {
    $redirectQs = $_GET;
    $redirectQs['bagian'] = 'syahriyah_makan';
    header('Location: ' . app_rewrite_internal_url('/keuangan/pengaturan.php?' . http_build_query($redirectQs)));
    exit;
}
$validSections = ['umum', 'syahriyah_makan', 'makan', 'tarif', 'akun', 'alokasi', 'alokasi_awal', 'alokasi_makan'];
if (!in_array($section, $validSections, true)) {
    $section = 'umum';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
    keuangan_ensure_schema_deferred($pdo);
    $action = (string) ($_POST['action'] ?? '');
    $result = match ($action) {
        'save_periode' => keuangan_save_periode_settings($pdo, $_POST),
        'save_tagihan_masuk' => keuangan_save_tagihan_masuk_settings($pdo, $_POST),
        'save_tarif_awal_jenis' => keuangan_save_tarif_awal_tahun_jenis_settings($pdo, $_POST),
        'save_tarif' => keuangan_save_tarif_settings($pdo, $_POST),
        'save_tarif_bulan' => keuangan_save_tarif_bulanan_settings($pdo, $_POST),
        'save_pkpps_syahriyah' => keuangan_pkpps_syahriyah_save_settings($pdo, $_POST),
        'save_makan_pengaturan' => keuangan_makan_save_pengaturan($pdo, $_POST),
        'save_akun' => keuangan_save_akun($pdo, $_POST),
        'save_alokasi' => keuangan_save_alokasi($pdo, $_POST),
        default => ['ok' => false, 'message' => 'Aksi tidak dikenali.'],
    };
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    $redirectSection = match ($action) {
        'save_tagihan_masuk' => 'umum',
        'save_tarif_awal_jenis' => 'tarif',
        'save_tarif' => trim((string) ($_POST['redirect_bagian'] ?? '')) === 'syahriyah_makan'
            ? 'syahriyah_makan'
            : 'tarif',
        'save_tarif_bulan', 'save_pkpps_syahriyah' => 'syahriyah_makan',
        'save_makan_pengaturan' => 'makan',
        'save_akun' => 'akun',
        'save_alokasi' => keuangan_alokasi_section_for_jenis((string) ($_POST['jenis_dana'] ?? KEUNGAN_ALOKASI_JENIS_SYAHRIYAH)),
        default => 'umum',
    };
    $hash = match ($action) {
        'save_pkpps_syahriyah' => '#tambahan-pkpps',
        'save_tarif_bulan' => '#tarif-per-bulan',
        default => '',
    };
    header('Location: ' . app_rewrite_internal_url('/keuangan/pengaturan.php?bagian=' . urlencode($redirectSection) . $hash));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || empty($_SESSION['keuangan_schema_ready_v1'])) {
    require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
    keuangan_ensure_schema_deferred($pdo);
}

$tiers = ['muadalah' => 'Muadalah', 'wustho' => 'Wustho', 'ulya' => 'Ulya'];
$biayaDefs = [];
$feeMatrix = [];
$akunRows = [];
$alokasiRows = [];
$periode = pondok_tahun_ajaran_aktif($pdo);
$taMeta = null;
$keuanganTa = null;
$editAkun = null;
$editAlokasi = null;

if ($section === 'umum') {
    $taMeta = pondok_ta_form_meta($pdo);
    $tagihanMulaiMasuk = keuangan_tagihan_mulai_masuk_enabled($pdo);
    $awalTahunBedakan = keuangan_awal_tahun_bedakan_baru_lama($pdo);
} elseif ($section === 'syahriyah_makan') {
    ensure_keuangan_tarif_bulanan_table($pdo);
    pkpps_ensure_schema($pdo);
    $periode = pondok_tahun_ajaran_aktif($pdo);
    $taMeta = pondok_ta_form_meta($pdo);
    $syMakanDefs = keuangan_biaya_filter_syahriyah_makan(keuangan_biaya_definitions(), true);
    $feeMatrixSyMakan = keuangan_fee_matrix_from_settings($pdo, $syMakanDefs);
    $taMulaiTarifBulan = (int) ($_GET['ta_mulai'] ?? $periode['mulai']);
    $taSelesaiTarifBulan = (int) ($_GET['ta_selesai'] ?? $periode['selesai']);
    $taNormBulan = pondok_normalisasi_tahun_ajaran_input($pdo, $taMulaiTarifBulan, $taSelesaiTarifBulan);
    $taMulaiTarifBulan = (int) $taNormBulan['mulai'];
    $taSelesaiTarifBulan = (int) $taNormBulan['selesai'];
    $bulanSlotsTarif = pondok_bulan_slots_tahun_ajaran($pdo, $taMulaiTarifBulan, $taSelesaiTarifBulan);
    $tarifBulanMap = keuangan_tarif_bulanan_map($pdo, $taMulaiTarifBulan, $taSelesaiTarifBulan);
    $bulanLabelsShort = [];
    foreach ($bulanSlotsTarif as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $bulanLabelsShort[$b] = pondok_bulan_slot_label_tampilan($pdo, $slot);
        }
    }
    for ($b = 1; $b <= 12; $b++) {
        if (!isset($bulanLabelsShort[$b])) {
            $bulanLabelsShort[$b] = 'B' . $b;
        }
    }
    $loadSyahriyahBulanToggle = true;
} elseif ($section === 'makan') {
    ensure_kelas_keuangan_table($pdo);
    $periode = pondok_tahun_ajaran_aktif($pdo);
    $taMulaiTarifBulan = (int) ($periode['mulai'] ?? 0);
    $taSelesaiTarifBulan = (int) ($periode['selesai'] ?? 0);
    $bulanSlotsTarif = pondok_bulan_slots_tahun_ajaran($pdo, $taMulaiTarifBulan, $taSelesaiTarifBulan);
    $bulanLabelsShort = [];
    foreach ($bulanSlotsTarif as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $bulanLabelsShort[$b] = pondok_bulan_slot_label_tampilan($pdo, $slot);
        }
    }
    for ($b = 1; $b <= 12; $b++) {
        if (!isset($bulanLabelsShort[$b])) {
            $bulanLabelsShort[$b] = 'B' . $b;
        }
    }
} elseif ($section === 'tarif') {
    $biayaDefs = keuangan_biaya_filter_syahriyah_makan(keuangan_biaya_definitions(), false);
    $feeMatrix = keuangan_fee_matrix_from_settings($pdo, $biayaDefs);
    $awalTahunDefs = array_values(array_filter(
        $biayaDefs,
        static fn (array $d): bool => (string) ($d['kategori'] ?? '') === 'Awal Tahun'
    ));
    $feeMatrixAwalJenis = keuangan_fee_matrix_awal_tahun_jenis($pdo, $awalTahunDefs);
    $tagihanMulaiMasuk = keuangan_tagihan_mulai_masuk_enabled($pdo);
    $awalTahunBedakan = keuangan_awal_tahun_bedakan_baru_lama($pdo);
} elseif ($section === 'akun') {
    $editAkunId = (int) ($_GET['edit_akun'] ?? 0);
    $akunRows = keuangan_fetch_akun_all($pdo);
    foreach ($akunRows as $ar) {
        if ((int) ($ar['id'] ?? 0) === $editAkunId) {
            $editAkun = $ar;
            break;
        }
    }
} elseif ($section === 'alokasi' || $section === 'alokasi_awal' || $section === 'alokasi_makan') {
    require_once __DIR__ . '/../helpers/keuangan_alokasi.php';
    ensure_keuangan_alokasi_jenis_dana($pdo);
    $keuanganTa = keuangan_ta_resolve($pdo);
    $periode = ['mulai' => (int) $keuanganTa['mulai'], 'selesai' => (int) $keuanganTa['selesai']];
    $editAlokasiId = (int) ($_GET['edit_alokasi'] ?? 0);
    $alokasiRows = keuangan_fetch_alokasi_all($pdo);
    foreach ($alokasiRows as $al) {
        if ((int) ($al['id'] ?? 0) === $editAlokasiId) {
            $editAlokasi = $al;
            break;
        }
    }
}

$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);

$pageTitle = 'Pengaturan Keuangan';
$bodyClass = keuangan_body_class('keuangan-pengaturan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="/keuangan/index.php">Keuangan</a> · Pengaturan</p>
    <h1 class="h4 mb-1">Pengaturan Keuangan</h1>
    <p class="text-muted mb-0">
        Tahun ajaran, <strong>syahriyah &amp; makan</strong>, tarif lainnya, akun kas/bank, dan alokasi dana (syahriyah, awal tahun, makan) — dalam satu halaman.
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
        <a class="nav-link <?= $section === 'syahriyah_makan' ? 'active' : '' ?>" href="?bagian=syahriyah_makan">Syahriyah (termasuk PKPPS)</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'makan' ? 'active' : '' ?>" href="?bagian=makan">Makan per kelas</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $section === 'tarif' ? 'active' : '' ?>" href="?bagian=tarif">Tarif lainnya</a>
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
    <li class="nav-item">
        <a class="nav-link <?= $section === 'alokasi_makan' ? 'active' : '' ?>" href="?bagian=alokasi_makan">Alokasi makan</a>
    </li>
</ul>

<?php if ($section === 'umum'): ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tahun ajaran aktif (terpusat)</div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Atur <strong>sekali di sini</strong>. Tagihan, pembayaran, laporan, tingkatan santri, dan modul lain otomatis memakai periode ini — tidak perlu memilih tahun ajaran di setiap menu.
                </p>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_periode">
                    <?php
                    $taMulai = (int) $periode['mulai'];
                    $taSelesai = (int) $periode['selesai'];
                    $taColClass = 'col-md-8';
                    $taInputMode = 'dropdown';
                    $nameMulai = 'keuangan_periode_mulai';
                    $nameSelesai = 'keuangan_periode_selesai';
                    require __DIR__ . '/../includes/partials/pondok_ta_fields.php';
                    ?>
                    <div class="col-12">
                        <p class="small text-muted mb-2">
                            Tahun ajaran <?= $taMeta['suffix'] !== '' ? 'Hijriyah' : 'Masehi' ?> (12 bulan = Muharram–Dzulhijjah bila Hijriyah).
                            Saat ini aktif:
                            <strong><?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, $periode)) ?></strong>.
                        </p>
                        <?php if (pondok_kalender_hijriyah($pdo)): ?>
                            <p class="small mb-2">
                                <a href="/settings/kalender.php#alat-lanjutan">Sesuaikan data lama Masehi → Hijriyah</a>
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
                <a class="btn btn-outline-info text-start" href="<?= htmlspecialchars(app_href('/keuangan/panduan.php')) ?>">
                    <i class="fa-solid fa-book-open me-2"></i>Panduan alur keuangan
                </a>
                <a class="btn btn-outline-primary text-start" href="/settings/kelas_keuangan.php">
                    <i class="fa-solid fa-layer-group me-2"></i>Kelas / kategori keuangan santri
                </a>
                <a class="btn btn-warning text-start" href="/keuangan/potongan_syahriyah.php">
                    <i class="fa-solid fa-percent me-2"></i>Potongan syahriyah per santri (%)
                </a>
                <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=makan')) ?>">
                    <i class="fa-solid fa-utensils me-2"></i>Pengaturan makan per kelas
                </a>
                <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan')) ?>">
                    <i class="fa-solid fa-calendar-days me-2"></i>Syahriyah, makan &amp; tambahan PKPPS
                </a>
                <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">
                    <i class="fa-solid fa-layer-group me-2"></i>Kelas keuangan santri
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
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Tagihan sesuai tanggal masuk santri</div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Santri yang masuk di pertengahan tahun ajaran tidak ditagih bulan sebelum tanggal masuk.
                    Pada pembayaran <strong>awal tahun</strong>, sistem membedakan <strong>santri baru</strong> (masuk TA ini)
                    dan <strong>santri lama</strong> (sudah pernah tinggal di TA sebelumnya). Pastikan kolom
                    <strong>tanggal masuk</strong> di data santri terisi.
                </p>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="save_tagihan_masuk">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="keuangan_tagihan_mulai_masuk" value="1" id="tagihan-mulai-masuk"
                            <?= !empty($tagihanMulaiMasuk) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tagihan-mulai-masuk">
                            Tagihan bulanan mulai dari bulan masuk santri (berdasarkan tanggal masuk)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="keuangan_awal_tahun_bedakan_baru_lama" value="1" id="awal-bedakan-jenis"
                            <?= !empty($awalTahunBedakan) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="awal-bedakan-jenis">
                            Bedakan tarif awal tahun santri baru vs santri lama
                        </label>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan pengaturan tagihan masuk</button>
                        <?php if (!empty($awalTahunBedakan)): ?>
                            <a class="btn btn-outline-secondary btn-sm ms-1" href="?bagian=tarif#tarif-awal-jenis">Atur tarif baru/lama</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($section === 'syahriyah_makan'): ?>
<div class="alert alert-info small mb-3">
    Semua nominal di halaman ini otomatis dipakai di
    <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>"><strong>input pembayaran</strong></a>
    dan <a href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">tagihan bulanan</a>
    (syahriyah pokok + makan + <strong>tambahan PKPPS</strong> untuk santri PKPPS).
</div>
<?php require __DIR__ . '/partials/syahriyah_makan_pengaturan.php'; ?>
<?php require __DIR__ . '/partials/syahriyah_tambahan_nominal.php'; ?>
<?php require __DIR__ . '/partials/syahriyah_operator_notes.php'; ?>
<?php endif; ?>

<?php if ($section === 'makan'): ?>
<div class="alert alert-info small mb-3">
    Tarif tier global (Muadalah/Wustho/Ulya) tetap di tab
    <a href="?bagian=syahriyah_makan">Syahriyah &amp; makan</a>.
    Halaman ini untuk <strong>nama tampilan</strong> dan <strong>override per kelas keuangan</strong> (by name).
    Atur pembagian dana makan (bahan, gaji dapur, operasional) di tab
    <a href="?bagian=alokasi_makan"><strong>Alokasi makan</strong></a>.
</div>
<?php require __DIR__ . '/partials/makan_kelas_pengaturan.php'; ?>
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
    <div class="card-header fw-semibold">Tarif saku, awal tahun, dan komponen lain</div>
    <div class="card-body">
        <p class="small text-muted">
            Syahriyah &amp; makan di tab <a href="?bagian=syahriyah_makan">Syahriyah &amp; makan</a>.
            Tier dari <strong>Kelas keuangan</strong> santri. Nominal tanpa titik — contoh: 200000.
        </p>
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
                                    $val = (int) ($feeMatrix[$slug][$tk] ?? 0);
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

        <?php if (!empty($awalTahunBedakan) && ($awalTahunDefs ?? []) !== []): ?>
        <hr class="my-4" id="tarif-awal-jenis">
        <h2 class="h6 text-secondary">Awal tahun — santri baru vs lama</h2>
        <p class="small text-muted">
            Tarif di bawah dipakai saat input pembayaran awal tahun.
            Santri <strong>baru</strong> = tahun ajaran masuk sama dengan TA aktif.
            Santri <strong>lama</strong> = sudah punya riwayat tingkatan di TA sebelumnya atau masuk sebelum TA ini.
            Kosongkan tidak perlu — isi 0 bila komponen tidak dikenakan (mis. pendaftaran untuk santri lama).
        </p>
        <form method="post">
            <input type="hidden" name="action" value="save_tarif_awal_jenis">
            <?php foreach (['baru' => 'Santri baru', 'lama' => 'Santri lama'] as $jenisKey => $jenisLabel): ?>
                <h3 class="h6 mt-3 mb-2"><?= htmlspecialchars($jenisLabel) ?></h3>
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
                        <?php foreach ($awalTahunDefs as $def):
                            $slug = (string) $def['slug'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $def['nama']) ?></td>
                                <?php foreach ($tiers as $tk => $tl):
                                    $val = (int) ($feeMatrixAwalJenis[$jenisKey][$slug][$tk] ?? 0);
                                    ?>
                                    <td>
                                        <input type="text" class="form-control form-control-sm text-end"
                                               name="fee_<?= htmlspecialchars($jenisKey) ?>[<?= htmlspecialchars($slug) ?>][<?= htmlspecialchars($tk) ?>]"
                                               value="<?= htmlspecialchars((string) $val) ?>" inputmode="numeric">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-warning">Simpan tarif awal tahun (baru &amp; lama)</button>
        </form>
        <?php endif; ?>
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

<?php if ($section === 'alokasi' || $section === 'alokasi_awal' || $section === 'alokasi_makan'): ?>
<?php
    $alokasiJenisDana = match ($section) {
        'alokasi_awal' => KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN,
        'alokasi_makan' => KEUNGAN_ALOKASI_JENIS_MAKAN,
        default => KEUNGAN_ALOKASI_JENIS_SYAHRIYAH,
    };
    $alokasiSectionBagian = $section;
    $alokasiRowsFiltered = keuangan_alokasi_rows_for_jenis($alokasiRows, $alokasiJenisDana);
    $editAlokasiScoped = keuangan_alokasi_edit_for_jenis($editAlokasi, $alokasiJenisDana);
    require __DIR__ . '/partials/alokasi_pengaturan_section.php';
?>
<?php endif; ?>

<?php if ($section === 'umum' || $section === 'syahriyah_makan'): ?>
<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($loadSyahriyahBulanToggle)): ?>
<script src="<?= htmlspecialchars(app_href('/assets/js/syahriyah-bulan-toggle.js')) ?>" defer></script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
