<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_kalender_ui.php';
require_once __DIR__ . '/../helpers/akademik_hari_khusus.php';
require_once __DIR__ . '/../helpers/kalender_agenda.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';
require_once __DIR__ . '/../includes/auth_portal_layout.php';
require_once __DIR__ . '/../includes/partials/kalender_page_hero.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_libur_table($pdo);
ensure_akademik_libur_mingguan_table($pdo);
ensure_akademik_kalender_hari_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
ensure_hijri_mappings_table($pdo);
hijri_sync_from_akademik_awal_bulan($pdo);

$hijriBulanNama = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
$namaBulanMasehi = akademik_kalender_nama_bulan_masehi();
$todayMasehi = date('Y-m-d');

/** @param array<string, scalar|null> $q */
function akad_cal_url(array $q = []): string
{
    $base = '/akademik/kalender.php';
    if ($q === []) {
        return $base;
    }

    return $base . '?' . http_build_query($q);
}

/** @return array<string, scalar> */
function akad_cal_state_from_request(): array
{
    $view = strtolower(trim((string) ($_GET['view'] ?? 'bulan')));
    if ($view === 'tahun') {
        $view = 'atur';
    }
    if (!in_array($view, ['bulan', 'masehi', 'atur'], true)) {
        $view = 'bulan';
    }
    $mode = strtolower(trim((string) ($_GET['mode'] ?? 'hijri')));
    if (!in_array($mode, ['hijri', 'masehi'], true)) {
        $mode = 'hijri';
    }
    $anchor = akademik_hijri_anchor_hari_ini($GLOBALS['pdo']);
    $hy = (int) ($_GET['hy'] ?? $anchor['y']);
    $hm = (int) ($_GET['hm'] ?? $anchor['m']);
    $gy = (int) ($_GET['gy'] ?? (int) date('Y'));
    $gm = (int) ($_GET['gm'] ?? (int) date('n'));
    if ($hm < 1 || $hm > 12) {
        $hm = max(1, min(12, (int) $anchor['m']));
    }
    if ($gm < 1 || $gm > 12) {
        $gm = max(1, min(12, (int) date('n')));
    }
    if (!hijri_tahun_valid($hy)) {
        $hy = (int) $anchor['y'];
    }
    if ($gy < 1970 || $gy > 2100) {
        $gy = (int) date('Y');
    }

    return [
        'view' => $view,
        'mode' => $mode,
        'hy' => $hy,
        'hm' => $hm,
        'gy' => $gy,
        'gm' => $gm,
    ];
}

/** @return array<string, scalar> */
function akad_cal_back_state(): array
{
    $st = akad_cal_state_from_request();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $st;
    }
    foreach (['view', 'mode'] as $k) {
        $pk = 'ret_' . $k;
        if (!isset($_POST[$pk]) || !is_string($_POST[$pk])) {
            continue;
        }
        $v = strtolower(trim($_POST[$pk]));
        if ($k === 'view' && in_array($v, ['bulan', 'masehi', 'atur'], true)) {
            $st['view'] = $v;
        }
        if ($k === 'mode' && in_array($v, ['hijri', 'masehi'], true)) {
            $st['mode'] = $v;
        }
    }
    foreach (['hy', 'hm', 'gy', 'gm'] as $k) {
        $pk = 'ret_' . $k;
        if (isset($_POST[$pk]) && is_numeric($_POST[$pk])) {
            $st[$k] = (int) $_POST[$pk];
        }
    }

    return $st;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $back = akad_cal_back_state();

    if ($action === 'tambah_libur') {
        $d1 = trim((string) ($_POST['tanggal_mulai'] ?? ''));
        $d2 = trim((string) ($_POST['tanggal_selesai'] ?? ''));
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $cat = trim((string) ($_POST['catatan'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2) || $nama === '') {
            set_flash('error', 'Tanggal dan nama libur wajib valid.');
        } else {
            if ($d1 > $d2) {
                [$d1, $d2] = [$d2, $d1];
            }
            $pdo->prepare('
                INSERT INTO akademik_libur (tanggal_mulai, tanggal_selesai, nama, catatan, affects_presensi, affects_setoran, affects_penilaian)
                VALUES (:d1, :d2, :nama, :cat, :p, :s, :n)
            ')->execute([
                'd1' => $d1,
                'd2' => $d2,
                'nama' => mb_substr($nama, 0, 200),
                'cat' => $cat !== '' ? $cat : null,
                'p' => isset($_POST['aff_p']) ? 1 : 0,
                's' => isset($_POST['aff_s']) ? 1 : 0,
                'n' => isset($_POST['aff_n']) ? 1 : 0,
            ]);
            set_flash('success', 'Hari libur ditambahkan.');
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'hapus_libur') {
        $lid = (int) ($_POST['libur_id'] ?? 0);
        if ($lid > 0) {
            $pdo->prepare('DELETE FROM akademik_libur WHERE id = :id')->execute(['id' => $lid]);
            set_flash('success', 'Libur dihapus.');
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'tambah_libur_minggu') {
        $hariKe = (int) ($_POST['hari_ke'] ?? 0);
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($hariKe < 1 || $hariKe > 7 || $nama === '') {
            set_flash('error', 'Hari dan nama libur mingguan wajib diisi.');
        } else {
            $pdo->prepare('
                INSERT INTO akademik_libur_mingguan (hari_ke, nama, catatan, affects_presensi, affects_setoran, affects_penilaian, is_active)
                VALUES (:h, :nama, :cat, :p, :s, :n, 1)
            ')->execute([
                'h' => $hariKe,
                'nama' => mb_substr($nama, 0, 200),
                'cat' => trim((string) ($_POST['catatan'] ?? '')) ?: null,
                'p' => isset($_POST['aff_p']) ? 1 : 0,
                's' => isset($_POST['aff_s']) ? 1 : 0,
                'n' => isset($_POST['aff_n']) ? 1 : 0,
            ]);
            set_flash('success', 'Libur per hari (mingguan) ditambahkan.');
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'hapus_libur_minggu') {
        $lid = (int) ($_POST['libur_minggu_id'] ?? 0);
        if ($lid > 0) {
            $pdo->prepare('DELETE FROM akademik_libur_mingguan WHERE id = :id')->execute(['id' => $lid]);
            set_flash('success', 'Libur mingguan dihapus.');
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'tambah_agenda') {
        $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
        $judul = trim((string) ($_POST['judul'] ?? ''));
        $ulang = max(0, (int) ($_POST['ulang_interval_hari'] ?? 0));
        $ulangHingga = trim((string) ($_POST['ulang_hingga'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || $judul === '') {
            set_flash('error', 'Tanggal dan judul wajib diisi.');
        } elseif ($ulang > 0 && $ulangHingga !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ulangHingga)) {
            set_flash('error', 'Tanggal akhir pengulangan tidak valid.');
        } elseif ($ulang > 0 && $ulangHingga !== '' && $ulangHingga < $tanggal) {
            set_flash('error', 'Tanggal akhir pengulangan harus setelah tanggal mulai.');
        } else {
            $uid = (int) ($_SESSION['user']['id'] ?? 0);
            akademik_agenda_insert_from_post($pdo, $_POST, $uid);
            $msg = $ulang > 0
                ? 'Acara ditambahkan (berulang setiap ' . $ulang . ' hari).'
                : 'Acara ditambahkan.';
            set_flash('success', $msg);
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'hapus_agenda') {
        $aid = (int) ($_POST['agenda_id'] ?? 0);
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        $role = (string) ($_SESSION['user']['role'] ?? '');
        $isSuper = !empty($_SESSION['user']['is_super_admin']);
        if ($aid > 0) {
            if (akademik_agenda_delete($pdo, $aid, $uid, $role, $isSuper)) {
                set_flash('success', 'Acara dihapus.');
            } else {
                set_flash('error', 'Acara tidak ditemukan atau Anda tidak berhak menghapusnya.');
            }
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'tandai_agenda_selesai') {
        $aid = (int) ($_POST['agenda_id'] ?? 0);
        if ($aid > 0) {
            ensure_akademik_agenda_table($pdo);
            $pdo->prepare('UPDATE akademik_agenda SET selesai = 1 WHERE id = :id')->execute(['id' => $aid]);
            set_flash('success', 'Tugas ditandai selesai.');
        }
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
    if ($action === 'simpan_hijri_awal_bulan') {
        $hy = (int) ($_POST['hy'] ?? 0);
        if (!hijri_tahun_valid($hy)) {
            set_flash('error', 'Tahun hijriyah tidak valid.');
            $back['view'] = 'atur';
            header('Location: ' . app_href(akad_cal_url($back)));
            exit;
        }
        ensure_hijri_mappings_table($pdo);
        ensure_akademik_hijri_awal_bulan_table($pdo);
        $insLegacy = $pdo->prepare('
            INSERT INTO akademik_hijri_awal_bulan (tahun_hijriyah, bulan_hijriyah, tanggal_awal_masehi)
            VALUES (:y, :m, :d)
            ON DUPLICATE KEY UPDATE tanggal_awal_masehi = VALUES(tanggal_awal_masehi)
        ');
        $delLegacy = $pdo->prepare('DELETE FROM akademik_hijri_awal_bulan WHERE tahun_hijriyah = :y AND bulan_hijriyah = :m');
        $delMap = $pdo->prepare('DELETE FROM hijri_mappings WHERE tahun_hijriah = :y AND nama_bulan = :n');
        $awal = $_POST['awal'] ?? [];
        $totalHariPost = $_POST['total_hari'] ?? [];
        if (!is_array($awal)) {
            $awal = [];
        }
        if (!is_array($totalHariPost)) {
            $totalHariPost = [];
        }
        [$gLo, $gHi] = akademik_gregorian_bounds_for_hijri_year($pdo, $hy);
        for ($m = 1; $m <= 12; $m++) {
            $namaBulan = hijri_indeks_ke_nama($m);
            $raw = hijri_masehi_awal_dari_post($_POST, $m);
            $th = (int) ($totalHariPost[$m] ?? 30);
            $th = $th === 29 ? 29 : 30;
            if ($raw === '') {
                $delLegacy->execute(['y' => $hy, 'm' => $m]);
                $delMap->execute(['y' => $hy, 'n' => $namaBulan]);
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                set_flash('error', 'Tanggal tidak valid untuk ' . $namaBulan . ' — isi H/B/T (hari/bulan/tahun Masehi) dengan benar.');
                $back['view'] = 'atur';
                $back['hy'] = $hy;
                header('Location: ' . app_href(akad_cal_url($back)));
                exit;
            }
            if ($raw < $gLo || $raw > $gHi) {
                set_flash('error', 'Tanggal Masehi harus dalam rentang ' . $gLo . ' — ' . $gHi . ' (tahun ' . $hy . ' H.).');
                $back['view'] = 'atur';
                $back['hy'] = $hy;
                header('Location: ' . app_href(akad_cal_url($back)));
                exit;
            }
            hijri_simpan_mapping($pdo, $hy, $namaBulan, $raw, $th);
            $insLegacy->execute(['y' => $hy, 'm' => $m, 'd' => $raw]);
        }
        akademik_hijri_awal_bulan_rows($pdo, true);
        hijri_mappings_rows($pdo, true);
        set_flash('success', 'Pemetaan tahun ' . $hy . ' H. disimpan.');
        $back['view'] = 'atur';
        $back['hy'] = $hy;
        header('Location: ' . app_href(akad_cal_url($back)));
        exit;
    }
}

$defViewSetting = strtolower(trim((string) app_setting($pdo, 'akademik_kalender_default_view', 'bulan')));
if ($defViewSetting === 'tahun') {
    $defViewSetting = 'atur';
}
$st = akad_cal_state_from_request();
$view = $st['view'];
$mode = $st['mode'];
$hijriYear = (int) $st['hy'];
$hijriMonth = (int) $st['hm'];
$gregYear = (int) $st['gy'];
$gregMonth = (int) $st['gm'];

if (!isset($_GET['view']) && in_array($defViewSetting, ['bulan', 'masehi', 'atur'], true)) {
    $view = $defViewSetting;
}
if (!isset($_GET['mode']) && pondok_kalender_hijriyah($pdo)) {
    $mode = 'hijri';
}
if (!isset($_GET['view']) && pondok_kalender_hijriyah($pdo) && $view === 'masehi') {
    $view = 'bulan';
}

$hijriAnchorDefault = akademik_hijri_anchor_hari_ini($pdo);

// Data pengaturan tahun H.
$hijriAwalByMonth = [];
$hijriTotalHariByMonth = [];
$stMap = $pdo->prepare('SELECT nama_bulan, tanggal_masehi_awal_bulan, total_hari FROM hijri_mappings WHERE tahun_hijriah = :y');
$stMap->execute(['y' => $hijriYear]);
foreach ($stMap->fetchAll(PDO::FETCH_ASSOC) as $rw) {
    $bm = hijri_nama_ke_indeks((string) ($rw['nama_bulan'] ?? ''));
    if ($bm >= 1 && $bm <= 12) {
        $hijriAwalByMonth[$bm] = (string) ($rw['tanggal_masehi_awal_bulan'] ?? '');
        $hijriTotalHariByMonth[$bm] = (int) ($rw['total_hari'] ?? 30);
    }
}
if ($hijriAwalByMonth === []) {
    $stAwal = $pdo->prepare('SELECT bulan_hijriyah, tanggal_awal_masehi FROM akademik_hijri_awal_bulan WHERE tahun_hijriyah = :y');
    $stAwal->execute(['y' => $hijriYear]);
    foreach ($stAwal->fetchAll(PDO::FETCH_ASSOC) as $rw) {
        $bm = (int) ($rw['bulan_hijriyah'] ?? 0);
        if ($bm >= 1 && $bm <= 12) {
            $hijriAwalByMonth[$bm] = (string) ($rw['tanggal_awal_masehi'] ?? '');
            $hijriTotalHariByMonth[$bm] = 30;
        }
    }
}
[$gYearMin, $gYearMax] = akademik_gregorian_bounds_for_hijri_year($pdo, $hijriYear);
$gYearMasehiAwal = (int) substr($gYearMin, 0, 4);
$gYearMasehiAkhir = (int) substr($gYearMax, 0, 4);
$gYearMasehiLabel = $gYearMasehiAwal === $gYearMasehiAkhir
    ? (string) $gYearMasehiAwal
    : $gYearMasehiAwal . ' — ' . $gYearMasehiAkhir;
[$hyOverlapLo, $hyOverlapHi] = akademik_hijri_tahun_range_untuk_tahun_masehi($pdo, $gregYear);

$liburMingguanRows = akademik_libur_mingguan_rows($pdo, false);
$namaHariMinggu = akademik_nama_hari_minggu();
$tahunSinkronLibur = [$gregYear];
if ($view === 'atur') {
    for ($y = $gYearMasehiAwal; $y <= $gYearMasehiAkhir; $y++) {
        $tahunSinkronLibur[] = $y;
    }
} elseif ($view === 'bulan' && $mode === 'hijri') {
    $tahunSinkronLibur[] = (int) substr($gYearMin, 0, 4);
    $tahunSinkronLibur[] = (int) substr($gYearMax, 0, 4);
}
foreach (array_unique($tahunSinkronLibur) as $ySinkron) {
    akademik_libur_sinkron_hari_khusus_tahun($pdo, (int) $ySinkron, $hijriBulanNama);
}
$liburRows = $pdo->query('SELECT * FROM akademik_libur ORDER BY tanggal_mulai DESC, id DESC LIMIT 80')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$hijriHariIniLabel = akademik_hijri_label_dari_masehi($pdo, $todayMasehi, $hijriBulanNama);
$agendaRows = [];
$agendaByDate = [];
$agendaJsonModal = [];
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
$currentUserRole = (string) ($_SESSION['user']['role'] ?? '');
$currentUserSuper = !empty($_SESSION['user']['is_super_admin']);
$currentUserNama = (string) ($_SESSION['user']['nama'] ?? $_SESSION['user']['username'] ?? 'Anda');
$agendaKlikAktif = in_array($view, ['bulan', 'masehi'], true);

$bulanPaket = null;
$judulBulan = '';
$subjudulBulan = '';
$tahunMasehiPaket = [];
if ($view === 'bulan') {
    if ($mode === 'masehi') {
        $bulanPaket = akademik_kalender_siapkan_bulan_masehi($pdo, $gregYear, $gregMonth, $hijriBulanNama, $todayMasehi);
        $judulBulan = ($namaBulanMasehi[$gregMonth] ?? 'Bulan') . ' ' . $gregYear;
    } else {
        $bulanPaket = akademik_kalender_siapkan_bulan_hijri($pdo, $hijriYear, $hijriMonth, $hijriBulanNama, $todayMasehi);
        $judulBulan = ($hijriBulanNama[$hijriMonth] ?? ('Bulan ' . $hijriMonth)) . ' ' . (int) $hijriYear . ' H.';
    }
    if (is_array($bulanPaket)) {
        $subjudulBulan = akademik_kalender_subjudul_hijri_bulan($pdo, $bulanPaket['gStart'], $bulanPaket['gEnd'], $hijriBulanNama);
        $agendaByDate = akademik_agenda_by_date_map($pdo, (string) $bulanPaket['gStart'], (string) $bulanPaket['gEnd']);
        $bulanPaket['cells'] = akademik_agenda_merge_into_cells($bulanPaket['cells'], $agendaByDate);
        $agendaRows = akademik_agenda_for_range($pdo, (string) $bulanPaket['gStart'], (string) $bulanPaket['gEnd']);
    }
}

if ($view === 'masehi') {
    $rangeAwal = sprintf('%04d-01-01', $gregYear);
    $rangeAkhir = sprintf('%04d-12-31', $gregYear);
    $liburTahun = akademik_kalender_libur_overlap($pdo, $rangeAwal, $rangeAkhir);
    $calMapTahun = akademik_kalender_hari_map_range($pdo, $rangeAwal, $rangeAkhir);
    $liburMingguanAktif = akademik_libur_mingguan_rows($pdo);
    $agendaByDateTahun = akademik_agenda_by_date_map($pdo, $rangeAwal, $rangeAkhir);
    $agendaRows = akademik_agenda_for_range($pdo, $rangeAwal, $rangeAkhir);
    for ($mi = 1; $mi <= 12; $mi++) {
        $paket = akademik_kalender_siapkan_bulan_masehi($pdo, $gregYear, $mi, $hijriBulanNama, $todayMasehi, $liburTahun, $calMapTahun, $liburMingguanAktif);
        $paket['cells'] = akademik_agenda_merge_into_cells($paket['cells'], $agendaByDateTahun);
        $tahunMasehiPaket[$mi] = [
            'paket' => $paket,
            'label' => ($namaBulanMasehi[$mi] ?? ('Bulan ' . $mi)) . ' ' . $gregYear,
            'subtitle' => akademik_kalender_subjudul_hijri_bulan($pdo, $paket['gStart'], $paket['gEnd'], $hijriBulanNama),
        ];
    }
    $agendaByDate = $agendaByDateTahun;
}

if ($agendaKlikAktif) {
    $agendaJsonModal = akademik_agenda_json_for_modal($agendaByDate, $currentUserId, $currentUserRole, $currentUserSuper);
}

$retHidden = $st;
$brandNama = auth_portal_brand_nama($pdo);

$pageTitle = 'Kalender akademik';
$bodyClass = 'akademik-kalender-page';
require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?= htmlspecialchars(app_href('/assets/css/kalender-akademik.css')) ?>" rel="stylesheet">

<?php
render_kalender_page_hero([
    'kicker' => 'Akademik',
    'brand' => $brandNama,
    'title' => 'Kalender & hari libur',
    'description' => 'Jadwal pondok per bulan atau satu tahun penuh — Hijriyah, pasaran, dan libur dalam satu tampilan.',
    'today_masehi' => akademik_masehi_label_pendek($todayMasehi),
    'today_hijri' => $hijriHariIniLabel,
]);
?>

<div class="card akad-cal-nav-card mb-3">
    <div class="card-body py-3">
        <div class="akad-cal-toolbar mb-3">
            <div class="btn-group btn-group-sm" role="group" aria-label="Tampilan">
                <a href="<?= htmlspecialchars(akad_cal_url(['view' => 'bulan', 'mode' => $mode, 'hy' => $hijriYear, 'hm' => $hijriMonth, 'gy' => $gregYear, 'gm' => $gregMonth])) ?>"
                   class="btn btn-outline-primary<?= $view === 'bulan' ? ' active' : '' ?>"><i class="fa-solid fa-calendar-day me-1"></i> Satu bulan</a>
                <a href="<?= htmlspecialchars(akad_cal_url(['view' => 'masehi', 'gy' => $gregYear])) ?>"
                   class="btn btn-outline-primary<?= $view === 'masehi' ? ' active' : '' ?>"><i class="fa-solid fa-table-cells me-1"></i> 12 bulan (Masehi)</a>
                <a href="<?= htmlspecialchars(akad_cal_url(['view' => 'atur', 'hy' => $hijriYear])) ?>"
                   class="btn btn-outline-secondary<?= $view === 'atur' ? ' active' : '' ?>"><i class="fa-solid fa-sliders me-1"></i> Atur tahun H.</a>
            </div>
            <a class="btn btn-outline-secondary btn-sm ms-auto" href="/settings/kalender.php"><i class="fa-solid fa-gear me-1"></i> Pengaturan</a>
        </div>

        <div class="akad-cal-legend">
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--today"></span> Hari ini</span>
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--jumat"></span> Jumat</span>
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--libur"></span> Libur rentang</span>
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--libur-minggu"></span> Libur mingguan</span>
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--hari-islam"></span> Hari besar Islam</span>
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--hari-nasional"></span> Libur nasional</span>
            <?php if ($agendaKlikAktif): ?>
            <span class="akad-cal-legend-item"><span class="akad-cal-legend-swatch akad-cal-legend-swatch--agenda"></span> Acara / jadwal</span>
            <?php endif; ?>
            <span class="akad-cal-legend-note">Warna teks hijriyah = per bulan H. · Pasaran: Legi, Pahing, Pon, Wage, Kliwon.<?= $agendaKlikAktif ? ' · Klik tanggal untuk menambah acara.' : '' ?></span>
        </div>
    </div>
</div>

<?php if ($view === 'bulan'): ?>
    <div class="akad-cal-main-card mb-4">
            <form method="get" class="akad-cal-filter-bar row g-2 align-items-end">
                <input type="hidden" name="view" value="bulan">
                <div class="col-12 col-md-auto">
                    <label class="form-label small mb-1">Kalender menurut</label>
                    <select name="mode" class="form-select form-select-sm" id="calModeSelect">
                        <option value="masehi" <?= $mode === 'masehi' ? 'selected' : '' ?>>Bulan Masehi</option>
                        <option value="hijri" <?= $mode === 'hijri' ? 'selected' : '' ?>>Bulan Hijriyah</option>
                    </select>
                </div>
                <div class="col-6 col-md-auto cal-field cal-field--masehi">
                    <label class="form-label small mb-1">Tahun Masehi</label>
                    <input type="number" name="gy" class="form-control form-control-sm" style="width:6rem" min="1970" max="2100" value="<?= $gregYear ?>">
                </div>
                <div class="col-6 col-md-auto cal-field cal-field--masehi">
                    <label class="form-label small mb-1">Bulan</label>
                    <select name="gm" class="form-select form-select-sm">
                        <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                            <option value="<?= $mi ?>" <?= $gregMonth === $mi ? 'selected' : '' ?>><?= htmlspecialchars($namaBulanMasehi[$mi] ?? ('Bulan ' . $mi)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto cal-field cal-field--hijri d-none">
                    <label class="form-label small mb-1">Tahun H.</label>
                    <input type="number" name="hy" class="form-control form-control-sm" style="width:6rem" min="1300" max="1500" value="<?= $hijriYear ?>">
                </div>
                <div class="col-6 col-md-auto cal-field cal-field--hijri d-none">
                    <label class="form-label small mb-1">Bulan H.</label>
                    <select name="hm" class="form-select form-select-sm">
                        <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                            <option value="<?= $mi ?>" <?= $hijriMonth === $mi ? 'selected' : '' ?>><?= htmlspecialchars($hijriBulanNama[$mi] ?? ('Bulan ' . $mi)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
                    <a class="btn btn-success btn-sm" href="<?= htmlspecialchars(akad_cal_url(['view' => 'bulan', 'mode' => 'masehi', 'gy' => (int) date('Y'), 'gm' => (int) date('n')])) ?>">Hari ini</a>
                </div>
            </form>

            <?php if ($mode === 'hijri' && $gYearMasehiAwal !== $gYearMasehiAkhir): ?>
                <p class="akad-cal-year-note mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Tahun <strong><?= (int) $hijriYear ?> H.</strong> mencakup Masehi <strong><?= htmlspecialchars($gYearMasehiLabel) ?></strong>
                    (<?= htmlspecialchars($gYearMin) ?> — <?= htmlspecialchars($gYearMax) ?>).
                </p>
            <?php endif; ?>

            <div class="akad-cal-month-header">
                <div class="akad-cal-month-header-text">
                    <h2><?= htmlspecialchars($judulBulan !== '' ? $judulBulan : 'Kalender') ?></h2>
                    <?php if ($subjudulBulan !== ''): ?>
                        <p><?= htmlspecialchars($subjudulBulan) ?></p>
                    <?php endif; ?>
                </div>
                <nav class="akad-cal-month-nav" aria-label="Navigasi bulan">
                    <?php if ($mode === 'masehi'):
                        $prevGm = $gregMonth - 1;
                        $prevGy = $gregYear;
                        if ($prevGm < 1) {
                            $prevGm = 12;
                            $prevGy--;
                        }
                        $nextGm = $gregMonth + 1;
                        $nextGy = $gregYear;
                        if ($nextGm > 12) {
                            $nextGm = 1;
                            $nextGy++;
                        }
                        ?>
                        <a class="btn" href="<?= htmlspecialchars(akad_cal_url(['view' => 'bulan', 'mode' => 'masehi', 'gy' => $prevGy, 'gm' => $prevGm])) ?>" title="Bulan sebelumnya" aria-label="Bulan sebelumnya"><i class="fa-solid fa-chevron-left"></i></a>
                        <a class="btn" href="<?= htmlspecialchars(akad_cal_url(['view' => 'bulan', 'mode' => 'masehi', 'gy' => $nextGy, 'gm' => $nextGm])) ?>" title="Bulan berikutnya" aria-label="Bulan berikutnya"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php else:
                        $prevHm = $hijriMonth - 1;
                        $prevHy = $hijriYear;
                        if ($prevHm < 1) {
                            $prevHm = 12;
                            $prevHy--;
                        }
                        $nextHm = $hijriMonth + 1;
                        $nextHy = $hijriYear;
                        if ($nextHm > 12) {
                            $nextHm = 1;
                            $nextHy++;
                        }
                        ?>
                        <a class="btn" href="<?= htmlspecialchars(akad_cal_url(['view' => 'bulan', 'mode' => 'hijri', 'hy' => $prevHy, 'hm' => $prevHm])) ?>" title="Bulan sebelumnya" aria-label="Bulan sebelumnya"><i class="fa-solid fa-chevron-left"></i></a>
                        <a class="btn" href="<?= htmlspecialchars(akad_cal_url(['view' => 'bulan', 'mode' => 'hijri', 'hy' => $nextHy, 'hm' => $nextHm])) ?>" title="Bulan berikutnya" aria-label="Bulan berikutnya"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php endif; ?>
                </nav>
            </div>

            <div class="akad-cal-month-body">
            <?php if (is_array($bulanPaket)): ?>
                <div class="akad-cal-month-wrap">
                    <?php akademik_kalender_render_month($bulanPaket['cells'], false, '', '', true); ?>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    <i class="fa-solid fa-hand-pointer me-1"></i> Klik tanggal untuk menambah atau melihat acara. Arahkan kursor untuk detail libur &amp; hijriyah.
                    Rentang Masehi: <strong><?= htmlspecialchars($bulanPaket['gStart']) ?></strong> — <strong><?= htmlspecialchars($bulanPaket['gEnd']) ?></strong>.
                </p>
            <?php endif; ?>
            </div>
    </div>

<?php elseif ($view === 'masehi'): ?>
    <div class="akad-cal-main-card mb-4">
            <form method="get" class="akad-cal-filter-bar row g-2 align-items-end">
                <input type="hidden" name="view" value="masehi">
                <div class="col-auto">
                    <label class="form-label small mb-1">Tahun Masehi</label>
                    <input type="number" name="gy" class="form-control form-control-sm" style="width:6.5rem" min="1970" max="2100" value="<?= $gregYear ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
                    <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(akad_cal_url(['view' => 'masehi', 'gy' => (int) date('Y')])) ?>">Tahun ini</a>
                </div>
            </form>

            <?php if ($hyOverlapLo > 0 && $hyOverlapHi > 0 && $hyOverlapLo !== $hyOverlapHi): ?>
                <p class="akad-cal-year-note mb-3">
                    Tahun Masehi <strong><?= $gregYear ?></strong> beririsan dengan tahun hijriyah
                    <strong><?= $hyOverlapLo ?> — <?= $hyOverlapHi ?> H.</strong>
                </p>
            <?php elseif ($hyOverlapLo > 0): ?>
                <p class="akad-cal-year-note mb-3">
                    Tahun Masehi <strong><?= $gregYear ?></strong> sebagian besar dalam tahun hijriyah <strong><?= $hyOverlapLo ?> H.</strong>
                </p>
            <?php endif; ?>

            <div class="akad-cal-month-body">
            <div class="akad-cal-year-grid">
                <?php foreach ($tahunMasehiPaket as $mi => $blok): ?>
                    <?php akademik_kalender_render_month(
                        $blok['paket']['cells'],
                        true,
                        (string) ($blok['label'] ?? ''),
                        (string) ($blok['subtitle'] ?? ''),
                        true
                    ); ?>
                <?php endforeach; ?>
            </div>
            </div>
    </div>

<?php else: /* atur tahun H. */ ?>
    <div class="card shadow-sm mb-4 border-0 akad-cal-settings-panel">
        <div class="card-header bg-light py-2">
            <h2 class="h6 mb-0">Atur tahun <?= (int) $hijriYear ?> H.</h2>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="view" value="atur">
                <div class="col-auto">
                    <label class="form-label small mb-1">Tahun hijriyah</label>
                    <input type="number" name="hy" class="form-control form-control-sm" style="width:6rem" min="1300" max="1500" value="<?= $hijriYear ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Buka</button>
                </div>
            </form>

            <p class="small text-muted mb-2">
                <strong>H</strong> = hari · <strong>B</strong> = bulan · <strong>T</strong> = tahun <em>Masehi</em> (tanggal ke-1 bulan hijriyah menurut kalender Masehi).
            </p>
            <p class="akad-cal-year-note mb-3">
                Rentang Masehi untuk <strong><?= (int) $hijriYear ?> H.</strong>:
                <span class="font-monospace"><?= htmlspecialchars($gYearMin) ?></span> —
                <span class="font-monospace"><?= htmlspecialchars($gYearMax) ?></span>
                <?php if ($gYearMasehiAwal !== $gYearMasehiAkhir): ?>
                    <br><span class="text-muted">Satu tahun H. dapat mencakup dua tahun Masehi (<?= htmlspecialchars($gYearMasehiLabel) ?>) — ini normal.</span>
                <?php endif; ?>
            </p>

            <form method="post">
                <input type="hidden" name="action" value="simpan_hijri_awal_bulan">
                <input type="hidden" name="hy" value="<?= (int) $hijriYear ?>">
                <input type="hidden" name="ret_view" value="atur">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Bulan H.</th>
                                <th>Tanggal 1 Masehi <span class="text-muted fw-normal">(H / B / T)</span></th>
                                <th style="width:6rem">Jumlah hari</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php for ($mi = 1; $mi <= 12; $mi++):
                            $ymdAwal = $hijriAwalByMonth[$mi] ?? '';
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($hijriBulanNama[$mi] ?? ('Bulan ' . $mi)) ?></td>
                                <td>
                                    <?php hijri_render_input_hbt('awal', $mi, $ymdAwal); ?>
                                    <?php if ($ymdAwal !== ''): ?>
                                        <div class="small text-muted mt-1 font-monospace"><?= htmlspecialchars($ymdAwal) ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted mt-1">Kosongkan H/B/T = hisab otomatis</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $thCur = (int) ($hijriTotalHariByMonth[$mi] ?? 30); ?>
                                    <select class="form-select form-select-sm" name="total_hari[<?= $mi ?>]">
                                        <option value="30" <?= $thCur !== 29 ? 'selected' : '' ?>>30</option>
                                        <option value="29" <?= $thCur === 29 ? 'selected' : '' ?>>29</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Simpan tahun <?= (int) $hijriYear ?> H.</button>
                <a class="btn btn-outline-secondary btn-sm" href="/settings/hijri_mappings.php">Pemetaan lengkap</a>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4" id="kalender-libur">
    <div class="col-lg-6" id="kalender-libur-minggu">
        <div class="akad-cal-libur-card h-100">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-calendar-week text-success me-1"></i> Libur per hari (mingguan)</h2>
                <p class="small text-muted mb-2">Contoh: setiap <strong>Jumat</strong> libur — berlaku otomatis tiap minggu.</p>
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="action" value="tambah_libur_minggu">
                    <?php foreach ($retHidden as $k => $v): ?>
                        <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="ret_view" value="<?= htmlspecialchars($view) ?>">
                    <div class="col-md-5">
                        <label class="form-label small mb-0">Hari</label>
                        <select name="hari_ke" class="form-select form-select-sm" required>
                            <?php foreach ($namaHariMinggu as $hk => $hn): ?>
                                <option value="<?= $hk ?>" <?= $hk === 5 ? 'selected' : '' ?>><?= htmlspecialchars($hn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label small mb-0">Nama</label>
                        <input type="text" name="nama" class="form-control form-control-sm" required maxlength="200" placeholder="Mis. Libur Jumat">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="aff_p" id="affpm" checked>
                            <label class="form-check-label small" for="affpm">Presensi</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="aff_s" id="affsm" checked>
                            <label class="form-check-label small" for="affsm">Setoran</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="aff_n" id="affnm" checked>
                            <label class="form-check-label small" for="affnm">Penilaian</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm">Tambah</button>
                    </div>
                </form>
                <?php if ($liburMingguanRows === []): ?>
                    <p class="small text-muted mb-0">Belum ada libur mingguan.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($liburMingguanRows as $lm): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><strong><?= htmlspecialchars($namaHariMinggu[(int) ($lm['hari_ke'] ?? 0)] ?? '?') ?></strong> — <?= htmlspecialchars((string) ($lm['nama'] ?? '')) ?></span>
                                <form method="post" class="m-0" onsubmit="return confirm('Hapus?');">
                                    <input type="hidden" name="action" value="hapus_libur_minggu">
                                    <input type="hidden" name="libur_minggu_id" value="<?= (int) ($lm['id'] ?? 0) ?>">
                                    <?php foreach ($retHidden as $k => $v): ?>
                                        <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0">Hapus</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6" id="kalender-libur-rentang">
        <div class="akad-cal-libur-card h-100">
        <div class="card-body">
            <h2 class="h6 mb-2"><i class="fa-solid fa-umbrella-beach text-warning me-1"></i> Libur rentang tanggal</h2>
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="tambah_libur">
                <?php foreach ($retHidden as $k => $v): ?>
                    <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="ret_view" value="<?= htmlspecialchars($view) ?>">
                <div class="col-md-6">
                    <label class="form-label small mb-0">Mulai</label>
                    <input type="date" name="tanggal_mulai" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-0">Selesai</label>
                    <input type="date" name="tanggal_selesai" class="form-control form-control-sm" required>
                </div>
                <div class="col-12">
                    <input type="text" name="nama" class="form-control form-control-sm" required maxlength="200" placeholder="Nama libur">
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="aff_p" id="affp" checked>
                        <label class="form-check-label small" for="affp">Presensi</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="aff_s" id="affs" checked>
                        <label class="form-check-label small" for="affs">Setoran</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="aff_n" id="affn" checked>
                        <label class="form-check-label small" for="affn">Penilaian</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Simpan libur</button>
                </div>
            </form>
        </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-light py-2"><h2 class="h6 mb-0">Daftar libur rentang</h2></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Mulai</th><th>Selesai</th><th>Nama</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$liburRows): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Belum ada libur.</td></tr>
            <?php endif; ?>
            <?php foreach ($liburRows as $L): ?>
                <tr>
                    <td class="small font-monospace"><?= htmlspecialchars((string) $L['tanggal_mulai']) ?></td>
                    <td class="small font-monospace"><?= htmlspecialchars((string) $L['tanggal_selesai']) ?></td>
                    <td class="small"><?= htmlspecialchars((string) $L['nama']) ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus libur ini?');">
                            <input type="hidden" name="action" value="hapus_libur">
                            <input type="hidden" name="libur_id" value="<?= (int) $L['id'] ?>">
                            <input type="hidden" name="ret_view" value="<?= htmlspecialchars($view) ?>">
                            <?php foreach ($retHidden as $k => $v): ?>
                                <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($agendaKlikAktif): ?>
<div class="modal fade" id="modalAgendaKalender" tabindex="-1" aria-labelledby="modalAgendaKalenderLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0" id="modalAgendaKalenderLabel">
                    <i class="fa-solid fa-calendar-plus text-primary me-1"></i>
                    <span id="agendaModalJudulTanggal">Acara</span>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div id="agendaModalDaftar" class="mb-3"></div>
                <hr class="my-2">
                <p class="small text-muted mb-2">Tambah acara baru · pembuat: <strong><?= htmlspecialchars($currentUserNama) ?></strong></p>
                <form method="post" id="formTambahAgendaModal" class="row g-2">
                    <input type="hidden" name="action" value="tambah_agenda">
                    <input type="hidden" name="tanggal" id="agendaModalTanggal" value="<?= htmlspecialchars($todayMasehi) ?>">
                    <?php foreach ($retHidden as $k => $v): ?>
                        <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                    <?php endforeach; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Judul acara <span class="text-danger">*</span></label>
                        <input type="text" name="judul" class="form-control form-control-sm" required maxlength="200" placeholder="Contoh: Rapat koordinasi">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Jenis</label>
                        <select name="jenis" class="form-select form-select-sm">
                            <option value="acara">Acara</option>
                            <option value="tugas">Tugas</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Jam (opsional)</label>
                        <input type="time" name="jam_mulai" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Catatan</label>
                        <textarea name="catatan" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="Detail, lokasi, atau pengingat pribadi"></textarea>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Ulang setiap (hari)</label>
                        <input type="number" name="ulang_interval_hari" class="form-control form-control-sm" min="0" max="365" value="0" placeholder="0 = tidak berulang">
                        <span class="form-text">0 = sekali. Contoh: 7 = mingguan.</span>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Ulang hingga (ops.)</label>
                        <input type="date" name="ulang_hingga" class="form-control form-control-sm" id="agendaModalUlangHingga">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Ingatkan sebelum (menit) — untuk notifikasi nanti</label>
                        <select name="ingat_menit" class="form-select form-select-sm">
                            <option value="0">Tanpa pengingat</option>
                            <option value="15">15 menit</option>
                            <option value="60">1 jam</option>
                            <option value="1440">1 hari</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fa-solid fa-check me-1"></i> Simpan acara
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0 mt-4" id="kalender-agenda">
    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center flex-wrap gap-1">
        <h2 class="h6 mb-0"><i class="fa-solid fa-bell text-primary me-1"></i> Jadwal &amp; acara</h2>
        <span class="small text-muted"><?= $agendaKlikAktif ? 'Klik tanggal di kalender untuk menambah' : 'Tampilan bulan / tahun untuk mengelola acara' ?></span>
    </div>
    <div class="card-body">
        <?php if ($agendaRows === []): ?>
            <p class="small text-muted mb-0">Belum ada acara di rentang yang ditampilkan.<?= $agendaKlikAktif ? ' Klik sebuah tanggal pada kalender di atas.' : '' ?></p>
        <?php else: ?>
            <ul class="list-group list-group-flush small">
                <?php foreach ($agendaRows as $ag):
                    $occDate = (string) ($ag['occurrence_date'] ?? $ag['tanggal'] ?? '');
                    $canDel = akademik_agenda_user_can_manage($ag, $currentUserId, $currentUserRole, $currentUserSuper);
                    $ulangInt = (int) ($ag['ulang_interval_hari'] ?? 0);
                    ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2 px-0<?= !empty($ag['selesai']) ? ' opacity-50' : '' ?>">
                        <span>
                            <span class="badge text-bg-<?= ($ag['jenis'] ?? '') === 'tugas' ? 'warning' : 'info' ?> me-1"><?= ($ag['jenis'] ?? '') === 'tugas' ? 'Tugas' : 'Acara' ?></span>
                            <strong><?= htmlspecialchars((string) $ag['judul']) ?></strong>
                            <span class="text-muted">· <?= htmlspecialchars($occDate) ?></span>
                            <?php if (!empty($ag['is_ulang'])): ?>
                                <span class="badge text-bg-secondary">ulang</span>
                            <?php endif; ?>
                            <?php if ($ulangInt > 0 && empty($ag['is_ulang'])): ?>
                                <span class="badge text-bg-light text-dark border">setiap <?= $ulangInt ?> hari</span>
                            <?php endif; ?>
                            <?php if (!empty($ag['jam_mulai'])): ?>
                                <span class="text-muted">· <?= htmlspecialchars(substr((string) $ag['jam_mulai'], 0, 5)) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ag['catatan'])): ?>
                                <br><span class="text-muted"><?= htmlspecialchars((string) $ag['catatan']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ag['pembuat_nama'])): ?>
                                <br><span class="text-muted"><i class="fa-solid fa-user fa-xs me-1"></i><?= htmlspecialchars((string) $ag['pembuat_nama']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="d-flex gap-1">
                            <?php if (($ag['jenis'] ?? '') === 'tugas' && empty($ag['selesai'])): ?>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="action" value="tandai_agenda_selesai">
                                    <input type="hidden" name="agenda_id" value="<?= (int) $ag['id'] ?>">
                                    <?php foreach ($retHidden as $k => $v): ?>
                                        <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-outline-success btn-sm py-0">Selesai</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDel): ?>
                            <form method="post" class="m-0" onsubmit="return confirm('Hapus acara ini?<?= $ulangInt > 0 ? ' Seluruh pengulangan ikut terhapus.' : '' ?>');">
                                <input type="hidden" name="action" value="hapus_agenda">
                                <input type="hidden" name="agenda_id" value="<?= (int) $ag['id'] ?>">
                                <?php foreach ($retHidden as $k => $v): ?>
                                    <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0">Hapus</button>
                            </form>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('calModeSelect');
    if (sel) {
        function syncFields() {
            var hijri = sel.value === 'hijri';
            document.querySelectorAll('.cal-field--hijri').forEach(function (el) {
                el.classList.toggle('d-none', !hijri);
            });
            document.querySelectorAll('.cal-field--masehi').forEach(function (el) {
                el.classList.toggle('d-none', hijri);
            });
        }
        sel.addEventListener('change', syncFields);
        syncFields();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($agendaKlikAktif): ?>
<script>
(function () {
    var agendaData = <?= json_encode($agendaJsonModal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var agendaRetFields = <?= json_encode(array_map(
        static fn ($k, $v) => ['name' => 'ret_' . $k, 'value' => (string) $v],
        array_keys($retHidden),
        array_values($retHidden)
    ), JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function formatTanggalId(ymd) {
        if (!ymd || ymd.length < 10) return ymd;
        var p = ymd.split('-');
        var bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        return parseInt(p[2], 10) + ' ' + (bulan[parseInt(p[1], 10)] || p[1]) + ' ' + p[0];
    }

    function initAgendaKalenderKlik() {
        var modalEl = document.getElementById('modalAgendaKalender');
        if (!modalEl) return;

        var judulTgl = document.getElementById('agendaModalJudulTanggal');
        var inputTgl = document.getElementById('agendaModalTanggal');
        var daftar = document.getElementById('agendaModalDaftar');
        var ulangHingga = document.getElementById('agendaModalUlangHingga');
        var bsModal = null;

        function getBsModal() {
            if (bsModal) return bsModal;
            if (typeof bootstrap !== 'undefined') {
                bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            }
            return bsModal;
        }

        function renderDaftar(ymd) {
            if (!daftar) return;
            var items = agendaData[ymd] || [];
            if (!items.length) {
                daftar.innerHTML = '<p class="small text-muted mb-0">Belum ada acara di tanggal ini.</p>';
                return;
            }
            var html = '<ul class="list-group list-group-flush small mb-0">';
            items.forEach(function (it) {
                html += '<li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">';
                html += '<span><span class="badge text-bg-' + (it.jenis === 'tugas' ? 'warning' : 'info') + ' me-1">' + (it.jenis === 'tugas' ? 'Tugas' : 'Acara') + '</span>';
                html += '<strong>' + escapeHtml(it.judul) + '</strong>';
                if (it.is_ulang) html += ' <span class="badge text-bg-secondary">ulang</span>';
                if (it.jam_mulai) html += ' <span class="text-muted">· ' + escapeHtml(it.jam_mulai) + '</span>';
                if (it.ulang_interval_hari > 0) html += ' <span class="badge text-bg-light text-dark border">/' + it.ulang_interval_hari + ' hr</span>';
                if (it.catatan) html += '<br><span class="text-muted">' + escapeHtml(it.catatan) + '</span>';
                if (it.pembuat_nama) html += '<br><span class="text-muted"><i class="fa-solid fa-user fa-xs"></i> ' + escapeHtml(it.pembuat_nama) + '</span>';
                html += '</span>';
                if (it.can_delete) {
                    html += '<form method="post" class="m-0 flex-shrink-0" onsubmit="return confirm(\'Hapus acara ini?\');">';
                    html += '<input type="hidden" name="action" value="hapus_agenda">';
                    html += '<input type="hidden" name="agenda_id" value="' + String(it.id) + '">';
                    agendaRetFields.forEach(function (f) {
                        html += '<input type="hidden" name="' + escapeHtml(f.name) + '" value="' + escapeHtml(f.value) + '">';
                    });
                    html += '<button type="submit" class="btn btn-outline-danger btn-sm py-0">Hapus</button></form>';
                }
                html += '</li>';
            });
            html += '</ul>';
            daftar.innerHTML = html;
        }

        function bukaModal(ymd) {
            if (!ymd) return;
            if (inputTgl) inputTgl.value = ymd;
            if (ulangHingga) ulangHingga.min = ymd;
            if (judulTgl) judulTgl.textContent = 'Acara · ' + formatTanggalId(ymd);
            renderDaftar(ymd);
            var m = getBsModal();
            if (m) {
                m.show();
            }
        }

        document.addEventListener('click', function (e) {
            var cell = e.target.closest('.akad-cal-day--pick[data-masehi]');
            if (!cell) return;
            if (e.target.closest('a, button, form')) return;
            e.preventDefault();
            bukaModal(cell.getAttribute('data-masehi'));
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var cell = e.target.closest('.akad-cal-day--pick[data-masehi]');
            if (!cell) return;
            e.preventDefault();
            bukaModal(cell.getAttribute('data-masehi'));
        });
    }

    initAgendaKalenderKlik();
})();
</script>
<?php endif; ?>
