<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/munawib_portal.php';
require_once __DIR__ . '/../../helpers/hijri_kalender.php';
require_once __DIR__ . '/../../helpers/akademik_pasaran.php';

/** @var PDO $pdo */

akademik_setoran_require_access();
munawib_portal_require_konteks();
ensure_akademik_setoran_penerima_schema($pdo);

$portalAccess = ['ok' => true, 'reason' => '', 'peran' => '', 'ref_id' => 0];
$setoranPortalWarning = '';
$rolePortal = strtolower((string) ($_SESSION['user']['role'] ?? ''));
if (!is_super_admin() && !in_array($rolePortal, ['admin', 'pengurus', 'petugas_absensi'], true)) {
    $portalAccess = akademik_setoran_portal_access_status($pdo);
    if (!$portalAccess['ok']) {
        set_flash('error', akademik_setoran_portal_denial_message($portalAccess));
        app_redirect('login.php?dest=setoran');
    }
    $setoranPortalWarning = akademik_setoran_portal_setup_warning($pdo, $portalAccess);
    if (($portalAccess['peran'] ?? '') === 'pembimbing' && (int) ($portalAccess['ref_id'] ?? 0) > 0) {
        akademik_setoran_session_set_pembimbing_id((int) $portalAccess['ref_id']);
    }
}

$ctx = akademik_setoran_petugas_context($pdo);
$today = date('Y-m-d');
$isMunawib = munawib_is_portal_session();

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus', 'petugas_absensi'], true);

$labelUser = trim((string) ($_SESSION['user']['nama'] ?? 'Petugas'));
if ($isMunawib) {
    $labelUser = trim((string) ($_SESSION['user']['nama'] ?? 'Munawib'));
} elseif ((int) ($ctx['pembimbing_id'] ?? 0) > 0 && table_exists($pdo, 'pembimbing')) {
    $stN = $pdo->prepare('SELECT nama_pembimbing FROM pembimbing WHERE id = :id LIMIT 1');
    $stN->execute(['id' => (int) $ctx['pembimbing_id']]);
    $namaPb = trim((string) ($stN->fetchColumn() ?: ''));
    if ($namaPb !== '') {
        $labelUser = $namaPb;
    }
}

$tingkatanList = $ctx['tingkatan_allowed'] ?? [];
$santriRows = akademik_setoran_santri_list_for_ctx($pdo, $ctx, $today);
$santriByTingkatan = [];
foreach ($santriRows as $sr) {
    $tk = (string) ($sr['tingkatan'] ?? '—');
    if (!isset($santriByTingkatan[$tk])) {
        $santriByTingkatan[$tk] = [];
    }
    $santriByTingkatan[$tk][] = $sr;
}

$jumlahSantri = count($santriRows);
$jumlahTingkatan = count($tingkatanList);
$setoranHariIni = ['setor' => 0, 'belum' => 0, 'izin' => 0, 'libur' => 0];
foreach ($santriRows as $sr) {
    $st = (string) ($sr['status_hari_ini'] ?? 'BELUM');
    if ($st === 'SETOR') {
        $setoranHariIni['setor']++;
    } elseif ($st === 'IZIN') {
        $setoranHariIni['izin']++;
    } elseif ($st === 'LIBUR') {
        $setoranHariIni['libur']++;
    } else {
        $setoranHariIni['belum']++;
    }
}

$pbDashHijriLabel = '';
$pbDashPasaran = '';
ensure_hijri_mappings_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
$hijriBulanNama = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
$pbDashHijriLabel = akademik_hijri_label_dari_masehi($pdo, $today, $hijriBulanNama);
$pbDashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';
