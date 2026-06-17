<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/presensi_admin.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/presensi_scan_jadwal.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';
require_once __DIR__ . '/../helpers/presensi_notif.php';
require_once __DIR__ . '/../helpers/munawib.php';
require_once __DIR__ . '/../helpers/kegiatan_khusus.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/presensi_scan_client.php';
require_once __DIR__ . '/../helpers/perizinan_aktif.php';

$pbPortalScan = trim((string) ($_GET['portal'] ?? '')) === '1'
    || trim((string) ($_POST['pb_portal_scan'] ?? '')) === '1';

if (!$pbPortalScan) {
    require_roles(['admin', 'pengurus', 'petugas_absensi', 'pembimbing']);
}

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada. Jalankan schema_presensi.sql di phpMyAdmin.');
    header('Location: ' . app_href($pbPortalScan ? '/login.php?peran=pembimbing' : '/dashboard.php'));
    exit;
}

$resultMessage = null;
$resultType = 'success';
$scanRedirect = null;
$izinSelesaiMsgPreset = '';
$today = date('Y-m-d');
$nowTime = date('H:i:s');
$createdBy = $pbPortalScan ? 0 : (int) ($_SESSION['user']['id'] ?? 1);
$scanBackUrl = $pbPortalScan
    ? app_href('/login.php?peran=pembimbing')
    : app_href((string) ($_SESSION['user']['role'] ?? '') === 'petugas_absensi' ? '/logout.php' : '/dashboard.php');
$scanBackLabel = $pbPortalScan ? 'Login pembimbing' : ((string) ($_SESSION['user']['role'] ?? '') === 'petugas_absensi' ? 'Keluar' : 'Dashboard');
$pendingMunawibPick = $_SESSION['munawib_scan_pending'] ?? null;

if (table_exists($pdo, 'pembimbing')) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_pembimbing (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NOT NULL,
            kegiatan_id INT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            jenis_scan ENUM("DATANG","PULANG") NOT NULL DEFAULT "DATANG",
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
        )
    ');
    try { $pdo->exec('ALTER TABLE presensi_pembimbing ADD COLUMN IF NOT EXISTS kegiatan_id INT NULL AFTER pembimbing_id'); } catch (PDOException $e) {}
    ensure_jadwal_kegiatan_tempat($pdo);
}
kegiatan_khusus_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scanClock = presensi_scan_resolve_clock($_POST);
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'munawib_pick_schedule') {
        $pending = $_SESSION['munawib_scan_pending'] ?? null;
        $pickKid = (int) ($_POST['kegiatan_id'] ?? 0);
        $pickMid = (int) ($_POST['munawib_id'] ?? 0);
        if (is_array($pending) && $pickKid > 0 && $pickMid > 0 && (int) ($pending['munawib_id'] ?? 0) === $pickMid) {
            $allowedSlots = is_array($pending['slots'] ?? null) ? $pending['slots'] : [];
            $okSlot = null;
            foreach ($allowedSlots as $slot) {
                if ((int) ($slot['kegiatan_id'] ?? 0) === $pickKid) {
                    $okSlot = $slot;
                    break;
                }
            }
            if ($okSlot !== null) {
                $resPick = munawib_catat_presensi($pdo, $pickMid, $pickKid, $scanClock['tanggal'], $scanClock['jam'], $createdBy);
                $resultType = $resPick['ok'] ? 'success' : 'warning';
                $resultMessage = ($resPick['ok'] ? 'Munawib: ' : '') . $resPick['message'];
                if ($resPick['ok']) {
                    $resultMessage .= ' · ' . (string) ($okSlot['nama_kegiatan'] ?? ('Kegiatan #' . $pickKid));
                }
            } else {
                $resultType = 'warning';
                $resultMessage = 'Jadwal yang dipilih tidak valid. Silakan scan ulang munawib.';
            }
        } else {
            $resultType = 'warning';
            $resultMessage = 'Pilihan jadwal munawib tidak ditemukan. Silakan scan ulang.';
        }
        unset($_SESSION['munawib_scan_pending']);
        goto end_scan_process;
    }

    if (($_POST['scan_source'] ?? '') !== 'camera') {
        $resultType = 'warning';
        $resultMessage = 'Input manual dinonaktifkan. Silakan gunakan scan kamera.';
    } else {
    $code = trim($_POST['kode_qr'] ?? '');
    if ($code !== '') {
        require_once __DIR__ . '/../helpers/santri_kartu_sementara.php';
        $santri = santri_resolve_by_scan_code($pdo, $code);
        if (is_array($santri)) {
            $loadFull = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
            $loadFull->execute(['id' => (int) ($santri['id'] ?? 0)]);
            $santri = $loadFull->fetch() ?: $santri;
        } else {
            $santri = null;
        }

        $pembimbing = null;
        $munawib = null;
        if (!$santri && table_exists($pdo, 'pembimbing')) {
            $findP = $pdo->prepare('SELECT id, nama_pembimbing FROM pembimbing WHERE qr = :code OR nip = :code LIMIT 1');
            $findP->execute(['code' => $code]);
            $pembimbing = $findP->fetch() ?: null;
        }
        if (!$santri && !$pembimbing && function_exists('munawib_find_by_code')) {
            munawib_ensure_schema($pdo);
            $munawib = munawib_find_by_code($pdo, $code);
        }
        if (!$santri && !$pembimbing && !$munawib) {
            $gerbang = perizinan_proses_scan_gerbang($pdo, $code, $createdBy);
            if ($gerbang['handled'] ?? false) {
                if (!empty($gerbang['redirect'])) {
                    $scanRedirect = (string) $gerbang['redirect'];
                    $resultType = ($gerbang['ok'] ?? false) ? 'success' : 'warning';
                    $resultMessage = (string) ($gerbang['message'] ?? 'OK');
                    goto end_scan_process;
                }
                if (!($gerbang['ok'] ?? false)) {
                    $resultType = 'warning';
                    $resultMessage = (string) ($gerbang['message'] ?? 'Scan izin gagal.');
                    goto end_scan_process;
                }
                $gerbangAction = (string) ($gerbang['action'] ?? '');
                if (in_array($gerbangAction, ['checkout', 'rombongan_checkout'], true)) {
                    $resultType = 'success';
                    $resultMessage = (string) ($gerbang['message'] ?? 'Check-out tercatat.');
                    goto end_scan_process;
                }
                if ($gerbangAction === 'checkin' && (int) ($gerbang['santri_id'] ?? 0) > 0) {
                    $loadSantri = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
                    $loadSantri->execute(['id' => (int) $gerbang['santri_id']]);
                    $santri = $loadSantri->fetch() ?: null;
                    $izinSelesaiMsgPreset = (string) ($gerbang['message'] ?? 'Izin selesai.') . ' Santri kembali aktif. ';
                }
            }
        }

        if (!$santri && !$pembimbing && !$munawib) {
            $resultType = 'warning';
            $resultMessage = 'Peringatan: kode QR tidak terdaftar (santri, pembimbing, munawib, atau izin digital).';
        } elseif ($santri) {
            unset($_SESSION['munawib_scan_pending']);
            $izinSelesaiMsg = $izinSelesaiMsgPreset;
            if ($izinSelesaiMsg === '') {
                $izinSelesai = perizinan_selesai_dari_scan_kartu($pdo, (int) $santri['id'], $createdBy);
                if ($izinSelesai !== null && ($izinSelesai['ok'] ?? false)) {
                    $izinSelesaiMsg = (string) ($izinSelesai['message'] ?? 'Izin selesai.') . ' Santri kembali aktif. ';
                }
            } else {
                $izinSelesai = ['ok' => true];
            }
            $chkAktif = $pdo->prepare('SELECT 1 FROM santri s WHERE s.id = :id AND ' . santri_sql_aktif_only('s') . ' LIMIT 1');
            $chkAktif->execute(['id' => (int) $santri['id']]);
            if (!$chkAktif->fetchColumn()) {
                if ($izinSelesai === null || !($izinSelesai['ok'] ?? false)) {
                    $resultType = 'warning';
                    $resultMessage = 'Santri tidak aktif atau sedang izin — presensi tidak dicatat. Scan QR izin atau kartu santri saat kembali.';
                    goto end_scan_process;
                }
            }
            $tanggal = $scanClock['tanggal'];
            ensure_akademik_libur_table($pdo);
            $liburP = akademik_libur_info($pdo, $tanggal, 'presensi');
            $jam = $scanClock['jam'];
            $hijri = akademik_hijri_ym_untuk_masehi($pdo, $tanggal);
            pkpps_ensure_schema($pdo);
            $kegiatan = activity_for_pkpps_santri($pdo, (int) $santri['id'], $tanggal, $jam);
            if (!$kegiatan) {
                $kegiatan = activity_for_tingkatan($pdo, (string) ($santri['tingkatan'] ?? ''), $tanggal, $jam);
            }
            $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
            if ($liburP !== null && akademik_blokir_presensi_libur($pdo)) {
                if ($modeLiburAktif === 'ALL_BLOCKED') {
                    $resultType = 'warning';
                    $resultMessage = 'Hari libur akademik: ' . $liburP['nama'] . ' — semua jalur presensi diliburkan.';
                    goto end_scan_process;
                }
                if ($kegiatan) {
                    $kategori = strtoupper((string) ($kegiatan['kategori_kegiatan'] ?? 'TAALIM'));
                    if (!akademik_libur_presensi_diizinkan($pdo, $kategori)) {
                        $resultType = 'warning';
                        $resultMessage = 'Hari libur akademik: ' . $liburP['nama'] . ' — mode saat libur: ' . akademik_libur_presensi_mode_label($pdo) . '.';
                        goto end_scan_process;
                    }
                }
            }
            $kegiatanKhusus = null;
            if (!$kegiatan) {
                $kegiatanKhusus = kegiatan_khusus_find_active_for_tingkatan(
                    $pdo,
                    $tanggal,
                    $jam,
                    (string) ($santri['tingkatan'] ?? '')
                );
            }
            if (!$kegiatan) {
                if ($kegiatanKhusus === null) {
                    $resultType = 'warning';
                    if ($modeLiburAktif !== null) {
                        $resultMessage = 'Hari libur akademik: ' . ($liburP['nama'] ?? 'Libur') . ' — mode saat libur: ' . akademik_libur_presensi_mode_label($pdo) . '.';
                    } else {
                        $pkppsLabel = pkpps_tingkatan_nama_for_santri($pdo, (int) $santri['id']);
                        if ($pkppsLabel !== '') {
                            $resultMessage = 'Peringatan: scan di luar jadwal PKPPS (' . $pkppsLabel . ') dan jadwal kajian untuk tingkatan ' . ($santri['tingkatan'] ?: '-') . '.';
                        } else {
                            $resultMessage = 'Peringatan: scan di luar jadwal aktif untuk tingkatan ' . ($santri['tingkatan'] ?: '-') . '.';
                        }
                    }
                    goto end_scan_process;
                }
                $cekKhusus = $pdo->prepare('
                    SELECT id
                    FROM presensi_kegiatan_khusus
                    WHERE kegiatan_khusus_id = :kid AND santri_id = :sid AND tanggal = :tgl
                    LIMIT 1
                ');
                $cekKhusus->execute([
                    'kid' => (int) ($kegiatanKhusus['id'] ?? 0),
                    'sid' => (int) ($santri['id'] ?? 0),
                    'tgl' => $tanggal,
                ]);
                if ($cekKhusus->fetch()) {
                    $resultType = 'warning';
                    $resultMessage = 'Presensi kegiatan khusus sudah tercatat untuk ' . $santri['nama_santri'] . '.';
                    goto end_scan_process;
                }
                $insKhusus = $pdo->prepare('
                    INSERT INTO presensi_kegiatan_khusus (kegiatan_khusus_id, santri_id, tanggal, jam, created_by)
                    VALUES (:kid, :sid, :tgl, :jam, :by)
                ');
                $insKhusus->execute([
                    'kid' => (int) ($kegiatanKhusus['id'] ?? 0),
                    'sid' => (int) ($santri['id'] ?? 0),
                    'tgl' => $tanggal,
                    'jam' => $jam,
                    'by' => $createdBy,
                ]);
                $resultType = 'success';
                $resultMessage = 'Santri hadir kegiatan khusus: ' . $santri['nama_santri']
                    . ' · ' . (string) ($kegiatanKhusus['nama_kegiatan'] ?? 'Kegiatan')
                    . ' [' . substr((string) ($kegiatanKhusus['jam_mulai'] ?? ''), 0, 5)
                    . '-' . substr((string) ($kegiatanKhusus['jam_selesai'] ?? ''), 0, 5) . ']';
                goto end_scan_process;
            }
            $kegiatanId = isset($kegiatan['id']) ? (int) $kegiatan['id'] : null;
            $jadwalId = isset($kegiatan['jadwal_kegiatan_id']) ? (int) $kegiatan['jadwal_kegiatan_id'] : 0;
            $pkppsJadwalId = isset($kegiatan['pkpps_jadwal_id']) ? (int) $kegiatan['pkpps_jadwal_id'] : 0;
            ensure_presensi_jadwal_column($pdo);
            $lateThreshold = (int) app_setting($pdo, 'batas_telat_menit', '15');
            $catatan = presensi_scan_catatan_telat(
                isset($kegiatan['jam_mulai']) ? (string) $kegiatan['jam_mulai'] : null,
                $jam,
                $lateThreshold
            );

            $existingStmt = $pdo->prepare('
                SELECT id, status_presensi
                FROM presensi
                WHERE santri_id = :santri_id
                  AND tanggal_presensi = :tanggal_presensi
                  AND (
                        (:kegiatan_id IS NULL AND kegiatan_id IS NULL)
                        OR kegiatan_id = :kegiatan_id
                  )
                ORDER BY id DESC
                LIMIT 1
            ');
            $existingStmt->execute([
                'santri_id' => (int) $santri['id'],
                'tanggal_presensi' => $tanggal,
                'kegiatan_id' => $kegiatanId,
            ]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                $resultType = 'warning';
                $resultMessage = 'Presensi sudah tercatat untuk kegiatan aktif ini: ' . $santri['nama_santri'] . '. Scan ditolak.';
                goto end_scan_process;
            } else {
                $insert = $pdo->prepare('
                    INSERT INTO presensi (santri_id, kegiatan_id, jadwal_kegiatan_id, pkpps_jadwal_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by, catatan)
                    VALUES (:santri_id, :kegiatan_id, :jid, :pjid, :tanggal_presensi, :jam_presensi, :status_presensi, :kalender_hijriyah, :created_by, :catatan)
                ');
                $insert->execute([
                    'santri_id' => (int) $santri['id'],
                    'kegiatan_id' => $kegiatanId,
                    'jid' => $jadwalId > 0 ? $jadwalId : null,
                    'pjid' => $pkppsJadwalId > 0 ? $pkppsJadwalId : null,
                    'tanggal_presensi' => $tanggal,
                    'jam_presensi' => $jam,
                    'status_presensi' => 'HADIR',
                    'kalender_hijriyah' => $hijri,
                    'created_by' => $createdBy,
                    'catatan' => $catatan,
                ]);
            }

            $resultType = 'success';
            $tingkatanTampil = (string) ($santri['tingkatan'] ?: '-');
            if ($pkppsJadwalId > 0 && !empty($kegiatan['pkpps_tingkatan'])) {
                $tingkatanTampil = (string) $kegiatan['pkpps_tingkatan'] . ' (PKPPS)';
            }
            $resultMessage = $izinSelesaiMsg . 'Santri hadir: ' . $santri['nama_santri'] . ' (' . $tingkatanTampil . ').';
            $namaKeg = (string) ($kegiatan['nama_kegiatan'] ?? '');
            $tempatKeg = trim((string) ($kegiatan['tempat'] ?? ''));
            if ($namaKeg !== '') {
                $resultMessage .= ' Kegiatan: ' . $namaKeg;
            }
            if ($tempatKeg !== '') {
                $resultMessage .= ' — Tempat: ' . $tempatKeg;
            }
            try {
                presensi_notif_santri_hadir($pdo, $santri, $kegiatan, $tanggal, $jam, $catatan);
            } catch (Throwable $e) {
                // jangan ganggu alur scan
            }
        } elseif ($munawib) {
            $tanggal = $scanClock['tanggal'];
            $jam = $scanClock['jam'];
            $hariKe = (int) date('N', strtotime($tanggal));
            $liburP = akademik_libur_info($pdo, $tanggal, 'presensi');
            $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
            $kategoriFilterSql = $modeLiburAktif !== null
                ? akademik_libur_presensi_filter_sql_by_mode($modeLiburAktif, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
                : '';
            if ($modeLiburAktif === 'ALL_BLOCKED') {
                $resultType = 'warning';
                $resultMessage = 'Hari libur akademik: ' . $liburP['nama'] . ' — presensi tidak dicatat.';
                goto end_scan_process;
            }
            $jadwalM = $pdo->prepare('
                SELECT j.kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan, j.jam_mulai, j.jam_selesai, j.tingkatan
                FROM jadwal_kegiatan j
                INNER JOIN kegiatan k ON k.id = j.kegiatan_id
                WHERE (j.hari_ke = 0 OR j.hari_ke = :hk)
                  AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
                  AND k.is_active = 1
                  ' . $kategoriFilterSql . '
                ORDER BY j.jam_mulai ASC, k.nama_kegiatan ASC
            ');
            $jadwalM->execute(['hk' => $hariKe, 'jam' => $jam]);
            $slotsM = $jadwalM->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($slotsM === []) {
                $resultType = 'warning';
                $resultMessage = 'Tidak ada kegiatan aktif untuk scan munawib pada jam ini.';
                goto end_scan_process;
            }
            $_SESSION['munawib_scan_pending'] = [
                'munawib_id' => (int) $munawib['id'],
                'munawib_nama' => (string) ($munawib['nama'] ?? ''),
                'slots' => array_map(static function (array $s): array {
                    $mulai = substr((string) ($s['jam_mulai'] ?? ''), 0, 5);
                    $selesai = substr((string) ($s['jam_selesai'] ?? ''), 0, 5);
                    $range = ($mulai !== '' && $selesai !== '') ? ($mulai . '-' . $selesai) : '';
                    $tingkatan = trim((string) ($s['tingkatan'] ?? ''));
                    $label = (string) ($s['nama_kegiatan'] ?? '');
                    if ($range !== '') {
                        $label .= ' [' . $range . ']';
                    }
                    if ($tingkatan !== '') {
                        $label .= ' · ' . $tingkatan;
                    }
                    return [
                        'kegiatan_id' => (int) ($s['kegiatan_id'] ?? 0),
                        'nama_kegiatan' => (string) ($s['nama_kegiatan'] ?? ''),
                        'label' => $label,
                    ];
                }, $slotsM),
                'created_at' => time(),
            ];
            $resultType = 'warning';
            $resultMessage = 'Munawib terdeteksi: ' . (string) ($munawib['nama'] ?? '-') . '. Pilih jadwal yang diwakili.';
        } else {
            unset($_SESSION['munawib_scan_pending']);
            $tanggal = $scanClock['tanggal'];
            $jam = $scanClock['jam'];
            $liburP = akademik_libur_info($pdo, $tanggal, 'presensi');
            $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
            pkpps_ensure_schema($pdo);
            $jadwalAktif = jadwal_aktif_for_pembimbing($pdo, (int) $pembimbing['id'], $tanggal, $jam);
            if (!$jadwalAktif) {
                $resultType = 'warning';
                $resultMessage = 'Tidak ada kegiatan aktif untuk pembimbing "' . $pembimbing['nama_pembimbing'] . '" pada jam sekarang (kajian atau PKPPS).';
                goto end_scan_process;
            }
            if ($liburP !== null && akademik_blokir_presensi_libur($pdo)) {
                if ($modeLiburAktif === 'ALL_BLOCKED') {
                    $resultType = 'warning';
                    $resultMessage = 'Hari libur akademik: ' . $liburP['nama'] . ' — semua jalur presensi diliburkan.';
                    goto end_scan_process;
                }
                $kategori = strtoupper((string) ($jadwalAktif['kategori_kegiatan'] ?? 'TAALIM'));
                if (!akademik_libur_presensi_diizinkan($pdo, $kategori)) {
                    $resultType = 'warning';
                    $resultMessage = 'Hari libur akademik: ' . $liburP['nama'] . ' — mode saat libur: ' . akademik_libur_presensi_mode_label($pdo) . '.';
                    goto end_scan_process;
                }
            }
            $check = $pdo->prepare('
                SELECT id
                FROM presensi_pembimbing
                WHERE pembimbing_id = :id
                  AND kegiatan_id = :kegiatan_id
                  AND tanggal = :tgl
                LIMIT 1
            ');
            $check->execute([
                'id' => (int) $pembimbing['id'],
                'kegiatan_id' => (int) $jadwalAktif['kegiatan_id'],
                'tgl' => $tanggal,
            ]);
            $existsThisKegiatan = $check->fetch();
            if ($existsThisKegiatan) {
                $resultType = 'warning';
                $resultMessage = 'Presensi pembimbing "' . $pembimbing['nama_pembimbing'] . '" sudah tercatat untuk kegiatan "' . (string) $jadwalAktif['nama_kegiatan'] . '".';
                goto end_scan_process;
            }
            $ins = $pdo->prepare('
                INSERT INTO presensi_pembimbing (pembimbing_id, kegiatan_id, tanggal, jam, jenis_scan, created_by)
                VALUES (:id, :kegiatan_id, :tgl, :jam, "DATANG", :by)
            ');
            $ins->execute([
                'id' => (int) $pembimbing['id'],
                'kegiatan_id' => (int) $jadwalAktif['kegiatan_id'],
                'tgl' => $tanggal,
                'jam' => $jam,
                'by' => $createdBy,
            ]);
            $resultType = 'success';
            $sumberPb = (string) ($jadwalAktif['sumber'] ?? '') === 'pkpps' ? ' (PKPPS)' : '';
            $resultMessage = 'Pembimbing hadir: ' . $pembimbing['nama_pembimbing'] . ' — Kegiatan ' . (string) $jadwalAktif['nama_kegiatan'] . $sumberPb;
            $tempat = trim((string) ($jadwalAktif['tempat'] ?? ''));
            if ($tempat !== '') {
                $resultMessage .= ' (Tempat: ' . $tempat . ')';
            }
            try {
                presensi_notif_pembimbing_hadir($pdo, $pembimbing, (string) $jadwalAktif['nama_kegiatan'], $tanggal, $jam);
            } catch (Throwable $e) {
                // jangan ganggu alur scan
            }
        }
    }
    }
}
end_scan_process:

if ($resultMessage !== null && $resultMessage !== '' && $resultType === 'warning') {
    $pendingForClassify = $_SESSION['munawib_scan_pending'] ?? null;
    if (is_array($pendingForClassify) && !empty($pendingForClassify['slots'])) {
        $resultType = 'info';
    } elseif (preg_match('/sudah tercatat|sudah scan|Scan ditolak|sudah diwakili|pembimbing asli sudah|Kegiatan ini sudah|sudah scan pada jadwal/i', $resultMessage)) {
        $resultType = 'duplicate';
    } else {
        $resultType = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json()) {
        $pending = $_SESSION['munawib_scan_pending'] ?? null;
        $extra = [];
        if (is_array($pending)) {
            $extra['munawib_pending'] = true;
            $extra['munawib_id'] = (int) ($pending['munawib_id'] ?? 0);
            $extra['munawib_slots'] = $pending['slots'] ?? [];
            $extra['munawib_nama'] = (string) ($pending['munawib_nama'] ?? '');
        } else {
            $extra['munawib_pending'] = false;
        }
        if ($scanRedirect !== null && $scanRedirect !== '') {
            $extra['redirect'] = $scanRedirect;
        }
        offline_sync_json_response(
            $resultType ?: 'success',
            $resultMessage ?: 'OK',
            $extra
        );
    }
}

$pendingMunawibPick = $_SESSION['munawib_scan_pending'] ?? null;

$todayDate = date('Y-m-d');
$todayRowsStmt = $pdo->prepare('
    SELECT p.jam_presensi AS jam, p.status_presensi AS status, s.nama_santri AS nama, s.tingkatan AS info, "Santri" AS jenis
    FROM presensi p
    INNER JOIN santri s ON s.id = p.santri_id AND ' . santri_sql_aktif_only('s') . '
    WHERE p.tanggal_presensi = :tanggal
    ORDER BY p.id DESC
    LIMIT 20
');
$todayRowsStmt->execute(['tanggal' => $todayDate]);
$todaySantri = $todayRowsStmt->fetchAll();

$todayPembimbing = [];
if (table_exists($pdo, 'presensi_pembimbing')) {
    $todayRowsP = $pdo->prepare('
        SELECT pp.jam AS jam, "HADIR" AS status, b.nama_pembimbing AS nama, COALESCE(k.nama_kegiatan, "-") AS info, "Pembimbing" AS jenis
        FROM presensi_pembimbing pp
        INNER JOIN pembimbing b ON b.id = pp.pembimbing_id
        LEFT JOIN kegiatan k ON k.id = pp.kegiatan_id
        WHERE pp.tanggal = :tanggal
        ORDER BY pp.id DESC
        LIMIT 20
    ');
    $todayRowsP->execute(['tanggal' => $todayDate]);
    $todayPembimbing = $todayRowsP->fetchAll();
}

$todayKhusus = [];
if (table_exists($pdo, 'presensi_kegiatan_khusus')) {
    $todayRowsK = $pdo->prepare('
        SELECT pk.jam AS jam, "HADIR" AS status, s.nama_santri AS nama,
               CONCAT("Kegiatan Khusus: ", COALESCE(k.nama_kegiatan, "-")) AS info, "Khusus" AS jenis
        FROM presensi_kegiatan_khusus pk
        INNER JOIN santri s ON s.id = pk.santri_id
        INNER JOIN kegiatan_khusus k ON k.id = pk.kegiatan_khusus_id
        WHERE pk.tanggal = :tanggal
        ORDER BY pk.id DESC
        LIMIT 20
    ');
    $todayRowsK->execute(['tanggal' => $todayDate]);
    $todayKhusus = $todayRowsK->fetchAll() ?: [];
}

$todayRows = array_merge($todaySantri, $todayPembimbing, $todayKhusus);
usort($todayRows, static function ($a, $b): int {
    return strcmp((string) ($b['jam'] ?? ''), (string) ($a['jam'] ?? ''));
});
$todayRows = array_slice($todayRows, 0, 30);

$pageTitle = $pbPortalScan ? 'Scan Presensi Pembimbing' : 'Scan Presensi';
$bodyClass = 'scan-simple-page' . ($pbPortalScan ? ' scan-portal-pembimbing' : '');
$pageStylesheets = [app_asset_href('/assets/css/presensi-scan.css')];
$isPetugasAbsensi = !$pbPortalScan && (string) ($_SESSION['user']['role'] ?? '') === 'petugas_absensi';
$todayScanCount = count($todayRows);
$scanJadwalCtx = presensi_scan_jadwal_context($pdo);
$timerState = (string) ($scanJadwalCtx['state'] ?? 'none');
$timerClass = in_array($timerState, ['active', 'upcoming', 'ended', 'libur', 'none'], true) ? $timerState : 'none';
$timerSec = $timerState === 'active'
    ? (int) ($scanJadwalCtx['seconds_remaining'] ?? 0)
    : ($timerState === 'upcoming' ? (int) ($scanJadwalCtx['seconds_until_start'] ?? 0) : 0);
$timerClockInit = sprintf('%02d:%02d', (int) floor($timerSec / 60), $timerSec % 60);
require_once __DIR__ . '/../includes/header.php';
$canBersihkanPresensi = !$pbPortalScan && user_can_hapus_presensi_admin();
?>


<div class="presensi-scan-app">
    <header class="presensi-scan-top">
        <div>
            <a href="<?= htmlspecialchars($scanBackUrl) ?>"><i class="fa-solid fa-arrow-left me-1"></i> <?= htmlspecialchars($scanBackLabel) ?></a>
        </div>
        <strong class="small"><?= $pbPortalScan ? 'Presensi · Portal Pembimbing' : 'Scan Presensi' ?></strong>
        <span id="scan-status-badge" class="presensi-scan-status is-waiting">Menyiapkan…</span>
    </header>

    <div id="presensi-scan-banner-host"<?= $resultMessage ? '' : ' hidden' ?>>
    <?php if ($resultMessage): ?>
    <?php
    $bannerIcon = match ($resultType) {
        'success' => 'fa-circle-check',
        'duplicate' => 'fa-ban',
        'danger' => 'fa-circle-xmark',
        'info' => 'fa-circle-info',
        default => 'fa-triangle-exclamation',
    };
    $bannerText = $resultType === 'success'
        ? 'Berhasil'
        : ($resultType === 'duplicate' ? 'Anda sudah scan' : (
            preg_match('/luar jadwal|tidak ada kegiatan/i', (string) $resultMessage) ? 'Di luar jadwal'
            : (preg_match('/hari libur/i', (string) $resultMessage) ? 'Hari libur'
            : (preg_match('/tidak terdaftar/i', (string) $resultMessage) ? 'QR tidak terdaftar'
            : (preg_match('/tidak aktif|sudah keluar/i', (string) $resultMessage) ? 'Santri tidak aktif'
            : ($resultType === 'danger' ? 'Scan ditolak' : $resultMessage))))
        ));
    ?>
    <div class="presensi-scan-banner presensi-scan-banner--<?= htmlspecialchars($resultType) ?>" role="alert" aria-live="assertive">
        <i class="fa-solid <?= $bannerIcon ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars((string) $bannerText) ?></span>
    </div>
    <?php endif; ?>
    </div>

    <?php if (is_array($pendingMunawibPick) && !empty($pendingMunawibPick['slots']) && (int) ($pendingMunawibPick['munawib_id'] ?? 0) > 0): ?>
        <div class="alert alert-warning mx-2 my-2 py-2">
            <div class="small fw-semibold mb-1">
                Munawib: <?= htmlspecialchars((string) ($pendingMunawibPick['munawib_nama'] ?? '-')) ?> — pilih jadwal yang diwakili
            </div>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="hidden" name="action" value="munawib_pick_schedule">
                <?php if ($pbPortalScan): ?>
                    <input type="hidden" name="pb_portal_scan" value="1">
                <?php endif; ?>
                <input type="hidden" name="munawib_id" value="<?= (int) ($pendingMunawibPick['munawib_id'] ?? 0) ?>">
                <select name="kegiatan_id" class="form-select form-select-sm" style="max-width:280px" required>
                    <option value="">Pilih jadwal aktif</option>
                    <?php foreach ((array) $pendingMunawibPick['slots'] as $slot): ?>
                        <option value="<?= (int) ($slot['kegiatan_id'] ?? 0) ?>">
                            <?= htmlspecialchars((string) (($slot['label'] ?? '') !== '' ? $slot['label'] : ($slot['nama_kegiatan'] ?? ('Kegiatan #' . (int) ($slot['kegiatan_id'] ?? 0))))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-warning" type="submit">
                    <i class="fa-solid fa-check me-1"></i> Konfirmasi jadwal
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php
    $activeSlotsTimer = ($timerState === 'active' && is_array($scanJadwalCtx['slots'] ?? null))
        ? $scanJadwalCtx['slots'] : [];
    $activeSlotCount = count($activeSlotsTimer);
    ?>
    <div id="presensi-scan-timer" class="presensi-scan-timer is-<?= htmlspecialchars($timerClass) ?><?= $activeSlotCount > 0 && $timerState === 'active' ? ' has-marquee' : '' ?>" aria-live="polite">
        <div class="presensi-scan-timer-inner">
            <div id="presensi-scan-timer-marquee" class="presensi-scan-timer-marquee<?= ($activeSlotCount > 0 && $timerState === 'active') ? '' : ' d-none' ?>" aria-label="Kegiatan berlangsung">
                <div class="presensi-scan-timer-marquee__viewport">
                    <div id="presensi-scan-timer-marquee-track" class="presensi-scan-timer-marquee__track"></div>
                </div>
            </div>
            <span id="presensi-scan-timer-title" class="presensi-scan-timer-title<?= ($activeSlotCount > 0 && $timerState === 'active') ? ' d-none' : '' ?>"><?php
                if ($timerState === 'active') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['nama_kegiatan'] ?: 'Kegiatan aktif'));
                } elseif ($timerState === 'upcoming') {
                    echo htmlspecialchars((string) ($scanJadwalCtx['nama_kegiatan'] ?: 'Menunggu jadwal'));
                } elseif ($timerState === 'libur') {
                    echo 'Hari libur';
                } elseif ($timerState === 'ended') {
                    echo 'Di luar jadwal';
                } else {
                    echo 'Belum ada jadwal';
                }
            ?></span>
            <span id="presensi-scan-timer-range" class="presensi-scan-timer-range<?= ($activeSlotCount > 0 && $timerState === 'active') ? ' d-none' : '' ?>"><?php
                if (!empty($scanJadwalCtx['jam_mulai']) && !empty($scanJadwalCtx['jam_selesai'])) {
                    echo htmlspecialchars(substr((string) $scanJadwalCtx['jam_mulai'], 0, 5) . ' – ' . substr((string) $scanJadwalCtx['jam_selesai'], 0, 5));
                    if (!empty($scanJadwalCtx['tingkatan'])) {
                        echo ' · ' . htmlspecialchars((string) $scanJadwalCtx['tingkatan']);
                    }
                }
            ?></span>
            <span id="presensi-scan-timer-clock" class="presensi-scan-timer-clock"><?= htmlspecialchars($timerClockInit) ?></span>
            <span id="presensi-scan-timer-hint" class="presensi-scan-timer-hint" aria-live="polite"></span>
        </div>
    </div>
    <script type="application/json" id="presensi-scan-timer-data"><?= json_encode($scanJadwalCtx, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <form method="post" id="form-scan-presensi" class="visually-hidden">
        <input type="text" id="kode_qr" name="kode_qr" required readonly>
        <input type="hidden" name="scan_source" id="scan_source" value="camera">
        <input type="hidden" name="scan_client_at" id="scan_client_at" value="">
        <?php if ($pbPortalScan): ?>
            <input type="hidden" name="pb_portal_scan" value="1">
        <?php endif; ?>
    </form>

    <div class="presensi-scan-viewport">
        <div id="qr-reader" aria-label="Kamera scan QR"></div>
        <div class="presensi-scan-frame" aria-hidden="true">
            <div class="presensi-scan-frame-box"></div>
        </div>
        <div id="camera-error-panel" class="presensi-scan-error d-none" role="alert">
            <div>
                <p class="fw-semibold mb-2" id="camera-error-text">Gagal membuka kamera</p>
                <p class="small opacity-75 mb-3">Izinkan akses kamera saat browser meminta, atau buka pengaturan situs → Kamera → Izinkan.</p>
                <button type="button" class="btn btn-light btn-sm" id="btn-retry-camera">Coba lagi</button>
            </div>
        </div>
    </div>

    <div id="presensi-scan-settings" class="presensi-scan-settings">
        <label class="form-label mb-1" for="camera-select">Pilih kamera</label>
        <select id="camera-select" class="form-select form-select-sm" aria-label="Pilih kamera"></select>
    </div>

    <div class="presensi-scan-controls">
        <button type="button" class="btn-scan-ctl" id="btn-flip-camera" title="Ganti kamera depan/belakang">
            <i class="fa-solid fa-camera-rotate"></i>
            <span>Ganti kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-scan-settings" title="Pilih kamera">
            <i class="fa-solid fa-sliders"></i>
            <span>Kamera</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-torch" title="Lampu (jika didukung)" style="display:none">
            <i class="fa-solid fa-lightbulb"></i>
            <span>Lampu</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-super-focus" title="Optimalkan fokus kamera">
            <i class="fa-solid fa-crosshairs"></i>
            <span>Super Fokus</span>
        </button>
        <button type="button" class="btn-scan-ctl" id="btn-restart-camera" title="Nyalakan ulang kamera">
            <i class="fa-solid fa-rotate-right"></i>
            <span>Ulangi</span>
        </button>
    </div>
</div>

<?php if ($resultMessage): ?>
<div id="presensi-scan-result" class="visually-hidden" data-type="<?= htmlspecialchars($resultType) ?>" data-speak="<?= htmlspecialchars($resultMessage) ?>" aria-hidden="true">
    <span class="presensi-scan-result-text"><?= htmlspecialchars($resultMessage) ?></span>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/app_html5_qrcode_script.php'; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-feedback.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-timer.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/presensi-scan-camera.js')) ?>"></script>
<script>
(function () {
    var form = document.getElementById('form-scan-presensi');
    var input = document.getElementById('kode_qr');
    var submitting = false;

    function stampScanClientTime() {
        var el = document.getElementById('scan_client_at');
        if (el) {
            el.value = new Date().toISOString();
        }
    }

    function submitScan(code) {
        if (submitting) {
            return;
        }
        input.value = code;
        document.getElementById('scan_source').value = 'camera';
        stampScanClientTime();
        if (window.PondokOfflineSync && PondokOfflineSync.handleFormSubmit(form, { label: 'Scan: ' + code })) {
            return;
        }
        submitting = true;
        var fd = new FormData(form);
        var url = form.getAttribute('action') || window.location.href;
        fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-PWA-Offline-Sync': '1' },
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('invalid json');
            });
        }).then(function (data) {
            submitting = false;
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            if (data.munawib_pending) {
                window.location.reload();
                return;
            }
            var type = data.type || (data.ok ? 'success' : 'warning');
            var msg = data.message || '';
            if (window.PresensiScanFeedback) {
                PresensiScanFeedback.show(type, msg);
            }
        }).catch(function () {
            submitting = false;
            form.submit();
        });
    }

    var scanner = new PresensiScanCamera({
        readerId: 'qr-reader',
        statusEl: document.getElementById('scan-status-badge'),
        errorPanel: document.getElementById('camera-error-panel'),
        errorText: document.getElementById('camera-error-text'),
        cameraSelect: document.getElementById('camera-select'),
        settingsPanel: document.getElementById('presensi-scan-settings'),
        btnFlip: document.getElementById('btn-flip-camera'),
        btnRestart: document.getElementById('btn-restart-camera'),
        btnSettings: document.getElementById('btn-scan-settings'),
        btnRetry: document.getElementById('btn-retry-camera'),
        btnTorch: document.getElementById('btn-torch'),
        btnSuperFocus: document.getElementById('btn-super-focus'),
        onSubmit: submitScan,
    });

    scanner.init().then(function () {
        var torchBtn = document.getElementById('btn-torch');
        if (!torchBtn) return;
        setTimeout(function () {
            try {
                var video = document.querySelector('#qr-reader video');
                if (!video || !video.srcObject) return;
                var track = video.srcObject.getVideoTracks()[0];
                var caps = track && track.getCapabilities ? track.getCapabilities() : {};
                if (caps.torch) {
                    torchBtn.style.display = '';
                }
            } catch (e) { /* abaikan */ }
        }, 800);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
