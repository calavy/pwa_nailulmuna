<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';
require_once __DIR__ . '/../helpers/pondok_ta.php';
require_once __DIR__ . '/../helpers/kalender_pengaturan.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';
require_once __DIR__ . '/../includes/partials/kalender_page_hero.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_kalender_hari_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan_pengaturan') {
        $res = kalender_pengaturan_simpan($pdo, $_POST);
        $msg = $res['message'];
        if (!empty($res['backfill']) && is_array($res['backfill'])) {
            $bf = $res['backfill'];
            $msg .= sprintf(
                ' Data lama disesuaikan: %d pembayaran, %d presensi, %d jeda potongan.',
                (int) ($bf['pembayaran'] ?? 0),
                (int) ($bf['presensi'] ?? 0),
                (int) ($bf['jeda'] ?? 0)
            );
        }
        set_flash($res['ok'] ? 'success' : 'error', $msg);
        header('Location: ' . app_href('/settings/kalender.php'));
        exit;
    }
    if ($action === 'backfill_kalender_hijriyah') {
        $bf = pondok_backfill_kalender_hijriyah($pdo, !empty($_POST['force']));
        set_flash('success', sprintf(
            'Penyesuaian selesai: %d pembayaran, %d presensi, %d jeda (%d dilewati).',
            (int) $bf['pembayaran'],
            (int) $bf['presensi'],
            (int) $bf['jeda'],
            (int) $bf['skipped']
        ));
        header('Location: ' . app_href('/settings/kalender.php#alat-lanjutan'));
        exit;
    }
}

$v = kalender_pengaturan_load($pdo);
$hari = kalender_pengaturan_ringkas_hari_ini($pdo);
$blokP = akademik_blokir_presensi_libur($pdo);
$blokS = akademik_blokir_setoran_libur($pdo);
$blokN = akademik_blokir_penilaian_libur($pdo);
$taAktifKeu = keuangan_tahun_ajaran_aktif($pdo);
$taOtomatisHari = pondok_tahun_ajaran_from_date($pdo);
$taMismatch = (int) ($taAktifKeu['mulai'] ?? 0) !== (int) ($taOtomatisHari['mulai'] ?? 0)
    || (int) ($taAktifKeu['selesai'] ?? 0) !== (int) ($taOtomatisHari['selesai'] ?? 0);

$pageTitle = 'Pengaturan Kalender';
$bodyClass = 'settings-module-page pondok-kalender-page';
$settingsNavActive = '/settings/kalender.php';
$brandNama = auth_portal_brand_nama($pdo);
require_once __DIR__ . '/../includes/header.php';

$err = get_flash('error');
$ok = get_flash('success');
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/kalender-akademik.css')) ?>" rel="stylesheet">

<?php
render_kalender_page_hero([
    'kicker' => 'Pengaturan',
    'brand' => $brandNama,
    'title' => 'Pengaturan Kalender',
    'description' => 'Kalender Hijriyah/Masehi, tagihan bulanan, rekap tahun, dan aturan hari libur pondok.',
    'today_masehi' => (string) ($hari['masehi_label'] ?? ''),
    'today_hijri' => (string) ($hari['hijri_label'] ?? ''),
    'status_label' => (string) ($hari['status']['label'] ?? ''),
    'status_active' => !empty($hari['status']['aktif']),
]);
?>

<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if ($taMismatch): ?>
<div class="alert alert-warning py-2 small">
    <strong>Tahun ajaran aktif keuangan</strong> (<?= (int) $taAktifKeu['mulai'] ?>/<?= (int) $taAktifKeu['selesai'] ?>)
    berbeda dari TA otomatis hari ini (<?= (int) $taOtomatisHari['mulai'] ?>/<?= (int) $taOtomatisHari['selesai'] ?>).
    Tagihan bulan berjalan bisa tidak selaras — selaraskan di
    <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=umum')) ?>">Keuangan → Umum &amp; periode</a>.
</div>
<?php endif; ?>

<form method="post" class="card shadow-sm mb-3 akad-cal-settings-card">
    <input type="hidden" name="action" value="simpan_pengaturan">
    <div class="card-header akad-cal-card-header fw-semibold">Pengaturan utama</div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-6">
                <h2 class="h6 akad-cal-section-title mb-2">Kalender pondok</h2>
                <p class="small text-muted">Mempengaruhi tagihan syahriyah, laporan keuangan, rekap presensi, dan tampilan bulan di aplikasi.</p>
                <div class="mb-3">
                    <label class="form-label">Jenis kalender</label>
                    <select name="wa_tagihan_calendar" class="form-select" id="sel-kalender-mode">
                        <option value="HIJRIYAH" <?= $v['wa_tagihan_calendar'] === 'HIJRIYAH' ? 'selected' : '' ?>>Hijriyah (Muharram – Dzulhijjah)</option>
                        <option value="MASEHI" <?= $v['wa_tagihan_calendar'] === 'MASEHI' ? 'selected' : '' ?>>Masehi (Januari – Desember)</option>
                    </select>
                </div>
                <h2 class="h6 akad-cal-section-title mb-2 mt-3">Awal tahun ajaran</h2>
                <p class="small text-muted">Bulan pertama slot tagihan (1–12) dalam satu TA. Awal TA tidak harus Muharram/Juli — misalnya mulai Rajab atau Agustus.</p>
                <div class="row g-2 mb-3">
                    <div class="col-md-6" id="wrap-ta-awal-hijri" <?= $v['wa_tagihan_calendar'] === 'MASEHI' ? 'style="display:none"' : '' ?>>
                        <label class="form-label">Bulan awal TA (Hijriyah)</label>
                        <select name="pondok_ta_bulan_awal_hijri" class="form-select">
                            <?php foreach (hijri_nama_bulan_list() as $idx => $nama): ?>
                                <option value="<?= (int) $idx ?>" <?= (int) $v['pondok_ta_bulan_awal_hijri'] === (int) $idx ? 'selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6" id="wrap-ta-awal-masehi" <?= $v['wa_tagihan_calendar'] === 'HIJRIYAH' ? 'style="display:none"' : '' ?>>
                        <label class="form-label">Bulan awal TA (Masehi)</label>
                        <select name="pondok_ta_bulan_awal_masehi" class="form-select">
                            <?php
                            $masehiBulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
                            foreach ($masehiBulan as $idx => $nama):
                                ?>
                                <option value="<?= (int) $idx ?>" <?= (int) $v['pondok_ta_bulan_awal_masehi'] === (int) $idx ? 'selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Tanggal kirim tagihan</label>
                        <input type="number" name="wa_tagihan_day" class="form-control" min="1" max="30" value="<?= htmlspecialchars($v['wa_tagihan_day']) ?>">
                        <div class="form-text">Hari ke-1 s/d 30 tiap bulan.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Jam kirim tagihan (WA)</label>
                        <input type="time" name="wa_tagihan_send_time" class="form-control" value="<?= htmlspecialchars($v['wa_tagihan_send_time']) ?>">
                    </div>
                    <div class="col-12" id="wrap-custom-masehi-dates" <?= $v['wa_tagihan_calendar'] === 'HIJRIYAH' ? 'style="display:none"' : '' ?>>
                        <label class="form-label">Tanggal Masehi custom (opsional, untuk dadakan)</label>
                        <input type="text" name="wa_tagihan_custom_masehi_dates" class="form-control" value="<?= htmlspecialchars((string) ($v['wa_tagihan_custom_masehi_dates'] ?? '')) ?>" placeholder="2026-05-28, 2026-06-02">
                        <div class="form-text">
                            Jika diisi, mode Masehi akan kirim hanya di tanggal ini (format YYYY-MM-DD, pisahkan koma/spasi).
                            Jika kosong, sistem pakai tanggal kirim bulanan di atas.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <h2 class="h6 akad-cal-section-title mb-2">Rekap &amp; laporan</h2>
                <p class="small text-muted">Tahun default saat membuka rekap tanpa memilih tahun di alamat web.</p>
                <div class="mb-3">
                    <label class="form-label">Sumber tahun Masehi</label>
                    <select name="app_tahun_masehi_mode" class="form-select">
                        <option value="BERJALAN" <?= $v['app_tahun_masehi_mode'] === 'BERJALAN' ? 'selected' : '' ?>>Otomatis tahun berjalan</option>
                        <option value="TETAP" <?= $v['app_tahun_masehi_mode'] === 'TETAP' ? 'selected' : '' ?>>Tetap ke tahun tertentu</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tahun Masehi tetap</label>
                    <input type="number" name="app_tahun_masehi_tetap" class="form-control" min="1900" max="2100" value="<?= htmlspecialchars($v['app_tahun_masehi_tetap']) ?>">
                </div>
            </div>

            <div class="col-12"><hr class="my-0"></div>

            <div class="col-lg-6">
                <h2 class="h6 akad-cal-section-title mb-2">Kalender akademik</h2>
                <label class="form-label">Tampilan awal halaman kalender</label>
                <select name="akademik_kalender_default_view" class="form-select mb-3">
                    <option value="bulan" <?= $v['akademik_kalender_default_view'] === 'bulan' ? 'selected' : '' ?>>Satu bulan Hijriyah</option>
                    <option value="masehi" <?= $v['akademik_kalender_default_view'] === 'masehi' ? 'selected' : '' ?>>12 bulan Masehi</option>
                    <option value="atur" <?= $v['akademik_kalender_default_view'] === 'atur' ? 'selected' : '' ?>>Atur tahun H. (awal bulan)</option>
                </select>
            </div>

            <div class="col-lg-6">
                <h2 class="h6 akad-cal-section-title mb-2">Hari libur</h2>
                <p class="small text-muted">Blokir input saat tanggal ditandai libur di kalender akademik.</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="blok_presensi" id="bp" <?= $blokP ? 'checked' : '' ?>>
                    <label class="form-check-label" for="bp">Blokir presensi</label>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="mode-presensi-libur">Mode presensi saat libur</label>
                    <select class="form-select" name="akademik_libur_presensi_mode" id="mode-presensi-libur">
                        <option value="ALL_BLOCKED" <?= ($v['akademik_libur_presensi_mode'] ?? 'TAALIM_ONLY') === 'ALL_BLOCKED' ? 'selected' : '' ?>>Semua jalur presensi libur</option>
                        <option value="TAALIM_ONLY" <?= ($v['akademik_libur_presensi_mode'] ?? 'TAALIM_ONLY') === 'TAALIM_ONLY' ? 'selected' : '' ?>>Ta'lim/Ta'alum libur, Jama'ah aktif</option>
                        <option value="JAMAAH_ONLY" <?= ($v['akademik_libur_presensi_mode'] ?? 'TAALIM_ONLY') === 'JAMAAH_ONLY' ? 'selected' : '' ?>>Jama'ah libur, Ta'lim/Ta'alum aktif</option>
                    </select>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="blok_setoran" id="bs" <?= $blokS ? 'checked' : '' ?>>
                    <label class="form-check-label" for="bs">Blokir setoran hafalan</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="blok_penilaian" id="bn" <?= $blokN ? 'checked' : '' ?>>
                    <label class="form-check-label" for="bn">Blokir input poin / penilaian</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer akad-cal-card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span class="small text-muted">Semua pengaturan di atas disimpan sekaligus.</span>
        <button type="submit" class="btn btn-success"><i class="fa-solid fa-save me-1"></i> Simpan pengaturan</button>
    </div>
</form>

<div class="card shadow-sm mb-3 akad-cal-link-card">
    <div class="card-header akad-cal-card-header fw-semibold small">Kelola kalender &amp; libur</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/akademik/kalender.php')) ?>" class="btn btn-success"><i class="fa-solid fa-calendar-days me-1"></i> Kalender akademik (grid &amp; libur)</a>
        <a href="/settings/hijri_mappings.php" class="btn btn-outline-secondary"><i class="fa-solid fa-moon me-1"></i> Pemetaan bulan Hijriyah</a>
    </div>
    <div class="card-footer small text-muted">
        Atur tanggal awal tiap bulan H., jumlah hari (29/30), dan libur rentang di halaman kalender akademik atau pemetaan DB.
    </div>
</div>

<div class="card shadow-sm mb-3 border-warning border-opacity-50" id="alat-lanjutan">
    <div class="card-header fw-semibold small">Alat lanjutan</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Jika data lama masih memakai bulan Masehi (Januari–Desember), jalankan penyesuaian sekali setelah mengaktifkan kalender Hijriyah.</p>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center" onsubmit="return confirm('Jalankan penyesuaian data ke Hijriyah?');">
            <input type="hidden" name="action" value="backfill_kalender_hijriyah">
            <button type="submit" class="btn btn-warning btn-sm">Sesuaikan data lama ke Hijriyah</button>
            <label class="form-check-label small mb-0">
                <input class="form-check-input" type="checkbox" name="force" value="1"> Paksa ulang semua baris
            </label>
        </form>
    </div>
</div>

<script>
(function () {
    const sel = document.getElementById('sel-kalender-mode');
    const wh = document.getElementById('wrap-ta-awal-hijri');
    const wm = document.getElementById('wrap-ta-awal-masehi');
    const wc = document.getElementById('wrap-custom-masehi-dates');
    if (!sel || !wh || !wm) return;
    function sync() {
        const h = sel.value === 'HIJRIYAH';
        wh.style.display = h ? '' : 'none';
        wm.style.display = h ? 'none' : '';
        if (wc) wc.style.display = h ? 'none' : '';
    }
    sel.addEventListener('change', sync);
})();
</script>

<?php if (!class_exists('IntlDateFormatter')): ?>
    <p class="alert alert-warning small py-2">Aktifkan ekstensi PHP <code>intl</code> agar hisab Hijriyah otomatis (Um al-Qura) berjalan optimal.</p>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
