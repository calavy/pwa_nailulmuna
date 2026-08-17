<?php

declare(strict_types=1);

/** Dipanggil dari presensi/scan.php atau presensi_scan_portal_json(). */
    presensi_scan_jadwal_context_invalidate();
    kegiatan_khusus_ensure_schema_deferred($pdo);
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
        require_once __DIR__ . '/santri_kartu_sementara.php';
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
            $resultType = 'warning';
            $resultMessage = 'Peringatan: kode QR tidak terdaftar (santri, pembimbing, atau munawib).';
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
                    $resultMessage = 'Santri tidak aktif atau sedang izin — presensi tidak dicatat. Saat kembali dari izin, scan QR kartu santri di halaman ini.';
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
                $kegiatanKhusus = kegiatan_khusus_find_active_for_santri(
                    $pdo,
                    $tanggal,
                    $jam,
                    (int) ($santri['id'] ?? 0),
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

            $clientUuidPresensi = offline_sync_client_uuid_from_post($_POST);
            if ($clientUuidPresensi !== '') {
                $idemPresensi = offline_sync_idempotent_response($pdo, $clientUuidPresensi, 'presensi_scan');
                if ($idemPresensi !== null && ($idemPresensi['handled'] ?? false)) {
                    $resultType = (string) ($idemPresensi['type'] ?? 'success');
                    $resultMessage = (string) ($idemPresensi['message'] ?? 'OK');
                    goto end_scan_process;
                }
            }

            $existingStmt = $pdo->prepare('
                SELECT id, status_presensi, jam_presensi
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
            $presensiRowId = 0;
            if ($existing) {
                $decision = offline_sync_presensi_existing_decision(
                    is_array($existing) ? $existing : [],
                    $jam,
                    (bool) ($scanClock['from_client'] ?? false)
                );
                if (($decision['action'] ?? '') === 'replace') {
                    $updPres = $pdo->prepare('UPDATE presensi SET jam_presensi = :jam, catatan = :catatan WHERE id = :id');
                    $updPres->execute([
                        'jam' => $jam,
                        'catatan' => $catatan,
                        'id' => (int) $existing['id'],
                    ]);
                    $presensiRowId = (int) $existing['id'];
                    if ($clientUuidPresensi !== '') {
                        offline_sync_log_write(
                            $pdo,
                            $clientUuidPresensi,
                            'presensi_scan',
                            $createdBy,
                            'accepted',
                            $presensiRowId,
                            trim((string) ($_POST['scan_client_at'] ?? '')) ?: null
                        );
                    }
                } else {
                    // type: duplicate agar antrian offline langsung bersih (tanpa retry).
                    $resultType = 'duplicate';
                    $resultMessage = (string) ($decision['message'] ?? ('Duplikat — sudah tercatat di perangkat lain: ' . $santri['nama_santri'] . '.'));
                    if ($clientUuidPresensi !== '') {
                        offline_sync_log_write(
                            $pdo,
                            $clientUuidPresensi,
                            'presensi_scan',
                            $createdBy,
                            'duplicate',
                            (int) ($existing['id'] ?? 0),
                            trim((string) ($_POST['scan_client_at'] ?? '')) ?: null
                        );
                    }
                    goto end_scan_process;
                }
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
                $presensiRowId = (int) $pdo->lastInsertId();
                if ($clientUuidPresensi !== '') {
                    offline_sync_log_write(
                        $pdo,
                        $clientUuidPresensi,
                        'presensi_scan',
                        $createdBy,
                        'accepted',
                        $presensiRowId,
                        trim((string) ($_POST['scan_client_at'] ?? '')) ?: null
                    );
                }
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
            require_once __DIR__ . '/jadwal_jamaah_pembimbing.php';
            $slotsM = jadwal_jamaah_munawib_filter_slots_scan($pdo, (int) ($munawib['id'] ?? 0), $hariKe, $slotsM);
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
end_scan_process:

if ($resultMessage !== null && $resultMessage !== '' && $resultType === 'warning') {
    $pendingForClassify = $_SESSION['munawib_scan_pending'] ?? null;
    if (is_array($pendingForClassify) && !empty($pendingForClassify['slots'])) {
        $resultType = 'info';
    } elseif (preg_match('/sudah tercatat|sudah scan|Scan ditolak|sudah diwakili|pembimbing asli sudah|Kegiatan ini sudah|sudah scan pada jadwal|Duplikat/i', $resultMessage)) {
        $resultType = 'duplicate';
    } else {
        $resultType = 'danger';
    }
}
