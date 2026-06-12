<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/perizinan_jenis.php';
require_once __DIR__ . '/entity_list_sort.php';
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

    if (table_exists($pdo, 'users') && !column_exists($pdo, 'users', 'no_wa')) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN no_wa VARCHAR(32) NULL DEFAULT NULL');
        } catch (PDOException $e) {
            /* abaikan */
        }
    }

    if (table_exists($pdo, 'perizinan') && !column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
        try {
            $pdo->exec('ALTER TABLE perizinan ADD COLUMN pengasuh_approved_by INT NULL DEFAULT NULL');
            $pdo->exec('ALTER TABLE perizinan ADD COLUMN pengasuh_approved_at DATETIME NULL DEFAULT NULL');
        } catch (PDOException $e) {
            /* abaikan */
        }
    }
    perizinan_jenis_ensure_enum($pdo);
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

/** Nama pembimbing terkait santri (untuk teks WA). */
function perizinan_pembimbing_nama_untuk_santri(PDO $pdo, int $santriId): string
{
    if ($santriId <= 0 || !table_exists($pdo, 'pembimbing')) {
        return '';
    }
    $pbIds = perizinan_pembimbing_ids_untuk_santri($pdo, $santriId);
    if ($pbIds === []) {
        return '';
    }
    $placeholders = implode(',', array_fill(0, count($pbIds), '?'));
    $st = $pdo->prepare(
        'SELECT nama_pembimbing FROM pembimbing WHERE id IN (' . $placeholders . ') AND is_aktif = 1 ORDER BY ' . pembimbing_list_order_sql('')
    );
    $st->execute($pbIds);
    $names = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $names[] = $n;
        }
    }

    return implode(', ', array_values(array_unique($names)));
}

/** Format satu baris santri untuk daftar WA (rombongan / tunggal). */
function perizinan_wa_format_baris_santri(string $namaSantri, string $nis = '', string $tingkatan = ''): string
{
    $part = trim($namaSantri) !== '' ? trim($namaSantri) : '-';
    if (trim($nis) !== '') {
        $part .= ' (' . trim($nis) . ')';
    }
    if (trim($tingkatan) !== '') {
        $part .= ' · ' . trim($tingkatan);
    }

    return '• ' . $part;
}

/**
 * @param list<array<string,mixed>> $anggota
 */
function perizinan_wa_format_daftar_santri(array $anggota): string
{
    if ($anggota === []) {
        return '';
    }
    $lines = [];
    foreach ($anggota as $ang) {
        $lines[] = perizinan_wa_format_baris_santri(
            (string) ($ang['nama_santri'] ?? '-'),
            trim((string) ($ang['nis'] ?? '')),
            trim((string) ($ang['tingkatan'] ?? ''))
        );
    }
    if (count($lines) <= 1) {
        return '';
    }

    return "Anggota:\n" . implode("\n", $lines) . "\n";
}

/** Doa tambahan untuk izin sakit; kosong jika bukan sakit atau template dinonaktifkan. */
function perizinan_wa_sakit_doa_tambahan(PDO $pdo, string $jenisIzin, string $namaSantri = ''): string
{
    if (strtoupper(trim($jenisIzin)) !== 'SAKIT') {
        return '';
    }
    $doaTpl = trim(wa_template_get($pdo, 'izin_sakit_doa'));
    if ($doaTpl === '') {
        return '';
    }

    return wa_template_render($pdo, 'izin_sakit_doa', [
        'nama_santri' => $namaSantri !== '' ? $namaSantri : 'santri',
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/** Sisipkan blok opsional ke pesan; append otomatis jika template belum punya placeholder. */
function perizinan_wa_sisipkan_blok(PDO $pdo, string $slug, string $pesan, string $placeholder, string $konten): string
{
    if ($konten === '') {
        return str_replace('{' . $placeholder . '}', '', $pesan);
    }
    if (str_contains(wa_template_get($pdo, $slug), '{' . $placeholder . '}')) {
        return str_replace('{' . $placeholder . '}', $konten, $pesan);
    }

    return $pesan . $konten;
}

/** Sisipkan doa sakit ke pesan; append otomatis jika template belum punya placeholder {doa}. */
function perizinan_wa_sisipkan_doa(PDO $pdo, string $slug, string $pesan, string $jenisIzin, string $namaSantri = ''): string
{
    return perizinan_wa_sisipkan_blok(
        $pdo,
        $slug,
        $pesan,
        'doa',
        perizinan_wa_sakit_doa_tambahan($pdo, $jenisIzin, $namaSantri)
    );
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
    string $alasan,
    string $namaPembimbing = '',
    string $jenisRaw = '',
    string $daftarSantri = ''
): string {
    $jenisCek = $jenisRaw !== '' ? $jenisRaw : $jenisLabel;
    $slug = 'izin_disetujui_pembimbing';
    $pesan = wa_template_render($pdo, $slug, [
        'nama_santri' => $namaSantri,
        'daftar_santri' => '',
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'jenis_izin' => $jenisLabel,
        'tanggal_mulai' => $tanggalMulai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => $alasan,
        'nama_pembimbing' => $namaPembimbing,
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'doa' => '',
    ]);
    $pesan = perizinan_wa_sisipkan_blok($pdo, $slug, $pesan, 'daftar_santri', $daftarSantri);

    return perizinan_wa_sisipkan_doa($pdo, $slug, $pesan, $jenisCek, $namaSantri);
}

/**
 * ID grup WA Fonte untuk notifikasi izin disetujui (bisa beberapa, pisah koma).
 */
function wa_izin_grup_fonte_targets(PDO $pdo): string
{
    $grupId = trim((string) app_setting($pdo, 'wa_izin_grup_fonte', ''));
    if ($grupId !== '') {
        return $grupId;
    }

    return trim((string) app_setting($pdo, 'wa_izin_pembimbing_grup', ''));
}

/** Apakah kirim otomatis ke grup saat izin disetujui. */
function wa_izin_grup_fonte_enabled(PDO $pdo): bool
{
    $targets = wa_izin_grup_fonte_targets($pdo);
    if ($targets === '') {
        return false;
    }

    $flag = trim((string) app_setting($pdo, 'wa_izin_grup_fonte_enabled', '1'));
    if ($flag === '0') {
        return false;
    }
    if ($flag === '1') {
        return true;
    }

    return trim((string) app_setting($pdo, 'wa_izin_pembimbing_kirim_grup', '0')) === '1';
}

/**
 * @return array{pembimbing:int,grup:int,pengurus:int,total:int}
 */
function perizinan_wa_kirim_ringkasan(int $pembimbing, int $grup, int $pengurus = 0): array
{
    $pb = max(0, $pembimbing);
    $gr = max(0, $grup);
    $pg = max(0, $pengurus);

    return [
        'pembimbing' => $pb,
        'grup' => $gr,
        'pengurus' => $pg,
        'total' => $pb + $gr + $pg,
    ];
}

/** Teks flash singkat hasil kirim WA izin disetujui. */
function perizinan_wa_flash_kirim_disetujui(array $ringkasan): string
{
    $parts = [];
    if ((int) ($ringkasan['pembimbing'] ?? 0) > 0) {
        $parts[] = (int) $ringkasan['pembimbing'] . ' pembimbing';
    }
    if ((int) ($ringkasan['grup'] ?? 0) > 0) {
        $parts[] = (int) $ringkasan['grup'] . ' grup WA';
    }
    if ((int) ($ringkasan['pengurus'] ?? 0) > 0) {
        $parts[] = (int) $ringkasan['pengurus'] . ' pengurus';
    }
    if ($parts === []) {
        return '';
    }

    return ' WA terkirim ke ' . implode(' & ', $parts) . '.';
}

/** @return list<string> */
function perizinan_wa_pengurus_phone_list(PDO $pdo, int $approvedByUserId = 0): array
{
    $phones = [];
    $setting = wa_izin_pengurus_target($pdo);
    if ($setting !== '') {
        foreach (preg_split('/\s*,\s*/', $setting) ?: [] as $ph) {
            $ph = trim((string) $ph);
            if ($ph !== '') {
                $phones[$ph] = true;
            }
        }
    }
    if ($approvedByUserId > 0 && table_exists($pdo, 'users') && column_exists($pdo, 'users', 'no_wa')) {
        $st = $pdo->prepare('SELECT no_wa FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $approvedByUserId]);
        $noWa = trim((string) ($st->fetchColumn() ?: ''));
        if ($noWa !== '') {
            $phones[$noWa] = true;
        }
    }

    return array_keys($phones);
}

function perizinan_nama_pengurus_by_id(PDO $pdo, int $userId): string
{
    if ($userId <= 0 || !table_exists($pdo, 'users')) {
        return 'Pengurus';
    }
    $st = $pdo->prepare('SELECT nama FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $nama = trim((string) ($st->fetchColumn() ?: ''));

    return $nama !== '' ? $nama : 'Pengurus';
}

function wa_format_izin_disetujui_pengurus(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $tingkatan,
    string $jenisLabel,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $jamMulai,
    string $jamSelesai,
    string $alasan,
    string $namaPengurus = '',
    string $daftarSantri = ''
): string {
    $slug = 'izin_disetujui_pengurus';
    $pesan = wa_template_render($pdo, $slug, [
        'nama_santri' => $namaSantri,
        'daftar_santri' => '',
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'jenis_izin' => $jenisLabel,
        'tanggal_mulai' => $tanggalMulai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => $alasan,
        'nama_pengurus' => $namaPengurus !== '' ? $namaPengurus : 'Pengurus',
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);

    return perizinan_wa_sisipkan_blok($pdo, $slug, $pesan, 'daftar_santri', $daftarSantri);
}

function wa_format_izin_selesai_pengurus(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $tingkatan,
    string $jenisLabel,
    string $waktuKembali,
    int $lateMinutes = 0,
    int $latePoint = 0
): string {
    $infoTelat = '';
    if ($latePoint > 0) {
        $infoTelat = '⚠️ Terlambat ' . $lateMinutes . ' menit (poin +' . $latePoint . ').';
    }
    $slug = 'izin_selesai_pengurus';

    return wa_template_render($pdo, $slug, [
        'nama_santri' => $namaSantri,
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'jenis_izin' => $jenisLabel,
        'waktu_kembali' => $waktuKembali,
        'info_telat' => $infoTelat !== '' ? $infoTelat . "\n" : '',
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/**
 * Kirim WA ke pengurus/petugas surat saat izin disetujui.
 *
 * @param array<string,mixed> $izinRow
 */
function perizinan_kirim_wa_pengurus_disetujui(
    PDO $pdo,
    array $izinRow,
    string $tglMulai,
    string $tglSelesai,
    string $jamMulai,
    string $jamSelesai,
    int $approvedByUserId = 0,
    string $daftarSantri = ''
): int {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin') || !wa_izin_pengurus_enabled($pdo)) {
        return 0;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return 0;
    }

    $phones = perizinan_wa_pengurus_phone_list($pdo, $approvedByUserId);
    if ($phones === []) {
        return 0;
    }

    $jenisRaw = strtoupper((string) ($izinRow['jenis_izin'] ?? 'KELUAR'));
    $msg = wa_format_izin_disetujui_pengurus(
        $pdo,
        (string) ($izinRow['nama_santri'] ?? '-'),
        trim((string) ($izinRow['nis'] ?? '')),
        trim((string) ($izinRow['tingkatan'] ?? '')),
        jenis_izin_label($jenisRaw),
        $tglMulai,
        $tglSelesai,
        $jamMulai,
        $jamSelesai,
        (string) ($izinRow['alasan'] ?? '-'),
        perizinan_nama_pengurus_by_id($pdo, $approvedByUserId),
        $daftarSantri
    );

    return send_wa_bulk($pdo, implode(',', $phones), $msg);
}

/** Kirim laporan WA ke pengurus saat santri tercatat kembali / izin selesai. */
function perizinan_kirim_wa_pengurus_izin_selesai(
    PDO $pdo,
    int $izinId,
    int $lateMinutes = 0,
    int $latePoint = 0
): int {
    require_once __DIR__ . '/wa_otomatis.php';
    if ($izinId <= 0 || !wa_otomatis_should_run($pdo, 'izin') || !wa_izin_selesai_enabled($pdo)) {
        return 0;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return 0;
    }

    $phones = perizinan_wa_pengurus_phone_list($pdo);
    if ($phones === []) {
        return 0;
    }

    $st = $pdo->prepare('
        SELECT i.jenis_izin, i.waktu_kembali, i.approved_by,
               s.nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }

    $waktuKembali = trim((string) ($row['waktu_kembali'] ?? ''));
    if ($waktuKembali === '') {
        $waktuKembali = date('Y-m-d H:i');
    } else {
        $ts = strtotime($waktuKembali);
        $waktuKembali = $ts !== false ? date('d/m/Y H:i', $ts) : $waktuKembali;
    }

    $jenisRaw = strtoupper((string) ($row['jenis_izin'] ?? 'KELUAR'));
    $msg = wa_format_izin_selesai_pengurus(
        $pdo,
        (string) ($row['nama_santri'] ?? '-'),
        trim((string) ($row['nis'] ?? '')),
        trim((string) ($row['tingkatan'] ?? '')),
        jenis_izin_label($jenisRaw),
        $waktuKembali,
        $lateMinutes,
        $latePoint
    );

    return send_wa_bulk($pdo, implode(',', $phones), $msg);
}

/**
 * Kirim WA ke nomor wali santri saat permohonan izin disetujui.
 *
 * @param array<string,mixed> $izinRow minimal: santri_id, nama_santri, jenis_izin, alasan (+ kolom wali jika ada)
 */
function perizinan_kirim_wa_wali_disetujui(
    PDO $pdo,
    array $izinRow,
    string $tglMulai,
    string $tglSelesai,
    string $jamMulai,
    string $jamSelesai
): int {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin') || !wa_izin_wali_enabled($pdo)) {
        return 0;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return 0;
    }

    $waliPhone = wa_otomatis_santri_wali_phone($pdo, $izinRow);
    if ($waliPhone === '') {
        $santriId = (int) ($izinRow['santri_id'] ?? 0);
        if ($santriId > 0) {
            $waliPhone = wa_otomatis_santri_wali_phone($pdo, $santriId);
        }
    }
    if ($waliPhone === '') {
        return 0;
    }

    $jenisRaw = strtoupper((string) ($izinRow['jenis_izin'] ?? 'KELUAR'));
    $msg = wa_format_izin_disetujui_untuk_wali(
        $pdo,
        (string) ($izinRow['nama_santri'] ?? '-'),
        jenis_izin_label($jenisRaw),
        $tglSelesai,
        $jamSelesai,
        (string) ($izinRow['alasan'] ?? '-'),
        $tglMulai,
        $jamMulai
    );

    return send_wa_message($pdo, $waliPhone, $msg) ? 1 : 0;
}

/**
 * Kirim WA ke pembimbing terkait (+ opsional grup) saat izin disetujui.
 *
 * @param array<string,mixed> $izinRow minimal: santri_id, nama_santri, nis, tingkatan, jenis_izin, alasan
 * @return array{pembimbing:int,grup:int,pengurus:int,total:int}
 */
function perizinan_kirim_wa_pembimbing_disetujui(
    PDO $pdo,
    array $izinRow,
    string $tglMulai,
    string $tglSelesai,
    string $jamMulai,
    string $jamSelesai,
    int $approvedByUserId = 0
): array {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin')) {
        return perizinan_wa_kirim_ringkasan(0, 0, 0);
    }

    $sentPb = 0;
    $santriId = (int) ($izinRow['santri_id'] ?? 0);
    $phones = perizinan_pembimbing_wa_targets($pdo, $santriId);
    if ($phones !== []) {
        $jenisRaw = strtoupper((string) ($izinRow['jenis_izin'] ?? 'KELUAR'));
        $namaPb = perizinan_pembimbing_nama_untuk_santri($pdo, $santriId);
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
            (string) ($izinRow['alasan'] ?? '-'),
            $namaPb,
            $jenisRaw
        );
        $sentPb = send_wa_bulk($pdo, implode(',', $phones), $msg);
    }

    $sentGrup = perizinan_kirim_wa_grup_fonte($pdo, $izinRow, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai);
    $sentPg = perizinan_kirim_wa_pengurus_disetujui($pdo, $izinRow, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai, $approvedByUserId);

    return perizinan_wa_kirim_ringkasan($sentPb, $sentGrup, $sentPg);
}

/**
 * Kirim satu pesan WA untuk seluruh anggota izin rombongan (bukan per santri).
 *
 * @param list<array<string,mixed>> $anggota
 * @return array{pembimbing:int,grup:int,pengurus:int,total:int}
 */
function perizinan_kirim_wa_rombongan_disetujui(
    PDO $pdo,
    array $anggota,
    string $jenisIzin,
    string $alasan,
    string $tglMulai,
    string $tglSelesai,
    string $jamMulai,
    string $jamSelesai,
    int $approvedByUserId = 0
): array {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin') || $anggota === []) {
        return perizinan_wa_kirim_ringkasan(0, 0, 0);
    }

    $jenisRaw = strtoupper(trim($jenisIzin));
    $jenisLabel = jenis_izin_label($jenisRaw);
    $daftarSantri = perizinan_wa_format_daftar_santri($anggota);
    $jumlah = count($anggota);
    $namaJudul = $jumlah > 1
        ? 'Izin rombongan (' . $jumlah . ' santri)'
        : (string) ($anggota[0]['nama_santri'] ?? '-');

    $phones = [];
    $pbNames = [];
    foreach ($anggota as $ang) {
        $sid = (int) ($ang['santri_id'] ?? 0);
        foreach (perizinan_pembimbing_wa_targets($pdo, $sid) as $ph) {
            $phones[$ph] = true;
        }
        $namaPb = perizinan_pembimbing_nama_untuk_santri($pdo, $sid);
        if ($namaPb !== '') {
            foreach (preg_split('/\s*,\s*/', $namaPb) ?: [] as $n) {
                $n = trim((string) $n);
                if ($n !== '') {
                    $pbNames[$n] = true;
                }
            }
        }
    }
    $phoneList = array_keys($phones);
    $namaPbAll = implode(', ', array_keys($pbNames));

    $sentPb = 0;
    if ($phoneList !== []) {
        $msg = wa_format_izin_disetujui_pembimbing(
            $pdo,
            $namaJudul,
            '',
            '',
            $jenisLabel,
            $tglMulai,
            $tglSelesai,
            $jamMulai,
            $jamSelesai,
            $alasan,
            $namaPbAll,
            $jenisRaw,
            $daftarSantri
        );
        $sentPb = send_wa_bulk($pdo, implode(',', $phoneList), $msg);
    }

    $izinRowGrup = [
        'nama_santri' => $namaJudul,
        'nis' => '',
        'tingkatan' => '',
        'jenis_izin' => $jenisRaw,
        'alasan' => $alasan,
        'daftar_santri' => $daftarSantri,
    ];

    $sentGrup = perizinan_kirim_wa_grup_fonte($pdo, $izinRowGrup, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai, $daftarSantri);
    $sentPg = perizinan_kirim_wa_pengurus_disetujui(
        $pdo,
        $izinRowGrup,
        $tglMulai,
        $tglSelesai,
        $jamMulai,
        $jamSelesai,
        $approvedByUserId,
        $daftarSantri
    );

    foreach ($anggota as $ang) {
        $izinRowWali = [
            'santri_id' => (int) ($ang['santri_id'] ?? 0),
            'nama_santri' => (string) ($ang['nama_santri'] ?? '-'),
            'jenis_izin' => $jenisRaw,
            'alasan' => $alasan,
        ];
        perizinan_kirim_wa_wali_disetujui($pdo, $izinRowWali, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai);
    }

    return perizinan_wa_kirim_ringkasan($sentPb, $sentGrup, $sentPg);
}

/**
 * Kirim notifikasi ke grup WA Fonte saat izin santri disetujui.
 *
 * @param array<string,mixed> $izinRow
 */
function perizinan_kirim_wa_grup_fonte(
    PDO $pdo,
    array $izinRow,
    string $tglMulai,
    string $tglSelesai,
    string $jamMulai,
    string $jamSelesai,
    string $daftarSantri = ''
): int {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin')) {
        return 0;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return 0;
    }
    if (!wa_izin_grup_fonte_enabled($pdo)) {
        return 0;
    }

    $grupId = wa_izin_grup_fonte_targets($pdo);
    if ($grupId === '') {
        return 0;
    }

    $jenisRaw = strtoupper((string) ($izinRow['jenis_izin'] ?? 'KELUAR'));
    if ($daftarSantri === '' && isset($izinRow['daftar_santri'])) {
        $daftarSantri = (string) $izinRow['daftar_santri'];
    }
    $namaSantri = (string) ($izinRow['nama_santri'] ?? '-');
    $slug = 'izin_grup_fonte';
    $pesan = wa_template_render($pdo, $slug, [
        'nama_santri' => $namaSantri,
        'daftar_santri' => '',
        'nis' => trim((string) ($izinRow['nis'] ?? '')),
        'tingkatan' => trim((string) ($izinRow['tingkatan'] ?? '')),
        'jenis_izin' => jenis_izin_label($jenisRaw),
        'tanggal_mulai' => $tglMulai,
        'tanggal_selesai' => $tglSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => (string) ($izinRow['alasan'] ?? '-'),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'doa' => '',
    ]);
    $pesan = perizinan_wa_sisipkan_blok($pdo, $slug, $pesan, 'daftar_santri', $daftarSantri);
    $msg = perizinan_wa_sisipkan_doa($pdo, $slug, $pesan, $jenisRaw, $namaSantri);

    return send_wa_bulk($pdo, $grupId, $msg);
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

function user_is_pengasuh_kiai(): bool
{
    return strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'kiai';
}

/** Pengasuh tidak mengajukan izin — arahkan ke halaman persetujuan. */
function perizinan_redirect_kiai_dari_permohonan(): void
{
    if (!user_is_pengasuh_kiai() || (function_exists('is_super_admin') && is_super_admin())) {
        return;
    }
    require_once __DIR__ . '/app_path.php';
    header('Location: ' . app_href('/pengasuh/perizinan.php'));
    exit;
}

/**
 * Blok tampilan pengasuh pada surat cetak.
 *
 * @param array<string, mixed> $izin
 * @return array{disetujui:bool,keterangan:string,nama:string,waktu:string}
 */
function perizinan_surat_blok_pengasuh(PDO $pdo, array $izin): array
{
    require_once __DIR__ . '/pondok_cetak.php';
    $kop = pondok_kop_data($pdo);
    $namaDefault = trim((string) ($kop['nama_pengasuh'] ?? ''));
    $perluPersetujuan = perizinan_memerlukan_persetujuan_pengasuh((string) ($izin['jenis_izin'] ?? ''));
    $approvedAt = trim((string) ($izin['pengasuh_approved_at'] ?? ''));
    if ($perluPersetujuan && $approvedAt !== '') {
        $nama = $namaDefault;
        $byId = (int) ($izin['pengasuh_approved_by'] ?? 0);
        if ($byId > 0 && table_exists($pdo, 'users')) {
            $st = $pdo->prepare('SELECT nama_lengkap, username FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $byId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($u)) {
                $namaUser = trim((string) ($u['nama_lengkap'] ?? ''));
                if ($namaUser === '') {
                    $namaUser = trim((string) ($u['username'] ?? ''));
                }
                if ($namaUser !== '') {
                    $nama = $namaUser;
                }
            }
        }

        return [
            'disetujui' => true,
            'keterangan' => 'Telah disetujui pengasuh',
            'nama' => $nama !== '' ? $nama : '(Pengasuh)',
            'waktu' => app_format_datetime_id($approvedAt),
        ];
    }

    return [
        'disetujui' => false,
        'keterangan' => '',
        'nama' => $namaDefault !== '' ? $namaDefault : trim((string) ($izin['penandatangan_pengasuh'] ?? '')),
        'waktu' => '',
    ];
}

/** @return array{disetujui:bool,keterangan:string,nama:string,waktu:string} */
function perizinan_rombongan_surat_blok_pengasuh(PDO $pdo, int $rombonganId, array $meta = []): array
{
    perizinan_approval_ensure_schema($pdo);
    if ($rombonganId <= 0 || !table_exists($pdo, 'perizinan') || !column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
        return perizinan_surat_blok_pengasuh($pdo, $meta);
    }
    $st = $pdo->prepare('
        SELECT pengasuh_approved_by, pengasuh_approved_at
        FROM perizinan
        WHERE rombongan_id = :rid AND pengasuh_approved_at IS NOT NULL
        ORDER BY pengasuh_approved_at DESC
        LIMIT 1
    ');
    $st->execute(['rid' => $rombonganId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $blok = is_array($row) ? $row : [];
    $blok['jenis_izin'] = (string) ($meta['jenis_izin'] ?? '');

    return perizinan_surat_blok_pengasuh($pdo, $blok);
}

/**
 * @return array{ok:bool,message:string}
 */
function perizinan_pengasuh_setujui(PDO $pdo, int $izinId, int $userId): array
{
    perizinan_approval_ensure_schema($pdo);
    if ($izinId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $st = $pdo->prepare('
        SELECT id, approval_status, pengasuh_approved_at, jenis_izin
        FROM perizinan
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'Permohonan izin tidak ditemukan.'];
    }
    if (!perizinan_memerlukan_persetujuan_pengasuh((string) ($row['jenis_izin'] ?? ''))) {
        return ['ok' => false, 'message' => 'Hanya izin syar\'i yang memerlukan persetujuan pengasuh di menu ini.'];
    }
    if (strtoupper((string) ($row['approval_status'] ?? '')) !== 'PENDING') {
        return ['ok' => false, 'message' => 'Hanya permohonan menunggu yang dapat disetujui pengasuh.'];
    }
    if (trim((string) ($row['pengasuh_approved_at'] ?? '')) !== '') {
        return ['ok' => false, 'message' => 'Permohonan ini sudah disetujui pengasuh.'];
    }
    $up = $pdo->prepare("
        UPDATE perizinan
        SET pengasuh_approved_by = :uid, pengasuh_approved_at = NOW()
        WHERE id = :id AND approval_status = 'PENDING' AND pengasuh_approved_at IS NULL
    ");
    $up->execute(['uid' => $userId, 'id' => $izinId]);
    if ($up->rowCount() < 1) {
        return ['ok' => false, 'message' => 'Gagal menyimpan persetujuan pengasuh.'];
    }

    return ['ok' => true, 'message' => 'Persetujuan pengasuh tersimpan. Menunggu persetujuan pengurus.'];
}

/**
 * @return array{ok:bool,message:string,jumlah:int}
 */
function perizinan_pengasuh_setujui_rombongan(PDO $pdo, int $rombonganId, int $userId): array
{
    perizinan_approval_ensure_schema($pdo);
    require_once __DIR__ . '/perizinan_rombongan.php';
    if ($rombonganId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.', 'jumlah' => 0];
    }
    $meta = perizinan_rombongan_meta($pdo, $rombonganId);
    if (!$meta) {
        return ['ok' => false, 'message' => 'Data rombongan tidak ditemukan.', 'jumlah' => 0];
    }
    if (!perizinan_memerlukan_persetujuan_pengasuh((string) ($meta['jenis_izin'] ?? ''))) {
        return ['ok' => false, 'message' => 'Hanya izin syar\'i rombongan yang memerlukan persetujuan pengasuh.', 'jumlah' => 0];
    }
    $syari = perizinan_jenis_syari_kode();
    $up = $pdo->prepare("
        UPDATE perizinan
        SET pengasuh_approved_by = :uid, pengasuh_approved_at = NOW()
        WHERE rombongan_id = :rid
          AND approval_status = 'PENDING'
          AND pengasuh_approved_at IS NULL
          AND UPPER(TRIM(jenis_izin)) = '{$syari}'
    ");
    $up->execute(['uid' => $userId, 'rid' => $rombonganId]);
    $jumlah = $up->rowCount();
    if ($jumlah < 1) {
        return ['ok' => false, 'message' => 'Tidak ada permohonan rombongan yang menunggu persetujuan pengasuh.', 'jumlah' => 0];
    }

    return ['ok' => true, 'message' => 'Persetujuan pengasuh rombongan tersimpan (' . $jumlah . ' santri).', 'jumlah' => $jumlah];
}

/**
 * Daftar permohonan menunggu persetujuan pengasuh.
 *
 * @return list<array<string, mixed>>
 */
function perizinan_pengasuh_pending_list(PDO $pdo, int $limit = 80): array
{
    perizinan_approval_ensure_schema($pdo);
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return [];
    }
    require_once __DIR__ . '/santri_operasional.php';
    $aktif = santri_sql_aktif_only('s');
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $syari = perizinan_jenis_syari_kode();
    $hasPengasuhCol = column_exists($pdo, 'perizinan', 'pengasuh_approved_at');
    $filterPengasuh = $hasPengasuhCol ? ' AND i.pengasuh_approved_at IS NULL' : '';
    $limit = max(1, min(200, $limit));
    $st = $pdo->query("
        SELECT i.id, i.santri_id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai,
               i.jam_mulai, i.jam_selesai, i.alasan, i.created_at, i.rombongan_id,
               s.{$nameCol} AS nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktif}
        WHERE i.approval_status = 'PENDING'
          AND UPPER(TRIM(i.jenis_izin)) = '{$syari}'{$filterPengasuh}
        ORDER BY i.created_at DESC
        LIMIT {$limit}
    ");

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
