<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'simpan_pengaturan_blokir') {
        save_setting($pdo, 'akademik_blokir_presensi_libur', isset($_POST['blok_presensi']) ? '1' : '0');
        save_setting($pdo, 'akademik_blokir_setoran_libur', isset($_POST['blok_setoran']) ? '1' : '0');
        save_setting($pdo, 'akademik_blokir_penilaian_libur', isset($_POST['blok_penilaian']) ? '1' : '0');
        set_flash('success', 'Pengaturan blokir hari libur disimpan.');
        header('Location: /settings/kalender.php');
        exit;
    }
    if ($action === 'simpan_default_view_kalender') {
        $dv = strtolower(trim((string) ($_POST['akademik_kalender_default_view'] ?? 'bulan')));
        if (!in_array($dv, ['bulan', 'masehi', 'atur', 'tahun'], true)) {
            $dv = 'bulan';
        }
        if ($dv === 'tahun') {
            $dv = 'atur';
        }
        save_setting($pdo, 'akademik_kalender_default_view', $dv);
        set_flash('success', 'Tampilan awal halaman kalender akademik disimpan.');
        header('Location: /settings/kalender.php');
        exit;
    }
}

ensure_akademik_kalender_hari_table($pdo);

$hijriBulanNama = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];

$todayMasehi = date('Y-m-d');
$todayHijri = akademik_hijri_tanggal_penuh($pdo, $todayMasehi);
$hijriAnchor = akademik_hijri_anchor_hari_ini($pdo);
$hyToday = 1446;
$hmToday = 1;
if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $todayHijri, $mh)) {
    $hyToday = (int) $mh[1];
    $hmToday = (int) $mh[2];
}
if ($hyToday < 1300 || $hyToday > 1600) {
    $hyToday = $hijriAnchor['y'];
    $hmToday = $hijriAnchor['m'];
}
$hijriLabelLatin = $todayHijri;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $todayHijri, $mh)) {
    $mo = (int) $mh[2];
    $hijriLabelLatin = (int) $mh[3] . ' ' . ($hijriBulanNama[$mo] ?? ('Bulan ' . $mo)) . ' ' . (int) $mh[1] . ' H.';
}
$masehiLabel = akademik_masehi_label_pendek($todayMasehi);

$masehiTahunBerjalan = (int) date('Y');
[$hijriTahunMinMasehi, $hijriTahunMaxMasehi] = akademik_hijri_tahun_range_untuk_tahun_masehi($pdo, $masehiTahunBerjalan);
$hijriTahunRangeTeks = $hijriTahunMinMasehi > 0 && $hijriTahunMaxMasehi > 0
    ? ($hijriTahunMinMasehi === $hijriTahunMaxMasehi
        ? sprintf('seluruhnya dalam tahun Hijriyah %d H.', $hijriTahunMinMasehi)
        : sprintf('melintasi tahun Hijriyah %d H. dan %d H.', $hijriTahunMinMasehi, $hijriTahunMaxMasehi))
    : '';

$uPusat = '/menu/menu_hub.php?id=menu-grp-pengaturan';
$uPondokMasehi = '/settings/pesantren.php#tahun-masehi-acuan';
$uAkademik = '/akademik/kalender.php';
$uAwalBulan = $uAkademik . '?view=atur&hy=' . $hyToday;
$urlBulanIni = $uAkademik . '?view=bulan&hy=' . $hyToday . '&hm=' . $hmToday;

$blokP = akademik_blokir_presensi_libur($pdo);
$blokS = akademik_blokir_setoran_libur($pdo);
$blokN = akademik_blokir_penilaian_libur($pdo);
$defViewCur = strtolower(trim((string) app_setting($pdo, 'akademik_kalender_default_view', 'bulan')));
$defViewCur = in_array($defViewCur, ['bulan', 'tahun'], true) ? $defViewCur : 'bulan';

$pageTitle = 'Kalender';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/kalender.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-4">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars($uPusat) ?>">Pengaturan</a> · <a href="<?= htmlspecialchars($uAkademik) ?>">Kalender akademik</a></p>
    <h1 class="h4 mb-2">Kalender</h1>
    <p class="text-muted mb-2">Tanggal Masehi mengikuti jam sistem; tanggal Hijriyah memakai <strong>kalender kustom berbasis database</strong> (<a href="/settings/hijri_mappings.php">pemetaan Hijriyah</a>) dengan fallback hisab Intl bila belum diisi.</p>
    <p class="small text-muted mb-0"><span class="badge text-bg-light border me-1">Halaman ini</span> ringkasan, pintasan, dan pengaturan lanjutan (blokir aktivitas saat libur, tampilan awal). <span class="badge text-bg-primary me-1">Kalender akademik</span> untuk mengatur tahun H., tanggal Masehi hari ke-1 tiap bulan H., dan libur rentang.</p>
    <?php if (!class_exists('IntlDateFormatter')) { ?>
        <p class="alert alert-warning small py-2 px-3 mb-0 mt-3"><strong>PHP intl</strong> tidak aktif: hisab Hijriyah otomatis (Um al-Qura) tidak berjalan. Tahun H. di kalender memakai fallback aman (1447 H.) sampai Anda mengaktifkan <code class="user-select-all">extension=intl</code> di php.ini dan merestart Apache.</p>
    <?php } ?>
</div>

<div class="card shadow-sm mb-4 border-primary">
    <div class="card-body">
        <h2 class="h6 text-primary mb-3">1. Hari ini (otomatis)</h2>
        <div class="row g-3">
            <div class="col-md-6">
                <p class="small text-muted mb-1">Masehi</p>
                <p class="mb-0 fs-5 fw-semibold"><?= htmlspecialchars($masehiLabel) ?></p>
                <p class="small font-monospace text-muted mb-0"><?= htmlspecialchars($todayMasehi) ?></p>
            </div>
            <div class="col-md-6">
                <p class="small text-muted mb-1">Hijriyah (yang dipakai aplikasi)</p>
                <p class="mb-0 fs-5 fw-semibold"><?= htmlspecialchars($hijriLabelLatin) ?></p>
                <p class="small font-monospace text-muted mb-0"><?= htmlspecialchars($todayHijri) ?> · <span class="text-body">Tahun H. <?= (int) $hyToday ?>, bulan ke-<?= (int) $hmToday ?></span></p>
            </div>
        </div>
        <?php if ($hijriTahunRangeTeks !== '') { ?>
            <p class="small text-muted border-top pt-3 mb-0">Satu tahun Masehi penuh (1 Jan–31 Des <strong><?= (int) $masehiTahunBerjalan ?></strong>) menurut data aplikasi <?= htmlspecialchars($hijriTahunRangeTeks) ?>. Ini wajar karena tahun Hijriyah lebih pendek dari tahun Masehi.</p>
        <?php } ?>
    </div>
</div>

<div class="card shadow-sm mb-4" id="pengaturan-lanjutan-kalender">
    <div class="card-body">
        <h2 class="h6 mb-3">2. Pengaturan lanjutan kalender akademik</h2>
        <div class="row g-4">
            <div class="col-md-6">
                <h3 class="h6 text-muted mb-2">Tampilan awal halaman kalender</h3>
                <p class="small text-muted mb-2">Saat membuka kalender akademik tanpa memilih tampilan, halaman memakai pilihan ini.</p>
                <form method="post" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="action" value="simpan_default_view_kalender">
                    <select name="akademik_kalender_default_view" class="form-select form-select-sm" style="width:auto;min-width:10rem">
                        <option value="bulan" <?= in_array($defViewCur, ['bulan', ''], true) ? 'selected' : '' ?>>Satu bulan</option>
                        <option value="masehi" <?= $defViewCur === 'masehi' ? 'selected' : '' ?>>12 bulan (Masehi)</option>
                        <option value="atur" <?= in_array($defViewCur, ['atur', 'tahun'], true) ? 'selected' : '' ?>>Atur tahun H.</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                </form>
            </div>
            <div class="col-md-6">
                <h3 class="h6 text-muted mb-2">Blokir aktivitas di hari libur</h3>
                <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="action" value="simpan_pengaturan_blokir">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="blok_presensi" id="bp" <?= $blokP ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="bp">Blokir <strong>presensi</strong> pada tanggal libur</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="blok_setoran" id="bs" <?= $blokS ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="bs">Blokir <strong>setoran hafalan</strong> (kecuali lewati di form)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="blok_penilaian" id="bn" <?= $blokN ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="bn">Blokir <strong>input poin / penilaian</strong> pada tanggal libur</label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Simpan blokir</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h6 mb-2">3. Mengatur apa, di mana?</h2>
        <p class="small text-muted mb-3">Ikuti baris yang sesuai kebutuhan; tautan kanan membuka bagian yang tepat di aplikasi.</p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width:14rem">Yang ingin Anda lakukan</th>
                        <th scope="col">Buka</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="small">Atur <strong>tahun H.</strong>, <strong>tanggal Masehi hari ke-1</strong>, dan <strong>29/30 hari</strong> per bulan (sidang isbat)</td>
                        <td class="small">
                            <a class="btn btn-sm btn-primary mb-1" href="<?= htmlspecialchars($uAwalBulan) ?>">Kalender — pengaturan tahun <?= (int) $hyToday ?> H.</a>
                            <a class="btn btn-sm btn-outline-primary mb-1" href="/settings/hijri_mappings.php">Pemetaan Hijriyah (DB)</a>
                            <a class="btn btn-sm btn-outline-secondary mb-1" href="<?= htmlspecialchars($urlBulanIni) ?>">Grid bulan ini</a>
                        </td>
                    </tr>
                    <tr>
                        <td class="small"><strong>Libur rentang</strong> (cuti, libur nasional menurut Masehi)</td>
                        <td class="small"><a href="<?= htmlspecialchars($uAkademik) ?>#kalender-libur-rentang">Kalender akademik — tambah libur rentang</a></td>
                    </tr>
                    <tr>
                        <td class="small"><strong>Blokir aktivitas</strong> saat tanggal libur &amp; <strong>tampilan awal</strong> kalender</td>
                        <td class="small"><a href="#pengaturan-lanjutan-kalender">Bagian «Pengaturan lanjutan» di halaman ini</a></td>
                    </tr>
                    <tr>
                        <td class="small"><strong>Tahun Masehi default</strong> untuk rekap bila alamat web tanpa tahun</td>
                        <td class="small"><a href="<?= htmlspecialchars($uPondokMasehi) ?>">Pengaturan pondok — tahun Masehi acuan</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="alert alert-light border mb-0">
    <p class="small mb-2"><strong>Alur singkat:</strong> cek hijriyah hari ini di atas → buka <a href="<?= htmlspecialchars($uAwalBulan) ?>">kalender (tampilan tahun)</a> untuk mengisi tanggal Masehi hari ke-1 tiap bulan H. bila perlu → atur libur rentang di kalender akademik.</p>
    <p class="small text-muted mb-0">Butuh bantuan konteks? Kembali ke <a href="<?= htmlspecialchars($uPusat) ?>">pusat pengaturan</a> untuk modul lain.</p>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
