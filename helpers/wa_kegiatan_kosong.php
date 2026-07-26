<?php

declare(strict_types=1);

require_once __DIR__ . '/wa_otomatis.php';

/**
 * Tujuan laporan awal: override → petugas pendidikan.
 */
function wa_kegiatan_kosong_target_awal(PDO $pdo): string
{
    $custom = trim((string) app_setting($pdo, 'wa_kelas_kosong_target_1', ''));
    if ($custom !== '') {
        return $custom;
    }

    return trim((string) app_setting($pdo, 'wa_petugas_pendidikan', ''));
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

    return trim((string) app_setting($pdo, 'wa_pengurus', ''));
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
    if ($kegiatanId <= 0) {
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

/**
 * Notifikasi kegiatan/kelas kosong bertahap:
 * - deteksi ke-1 → petugas pendidikan (atau override)
 * - deteksi ke-N (default 3) → pengurus (atau override eskalasi)
 *
 * Kegiatan dianggap kosong bila tidak ada scan santri dan/atau pembimbing & munawib belum hadir.
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
          AND :jam_now BETWEEN ADDTIME(j.jam_mulai, SEC_TO_TIME(:batas_sec)) AND j.jam_selesai
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

    foreach ($rows as $r) {
        $jadwalId = (int) ($r['jadwal_id'] ?? 0);
        if ($jadwalId <= 0) {
            continue;
        }

        $status = wa_kegiatan_kosong_slot_status($pdo, $r, $tanggal);
        if (!$status['kosong']) {
            continue;
        }

        $counterKey = 'wa_kelas_kosong_counter_' . $tanggal . '_' . $jadwalId;
        $counter = (int) app_setting($pdo, $counterKey, '0');
        $counter++;
        save_setting($pdo, $counterKey, (string) $counter);

        $sentKeyAwal = 'wa_kelas_kosong_ok_' . $tanggal . '_' . $jadwalId . '_1';
        $sentKeyEskalasi = 'wa_kelas_kosong_ok_' . $tanggal . '_' . $jadwalId . '_' . $batasKali;

        $jamMulai = substr((string) ($r['jam_mulai'] ?? '00:00:00'), 0, 5);
        $jamSelesai = substr((string) ($r['jam_selesai'] ?? '00:00:00'), 0, 5);
        $tingkatan = trim((string) ($r['tingkatan'] ?? ''));
        $tempat = trim((string) ($r['tempat'] ?? ''));

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

        foreach ($levels as $lv) {
            $lines = [];
            $lines[] = '⚠️ Laporan kegiatan kosong (deteksi ke-' . $counter . ')';
            if (($lv['label'] ?? '') === 'eskalasi') {
                $lines[] = 'Eskalasi ke pengurus — batas ' . $batasKali . 'x deteksi berturut-turut.';
            }
            $lines[] = 'Tanggal: ' . date('d/m/Y');
            $lines[] = 'Kegiatan: ' . (string) ($r['nama_kegiatan'] ?? 'Kegiatan');
            $lines[] = 'Jam: ' . $jamMulai . ' - ' . $jamSelesai;
            $lines[] = 'Kelas/Tingkatan: ' . ($tingkatan !== '' ? $tingkatan : '-');
            if ($tempat !== '') {
                $lines[] = 'Tempat: ' . $tempat;
            }
            $lines[] = 'Pembimbing jadwal: ' . (string) ($r['nama_pembimbing'] ?? '-');
            $lines[] = 'Alasan: ' . implode('; ', $status['reasons']);
            $lines[] = 'ID Jadwal: #' . $jadwalId;
            $message = implode("\n", $lines);

            $bulk = send_wa_bulk_with_result($pdo, (string) $lv['target'], $message, ['kind' => 'presensi']);
            if ((int) ($bulk['sent'] ?? 0) > 0) {
                save_setting($pdo, (string) $lv['sent_key'], '1');
                save_setting($pdo, 'wa_kelas_kosong_last_sent_at', date('Y-m-d H:i:s'));
                save_setting($pdo, 'wa_kelas_kosong_last_level', (string) $lv['level']);
            }
        }
    }
}
