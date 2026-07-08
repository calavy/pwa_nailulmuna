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
require_once __DIR__ . '/../helpers/keuangan_pengaturan_sections.php';
require_once __DIR__ . '/../helpers/santri_opsional_pengaturan.php';
require_once __DIR__ . '/../helpers/keuangan_syahriyah_potongan_pengaturan.php';

require_login();
require_roles(['admin', 'pengurus']);

$rawSection = trim((string) ($_GET['bagian'] ?? 'umum'));
$legacyRedirect = keuangan_pengaturan_legacy_redirect($rawSection, $_GET);
if ($legacyRedirect !== null) {
    header('Location: ' . app_rewrite_internal_url($legacyRedirect));
    exit;
}
$section = keuangan_pengaturan_normalize_bagian($rawSection);
if (!in_array($section, keuangan_pengaturan_valid_sections(), true)) {
    $section = 'umum';
}
$alokasiJenisKey = strtolower(trim((string) ($_GET['alokasi_jenis'] ?? 'syahriyah')));
if (!in_array($alokasiJenisKey, ['syahriyah', 'awal_tahun', 'makan'], true)) {
    $alokasiJenisKey = 'syahriyah';
}
$santriBulananSub = strtolower(trim((string) ($_GET['sub'] ?? 'opsional')));
if (!in_array($santriBulananSub, ['opsional', 'potongan'], true)) {
    $santriBulananSub = 'opsional';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
    keuangan_ensure_schema_deferred($pdo);
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['save_table', 'bulk_aktif', 'bulk_nonaktif'], true)) {
        $opsRes = santri_opsional_pengaturan_handle_post(
            $pdo,
            $_POST,
            keuangan_pengaturan_url('santri_bulanan', ['sub' => 'opsional'])
        );
        set_flash($opsRes['ok'] ? 'success' : 'error', $opsRes['message']);
        header('Location: ' . app_rewrite_internal_url($opsRes['redirect']));
        exit;
    }
    if (in_array($action, ['simpan_potongan', 'hapus_potongan', 'tambah_jeda', 'hapus_jeda'], true)) {
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        $potRes = keuangan_syahriyah_potongan_pengaturan_handle_post($pdo, $_POST, $userId);
        set_flash($potRes['ok'] ? 'success' : 'error', $potRes['message']);
        header('Location: ' . app_rewrite_internal_url($potRes['redirect']));
        exit;
    }
    $result = match ($action) {
        'save_periode' => keuangan_save_periode_settings($pdo, $_POST),
        'save_tagihan_masuk' => keuangan_save_tagihan_masuk_settings($pdo, $_POST),
        'save_tarif_awal_jenis' => keuangan_save_tarif_awal_tahun_jenis_settings($pdo, $_POST),
        'save_awal_tahun_pos_aktif' => keuangan_save_awal_tahun_pos_aktif_settings($pdo, $_POST),
        'save_tarif' => keuangan_save_tarif_settings($pdo, $_POST),
        'save_tarif_bulan' => keuangan_save_tarif_bulanan_settings($pdo, $_POST),
        'save_pkpps_syahriyah' => keuangan_pkpps_syahriyah_save_settings($pdo, $_POST),
        'save_makan_pengaturan' => keuangan_makan_save_pengaturan($pdo, $_POST),
        'save_akun' => keuangan_save_akun($pdo, $_POST),
        'save_kas_saldo_mode' => keuangan_save_kas_saldo_mode($pdo, $_POST),
        'save_alokasi' => keuangan_save_alokasi($pdo, $_POST),
        default => ['ok' => false, 'message' => 'Aksi tidak dikenali.'],
    };
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    $redirectSection = match ($action) {
        'save_tagihan_masuk' => 'umum',
        'save_tarif_awal_jenis' => 'tarif',
        'save_awal_tahun_pos_aktif' => 'tarif',
        'save_tarif', 'save_tarif_bulan', 'save_pkpps_syahriyah', 'save_makan_pengaturan' => 'tarif',
        'save_akun' => 'akun',
        'save_kas_saldo_mode' => 'akun',
        'save_alokasi' => 'alokasi',
        default => 'umum',
    };
    $redirectExtra = [];
    if ($redirectSection === 'alokasi') {
        $jenisDana = (string) ($_POST['jenis_dana'] ?? KEUNGAN_ALOKASI_JENIS_SYAHRIYAH);
        $redirectExtra['alokasi_jenis'] = match ($jenisDana) {
            KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN => 'awal_tahun',
            KEUNGAN_ALOKASI_JENIS_MAKAN => 'makan',
            default => 'syahriyah',
        };
    }
    $hash = match ($action) {
        'save_pkpps_syahriyah' => '#tambahan-pkpps',
        'save_tarif_bulan' => '#tarif-per-bulan',
        'save_makan_pengaturan' => '#makan-kelas',
        'save_tarif_awal_jenis', 'save_awal_tahun_pos_aktif' => '#tarif-saku-awal',
        default => '',
    };
    $qs = array_merge(['bagian' => $redirectSection], $redirectExtra);
    header('Location: ' . app_rewrite_internal_url('/keuangan/pengaturan.php?' . http_build_query($qs) . $hash));
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
$kasSaldoMode = KEUNGAN_KAS_MODE_TRANSAKSI;
$kasUsesOpening = false;
$totalOpening = 0;
$tanpaAkun = 0;

if ($section === 'umum') {
    $taMeta = pondok_ta_form_meta($pdo);
    $tagihanMulaiMasuk = keuangan_tagihan_mulai_masuk_enabled($pdo);
    $awalTahunBedakan = keuangan_awal_tahun_bedakan_baru_lama($pdo);
} elseif ($section === 'tarif') {
    ensure_keuangan_tarif_bulanan_table($pdo);
    pkpps_ensure_schema($pdo);
    ensure_kelas_keuangan_table($pdo);
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
    $biayaDefs = keuangan_biaya_filter_syahriyah_makan(keuangan_biaya_definitions(), false);
    $feeMatrix = keuangan_fee_matrix_from_settings($pdo, $biayaDefs);
    $awalTahunDefs = array_values(array_filter(
        $biayaDefs,
        static fn (array $d): bool => (string) ($d['kategori'] ?? '') === 'Awal Tahun'
    ));
    $feeMatrixAwalJenis = keuangan_fee_matrix_awal_tahun_jenis($pdo, $awalTahunDefs);
    $posAktifAwal = keuangan_awal_tahun_pos_aktif_matrix($pdo, $awalTahunDefs);
    $tagihanMulaiMasuk = keuangan_tagihan_mulai_masuk_enabled($pdo);
    $awalTahunBedakan = keuangan_awal_tahun_bedakan_baru_lama($pdo);
} elseif ($section === 'santri_bulanan') {
    if ($santriBulananSub === 'opsional') {
        $ops = santri_opsional_pengaturan_load($pdo, $_GET);
        $opsEmbedBase = 'bagian=santri_bulanan&sub=opsional';
    } else {
        $potongan = keuangan_syahriyah_potongan_pengaturan_load($pdo, $_GET);
        $loadSantriSelectJs = true;
    }
} elseif ($section === 'akun') {
    $editAkunId = (int) ($_GET['edit_akun'] ?? 0);
    $kasSaldoMode = keuangan_kas_saldo_mode($pdo);
    $kasUsesOpening = keuangan_kas_uses_opening_balance($pdo);
    $totalOpening = keuangan_kas_total_opening_balance($pdo);
    $tanpaAkun = keuangan_count_transaksi_tanpa_akun($pdo);
    $akunRows = keuangan_fetch_akun_all_with_saldo($pdo);
    foreach ($akunRows as $ar) {
        if ((int) ($ar['id'] ?? 0) === $editAkunId) {
            $editAkun = $ar;
            break;
        }
    }
} elseif ($section === 'alokasi') {
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
        Satu pusat pengaturan: <strong>tarif &amp; komponen</strong>, <strong>alokasi dana</strong>, <strong>override per santri bulanan</strong>, tahun ajaran, dan akun kas.
        Master kelas keuangan di <a href="/settings/kelas_keuangan.php">Settings → Kelas keuangan</a>.
        Cashless di <a href="/keuangan/cashless_pin.php">Cashless &amp; uang saku</a>.
    </p>
</div>

<?php require __DIR__ . '/partials/pengaturan_nav.php'; ?>

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
            <div class="card-header fw-semibold">Tautan terkait</div>
            <div class="card-body d-grid gap-2">
                <a class="btn btn-outline-info text-start" href="<?= htmlspecialchars(app_href('/keuangan/panduan.php')) ?>">
                    <i class="fa-solid fa-book-open me-2"></i>Panduan alur keuangan
                </a>
                <a class="btn btn-outline-primary text-start" href="/settings/kelas_keuangan.php">
                    <i class="fa-solid fa-layer-group me-2"></i>Kelas / kategori keuangan santri
                </a>
                <a class="btn btn-outline-primary text-start" href="?bagian=tarif">
                    <i class="fa-solid fa-tags me-2"></i>Tarif &amp; komponen (syahriyah, makan, saku, awal tahun)
                </a>
                <a class="btn btn-outline-primary text-start" href="?bagian=santri_bulanan">
                    <i class="fa-solid fa-user-gear me-2"></i>Override per santri (bulanan)
                </a>
                <a class="btn btn-outline-secondary text-start" href="/pembayaran/tagihan_syahriyah.php">
                    <i class="fa-solid fa-receipt me-2"></i>Tagihan syahriyah per bulan
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
                <?php if (empty($tagihanMulaiMasuk) || empty($awalTahunBedakan)): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <strong>Pengaturan belum lengkap.</strong>
                    <?php if (empty($tagihanMulaiMasuk)): ?>
                        Centang <em>mulai bulan tanggal masuk</em> agar santri baru tidak ditagih sebelum bulan masuk.
                    <?php endif; ?>
                    <?php if (empty($awalTahunBedakan)): ?>
                        <?= empty($tagihanMulaiMasuk) ? ' ' : '' ?>Centang <em>bedakan awal tahun</em> agar tarif/komponen awal tahun berbeda untuk santri baru vs lama.
                    <?php endif; ?>
                    Tanpa ini, semua santri dihitung penuh dari bulan 1 dan awal tahun sama.
                </div>
                <?php endif; ?>
                <?php
                $masukStatus = keuangan_tagihan_masuk_pengaturan_status($pdo);
                if ((int) ($masukStatus['santri_tanpa_tanggal_masuk'] ?? 0) > 0):
                ?>
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    <?= (int) $masukStatus['santri_tanpa_tanggal_masuk'] ?> santri aktif belum punya <strong>tanggal masuk</strong> —
                    diperlakukan sebagai santri lama (tagihan dari bulan 1). Lengkapi di menu Santri.
                </div>
                <?php endif; ?>
                <p class="small text-muted mb-3">
                    <strong>Santri baru</strong> ditagih bulanan mulai <strong>bulan tanggal masuk</strong> pada TA pertama mereka
                    (bulan sebelumnya tidak ditagih). Catatan tersimpan otomatis dan tetap terlihat di TA berikutnya sebagai riwayat.
                    <strong>Santri lama</strong> ditagih penuh dari bulan 1.
                    Pada pembayaran <strong>awal tahun</strong>, sistem membedakan santri baru dan santri lama.
                    Pastikan kolom <strong>tanggal masuk</strong> di data santri terisi.
                </p>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="save_tagihan_masuk">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="keuangan_tagihan_mulai_masuk" value="1" id="tagihan-mulai-masuk"
                            <?= !empty($tagihanMulaiMasuk) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tagihan-mulai-masuk">
                            Santri baru ditagih bulanan mulai bulan tanggal masuk (santri lama tetap dari bulan 1)
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
    <?php require __DIR__ . '/partials/pengaturan_wajib_baru_lama.php'; ?>
</div>
<?php endif; ?>

<?php if ($section === 'tarif'): ?>
<div class="alert alert-info small mb-3">
    Semua tarif &amp; komponen di halaman ini otomatis dipakai di
    <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>"><strong>input pembayaran</strong></a>
    dan <a href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">tagihan bulanan</a>.
    Override per santri (makan/saku aktif, potongan %) di tab <a href="?bagian=santri_bulanan">Per santri (bulanan)</a>.
</div>
<div id="syahriyah-pokok" class="pt-1">
<?php require __DIR__ . '/partials/syahriyah_makan_pengaturan.php'; ?>
</div>
<div id="tambahan-pkpps" class="pt-2">
<?php require __DIR__ . '/partials/syahriyah_tambahan_nominal.php'; ?>
</div>
<div id="makan-kelas" class="pt-2">
<?php require __DIR__ . '/partials/makan_kelas_pengaturan.php'; ?>
</div>
<div id="tarif-saku-awal" class="pt-2">
<?php
    $byKategori = [];
    foreach ($biayaDefs as $def) {
        $kat = (string) ($def['kategori'] ?? 'Lainnya');
        $byKategori[$kat][] = $def;
    }
?>
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Saku &amp; pembayaran awal tahun</div>
    <div class="card-body">
        <p class="small text-muted">Tier dari <strong>Kelas keuangan</strong> santri. Syahriyah &amp; makan di atas.</p>
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
            <button type="submit" class="btn btn-primary">Simpan tarif saku &amp; lainnya</button>
        </form>

        <?php if (!empty($awalTahunBedakan) && ($awalTahunDefs ?? []) !== []): ?>
        <hr class="my-4" id="tarif-awal-jenis">
        <h2 class="h6 text-secondary">Awal tahun — santri baru vs lama</h2>
        <p class="small text-muted">Santri baru = masuk TA aktif; santri lama = sudah pernah di TA sebelumnya.</p>
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

        <hr class="my-4" id="pos-awal-jenis">
        <h2 class="h6 text-secondary">Komponen yang ditagihkan</h2>
        <p class="small text-muted">Centang komponen berlaku per jenis santri. Tidak dicentang = tidak muncul di form pembayaran awal tahun.</p>
        <form method="post">
            <input type="hidden" name="action" value="save_awal_tahun_pos_aktif">
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Komponen</th>
                            <th class="text-center">Santri baru</th>
                            <th class="text-center">Santri lama</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($awalTahunDefs as $def):
                        $slug = (string) $def['slug'];
                        $aktifBaru = !empty($posAktifAwal['baru'][$slug]);
                        $aktifLama = !empty($posAktifAwal['lama'][$slug]);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $def['nama']) ?></td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="pos_aktif_baru[<?= htmlspecialchars($slug) ?>]" value="1" <?= $aktifBaru ? 'checked' : '' ?>>
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="pos_aktif_lama[<?= htmlspecialchars($slug) ?>]" value="1" <?= $aktifLama ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-outline-warning">Simpan komponen berlaku</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</div>
<?php require __DIR__ . '/partials/syahriyah_operator_notes.php'; ?>
<?php endif; ?>

<?php if ($section === 'santri_bulanan'): ?>
<?php if ($santriBulananSub === 'potongan'): ?>
    <?php require __DIR__ . '/partials/potongan_syahriyah_embed.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/partials/santri_opsional_pengaturan.php'; ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($section === 'akun'): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Mode perhitungan saldo kas</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Pilih cara menghitung saldo berjalan di setiap akun kas/bank.
            Mode <strong>Mulai dari nol</strong> mengabaikan saldo awal manual — saldo hanya dari transaksi tercatat.
        </p>
        <form method="post" id="form-kas-saldo-mode" class="vstack gap-2">
            <input type="hidden" name="action" value="save_kas_saldo_mode">
            <input type="hidden" name="reset_opening" value="0" id="reset-opening-hidden">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="keuangan_kas_saldo_mode"
                       id="kas-mode-transaksi" value="<?= htmlspecialchars(KEUNGAN_KAS_MODE_TRANSAKSI) ?>"
                       <?= $kasSaldoMode === KEUNGAN_KAS_MODE_TRANSAKSI ? 'checked' : '' ?>>
                <label class="form-check-label" for="kas-mode-transaksi">
                    <strong>Mulai dari nol (transaksi)</strong>
                    <span class="d-block small text-muted">Saldo berjalan = total masuk − keluar per akun. Saldo awal manual tidak dipakai.</span>
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="keuangan_kas_saldo_mode"
                       id="kas-mode-legacy" value="<?= htmlspecialchars(KEUNGAN_KAS_MODE_LEGACY) ?>"
                       <?= $kasSaldoMode === KEUNGAN_KAS_MODE_LEGACY ? 'checked' : '' ?>>
                <label class="form-check-label" for="kas-mode-legacy">
                    <strong>Ada saldo sebelumnya (legacy)</strong>
                    <span class="d-block small text-muted">Saldo berjalan = saldo awal + transaksi. Cocok bila sudah ada saldo kas/rekening sebelum pencatatan dimulai.</span>
                </label>
            </div>
            <?php if ($tanpaAkun > 0): ?>
                <p class="small text-warning mb-0">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <?= (int) $tanpaAkun ?> transaksi belum punya akun kas/bank —
                    <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-kas.php')) ?>">perbaiki di Perbaikan Kas</a>.
                </p>
            <?php endif; ?>
            <div>
                <button type="submit" class="btn btn-primary btn-sm">Simpan mode saldo</button>
            </div>
        </form>
    </div>
</div>
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
                    <?php if ($kasUsesOpening): ?>
                    <div class="col-md-6">
                        <label class="form-label">Saldo awal</label>
                        <input class="form-control" name="opening_balance" inputmode="numeric"
                               value="<?= htmlspecialchars((string) (int) round((float) ($editAkun['opening_balance'] ?? 0))) ?>">
                        <div class="form-text">Kas/rekening sebelum transaksi pertama tercatat di sistem.</div>
                    </div>
                    <?php else: ?>
                    <div class="col-12">
                        <p class="small text-muted mb-0">
                            Mode <strong>Mulai dari nol</strong>: saldo awal otomatis 0 — saldo berjalan hanya dari pembayaran, pemasukan, dan pengeluaran.
                        </p>
                    </div>
                    <?php endif; ?>
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
                                <th class="text-end">Saldo berjalan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($akunRows === []): ?>
                            <tr><td colspan="4" class="text-muted text-center py-3">Belum ada akun. Tambahkan kas bendahara.</td></tr>
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
                                    <td class="text-end small">
                                        <?php if ($kasUsesOpening): ?>
                                            <?= htmlspecialchars($formatRupiah((int) round((float) ($ar['opening_balance'] ?? 0)))) ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small fw-semibold"><?= htmlspecialchars($formatRupiah((int) ($ar['saldo_berjalan'] ?? 0))) ?></td>
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
<script>
(function () {
    var form = document.getElementById('form-kas-saldo-mode');
    if (!form) return;
    var currentMode = <?= json_encode($kasSaldoMode, JSON_UNESCAPED_UNICODE) ?>;
    var totalOpening = <?= (int) $totalOpening ?>;
    form.addEventListener('submit', function (e) {
        var selected = form.querySelector('input[name="keuangan_kas_saldo_mode"]:checked');
        if (!selected) return;
        if (
            selected.value === <?= json_encode(KEUNGAN_KAS_MODE_TRANSAKSI, JSON_UNESCAPED_UNICODE) ?>
            && currentMode !== <?= json_encode(KEUNGAN_KAS_MODE_TRANSAKSI, JSON_UNESCAPED_UNICODE) ?>
            && totalOpening > 0
        ) {
            var ok = window.confirm(
                'Total saldo awal tercatat ' + totalOpening.toLocaleString('id-ID')
                + '. Beralih ke mode transaksi akan mengosongkan semua saldo awal di database. Lanjutkan?'
            );
            if (!ok) {
                e.preventDefault();
                return;
            }
            document.getElementById('reset-opening-hidden').value = '1';
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($section === 'alokasi'): ?>
<?php
    $alokasiJenisDana = keuangan_pengaturan_alokasi_jenis_dana($alokasiJenisKey);
    $alokasiSectionBagian = 'alokasi';
    $alokasiRowsFiltered = keuangan_alokasi_rows_for_jenis($alokasiRows, $alokasiJenisDana);
    $editAlokasiScoped = keuangan_alokasi_edit_for_jenis($editAlokasi, $alokasiJenisDana);
    require __DIR__ . '/partials/alokasi_pengaturan_section.php';
?>
<?php endif; ?>

<?php if ($section === 'umum' || $section === 'tarif' || ($section === 'santri_bulanan' && $santriBulananSub === 'potongan')): ?>
<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($loadSyahriyahBulanToggle)): ?>
<script src="<?= htmlspecialchars(app_href('/assets/js/syahriyah-bulan-toggle.js')) ?>" defer></script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
