<?php

declare(strict_types=1);

require_once __DIR__ . '/wa_otomatis.php';
require_once __DIR__ . '/presensi_jadwal.php';

/**
 * Tujuan laporan awal: override → petugas pendidikan.
 */
function wa_kegiatan_kosong_target_awal(PDO $pdo): string
{
    $custom = trim((string) app_setting($pdo, 'wa_kelas_kosong_target_1', ''));
    if ($custom !== '') {
        return $custom;
    }

    if (!function_exists('wa_petugas_pendidikan_target')) {
        require_once __DIR__ . '/app.php';
    }

    return wa_petugas_pendidikan_target($pdo);
}

/**
 * Tujuan eskalasi (mis. ke-3): override → nomor pengurus (tab Alpa).
 */
function wa_kegiatan_kosong_target_eskalasi(PDO $pdo): string
{
    $custom = trim((string) app_setting($pdo, 'wa_kelas_kosong_target_3', ''));
    if ($custom !== '') {
        return $custom;
    }

    if (!function_exists('wa_alpa_notif_target')) {
        require_once __DIR__ . '/app.php';
    }

    return wa_alpa_notif_target($pdo);
}

/**
 * @param array<string, mixed> $jadwalRow
 * @return array{kosong:bool,reasons:list<string>}
 */
function wa_kegiatan_kosong_slot_status(PDO $pdo, array $jadwalRow, string $tanggal): array
{
    $kegiatanId = (int) ($jadwalRow['kegiatan_id'] ?? 0);
    $pembimbingId = (int) ($jadwalRow['pembimbing_id'] ?? 0);
    $tingkatan = trim((string) ($jadwalRow['tingkatan'] ?? ''));
    $jamSelesai = trim((string) ($jadwalRow['jam_selesai'] ?? ''));
    if ($kegiatanId <= 0) {
        return ['kosong' => false, 'reasons' => []];
    }
    if ($jamSelesai === '' || !presensi_jam_selesai_lewat($tanggal, $jamSelesai)) {
        return ['kosong' => false, 'reasons' => []];
    }

    $reasons = [];

    $hadirPembimbing = false;
    if (table_exists($pdo, 'presensi_pembimbing')) {
        $sqlPb = '
            SELECT id
            FROM presensi_pembimbing
            WHERE kegiatan_id = :kid
              AND tanggal = :tgl
        ';
        $paramsPb = ['kid' => $kegiatanId, 'tgl' => $tanggal];
        if ($pembimbingId > 0) {
            $sqlPb .= ' AND pembimbing_id = :pid';
            $paramsPb['pid'] = $pembimbingId;
        }
        $sqlPb .= ' LIMIT 1';
        $stPb = $pdo->prepare($sqlPb);
        $stPb->execute($paramsPb);
        $hadirPembimbing = (int) ($stPb->fetchColumn() ?: 0) > 0;
    }

    $hadirMunawib = false;
    if (table_exists($pdo, 'presensi_munawib')) {
        $stMw = $pdo->prepare('
            SELECT id
            FROM presensi_munawib
            WHERE kegiatan_id = :kid
              AND tanggal = :tgl
            LIMIT 1
        ');
        $stMw->execute(['kid' => $kegiatanId, 'tgl' => $tanggal]);
        $hadirMunawib = (int) ($stMw->fetchColumn() ?: 0) > 0;
    }

    if (!$hadirPembimbing && !$hadirMunawib) {
        $reasons[] = 'Pembimbing & munawib belum hadir';
    }

    if ($tingkatan !== '' && table_exists($pdo, 'presensi') && table_exists($pdo, 'santri')) {
        $aktifSql = santri_sql_aktif_only('s');
        $stTotal = $pdo->prepare('SELECT COUNT(*) FROM santri s WHERE ' . $aktifSql . ' AND s.tingkatan = :tk');
        $stTotal->execute(['tk' => $tingkatan]);
        $santriTotal = (int) $stTotal->fetchColumn();

        if ($santriTotal > 0) {
            $stHadir = $pdo->prepare('
                SELECT COUNT(DISTINCT p.santri_id)
                FROM presensi p
                INNER JOIN santri s ON s.id = p.santri_id
                WHERE p.tanggal_presensi = :t
                  AND p.kegiatan_id = :k
                  AND p.status_presensi = "HADIR"
                  AND ' . $aktifSql . '
                  AND s.tingkatan = :tk
            ');
            $stHadir->execute(['t' => $tanggal, 'k' => $kegiatanId, 'tk' => $tingkatan]);
            $santriHadir = (int) $stHadir->fetchColumn();
            if ($santriHadir === 0) {
                $reasons[] = 'Tidak ada santri scan';
            }
        }
    }

    return [
        'kosong' => $reasons !== [],
        'reasons' => $reasons,
    ];
}

/** Kunci slot: satu kegiatan + rentang jam = satu laporan WA (gabung semua tingkatan). */
function wa_kegiatan_kosong_slot_key(int $kegiatanId, string $jamMulai, string $jamSelesai): string
{
    return $kegiatanId . '_' . substr($jamMulai, 0, 5) . '_' . substr($jamSelesai, 0, 5);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array{slot_key:string,kegiatan_id:int,jam_mulai:string,jam_selesai:string,nama_kegiatan:string,tempat:string,empty:list<array{tingkatan:string,reasons:list<string>,nama_pembimbing:string,jadwal_id:int}>}>
 */
function wa_kegiatan_kosong_group_slots(PDO $pdo, array $rows, string $tanggal): array
{
    $groups = [];
    foreach ($rows as $r) {
        $kegiatanId = (int) ($r['kegiatan_id'] ?? 0);
        if ($kegiatanId <= 0) {
            continue;
        }
        $jamMulai = (string) ($r['jam_mulai'] ?? '00:00:00');
        $jamSelesai = (string) ($r['jam_selesai'] ?? '00:00:00');
        $slotKey = wa_kegiatan_kosong_slot_key($kegiatanId, $jamMulai, $jamSelesai);

        if (!isset($groups[$slotKey])) {
            $groups[$slotKey] = [
                'slot_key' => $slotKey,
                'kegiatan_id' => $kegiatanId,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? 'Kegiatan'),
                'tempat' => trim((string) ($r['tempat'] ?? '')),
                'empty' => [],
            ];
        }

        $status = wa_kegiatan_kosong_slot_status($pdo, $r, $tanggal);
        if (!$status['kosong']) {
            continue;
        }

        $tingkatan = trim((string) ($r['tingkatan'] ?? ''));
        $groups[$slotKey]['empty'][] = [
            'tingkatan' => $tingkatan !== '' ? $tingkatan : '-',
            'reasons' => $status['reasons'],
            'nama_pembimbing' => (string) ($r['nama_pembimbing'] ?? '-'),
            'pembimbing_id' => (int) ($r['pembimbing_id'] ?? 0),
            'jadwal_id' => (int) ($r['jadwal_id'] ?? 0),
        ];
        if (trim((string) ($groups[$slotKey]['tempat'] ?? '')) === '') {
            $tempat = trim((string) ($r['tempat'] ?? ''));
            if ($tempat !== '') {
                $groups[$slotKey]['tempat'] = $tempat;
            }
        }
    }

    $out = [];
    foreach ($groups as $group) {
        if (($group['empty'] ?? []) === []) {
            continue;
        }
        $out[] = $group;
    }

    return $out;
}

/**
 * @param array{slot_key:string,kegiatan_id:int,jam_mulai:string,jam_selesai:string,nama_kegiatan:string,tempat:string,empty:list<array{tingkatan:string,reasons:list<string>,nama_pembimbing:string,jadwal_id:int}>} $group
 * @return array<string, string>
 */
function wa_kegiatan_kosong_pengurus_vars(PDO $pdo, array $group, int $counter, int $batasKali, string $levelLabel): array
{
    $jamMulai = substr((string) ($group['jam_mulai'] ?? '00:00:00'), 0, 5);
    $jamSelesai = substr((string) ($group['jam_selesai'] ?? '00:00:00'), 0, 5);
    $empty = $group['empty'] ?? [];
    $tempat = trim((string) ($group['tempat'] ?? ''));
    $tingkatanSatu = (string) ($empty[0]['tingkatan'] ?? '-');
    $namaPb = (string) ($empty[0]['nama_pembimbing'] ?? '-');
    $alasan = implode('; ', (array) ($empty[0]['reasons'] ?? []));
    $jadwalId = (int) ($empty[0]['jadwal_id'] ?? 0);

    if (count($empty) === 1) {
        $barisKelas = 'Kelas/Tingkatan: ' . $tingkatanSatu;
        $detailLines = [
            'Pembimbing jadwal: ' . $namaPb,
            'Alasan: ' . $alasan,
        ];
        if ($jadwalId > 0) {
            $detailLines[] = 'ID Jadwal: #' . $jadwalId;
        }
        $idJadwalTampil = $jadwalId > 0 ? '#' . $jadwalId : '';
    } else {
        $tingkatanList = array_map(static fn (array $e): string => (string) ($e['tingkatan'] ?? '-'), $empty);
        $barisKelas = 'Tingkatan kosong (' . count($empty) . '): ' . implode(', ', $tingkatanList);
        $detailLines = ['Detail per tingkatan:'];
        foreach ($empty as $entry) {
            $detailLines[] = '• ' . (string) ($entry['tingkatan'] ?? '-')
                . ' — ' . (string) ($entry['nama_pembimbing'] ?? '-')
                . ': ' . implode('; ', (array) ($entry['reasons'] ?? []));
        }
        $jadwalIds = array_values(array_filter(array_map(static fn (array $e): int => (int) ($e['jadwal_id'] ?? 0), $empty)));
        $idJadwalTampil = $jadwalIds !== [] ? '#' . implode(', #', $jadwalIds) : '';
        if ($idJadwalTampil !== '') {
            $detailLines[] = 'ID Jadwal: ' . $idJadwalTampil;
        }
        $tingkatanSatu = implode(', ', $tingkatanList);
        $namaPb = '';
        $alasan = '';
    }

    return [
        'counter' => (string) $counter,
        'batas_kali' => (string) $batasKali,
        'tanggal' => date('d/m/Y'),
        'nama_kegiatan' => (string) ($group['nama_kegiatan'] ?? 'Kegiatan'),
        'jam' => $jamMulai . ' - ' . $jamSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'tingkatan' => $tingkatanSatu,
        'tempat' => $tempat,
        'nama_pembimbing' => $namaPb,
        'alasan' => $alasan,
        'id_jadwal' => $idJadwalTampil,
        'nama_ponpes' => function_exists('app_brand_nama_ponpes') ? app_brand_nama_ponpes($pdo) : '',
        'baris_eskalasi' => $levelLabel === 'eskalasi'
            ? 'Eskalasi ke pengurus — batas ' . $batasKali . "x deteksi berturut-turut.\n"
            : '',
        'baris_kelas' => $barisKelas,
        'baris_tempat' => $tempat !== '' ? 'Tempat: ' . $tempat . "\n" : '',
        'detail' => implode("\n", $detailLines),
    ];
}

/**
 * @param array{slot_key:string,kegiatan_id:int,jam_mulai:string,jam_selesai:string,nama_kegiatan:string,tempat:string,empty:list<array{tingkatan:string,reasons:list<string>,nama_pembimbing:string,jadwal_id:int}>} $group
 */
function wa_kegiatan_kosong_format_message(PDO $pdo, array $group, int $counter, int $batasKali, string $levelLabel): string
{
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $msg = wa_template_render($pdo, 'kelas_kosong_pengurus', wa_kegiatan_kosong_pengurus_vars($pdo, $group, $counter, $batasKali, $levelLabel));

    return trim($msg);
}

/**
 * Pesan ringkas per pembimbing untuk slot kelas kosong.
 *
 * @param array{slot_key:string,kegiatan_id:int,jam_mulai:string,jam_selesai:string,nama_kegiatan:string,tempat:string,empty:list<array{tingkatan:string,reasons:list<string>,nama_pembimbing:string,pembimbing_id:int,jadwal_id:int}>} $group
 * @return array<int, string>
 */
function wa_kegiatan_kosong_pembimbing_messages(PDO $pdo, array $group, int $counter, int $batasKali, string $levelLabel): array
{
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $jamMulai = substr((string) ($group['jam_mulai'] ?? '00:00:00'), 0, 5);
    $jamSelesai = substr((string) ($group['jam_selesai'] ?? '00:00:00'), 0, 5);
    $kegiatan = (string) ($group['nama_kegiatan'] ?? 'Kegiatan');
    $namaPonpes = function_exists('app_brand_nama_ponpes') ? app_brand_nama_ponpes($pdo) : '';
    $barisEskalasi = $levelLabel === 'eskalasi'
        ? 'Eskalasi ke pengurus — batas ' . $batasKali . "x deteksi.\n"
        : '';
    $out = [];

    foreach ($group['empty'] ?? [] as $entry) {
        $pbId = (int) ($entry['pembimbing_id'] ?? 0);
        if ($pbId <= 0 || isset($out[$pbId])) {
            continue;
        }

        $msg = wa_template_render($pdo, 'kelas_kosong_pembimbing', [
            'counter' => (string) $counter,
            'batas_kali' => (string) $batasKali,
            'tanggal' => date('d/m/Y'),
            'nama_kegiatan' => $kegiatan,
            'jam' => $jamMulai . ' - ' . $jamSelesai,
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'tingkatan' => (string) ($entry['tingkatan'] ?? '-'),
            'alasan' => implode('; ', (array) ($entry['reasons'] ?? [])),
            'baris_eskalasi' => $barisEskalasi,
            'nama_pembimbing' => (string) ($entry['nama_pembimbing'] ?? '-'),
            'nama_ponpes' => $namaPonpes,
        ]);
        $out[$pbId] = trim($msg);
    }

    return $out;
}

/**
 * Notifikasi kegiatan/kelas kosong bertahap:
 * - deteksi ke-1 → petugas pendidikan (atau override)
 * - deteksi ke-N (default 3) → pengurus (atau override eskalasi)
 *
 * Dievaluasi hanya setelah jam kegiatan selesai, dalam jendela N menit berikutnya
 * (wa_kelas_kosong_batas_menit). Kegiatan dianggap kosong bila tidak ada scan santri
 * dan/atau pembimbing & munawib belum hadir — selaras dengan logika ALPA presensi.
 */
function trigger_wa_kelas_kosong_bertahap(PDO $pdo): void
{
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }
    if (!table_exists($pdo, 'jadwal_kegiatan')
        || !table_exists($pdo, 'kegiatan')
        || !table_exists($pdo, 'pembimbing')) {
        return;
    }

    $enabled = trim((string) app_setting($pdo, 'wa_kelas_kosong_enabled', '1')) === '1';
    if (!$enabled) {
        return;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return;
    }

    $targetAwal = wa_kegiatan_kosong_target_awal($pdo);
    $targetEskalasi = wa_kegiatan_kosong_target_eskalasi($pdo);
    if ($targetAwal === '' && $targetEskalasi === '') {
        return;
    }

    $batasMenit = max(5, (int) app_setting($pdo, 'wa_kelas_kosong_batas_menit', '20'));
    $batasKali = max(2, min(10, (int) app_setting($pdo, 'wa_kelas_kosong_batas_kali', '3')));

    $tanggal = date('Y-m-d');
    $jamSekarang = date('H:i:s');
    $hariKe = (int) date('N', strtotime($tanggal));

    $sql = '
        SELECT
            j.id AS jadwal_id,
            j.kegiatan_id,
            j.pembimbing_id,
            j.jam_mulai,
            j.jam_selesai,
            COALESCE(j.tingkatan, "") AS tingkatan,
            COALESCE(j.tempat, "") AS tempat,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS nama_kegiatan,
            COALESCE(b.nama_pembimbing, "-") AS nama_pembimbing
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        LEFT JOIN pembimbing b ON b.id = j.pembimbing_id
        WHERE k.is_active = 1
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now >= j.jam_selesai
          AND :jam_now <= ADDTIME(j.jam_selesai, SEC_TO_TIME(:batas_sec))
        ORDER BY j.jam_mulai ASC, j.id ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        'hari_ke' => $hariKe,
        'jam_now' => $jamSekarang,
        'batas_sec' => $batasMenit * 60,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return;
    }

    $groups = wa_kegiatan_kosong_group_slots($pdo, $rows, $tanggal);
    foreach ($groups as $group) {
        $slotKey = (string) ($group['slot_key'] ?? '');
        $kegiatanId = (int) ($group['kegiatan_id'] ?? 0);
        if ($slotKey === '' || $kegiatanId <= 0) {
            continue;
        }

        $counterKey = 'wa_kelas_kosong_counter_' . $tanggal . '_' . $slotKey;
        $counter = (int) app_setting($pdo, $counterKey, '0');
        $counter++;
        save_setting($pdo, $counterKey, (string) $counter);

        $sentKeyAwal = 'wa_kelas_kosong_ok_' . $tanggal . '_' . $slotKey . '_1';
        $sentKeyEskalasi = 'wa_kelas_kosong_ok_' . $tanggal . '_' . $slotKey . '_' . $batasKali;

        $levels = [];
        if ($counter >= 1
            && trim((string) app_setting($pdo, $sentKeyAwal, '')) !== '1'
            && $targetAwal !== '') {
            $levels[] = ['level' => 1, 'target' => $targetAwal, 'sent_key' => $sentKeyAwal, 'label' => 'awal'];
        }
        if ($counter >= $batasKali
            && trim((string) app_setting($pdo, $sentKeyEskalasi, '')) !== '1'
            && $targetEskalasi !== '') {
            $levels[] = [
                'level' => $batasKali,
                'target' => $targetEskalasi,
                'sent_key' => $sentKeyEskalasi,
                'label' => 'eskalasi',
            ];
        }
        if ($levels === []) {
            continue;
        }

        $jamMulai = substr((string) ($group['jam_mulai'] ?? '00:00:00'), 0, 5);
        $jamSelesai = substr((string) ($group['jam_selesai'] ?? '00:00:00'), 0, 5);

        foreach ($levels as $lv) {
            $message = wa_kegiatan_kosong_format_message(
                $pdo,
                $group,
                $counter,
                $batasKali,
                (string) ($lv['label'] ?? '')
            );

            $dedupKey = 'kelas_kosong:' . $tanggal . ':kegiatan:' . $kegiatanId . ':slot:' . $jamMulai . '-' . $jamSelesai . ':level:' . (int) ($lv['level'] ?? 0);
            if (!function_exists('presensi_wa_kirim')) {
                require_once __DIR__ . '/wa_presensi.php';
            }
            $bulk = presensi_wa_kirim($pdo, (string) $lv['target'], $message, [
                'kind' => 'presensi',
                'dedup_key' => $dedupKey,
                'dedup_key_once' => true,
            ]);
            if ((int) ($bulk['sent'] ?? 0) > 0 || (int) ($bulk['skipped'] ?? 0) > 0) {
                save_setting($pdo, (string) $lv['sent_key'], '1');
                save_setting($pdo, 'wa_kelas_kosong_last_sent_at', date('Y-m-d H:i:s'));
                save_setting($pdo, 'wa_kelas_kosong_last_level', (string) $lv['level']);

                $pbMessages = wa_kegiatan_kosong_pembimbing_messages($pdo, $group, $counter, $batasKali, (string) ($lv['label'] ?? ''));
                presensi_wa_kirim_ke_pembimbing($pdo, $pbMessages, [
                    'kind' => 'presensi',
                    'dedup_key' => $dedupKey,
                    'context' => 'kelas_kosong',
                ]);
            }
        }
    }
}
