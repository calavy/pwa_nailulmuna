<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_templates.php';
require_once __DIR__ . '/push_fcm.php';

/** Pastikan kolom audit & toggle WA izin pembimbing ada. */
function perizinan_approval_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (table_exists($pdo, 'perizinan') && !column_exists($pdo, 'perizinan', 'approved_bypass_alpa')) {
        try {
            $pdo->exec('ALTER TABLE perizinan ADD COLUMN approved_bypass_alpa TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_at');
        } catch (PDOException $e) {
            /* abaikan */
        }
    }

    if (table_exists($pdo, 'pembimbing') && !column_exists($pdo, 'pembimbing', 'wa_izin_notif')) {
        require_once __DIR__ . '/wa_pembimbing_scan.php';
        pembimbing_ensure_wa_scan_reminder_column($pdo);
        try {
            $after = column_exists($pdo, 'pembimbing', 'wa_scan_reminder') ? ' AFTER wa_scan_reminder' : '';
            $pdo->exec('ALTER TABLE pembimbing ADD COLUMN wa_izin_notif TINYINT(1) NOT NULL DEFAULT 1' . $after);
        } catch (PDOException $e) {
            /* abaikan */
        }
    }
}

/**
 * @return array{enabled:bool,keluar_max:int,keluar_hari:int,pulang_max:int,pulang_hari:int}
 */
function perizinan_alpa_settings(PDO $pdo): array
{
    return [
        'enabled' => trim((string) app_setting($pdo, 'izin_alpa_batas_enabled', '1')) === '1',
        'keluar_max' => max(0, (int) app_setting($pdo, 'izin_alpa_keluar_max', '3')),
        'keluar_hari' => max(1, (int) app_setting($pdo, 'izin_alpa_keluar_hari', '4')),
        'pulang_max' => max(0, (int) app_setting($pdo, 'izin_alpa_pulang_max', '3')),
        'pulang_hari' => max(1, (int) app_setting($pdo, 'izin_alpa_pulang_hari', '4')),
    ];
}

/** Hitung jumlah ALPA santri dalam N hari kalender (inklusif). */
function perizinan_alpa_hitung(PDO $pdo, int $santriId, int $hariWindow, ?string $refDate = null): int
{
    if ($santriId <= 0 || $hariWindow <= 0 || !table_exists($pdo, 'presensi')) {
        return 0;
    }
    $ref = $refDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate) ? $refDate : date('Y-m-d');
    $st = $pdo->prepare('
        SELECT COUNT(*)
        FROM presensi
        WHERE santri_id = :sid
          AND status_presensi = "ALPA"
          AND tanggal_presensi >= DATE_SUB(:ref, INTERVAL :win DAY)
          AND tanggal_presensi <= :ref
    ');
    $st->execute([
        'sid' => $santriId,
        'ref' => $ref,
        'win' => max(0, $hariWindow - 1),
    ]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * Cek apakah permohonan izin (selain sakit) boleh disetujui menurut riwayat ALPA.
 *
 * @return array{
 *   allowed:bool,
 *   subject:bool,
 *   alpa_count:int,
 *   max:int,
 *   hari:int,
 *   jenis_label:string,
 *   message:string
 * }
 */
function perizinan_alpa_cek_approval(PDO $pdo, int $santriId, string $jenisIzin, ?string $refDate = null): array
{
    $base = [
        'allowed' => true,
        'subject' => false,
        'alpa_count' => 0,
        'max' => 0,
        'hari' => 0,
        'jenis_label' => '',
        'message' => '',
    ];

    $jenis = strtoupper(trim($jenisIzin));
    if ($jenis === 'SAKIT' || $santriId <= 0) {
        return $base;
    }

    $cfg = perizinan_alpa_settings($pdo);
    if (!$cfg['enabled']) {
        return $base;
    }

    if (in_array($jenis, ['PULANG', 'TUGAS'], true)) {
        $max = $cfg['pulang_max'];
        $hari = $cfg['pulang_hari'];
        $jenisLabel = 'izin pulang/tugas';
    } else {
        $max = $cfg['keluar_max'];
        $hari = $cfg['keluar_hari'];
        $jenisLabel = 'izin keluar';
    }

    $base['subject'] = true;
    $base['max'] = $max;
    $base['hari'] = $hari;
    $base['jenis_label'] = $jenisLabel;

    if ($max <= 0) {
        return $base;
    }

    $count = perizinan_alpa_hitung($pdo, $santriId, $hari, $refDate);
    $base['alpa_count'] = $count;
    $base['allowed'] = $count < $max;
    if (!$base['allowed']) {
        $base['message'] = 'Santri memiliki ' . $count . ' ALPA dalam ' . $hari . ' hari terakhir. '
            . 'Batas ' . $jenisLabel . ': maks. ' . ($max - 1) . ' ALPA (blokir jika ≥ ' . $max . ').';
    }

    return $base;
}

/**
 * @return list<int>
 */
function perizinan_pembimbing_ids_untuk_santri(PDO $pdo, int $santriId): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return [];
    }

    $st = $pdo->prepare('SELECT tingkatan FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $tingkatan = trim((string) ($st->fetchColumn() ?: ''));
    $ids = [];

    if ($tingkatan !== '' && table_exists($pdo, 'jadwal_kegiatan')) {
        $stJ = $pdo->prepare('
            SELECT DISTINCT j.pembimbing_id
            FROM jadwal_kegiatan j
            WHERE j.pembimbing_id IS NOT NULL
              AND j.pembimbing_id > 0
              AND (j.tingkatan = :tk OR j.tingkatan = "Semua Tingkatan")
        ');
        $stJ->execute(['tk' => $tingkatan]);
        foreach ($stJ->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
            $id = (int) $pid;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    if (table_exists($pdo, 'pkpps_santri') && table_exists($pdo, 'pkpps_jadwal')) {
        require_once __DIR__ . '/pkpps.php';
        pkpps_ensure_schema($pdo);
        $stP = $pdo->prepare('
            SELECT DISTINCT j.pembimbing_id
            FROM pkpps_santri ps
            INNER JOIN pkpps_jadwal j ON j.pkpps_tingkatan_id = ps.pkpps_tingkatan_id AND j.is_aktif = 1
            WHERE ps.santri_id = :sid
              AND j.pembimbing_id IS NOT NULL
              AND j.pembimbing_id > 0
        ');
        $stP->execute(['sid' => $santriId]);
        foreach ($stP->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
            $id = (int) $pid;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return list<string> nomor WA (62…)
 */
function perizinan_pembimbing_wa_targets(PDO $pdo, int $santriId): array
{
    perizinan_approval_ensure_schema($pdo);
    if (trim((string) app_setting($pdo, 'wa_izin_pembimbing_enabled', '1')) !== '1') {
        return [];
    }
    if (!table_exists($pdo, 'pembimbing')) {
        return [];
    }

    $pbIds = perizinan_pembimbing_ids_untuk_santri($pdo, $santriId);
    if ($pbIds === []) {
        return [];
    }

    $phones = [];
    $placeholders = implode(',', array_fill(0, count($pbIds), '?'));
    $st = $pdo->prepare(
        'SELECT no_wa, wa_izin_notif FROM pembimbing WHERE id IN (' . $placeholders . ') AND is_aktif = 1'
    );
    $st->execute($pbIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if ((int) ($row['wa_izin_notif'] ?? 1) !== 1) {
            continue;
        }
        $wa = normalize_wa_phone(trim((string) ($row['no_wa'] ?? '')));
        if ($wa !== '') {
            $phones[] = $wa;
        }
    }

    return array_values(array_unique($phones));
}

function wa_format_izin_disetujui_pembimbing(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $tingkatan,
    string $jenisLabel,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $jamMulai,
    string $jamSelesai,
    string $alasan
): string {
    return wa_template_render($pdo, 'izin_disetujui_pembimbing', [
        'nama_santri' => $namaSantri,
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'jenis_izin' => $jenisLabel,
        'tanggal_mulai' => $tanggalMulai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => $alasan,
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/**
 * Kirim WA ke pembimbing terkait (+ opsional grup) saat izin disetujui.
 *
 * @param array<string,mixed> $izinRow minimal: santri_id, nama_santri, nis, tingkatan, jenis_izin, alasan
 */
function perizinan_kirim_wa_pembimbing_disetujui(PDO $pdo, array $izinRow, string $tglMulai, string $tglSelesai, string $jamMulai, string $jamSelesai): int
{
    if (!push_should_send_wa($pdo)) {
        return 0;
    }

    $santriId = (int) ($izinRow['santri_id'] ?? 0);
    $phones = perizinan_pembimbing_wa_targets($pdo, $santriId);
    $grupRaw = trim((string) app_setting($pdo, 'wa_izin_pembimbing_grup', ''));
    $kirimGrup = trim((string) app_setting($pdo, 'wa_izin_pembimbing_kirim_grup', '0')) === '1';
    if ($kirimGrup && $grupRaw !== '') {
        foreach (parse_phone_list($grupRaw) as $g) {
            $phones[] = $g;
        }
        $phones = array_values(array_unique($phones));
    }

    if ($phones === []) {
        return 0;
    }

    $jenisRaw = strtoupper((string) ($izinRow['jenis_izin'] ?? 'KELUAR'));
    $msg = wa_format_izin_disetujui_pembimbing(
        $pdo,
        (string) ($izinRow['nama_santri'] ?? '-'),
        trim((string) ($izinRow['nis'] ?? '')),
        trim((string) ($izinRow['tingkatan'] ?? '')),
        jenis_izin_label($jenisRaw),
        $tglMulai,
        $tglSelesai,
        $jamMulai,
        $jamSelesai,
        (string) ($izinRow['alasan'] ?? '-')
    );

    return send_wa_bulk($pdo, implode(',', $phones), $msg);
}

/**
 * Validasi ALPA sebelum setujui; kembalikan pesan error atau null jika lolos.
 */
function perizinan_validasi_setujui_alpa(PDO $pdo, int $santriId, string $jenisIzin, bool $bypassAlpa): ?string
{
    if ($bypassAlpa) {
        return null;
    }
    $cek = perizinan_alpa_cek_approval($pdo, $santriId, $jenisIzin);
    if ($cek['allowed']) {
        return null;
    }

    return (string) ($cek['message'] ?? 'Tidak memenuhi syarat ALPA untuk disetujui.');
}
