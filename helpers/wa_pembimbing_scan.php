<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_otomatis.php';
require_once __DIR__ . '/wa_templates.php';

/** Pastikan kolom pembimbing.wa_scan_reminder ada. */
function pembimbing_ensure_wa_scan_reminder_column(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'pembimbing')) {
        return;
    }
    $done = true;
    if (!column_exists($pdo, 'pembimbing', 'wa_scan_reminder')) {
        try {
            $pdo->exec('ALTER TABLE pembimbing ADD COLUMN wa_scan_reminder TINYINT(1) NOT NULL DEFAULT 1');
        } catch (PDOException $e) {
            /* abaikan jika sudah ada */
        }
    }
}

/**
 * Kirim WA ke pembimbing/munawib yang belum scan ~10 menit sebelum kegiatan selesai.
 */
function trigger_wa_pembimbing_belum_scan(PDO $pdo): void
{
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }
    if (trim((string) app_setting($pdo, 'wa_pembimbing_scan_enabled', '1')) !== '1') {
        return;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return;
    }
    if (!table_exists($pdo, 'jadwal_kegiatan')
        || !table_exists($pdo, 'kegiatan')
        || !table_exists($pdo, 'pembimbing')
        || !table_exists($pdo, 'presensi_pembimbing')) {
        return;
    }

    pembimbing_ensure_wa_scan_reminder_column($pdo);

    $menitSebelum = max(5, min(30, (int) app_setting($pdo, 'wa_pembimbing_scan_menit_sebelum', '10')));
    $tanggal = date('Y-m-d');
    $jamSekarang = date('H:i:s');
    $hariKe = (int) date('N', strtotime($tanggal));

    $sql = '
        SELECT
            j.id AS jadwal_id,
            j.kegiatan_id,
            j.pembimbing_id,
            j.jam_selesai,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS nama_kegiatan,
            b.nama_pembimbing,
            b.no_wa,
            COALESCE(b.wa_scan_reminder, 1) AS wa_scan_reminder
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        INNER JOIN pembimbing b ON b.id = j.pembimbing_id
        WHERE j.pembimbing_id IS NOT NULL
          AND j.pembimbing_id > 0
          AND COALESCE(b.is_aktif, 1) = 1
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN ADDTIME(j.jam_selesai, SEC_TO_TIME(-:menit * 60)) AND j.jam_selesai
        ORDER BY j.jam_selesai ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        'hari_ke' => $hariKe,
        'jam_now' => $jamSekarang,
        'menit' => $menitSebelum,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        $jadwalId = (int) ($r['jadwal_id'] ?? 0);
        $kegiatanId = (int) ($r['kegiatan_id'] ?? 0);
        $pembimbingId = (int) ($r['pembimbing_id'] ?? 0);
        if ($jadwalId <= 0 || $kegiatanId <= 0 || $pembimbingId <= 0) {
            continue;
        }
        if ((int) ($r['wa_scan_reminder'] ?? 1) !== 1) {
            continue;
        }

        $debounceKey = 'wa_pb_scan_sent_' . $tanggal . '_' . $jadwalId . '_' . $pembimbingId;
        if (trim((string) app_setting($pdo, $debounceKey, '')) === '1') {
            continue;
        }

        $stPb = $pdo->prepare('
            SELECT id FROM presensi_pembimbing
            WHERE pembimbing_id = :pid AND kegiatan_id = :kid AND tanggal = :tgl
            LIMIT 1
        ');
        $stPb->execute(['pid' => $pembimbingId, 'kid' => $kegiatanId, 'tgl' => $tanggal]);
        if ((int) ($stPb->fetchColumn() ?: 0) > 0) {
            continue;
        }

        $phone = normalize_wa_phone(trim((string) ($r['no_wa'] ?? '')));
        if ($phone === '') {
            if (!function_exists('presensi_wa_log_pembimbing_no_wa')) {
                require_once __DIR__ . '/wa_presensi.php';
            }
            presensi_wa_log_pembimbing_no_wa(
                $pdo,
                $pembimbingId,
                'pengingat_scan:' . $tanggal . ':jadwal:' . $jadwalId,
                (string) ($r['nama_pembimbing'] ?? '')
            );
            continue;
        }

        $pesan = wa_template_render($pdo, 'pembimbing_belum_scan', [
            'nama_pembimbing' => (string) ($r['nama_pembimbing'] ?? 'Pembimbing'),
            'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? 'kegiatan'),
        ]);

        if (send_wa_message($pdo, $phone, $pesan, [
            'kind' => 'presensi',
            'dedup_key' => 'pb_scan:' . $tanggal . ':' . $jadwalId . ':' . $pembimbingId,
        ])) {
            save_setting($pdo, $debounceKey, '1');
        }
    }

    wa_pembimbing_belum_scan_munawib($pdo, $menitSebelum, $tanggal, $jamSekarang, $hariKe);
}

function wa_pembimbing_belum_scan_munawib(PDO $pdo, int $menitSebelum, string $tanggal, string $jamSekarang, int $hariKe): void
{
    if (!table_exists($pdo, 'munawib') || !table_exists($pdo, 'munawib_penugasan') || !table_exists($pdo, 'presensi_munawib')) {
        return;
    }

    require_once __DIR__ . '/munawib.php';
    munawib_ensure_schema($pdo);

    $sql = '
        SELECT
            j.id AS jadwal_id,
            j.kegiatan_id,
            mp.munawib_id,
            j.jam_selesai,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS nama_kegiatan,
            m.nama AS nama_munawib,
            m.no_wa
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        INNER JOIN perizinan_pembimbing ip ON ip.pembimbing_id = j.pembimbing_id
            AND ip.status_izin = "IZIN"
            AND :tgl BETWEEN ip.tanggal_mulai AND ip.tanggal_selesai
        INNER JOIN munawib_penugasan mp ON mp.pembimbing_id = j.pembimbing_id AND mp.status = "AKTIF"
        INNER JOIN munawib m ON m.id = mp.munawib_id AND COALESCE(m.is_aktif, 1) = 1
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN ADDTIME(j.jam_selesai, SEC_TO_TIME(-:menit * 60)) AND j.jam_selesai
    ';
    if (!table_exists($pdo, 'perizinan_pembimbing')) {
        return;
    }

    $st = $pdo->prepare($sql);
    $st->execute([
        'tgl' => $tanggal,
        'hari_ke' => $hariKe,
        'jam_now' => $jamSekarang,
        'menit' => $menitSebelum,
    ]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $jadwalId = (int) ($r['jadwal_id'] ?? 0);
        $kegiatanId = (int) ($r['kegiatan_id'] ?? 0);
        $munawibId = (int) ($r['munawib_id'] ?? 0);
        if ($jadwalId <= 0 || $kegiatanId <= 0 || $munawibId <= 0) {
            continue;
        }

        $debounceKey = 'wa_mw_scan_sent_' . $tanggal . '_' . $jadwalId . '_' . $munawibId;
        if (trim((string) app_setting($pdo, $debounceKey, '')) === '1') {
            continue;
        }

        $stMw = $pdo->prepare('SELECT id FROM presensi_munawib WHERE munawib_id = :mid AND kegiatan_id = :kid AND tanggal = :tgl LIMIT 1');
        $stMw->execute(['mid' => $munawibId, 'kid' => $kegiatanId, 'tgl' => $tanggal]);
        if ((int) ($stMw->fetchColumn() ?: 0) > 0) {
            continue;
        }

        $phone = normalize_wa_phone(trim((string) ($r['no_wa'] ?? '')));
        if ($phone === '') {
            continue;
        }

        $pesan = wa_template_render($pdo, 'pembimbing_belum_scan', [
            'nama_pembimbing' => (string) ($r['nama_munawib'] ?? 'Munawib'),
            'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? 'kegiatan'),
        ]);

        if (send_wa_message($pdo, $phone, $pesan, [
            'kind' => 'presensi',
            'dedup_key' => 'mw_scan:' . $tanggal . ':' . $jadwalId . ':' . $munawibId,
        ])) {
            save_setting($pdo, $debounceKey, '1');
        }
    }
}
