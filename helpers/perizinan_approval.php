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
    perizinan_tujuan_ensure_schema($pdo);
    require_once __DIR__ . '/perizinan_syari_kategori.php';
    perizinan_syari_kategori_ensure_schema($pdo);
    perizinan_core_columns_ensure($pdo);
    perizinan_ehealth_ensure_table($pdo);
    perizinan_ensure_performance_indexes($pdo);
    perizinan_syari_backfill_finalize_semua($pdo);
}

/** Kolom inti modul perizinan (sekali per proses, bukan tiap request halaman). */
function perizinan_core_columns_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'perizinan')) {
        return;
    }
    $done = true;
    if (!column_exists($pdo, 'perizinan', 'jenis_izin')) {
        try {
            $pdo->exec("ALTER TABLE perizinan
                ADD COLUMN IF NOT EXISTS jenis_izin ENUM('SAKIT','KELUAR','TUGAS','PULANG') NOT NULL DEFAULT 'KELUAR',
                ADD COLUMN IF NOT EXISTS jam_mulai TIME NULL,
                ADD COLUMN IF NOT EXISTS jam_selesai TIME NULL,
                ADD COLUMN IF NOT EXISTS durasi_jam DECIMAL(5,2) NULL,
                ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
                ADD COLUMN IF NOT EXISTS approved_by INT NULL,
                ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
                ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL,
                ADD COLUMN IF NOT EXISTS qr_token VARCHAR(120) NULL,
                ADD COLUMN IF NOT EXISTS waktu_keluar DATETIME NULL,
                ADD COLUMN IF NOT EXISTS grace_menit INT NOT NULL DEFAULT 15,
                ADD COLUMN IF NOT EXISTS poin_pelanggaran INT NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            /* abaikan */
        }
    }
}

function perizinan_ehealth_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS ehealth_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                santri_id INT NOT NULL,
                gejala TEXT NOT NULL,
                suhu_tubuh DECIMAL(4,1) NULL,
                tindakan TEXT NULL,
                status_kesehatan ENUM("RAWAT_PONDOK","DIRUJUK_RS","ISOLASI","SELESAI") NOT NULL DEFAULT "RAWAT_PONDOK",
                notifikasi_wali TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
            )
        ');
    } catch (PDOException $e) {
        /* abaikan */
    }
}

/** Index lookup daftar izin & cek ALPA. */
function perizinan_ensure_performance_indexes(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'perizinan')) {
        return;
    }
    $done = true;
    $indexes = [
        'idx_perizinan_approval_tgl' => 'CREATE INDEX idx_perizinan_approval_tgl ON perizinan (approval_status, tanggal_mulai)',
        'idx_perizinan_rombongan' => 'CREATE INDEX idx_perizinan_rombongan ON perizinan (rombongan_id)',
    ];
    foreach ($indexes as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key') === false && strpos($msg, '1061') === false) {
                /* abaikan */
            }
        }
    }
    if (table_exists($pdo, 'presensi')) {
        try {
            $pdo->exec('ALTER TABLE presensi ADD INDEX IF NOT EXISTS idx_presensi_alpa (santri_id, status_presensi, tanggal_presensi)');
        } catch (PDOException $e) {
            try {
                $pdo->exec('CREATE INDEX idx_presensi_alpa ON presensi (santri_id, status_presensi, tanggal_presensi)');
            } catch (PDOException $e2) {
                /* abaikan */
            }
        }
    }
}

/** Selesaikan izin syar'i yang sudah distempel pengasuh (alur lama) tetapi masih PENDING. */
function perizinan_syari_backfill_finalize_semua(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'perizinan') || !column_exists($pdo, 'perizinan', 'pengasuh_approved_at')) {
        return;
    }
    $done = true;
    $syari = perizinan_jenis_syari_kode();
    $st = $pdo->query("
        SELECT id
        FROM perizinan
        WHERE approval_status = 'PENDING'
          AND UPPER(TRIM(jenis_izin)) = '{$syari}'
          AND pengasuh_approved_at IS NOT NULL
        ORDER BY id ASC
        LIMIT 200
    ");
    if (!$st) {
        return;
    }
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $idRaw) {
        perizinan_syari_backfill_finalize($pdo, (int) $idRaw);
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
 * Hitung ALPA per santri dalam satu query (batch).
 *
 * @param list<int> $santriIds
 * @return array<int, int> santri_id => jumlah ALPA
 */
function perizinan_alpa_hitung_batch(PDO $pdo, array $santriIds, int $hariWindow, ?string $refDate = null): array
{
    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn(int $id): bool => $id > 0)));
    if ($santriIds === [] || $hariWindow <= 0 || !table_exists($pdo, 'presensi')) {
        return [];
    }
    $ref = $refDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate) ? $refDate : date('Y-m-d');
    $win = max(0, $hariWindow - 1);
    $ph = implode(',', array_fill(0, count($santriIds), '?'));
    $sql = '
        SELECT santri_id, COUNT(*) AS cnt
        FROM presensi
        WHERE santri_id IN (' . $ph . ')
          AND status_presensi = "ALPA"
          AND tanggal_presensi >= DATE_SUB(?, INTERVAL ? DAY)
          AND tanggal_presensi <= ?
        GROUP BY santri_id
    ';
    $params = array_merge($santriIds, [$ref, $win, $ref]);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(int) ($row['santri_id'] ?? 0)] = (int) ($row['cnt'] ?? 0);
    }

    return $out;
}

/**
 * Peta cek ALPA untuk baris izin pending (hindari N+1 query).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<int, array<string, mixed>> izin_id => hasil cek
 */
function perizinan_alpa_map_for_rows(PDO $pdo, array $rows): array
{
    $cfg = perizinan_alpa_settings($pdo);
    if (!$cfg['enabled']) {
        return [];
    }
    $keluarIds = [];
    $pulangIds = [];
    $metaByIzin = [];
    foreach ($rows as $row) {
        if ((string) ($row['approval_status'] ?? 'PENDING') !== 'PENDING') {
            continue;
        }
        $izinId = (int) ($row['id'] ?? 0);
        $sid = (int) ($row['santri_id'] ?? 0);
        $jenis = strtoupper(trim((string) ($row['jenis_izin'] ?? 'KELUAR')));
        if ($izinId <= 0 || $sid <= 0 || $jenis === 'SAKIT') {
            continue;
        }
        if (in_array($jenis, ['PULANG', 'TUGAS'], true)) {
            $pulangIds[] = $sid;
            $metaByIzin[$izinId] = ['sid' => $sid, 'jenis' => $jenis, 'pool' => 'pulang'];
        } else {
            $keluarIds[] = $sid;
            $metaByIzin[$izinId] = ['sid' => $sid, 'jenis' => $jenis, 'pool' => 'keluar'];
        }
    }
    if ($metaByIzin === []) {
        return [];
    }
    $countsKeluar = $cfg['keluar_max'] > 0
        ? perizinan_alpa_hitung_batch($pdo, $keluarIds, $cfg['keluar_hari'])
        : [];
    $countsPulang = $cfg['pulang_max'] > 0
        ? perizinan_alpa_hitung_batch($pdo, $pulangIds, $cfg['pulang_hari'])
        : [];
    $map = [];
    foreach ($metaByIzin as $izinId => $meta) {
        $jenis = $meta['jenis'];
        if (in_array($jenis, ['PULANG', 'TUGAS'], true)) {
            $max = $cfg['pulang_max'];
            $hari = $cfg['pulang_hari'];
            $jenisLabel = 'izin pulang/tugas';
            $count = (int) ($countsPulang[(int) $meta['sid']] ?? 0);
        } else {
            $max = $cfg['keluar_max'];
            $hari = $cfg['keluar_hari'];
            $jenisLabel = 'izin';
            $count = (int) ($countsKeluar[(int) $meta['sid']] ?? 0);
        }
        $base = [
            'allowed' => true,
            'subject' => true,
            'alpa_count' => $count,
            'max' => $max,
            'hari' => $hari,
            'jenis_label' => $jenisLabel,
            'message' => '',
        ];
        if ($max > 0) {
            $base['allowed'] = $count < $max;
            if (!$base['allowed']) {
                $base['message'] = 'Santri memiliki ' . $count . ' ALPA dalam ' . $hari . ' hari terakhir. '
                    . 'Batas ' . $jenisLabel . ': maks. ' . ($max - 1) . ' ALPA (blokir jika ≥ ' . $max . ').';
            }
        }
        $map[$izinId] = perizinan_alpa_lengkapi_tampilan($base);
    }

    return $map;
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
function perizinan_alpa_cek_approval(PDO $pdo, int $santriId, string $jenisIzin, ?string $refDate = null, ?string $syariKategori = null): array
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
    if ($santriId <= 0) {
        return $base;
    }
    if ($jenis === 'SAKIT') {
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
    } elseif (perizinan_memerlukan_persetujuan_pengasuh($jenis)) {
        $max = $cfg['keluar_max'];
        $hari = $cfg['keluar_hari'];
        $jenisLabel = 'izin';
    } else {
        $max = $cfg['keluar_max'];
        $hari = $cfg['keluar_hari'];
        $jenisLabel = 'izin';
    }

    $base['subject'] = true;
    $base['max'] = $max;
    $base['hari'] = $hari;
    $base['jenis_label'] = $jenisLabel;

    if ($max <= 0) {
        return perizinan_alpa_lengkapi_tampilan($base, $refDate);
    }

    $count = perizinan_alpa_hitung($pdo, $santriId, $hari, $refDate);
    $base['alpa_count'] = $count;
    $base['allowed'] = $count < $max;
    if (!$base['allowed']) {
        $base['message'] = 'Santri memiliki ' . $count . ' ALPA dalam ' . $hari . ' hari terakhir. '
            . 'Batas ' . $jenisLabel . ': maks. ' . ($max - 1) . ' ALPA (blokir jika ≥ ' . $max . ').';
    }

    return perizinan_alpa_lengkapi_tampilan($base, $refDate);
}

/**
 * Lengkapi hasil cek ALPA dengan teks tampilan yang mudah dibaca.
 *
 * @param array<string, mixed> $cek
 * @return array<string, mixed>
 */
function perizinan_alpa_lengkapi_tampilan(array $cek, ?string $refDate = null): array
{
    if (empty($cek['subject'])) {
        $cek['status'] = 'na';
        $cek['status_label'] = '';
        $cek['ringkasan'] = 'Tidak dicek';
        $cek['penjelasan'] = 'Syarat ALPA tidak berlaku untuk jenis izin ini atau fitur ALPA nonaktif.';
        return $cek;
    }

    require_once __DIR__ . '/datetime_display.php';

    $count = (int) ($cek['alpa_count'] ?? 0);
    $max = (int) ($cek['max'] ?? 0);
    $hari = (int) ($cek['hari'] ?? 0);
    $jenisLabel = trim((string) ($cek['jenis_label'] ?? 'izin'));
    $allowed = !empty($cek['allowed']);
    $ref = $refDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate) ? $refDate : date('Y-m-d');
    $mulai = date('Y-m-d', strtotime($ref . ' -' . max(0, $hari - 1) . ' days'));

    $cek['status'] = $allowed ? 'ok' : 'blocked';
    $cek['status_label'] = $allowed ? 'Masih boleh disetujui' : 'Terhalang syarat ALPA';
    $cek['periode_mulai'] = $mulai;
    $cek['periode_selesai'] = $ref;

    if ($max <= 0) {
        $cek['ringkasan'] = 'Tanpa batas ALPA';
        $cek['penjelasan'] = 'Pembatasan ALPA untuk ' . $jenisLabel . ' tidak diaktifkan (batas = 0).';
        $cek['aturan_singkat'] = 'Tidak ada batas';
        $cek['progress_pct'] = 0;
        $cek['progress_label'] = '';
        return $cek;
    }

    $batasBlokir = $max;
    $batasAman = max(0, $max - 1);
    $periodeTeks = $hari . ' hari terakhir';
    if ($mulai !== $ref) {
        $periodeTeks .= ' (' . date('d/m', strtotime($mulai)) . '–' . date('d/m', strtotime($ref)) . ')';
    } else {
        $periodeTeks .= ' (' . date('d/m', strtotime($ref)) . ')';
    }

    $jumlahTeks = $count . ' kali ALPA';
    $cek['jumlah_teks'] = $jumlahTeks;
    $cek['periode_teks'] = $periodeTeks;
    $cek['aturan_singkat'] = 'Batas ' . $jenisLabel . ': maks. ' . $batasAman . ' kali ALPA';
    $cek['aturan_blokir'] = 'Diblokir jika sudah ' . $batasBlokir . ' kali ALPA dalam ' . $hari . ' hari';
    $cek['progress_pct'] = (int) min(100, round(($count / max(1, $batasBlokir)) * 100));
    $cek['progress_label'] = $count . ' / ' . $batasBlokir . ' (ambang blokir)';

    if ($allowed) {
        $sisa = max(0, $batasBlokir - $count - 1);
        $cek['ringkasan'] = $jumlahTeks . ' · ' . $periodeTeks . ' · masih boleh';
        $cek['penjelasan'] = 'Santri tercatat **' . $jumlahTeks . '** dalam **' . $periodeTeks . '**. '
            . 'Untuk **' . $jenisLabel . '**, izin masih boleh disetujui selama ALPA **kurang dari ' . $batasBlokir . ' kali** '
            . '(maks. **' . $batasAman . ' kali**).';
        if ($sisa === 0 && $count < $batasBlokir) {
            $cek['catatan'] = 'Perhatian: jika ALPA bertambah 1 lagi, persetujuan otomatis terhalang.';
        } elseif ($sisa > 0) {
            $cek['catatan'] = 'Masih tersisa toleransi ' . $sisa . ' kali ALPA sebelum terhalang.';
        } else {
            $cek['catatan'] = '';
        }
    } else {
        $cek['ringkasan'] = $jumlahTeks . ' · ' . $periodeTeks . ' · terhalang';
        $cek['penjelasan'] = 'Santri tercatat ' . $jumlahTeks . ' dalam ' . $periodeTeks . '. '
            . 'Untuk ' . $jenisLabel . ', batasnya kurang dari ' . $batasBlokir . ' kali ALPA '
            . '(maks. ' . $batasAman . ' kali). Saat ini sudah ' . $count . ' kali — belum memenuhi syarat otomatis.';
        $cek['catatan'] = 'Anda tetap dapat mengirim permohonan; pengasuh pondok yang menilai.';
        if (trim((string) ($cek['message'] ?? '')) === '') {
            $cek['message'] = 'Santri sudah ' . $count . ' kali ALPA dalam ' . $hari . ' hari. '
                . 'Batas ' . $jenisLabel . ': maks. ' . $batasAman . ' kali (blokir dari ' . $batasBlokir . ' kali).';
        }
    }

    return $cek;
}

/** @param array<string, mixed> $cek */
function perizinan_alpa_penjelasan_plain(array $cek): string
{
    $txt = trim((string) ($cek['penjelasan'] ?? ''));
    if ($txt === '') {
        return trim((string) ($cek['message'] ?? ''));
    }

    return str_replace('**', '', $txt);
}

/** Cocokkan tingkatan santri dengan tingkatan jadwal/asuhan (termasuk alias kelas keuangan). */
function perizinan_pembimbing_tingkatan_cocok(PDO $pdo, string $santriTk, string $jadwalTk): bool
{
    $s = trim($santriTk);
    $j = trim($jadwalTk);
    if ($j === '') {
        return false;
    }
    if (strcasecmp($j, 'Semua Tingkatan') === 0) {
        return $s !== '';
    }
    if ($s === $j || strcasecmp($s, $j) === 0) {
        return true;
    }
    if (function_exists('kelas_keuangan_resolve_kode')) {
        ensure_kelas_keuangan_table($pdo);
        $codeS = kelas_keuangan_resolve_kode($pdo, $s) ?? $s;
        $codeJ = kelas_keuangan_resolve_kode($pdo, $j) ?? $j;
        if (strcasecmp($codeS, $codeJ) === 0) {
            return true;
        }
        if (strcasecmp($codeS, $j) === 0 || strcasecmp($s, $codeJ) === 0) {
            return true;
        }
    }

    return false;
}

function perizinan_pembimbing_no_wa(PDO $pdo, int $pembimbingId): string
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'pembimbing')) {
        return '';
    }
    $st = $pdo->prepare('SELECT no_wa, nip FROM pembimbing WHERE id = :id AND COALESCE(is_aktif, 1) = 1 LIMIT 1');
    $st->execute(['id' => $pembimbingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }
    $wa = normalize_wa_phone(trim((string) ($row['no_wa'] ?? '')));
    if ($wa !== '') {
        return $wa;
    }
    $nip = trim((string) ($row['nip'] ?? ''));
    if ($nip !== '' && table_exists($pdo, 'users') && column_exists($pdo, 'users', 'no_wa')) {
        $stU = $pdo->prepare('SELECT no_wa FROM users WHERE TRIM(username) = :nip LIMIT 1');
        $stU->execute(['nip' => $nip]);
        $wa = normalize_wa_phone(trim((string) ($stU->fetchColumn() ?: '')));
        if ($wa !== '') {
            return $wa;
        }
    }

    return '';
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

    if (table_exists($pdo, 'jadwal_kegiatan')) {
        $rows = $pdo->query('
            SELECT DISTINCT pembimbing_id, tingkatan
            FROM jadwal_kegiatan
            WHERE pembimbing_id IS NOT NULL AND pembimbing_id > 0
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if ($tingkatan === '' || perizinan_pembimbing_tingkatan_cocok($pdo, $tingkatan, (string) ($row['tingkatan'] ?? ''))) {
                $id = (int) ($row['pembimbing_id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
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
              AND COALESCE(ps.is_aktif, 1) = 1
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

    if ($tingkatan !== '' && table_exists($pdo, 'akademik_setoran_pembimbing_tingkatan')) {
        $rows = $pdo->query('
            SELECT DISTINCT pembimbing_id, tingkatan
            FROM akademik_setoran_pembimbing_tingkatan
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (perizinan_pembimbing_tingkatan_cocok($pdo, $tingkatan, (string) ($row['tingkatan'] ?? ''))) {
                $id = (int) ($row['pembimbing_id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
    }

    if ($ids === [] && table_exists($pdo, 'pembimbing')) {
        require_once __DIR__ . '/pembimbing_dashboard.php';
        require_once __DIR__ . '/pembimbing_pkpps.php';
        $allPb = $pdo->query('SELECT id FROM pembimbing WHERE COALESCE(is_aktif, 1) = 1')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($allPb as $pid) {
            $pid = (int) $pid;
            if ($pid > 0 && pembimbing_dashboard_santri_dalam_scope($pdo, $santriId, $pid, false)) {
                $ids[] = $pid;
            }
        }
    }

    return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
}

/**
 * @return list<string> nomor WA (62…)
 */
function perizinan_pembimbing_wa_targets(PDO $pdo, int $santriId): array
{
    $rows = perizinan_pembimbing_wa_target_rows($pdo, $santriId);
    $phones = [];
    foreach ($rows as $row) {
        $wa = normalize_wa_phone(trim((string) ($row['phone'] ?? '')));
        if ($wa !== '') {
            $phones[$wa] = true;
        }
    }

    return array_keys($phones);
}

/**
 * Daftar pembimbing + nomor WA untuk konfirmasi sebelum kirim.
 *
 * @return list<array{id:int,nama:string,phone:string}>
 */
function perizinan_pembimbing_wa_target_rows(PDO $pdo, int $santriId): array
{
    perizinan_approval_ensure_schema($pdo);
    if (trim((string) app_setting($pdo, 'wa_izin_pembimbing_enabled', '1')) !== '1') {
        return [];
    }

    $rows = [];
    if (table_exists($pdo, 'pembimbing')) {
        $pbIds = perizinan_pembimbing_ids_untuk_santri($pdo, $santriId);
        if ($pbIds !== []) {
            $placeholders = implode(',', array_fill(0, count($pbIds), '?'));
            $st = $pdo->prepare(
                'SELECT id, nama_pembimbing, wa_izin_notif FROM pembimbing WHERE id IN (' . $placeholders . ') AND COALESCE(is_aktif, 1) = 1 ORDER BY ' . pembimbing_list_order_sql('')
            );
            $st->execute($pbIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if ((int) ($row['wa_izin_notif'] ?? 1) !== 1) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $rows[] = [
                    'id' => $id,
                    'nama' => trim((string) ($row['nama_pembimbing'] ?? '')),
                    'phone' => perizinan_pembimbing_no_wa($pdo, $id),
                ];
            }
        }
    }

    foreach (perizinan_pembimbing_wa_targets_legacy($pdo) as $idx => $legacy) {
        $rows[] = [
            'id' => -100 - $idx,
            'nama' => 'Penerima tambahan',
            'phone' => $legacy,
        ];
    }

    return $rows;
}

/**
 * @return list<array{nama:string,phone:string}>|null null = pakai otomatis dari database
 */
function perizinan_parse_wa_pembimbing_post(array $post): ?array
{
    if (!array_key_exists('wa_pb', $post)) {
        return null;
    }
    $raw = $post['wa_pb'];
    if (!is_array($raw)) {
        return [];
    }
    $targets = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (empty($row['send'])) {
            continue;
        }
        $phone = normalize_wa_phone(trim((string) ($row['phone'] ?? '')));
        if ($phone === '') {
            continue;
        }
        $targets[] = [
            'nama' => trim((string) ($row['nama'] ?? '')),
            'phone' => $phone,
        ];
    }

    return $targets;
}

/** Nomor tambahan dari pengaturan lama (wa_izin_pembimbing_grup). */
function perizinan_pembimbing_wa_targets_legacy(PDO $pdo): array
{
    if (trim((string) app_setting($pdo, 'wa_izin_pembimbing_kirim_grup', '0')) !== '1') {
        return [];
    }
    $raw = trim((string) app_setting($pdo, 'wa_izin_pembimbing_grup', ''));
    if ($raw === '') {
        return [];
    }
    $phones = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $ph) {
        $wa = normalize_wa_phone(trim((string) $ph));
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
        'SELECT nama_pembimbing FROM pembimbing WHERE id IN (' . $placeholders . ') AND COALESCE(is_aktif, 1) = 1 ORDER BY ' . pembimbing_list_order_sql('')
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

/** Sisipkan tanda tangan penyutuju; append otomatis jika template belum punya placeholder {ttd_penyetuju}. */
function perizinan_wa_sisipkan_ttd_penyetuju(PDO $pdo, string $slug, string $pesan, int $approvedByUserId = 0): string
{
    $penyetuju = perizinan_wa_vars_penyetuju($pdo, $approvedByUserId);
    $pesan = perizinan_wa_ganti_ttd_nama_ponpes_ke_penyetuju($pesan, $penyetuju['nama_penyetuju']);

    $rawTpl = wa_template_get($pdo, $slug);
    if (str_contains($rawTpl, '{ttd_penyetuju}') || str_contains($rawTpl, '{nama_penyetuju}')) {
        return $pesan;
    }

    return $pesan . $penyetuju['ttd_penyetuju'];
}

/**
 * Variabel tanda tangan akun yang menyetujui izin.
 *
 * @return array{nama_penyetuju: string, nama_pengurus: string, ttd_penyetuju: string, disetujui_oleh_baris: string}
 */
function perizinan_wa_vars_penyetuju(PDO $pdo, int $userId = 0): array
{
    $nama = $userId > 0 ? perizinan_nama_pengurus_by_id($pdo, $userId) : 'Pengurus';

    return [
        'nama_penyetuju' => $nama,
        'nama_pengurus' => $nama,
        'ttd_penyetuju' => "\n\n_Hormat kami,_\n_{$nama}_",
        'disetujui_oleh_baris' => 'Disetujui oleh: *' . $nama . "*\n",
    ];
}

/**
 * @param array<string, string> $vars
 * @return array<string, string>
 */
function perizinan_wa_vars_disetujui(string $jenisRaw, array $vars, ?PDO $pdo = null, int $approvedByUserId = 0): array
{
    $waJenis = perizinan_jenis_wa_disetujui_vars($jenisRaw);
    foreach ($waJenis as $key => $value) {
        $vars[$key] = $value;
    }

    if ($pdo !== null) {
        $penyetuju = perizinan_wa_vars_penyetuju($pdo, $approvedByUserId);
        $vars['nama_penyetuju'] = $penyetuju['nama_penyetuju'];
        $vars['ttd_penyetuju'] = $penyetuju['ttd_penyetuju'];
        $vars['disetujui_oleh_baris'] = $penyetuju['disetujui_oleh_baris'];
        if (trim((string) ($vars['nama_pengurus'] ?? '')) === '') {
            $vars['nama_pengurus'] = $penyetuju['nama_pengurus'];
        }
    }

    return $vars;
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
    string $daftarSantri = '',
    int $approvedByUserId = 0
): string {
    $jenisCek = $jenisRaw !== '' ? $jenisRaw : $jenisLabel;
    $baseSlug = 'izin_disetujui_pembimbing';
    $slug = wa_template_slug_izin_disetujui($baseSlug, $jenisCek);
    $pesan = wa_template_render_izin_disetujui($pdo, $baseSlug, $jenisCek, perizinan_wa_vars_disetujui($jenisCek, [
        'nama_santri' => $namaSantri,
        'daftar_santri' => '',
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'jenis_izin' => perizinan_jenis_wa_label($jenisCek),
        'tanggal_mulai' => $tanggalMulai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => $alasan,
        'nama_pembimbing' => $namaPembimbing,
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'doa' => '',
    ], $pdo, $approvedByUserId));
    $pesan = perizinan_wa_sisipkan_blok($pdo, $slug, $pesan, 'daftar_santri', $daftarSantri);
    $pesan = perizinan_wa_sisipkan_doa($pdo, $slug, $pesan, $jenisCek, $namaSantri);

    return perizinan_wa_sisipkan_ttd_penyetuju($pdo, $slug, $pesan, $approvedByUserId);
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

/** @return 'putra'|'putri'|null */
function perizinan_santri_kelompok(PDO $pdo, array $izinRow): ?string
{
    require_once __DIR__ . '/jadwal_jamaah.php';

    $jk = trim((string) ($izinRow['jenis_kelamin'] ?? ''));
    if ($jk !== '') {
        return strcasecmp($jk, 'Perempuan') === 0 ? 'putri' : 'putra';
    }

    $tingkatan = trim((string) ($izinRow['tingkatan'] ?? ''));
    if ($tingkatan !== '') {
        $kel = jadwal_tingkatan_kelompok_dari_nama($tingkatan);
        if ($kel === 'putra' || $kel === 'putri') {
            return $kel;
        }
    }

    $santriId = (int) ($izinRow['santri_id'] ?? 0);
    if ($santriId > 0 && table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'jenis_kelamin')) {
        $st = $pdo->prepare('SELECT jenis_kelamin, tingkatan FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return perizinan_santri_kelompok($pdo, $row);
        }
    }

    return null;
}

/** @param list<array<string,mixed>> $anggota @return list<'putra'|'putri'> */
function perizinan_kelompok_untuk_anggota(PDO $pdo, array $anggota): array
{
    $kelompok = [];
    foreach ($anggota as $ang) {
        if (!is_array($ang)) {
            continue;
        }
        $k = perizinan_santri_kelompok($pdo, $ang);
        if ($k === 'putra' || $k === 'putri') {
            $kelompok[$k] = true;
        }
    }

    return array_keys($kelompok);
}

/**
 * Daftar kelompok penerima WA pengurus dari data izin atau anggota rombongan.
 *
 * @param list<array<string,mixed>>|null $anggota
 * @return list<'putra'|'putri'|null>
 */
function perizinan_wa_pengurus_kelompok_dari_izin(PDO $pdo, array $izinRow, ?array $anggota = null): array
{
    if ($anggota !== null && $anggota !== []) {
        $list = perizinan_kelompok_untuk_anggota($pdo, $anggota);

        return $list !== [] ? $list : [null];
    }

    $k = perizinan_santri_kelompok($pdo, $izinRow);

    return $k !== null ? [$k] : [null];
}

/** @param list<'putra'|'putri'|null>|null $kelompokList @return list<string> */
function perizinan_wa_pengurus_phone_list(PDO $pdo, int $approvedByUserId = 0, ?array $kelompokList = null): array
{
    $phones = [];
    if ($kelompokList === null || $kelompokList === []) {
        $kelompokList = [null];
    }

    foreach ($kelompokList as $kelompok) {
        if ($kelompok === null || $kelompok === '') {
            $setting = wa_izin_pengurus_target($pdo);
        } else {
            $setting = wa_izin_pengurus_target_kelompok($pdo, (string) $kelompok);
        }
        if ($setting === '') {
            continue;
        }
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
    $st = $pdo->prepare('SELECT nama, username FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Pengurus';
    }
    $nama = trim((string) ($row['nama'] ?? ''));
    if ($nama !== '') {
        return $nama;
    }
    $username = trim((string) ($row['username'] ?? ''));

    return $username !== '' ? $username : 'Pengurus';
}

/** Ganti baris tanda tangan lama (nama pondok) menjadi nama akun penyutuju. */
function perizinan_wa_ganti_ttd_nama_ponpes_ke_penyetuju(string $pesan, string $namaPenyetuju): string
{
    if ($namaPenyetuju === '' || !str_contains($pesan, 'Hormat kami')) {
        return $pesan;
    }

    $fixed = preg_replace(
        '/(_Hormat kami,_\s*\n)_([^_\n]+)_/u',
        '$1_' . $namaPenyetuju . '_',
        $pesan,
        1
    );

    return is_string($fixed) ? $fixed : $pesan;
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
    string $daftarSantri = '',
    string $jenisRaw = '',
    int $approvedByUserId = 0
): string {
    $jenisCek = $jenisRaw !== '' ? $jenisRaw : $jenisLabel;
    $baseSlug = 'izin_disetujui_pengurus';
    $slug = wa_template_slug_izin_disetujui($baseSlug, $jenisCek);
    $pesan = wa_template_render_izin_disetujui($pdo, $baseSlug, $jenisCek, perizinan_wa_vars_disetujui($jenisCek, [
        'nama_santri' => $namaSantri,
        'daftar_santri' => '',
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'jenis_izin' => perizinan_jenis_wa_label($jenisCek),
        'tanggal_mulai' => $tanggalMulai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => $alasan,
        'nama_pengurus' => $namaPengurus !== '' ? $namaPengurus : 'Pengurus',
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ], $pdo, $approvedByUserId));

    $pesan = perizinan_wa_sisipkan_blok($pdo, $slug, $pesan, 'daftar_santri', $daftarSantri);

    return perizinan_wa_sisipkan_ttd_penyetuju($pdo, $slug, $pesan, $approvedByUserId);
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
    string $daftarSantri = '',
    ?array $anggotaRombongan = null
): int {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin') || !wa_izin_pengurus_enabled($pdo)) {
        return 0;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return 0;
    }

    $kelompokList = perizinan_wa_pengurus_kelompok_dari_izin($pdo, $izinRow, $anggotaRombongan);
    $phones = perizinan_wa_pengurus_phone_list($pdo, $approvedByUserId, $kelompokList);
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
        $daftarSantri,
        $jenisRaw,
        $approvedByUserId
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

    $st = $pdo->prepare('
        SELECT i.jenis_izin, i.waktu_kembali, i.approved_by, i.santri_id,
               s.nama_santri, s.nis, s.tingkatan, s.jenis_kelamin
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

    $kelompokList = perizinan_wa_pengurus_kelompok_dari_izin($pdo, $row);
    $phones = perizinan_wa_pengurus_phone_list($pdo, (int) ($row['approved_by'] ?? 0), $kelompokList);
    if ($phones === []) {
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
    string $jamSelesai,
    int $approvedByUserId = 0
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
        $jenisRaw,
        $tglSelesai,
        $jamSelesai,
        (string) ($izinRow['alasan'] ?? '-'),
        $tglMulai,
        $jamMulai,
        $approvedByUserId
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
    int $approvedByUserId = 0,
    ?array $pembimbingOverrides = null
): array {
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'izin')) {
        return perizinan_wa_kirim_ringkasan(0, 0, 0);
    }

    $sentPb = 0;
    $santriId = (int) ($izinRow['santri_id'] ?? 0);
    $jenisRaw = strtoupper((string) ($izinRow['jenis_izin'] ?? 'KELUAR'));

    if ($pembimbingOverrides !== null) {
        if ($pembimbingOverrides !== [] && wa_otomatis_gateway_error($pdo) === null) {
            foreach ($pembimbingOverrides as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $phone = normalize_wa_phone(trim((string) ($target['phone'] ?? '')));
                if ($phone === '') {
                    continue;
                }
                $namaPb = trim((string) ($target['nama'] ?? ''));
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
                    $namaPb !== '' ? $namaPb : 'Pembimbing',
                    $jenisRaw,
                    '',
                    $approvedByUserId
                );
                if (send_wa_message($pdo, $phone, $msg)) {
                    $sentPb++;
                }
            }
        }
    } else {
        $phones = perizinan_pembimbing_wa_targets($pdo, $santriId);
        if ($phones !== [] && wa_otomatis_gateway_error($pdo) === null) {
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
                $jenisRaw,
                '',
                $approvedByUserId
            );
            $sentPb = send_wa_bulk($pdo, implode(',', $phones), $msg);
        }
    }

    $sentGrup = perizinan_kirim_wa_grup_fonte($pdo, $izinRow, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai, '', $approvedByUserId);
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
            $daftarSantri,
            $approvedByUserId
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

    $sentGrup = perizinan_kirim_wa_grup_fonte($pdo, $izinRowGrup, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai, $daftarSantri, $approvedByUserId);
    $sentPg = perizinan_kirim_wa_pengurus_disetujui(
        $pdo,
        $izinRowGrup,
        $tglMulai,
        $tglSelesai,
        $jamMulai,
        $jamSelesai,
        $approvedByUserId,
        $daftarSantri,
        $anggota
    );

    foreach ($anggota as $ang) {
        $izinRowWali = [
            'santri_id' => (int) ($ang['santri_id'] ?? 0),
            'nama_santri' => (string) ($ang['nama_santri'] ?? '-'),
            'jenis_izin' => $jenisRaw,
            'alasan' => $alasan,
        ];
        perizinan_kirim_wa_wali_disetujui($pdo, $izinRowWali, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai, $approvedByUserId);
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
    string $daftarSantri = '',
    int $approvedByUserId = 0
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
    $baseSlug = 'izin_grup_fonte';
    $slug = wa_template_slug_izin_disetujui($baseSlug, $jenisRaw);
    $pesan = wa_template_render_izin_disetujui($pdo, $baseSlug, $jenisRaw, perizinan_wa_vars_disetujui($jenisRaw, [
        'nama_santri' => $namaSantri,
        'daftar_santri' => '',
        'nis' => trim((string) ($izinRow['nis'] ?? '')),
        'tingkatan' => trim((string) ($izinRow['tingkatan'] ?? '')),
        'jenis_izin' => perizinan_jenis_wa_label($jenisRaw),
        'tanggal_mulai' => $tglMulai,
        'tanggal_selesai' => $tglSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => (string) ($izinRow['alasan'] ?? '-'),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'doa' => '',
    ], $pdo, $approvedByUserId));
    $pesan = perizinan_wa_sisipkan_blok($pdo, $slug, $pesan, 'daftar_santri', $daftarSantri);
    $msg = perizinan_wa_sisipkan_doa($pdo, $slug, $pesan, $jenisRaw, $namaSantri);
    $msg = perizinan_wa_sisipkan_ttd_penyetuju($pdo, $slug, $msg, $approvedByUserId);

    return send_wa_bulk($pdo, $grupId, $msg);
}

/**
 * Validasi ALPA sebelum setujui; kembalikan pesan error atau null jika lolos.
 */
function perizinan_perlu_cek_alpa(string $jenisIzin): bool
{
    return strtoupper(trim($jenisIzin)) !== 'SAKIT';
}

/** @return list<int> */
function perizinan_alpa_bypass_user_ids(PDO $pdo): array
{
    $raw = trim((string) app_setting($pdo, 'izin_alpa_bypass_user_ids', ''));
    if ($raw === '') {
        return [];
    }
    $ids = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/** Admin super atau admin ditunjuk di pengaturan boleh lewati syarat ALPA. */
function perizinan_user_boleh_bypass_alpa(PDO $pdo, ?int $userId = null): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }
    $uid = $userId ?? (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    return in_array($uid, perizinan_alpa_bypass_user_ids($pdo), true);
}

/** Pengasuh (dan admin/pengurus di menu pengasuh) boleh lewati ALPA izin syar'i lewat centang. */
function perizinan_pengasuh_boleh_bypass_alpa(PDO $pdo, ?int $userId = null): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (in_array($role, ['kiai', 'admin', 'pengurus'], true)) {
        return true;
    }

    return perizinan_user_boleh_bypass_alpa($pdo, $userId);
}

/** @param array<string, mixed> $post */
function perizinan_request_bypass_alpa(PDO $pdo, array $post): bool
{
    if (!perizinan_user_boleh_bypass_alpa($pdo)) {
        return false;
    }

    return isset($post['bypass_alpa']) && (string) $post['bypass_alpa'] === '1';
}

/** @param array<string, mixed> $post */
function perizinan_request_bypass_alpa_pengasuh(PDO $pdo, array $post): bool
{
    if (!perizinan_pengasuh_boleh_bypass_alpa($pdo)) {
        return false;
    }

    return isset($post['bypass_alpa']) && (string) $post['bypass_alpa'] === '1';
}

function perizinan_validasi_setujui_alpa(PDO $pdo, int $santriId, string $jenisIzin, bool $bypassAlpa, bool $konteksPengasuh = false, ?string $syariKategori = null): ?string
{
    if (!perizinan_perlu_cek_alpa($jenisIzin)) {
        return null;
    }
    if ($bypassAlpa) {
        if ($konteksPengasuh && perizinan_pengasuh_boleh_bypass_alpa($pdo)) {
            return null;
        }
        if (!perizinan_user_boleh_bypass_alpa($pdo)) {
            return 'Anda tidak berwenang melewati syarat ALPA.';
        }

        return null;
    }
    $cek = perizinan_alpa_cek_approval($pdo, $santriId, $jenisIzin, null, $syariKategori);
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
            $st = $pdo->prepare('SELECT nama, username FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $byId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($u)) {
                $namaUser = trim((string) ($u['nama'] ?? ''));
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
 * Finalisasi persetujuan izin individu (QR, status aktif, notifikasi).
 *
 * @param array<string, mixed> $izinInfo
 * @param array<string, mixed> $jadwal
 * @return array{ok:bool,message:string,wa:array{pembimbing:int,grup:int,pengurus:int,total:int}}
 */
function perizinan_setujui_izin_satu(
    PDO $pdo,
    array $izinInfo,
    int $userId,
    bool $bypassAlpa = false,
    array $jadwal = [],
    bool $stampPengasuh = false,
    ?array $waPembimbingOverrides = null
): array {
    require_once __DIR__ . '/push_events.php';

    $id = (int) ($izinInfo['id'] ?? 0);
    if ($id <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.', 'wa' => ['pembimbing' => 0, 'grup' => 0, 'pengurus' => 0, 'total' => 0]];
    }

    $tglMulai = trim((string) ($jadwal['tanggal_mulai'] ?? $izinInfo['tanggal_mulai'] ?? date('Y-m-d')));
    $tglSelesai = trim((string) ($jadwal['tanggal_selesai'] ?? $izinInfo['tanggal_selesai'] ?? date('Y-m-d')));
    $jamMulai = trim((string) ($jadwal['jam_mulai'] ?? substr((string) ($izinInfo['jam_mulai'] ?? '00:00'), 0, 5)));
    $jamSelesai = trim((string) ($jadwal['jam_selesai'] ?? substr((string) ($izinInfo['jam_selesai'] ?? '00:00'), 0, 5)));
    $durasiRaw = $jadwal['durasi_jam'] ?? ($izinInfo['durasi_jam'] ?? '');
    $durasi = $durasiRaw === '' || $durasiRaw === null ? 0.0 : (float) $durasiRaw;

    $tsMulai = strtotime($tglMulai . ' ' . $jamMulai);
    $tsSelesai = strtotime($tglSelesai . ' ' . $jamSelesai);
    if ($tsMulai !== false && $tsSelesai !== false && $tsSelesai < $tsMulai) {
        return ['ok' => false, 'message' => 'Waktu selesai harus sesudah waktu mulai. Periksa kembali tanggal/jam.', 'wa' => ['pembimbing' => 0, 'grup' => 0, 'pengurus' => 0, 'total' => 0]];
    }

    $qrToken = trim((string) ($izinInfo['qr_token'] ?? ''));
    if ($qrToken === '') {
        $qrToken = bin2hex(random_bytes(16));
    }

    $pengasuhSql = $stampPengasuh
        ? ', pengasuh_approved_by = :uid, pengasuh_approved_at = NOW()'
        : '';

    $ap = $pdo->prepare('
        UPDATE perizinan
           SET approval_status = "DISETUJUI",
               approved_by = :uid,
               approved_at = NOW(),
               approved_bypass_alpa = :bypass,
               rejected_reason = NULL,
               qr_token = :qr_token,
               status_izin = "IZIN",
               tanggal_mulai = :tanggal_mulai,
               tanggal_selesai = :tanggal_selesai,
               jam_mulai = :jam_mulai,
               jam_selesai = :jam_selesai,
               durasi_jam = :durasi_jam' . $pengasuhSql . '
         WHERE id = :id
    ');
    $ap->execute([
        'uid' => $userId,
        'bypass' => $bypassAlpa ? 1 : 0,
        'qr_token' => $qrToken,
        'tanggal_mulai' => $tglMulai,
        'tanggal_selesai' => $tglSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'durasi_jam' => $durasi,
        'id' => $id,
    ]);

    $pdo->prepare('UPDATE santri s INNER JOIN perizinan i ON i.santri_id = s.id SET s.is_aktif = 0 WHERE i.id = :id')
        ->execute(['id' => $id]);

    $santriId = (int) ($izinInfo['santri_id'] ?? 0);
    $jenisIzinRaw = strtoupper((string) ($izinInfo['jenis_izin'] ?? ''));
    push_event_izin_disetujui_wali(
        $pdo,
        $santriId,
        (string) ($izinInfo['nama_santri'] ?? '-'),
        $jenisIzinRaw,
        $tglSelesai,
        $jamSelesai
    );
    perizinan_kirim_wa_wali_disetujui($pdo, $izinInfo, $tglMulai, $tglSelesai, $jamMulai, $jamSelesai, $userId);
    $waRingkasan = perizinan_kirim_wa_pembimbing_disetujui(
        $pdo,
        $izinInfo,
        $tglMulai,
        $tglSelesai,
        $jamMulai,
        $jamSelesai,
        $userId,
        $waPembimbingOverrides
    );

    $flashMsg = $stampPengasuh
        ? 'Izin syar\'i disetujui pengasuh. QR digital aktif — pengurus tinggal cetak surat.'
        : 'Izin disetujui. QR digital aktif dan surat siap dicetak.';
    if ($bypassAlpa) {
        $flashMsg .= ' (Syarat ALPA dilewati.)';
    }
    $flashMsg .= perizinan_wa_flash_kirim_disetujui($waRingkasan);
    if ($waRingkasan['total'] === 0 && wa_izin_grup_fonte_targets($pdo) !== '' && !wa_izin_grup_fonte_enabled($pdo)) {
        $flashMsg .= ' (Kirim grup nonaktif — aktifkan di Pengaturan → WA Otomatis → Izin.)';
    } elseif ($waRingkasan['total'] === 0 && wa_izin_grup_fonte_targets($pdo) === '' && trim((string) app_setting($pdo, 'wa_izin_pembimbing_enabled', '1')) === '1') {
        $pbIds = perizinan_pembimbing_ids_untuk_santri($pdo, $santriId);
        if ($pbIds === []) {
            $flashMsg .= ' (Tidak ada pembimbing terkait santri — periksa jadwal/PKPPS/setoran.)';
        } else {
            $flashMsg .= ' (Pembimbing ditemukan tetapi WA belum terkirim — isi no. WA pembimbing & aktifkan notif izin.)';
        }
    }

    return ['ok' => true, 'message' => $flashMsg, 'wa' => $waRingkasan];
}

/** @return array<string, mixed>|null */
function perizinan_fetch_izin_dengan_santri(PDO $pdo, int $izinId): ?array
{
    perizinan_approval_ensure_schema($pdo);
    if ($izinId <= 0 || !table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return null;
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare('
        SELECT i.id, i.santri_id, i.jenis_izin, i.syari_kategori, i.tanggal_mulai, i.tanggal_selesai,
               i.jam_mulai, i.jam_selesai, i.durasi_jam, i.alasan, i.tujuan, i.qr_token,
               i.approval_status, i.pengasuh_approved_at,
               s.' . $nameCol . ' AS nama_santri, s.nis, s.tingkatan, s.jenis_kelamin, s.no_wa_wali
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Finalisasi otomatis setelah input: sakit/keluar/tugas langsung DISETUJUI + notifikasi.
 * Izin syar'i (wali) mengembalikan null — tetap menunggu pengasuh.
 *
 * @return array{ok:bool,message:string,wa?:array<string,int>}|null
 */
function perizinan_finalisasi_setelah_input(PDO $pdo, int $izinId, int $userId): ?array
{
    require_once __DIR__ . '/perizinan_jenis.php';

    $row = perizinan_fetch_izin_dengan_santri($pdo, $izinId);
    if ($row === null) {
        return ['ok' => false, 'message' => 'Data izin tidak ditemukan.', 'wa' => ['pembimbing' => 0, 'grup' => 0, 'pengurus' => 0, 'total' => 0]];
    }
    if (!perizinan_langsung_disetujui_tanpa_persetujuan((string) ($row['jenis_izin'] ?? ''))) {
        return null;
    }
    if (strtoupper((string) ($row['approval_status'] ?? '')) === 'DISETUJUI') {
        return ['ok' => true, 'message' => 'Izin sudah aktif.', 'wa' => ['pembimbing' => 0, 'grup' => 0, 'pengurus' => 0, 'total' => 0]];
    }

    return perizinan_setujui_izin_satu($pdo, $row, $userId, false, [], false);
}

/** Finalisasi data lama: izin syar'i sudah distempel pengasuh tetapi masih PENDING. */
function perizinan_syari_backfill_finalize(PDO $pdo, int $izinId): bool
{
    perizinan_approval_ensure_schema($pdo);
    if ($izinId <= 0 || !table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return false;
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare("
        SELECT i.*, s.{$nameCol} AS nama_santri, s.nis, s.tingkatan, s.jenis_kelamin, s.no_wa_wali
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }
    if (!perizinan_memerlukan_persetujuan_pengasuh((string) ($row['jenis_izin'] ?? ''))) {
        return false;
    }
    if (strtoupper((string) ($row['approval_status'] ?? '')) !== 'PENDING') {
        return false;
    }
    if (trim((string) ($row['pengasuh_approved_at'] ?? '')) === '') {
        return false;
    }

    $pengasuhUid = (int) ($row['pengasuh_approved_by'] ?? 0);
    if ($pengasuhUid <= 0) {
        $pengasuhUid = (int) ($row['approved_by'] ?? 1);
    }
    $res = perizinan_setujui_izin_satu($pdo, $row, $pengasuhUid, false, [], false);

    return $res['ok'];
}

/**
 * @return array{ok:bool,message:string}
 */
function perizinan_pengasuh_setujui(PDO $pdo, int $izinId, int $userId, bool $bypassAlpa = false): array
{
    perizinan_approval_ensure_schema($pdo);
    if ($izinId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return ['ok' => false, 'message' => 'Modul perizinan belum siap.'];
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare("
        SELECT i.id, i.santri_id, i.jenis_izin, i.syari_kategori, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai,
               i.durasi_jam, i.alasan, i.qr_token, i.approval_status, i.pengasuh_approved_at,
               s.{$nameCol} AS nama_santri, s.nis, s.tingkatan, s.jenis_kelamin, s.no_wa_wali
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
        LIMIT 1
    ");
    $st->execute(['id' => $izinId]);
    $izinInfo = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($izinInfo)) {
        return ['ok' => false, 'message' => 'Permohonan izin tidak ditemukan.'];
    }
    if (!perizinan_memerlukan_persetujuan_pengasuh((string) ($izinInfo['jenis_izin'] ?? ''))) {
        return ['ok' => false, 'message' => 'Hanya izin syar\'i yang memerlukan persetujuan pengasuh di menu ini.'];
    }
    if (strtoupper((string) ($izinInfo['approval_status'] ?? '')) !== 'PENDING') {
        return ['ok' => false, 'message' => 'Hanya permohonan menunggu yang dapat disetujui pengasuh.'];
    }
    if (trim((string) ($izinInfo['pengasuh_approved_at'] ?? '')) !== '') {
        return ['ok' => false, 'message' => 'Permohonan ini sudah disetujui pengasuh.'];
    }

    $santriId = (int) ($izinInfo['santri_id'] ?? 0);
    $jenisIzinRaw = strtoupper((string) ($izinInfo['jenis_izin'] ?? ''));
    $syariKat = trim((string) ($izinInfo['syari_kategori'] ?? ''));
    $alpaErr = perizinan_validasi_setujui_alpa($pdo, $santriId, $jenisIzinRaw, $bypassAlpa, true, $syariKat !== '' ? $syariKat : null);
    if ($alpaErr !== null) {
        return ['ok' => false, 'message' => $alpaErr];
    }

    $res = perizinan_setujui_izin_satu($pdo, $izinInfo, $userId, $bypassAlpa, [], true);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => $res['message']];
    }

    return ['ok' => true, 'message' => $res['message']];
}

/**
 * @return array{ok:bool,message:string,jumlah:int}
 */
function perizinan_pengasuh_setujui_rombongan(PDO $pdo, int $rombonganId, int $userId, bool $bypassAlpa = false): array
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
    if (strtoupper((string) ($meta['approval_status'] ?? '')) !== 'PENDING') {
        return ['ok' => false, 'message' => 'Hanya permohonan menunggu yang dapat disetujui pengasuh.', 'jumlah' => 0];
    }

    $final = perizinan_rombongan_approve($pdo, $rombonganId, [], $userId, $bypassAlpa, true);
    if (!$final['ok']) {
        return ['ok' => false, 'message' => $final['message'], 'jumlah' => 0];
    }

    $jumlah = count(perizinan_rombongan_anggota($pdo, $rombonganId));

    return [
        'ok' => true,
        'message' => $final['message'],
        'jumlah' => $jumlah,
    ];
}

/**
 * Antrian izin syar'i untuk dashboard / pengasuh (individu + rombongan).
 *
 * @return array{
 *   total:int,
 *   individu:list<array<string,mixed>>,
 *   rombongan:list<array<string,mixed>>
 * }
 */
function perizinan_pengasuh_antrian(PDO $pdo, int $limitIndividu = 8): array
{
    require_once __DIR__ . '/perizinan_rombongan.php';
    perizinan_rombongan_ensure_schema($pdo);

    $pendingRows = perizinan_pengasuh_pending_list($pdo, max(20, $limitIndividu * 4));
    $rombonganPending = [];
    $rombonganSeen = [];
    $individu = [];

    foreach ($pendingRows as $row) {
        $rid = (int) ($row['rombongan_id'] ?? 0);
        if ($rid > 0) {
            if (!isset($rombonganSeen[$rid])) {
                $rombonganSeen[$rid] = true;
                $meta = perizinan_rombongan_meta($pdo, $rid);
                if ($meta && strtoupper((string) ($meta['approval_status'] ?? '')) === 'PENDING') {
                    $anggota = perizinan_rombongan_anggota($pdo, $rid);
                    $rombonganPending[] = [
                        'id' => $rid,
                        'jenis_izin' => (string) ($meta['jenis_izin'] ?? ''),
                        'tanggal_mulai' => (string) ($meta['tanggal_mulai'] ?? ''),
                        'tanggal_selesai' => (string) ($meta['tanggal_selesai'] ?? ''),
                        'jam_mulai' => (string) ($meta['jam_mulai'] ?? ''),
                        'jam_selesai' => (string) ($meta['jam_selesai'] ?? ''),
                        'alasan' => (string) ($meta['alasan'] ?? ''),
                        'jumlah' => count($anggota),
                    ];
                }
            }
            continue;
        }
        if (count($individu) < max(1, $limitIndividu)) {
            $individu[] = $row;
        }
    }

    return [
        'total' => perizinan_pengasuh_pending_count($pdo),
        'individu' => $individu,
        'rombongan' => $rombonganPending,
    ];
}

/**
 * Daftar permohonan menunggu persetujuan pengasuh.
 *
 * @return list<array<string, mixed>>
 */
function perizinan_pengasuh_pending_list(PDO $pdo, int $limit = 80): array
{
    perizinan_approval_ensure_schema($pdo);
    require_once __DIR__ . '/perizinan_syari_kategori.php';
    perizinan_syari_kategori_ensure_schema($pdo);
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
    $orderCol = column_exists($pdo, 'perizinan', 'created_at') ? 'i.created_at DESC' : 'i.id DESC';
    $st = $pdo->query("
        SELECT i.id, i.santri_id, i.jenis_izin, i.syari_kategori, i.tanggal_mulai, i.tanggal_selesai,
               i.jam_mulai, i.jam_selesai, i.alasan, i.created_at, i.rombongan_id,
               s.{$nameCol} AS nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktif}
        WHERE i.approval_status = 'PENDING'
          AND UPPER(TRIM(i.jenis_izin)) = '{$syari}'{$filterPengasuh}
        ORDER BY {$orderCol}
        LIMIT {$limit}
    ");

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/** Jumlah permohonan izin syar'i menunggu persetujuan pengasuh. */
function perizinan_pengasuh_pending_count(PDO $pdo): int
{
    perizinan_approval_ensure_schema($pdo);
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return 0;
    }
    require_once __DIR__ . '/santri_operasional.php';
    $aktif = santri_sql_aktif_only('s');
    $syari = perizinan_jenis_syari_kode();
    $hasPengasuhCol = column_exists($pdo, 'perizinan', 'pengasuh_approved_at');
    $filterPengasuh = $hasPengasuhCol ? ' AND i.pengasuh_approved_at IS NULL' : '';
    $cnt = $pdo->query("
        SELECT COUNT(*)
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktif}
        WHERE i.approval_status = 'PENDING'
          AND UPPER(TRIM(i.jenis_izin)) = '{$syari}'{$filterPengasuh}
    ")->fetchColumn();

    return (int) $cnt;
}

/**
 * Permohonan izin santri yang masih menunggu persetujuan (belum disetujui).
 *
 * @return array<string, mixed>|null
 */
function perizinan_santri_pending_row(PDO $pdo, int $santriId): ?array
{
    if ($santriId <= 0 || !table_exists($pdo, 'perizinan')) {
        return null;
    }
    perizinan_approval_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT id, jenis_izin, tanggal_mulai, tanggal_selesai, approval_status, alasan
        FROM perizinan
        WHERE santri_id = :sid AND approval_status = "PENDING"
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Pesan blokir pengajuan baru jika masih ada permohonan PENDING. */
function perizinan_pesan_blokir_pending(?array $pending): ?string
{
    if ($pending === null) {
        return null;
    }
    $id = (int) ($pending['id'] ?? 0);
    $jenis = jenis_izin_label((string) ($pending['jenis_izin'] ?? ''));
    $tgl1 = (string) ($pending['tanggal_mulai'] ?? '');
    $tgl2 = (string) ($pending['tanggal_selesai'] ?? '');
    $rentang = '';
    if ($tgl1 !== '') {
        $rentang = $tgl2 !== '' && $tgl2 !== $tgl1
            ? " ({$tgl1} – {$tgl2})"
            : " ({$tgl1})";
    }

    return 'Masih ada permohonan izin #' . $id . ' (' . $jenis . ')' . $rentang
        . ' yang belum disetujui. Tunggu persetujuan atau penolakan terlebih dahulu sebelum mengajukan izin baru.';
}

/** @return string|null pesan error jika pengajuan baru diblokir */
function perizinan_cek_blokir_pengajuan_baru(PDO $pdo, int $santriId): ?string
{
    return perizinan_pesan_blokir_pending(perizinan_santri_pending_row($pdo, $santriId));
}

/**
 * Daftar santri yang punya permohonan PENDING (satu baris terbaru per santri).
 *
 * @param list<int> $santriIds
 * @return array<int, array<string, mixed>>
 */
function perizinan_santri_pending_map(PDO $pdo, array $santriIds = []): array
{
    if (!table_exists($pdo, 'perizinan')) {
        return [];
    }
    perizinan_approval_ensure_schema($pdo);
    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn(int $id): bool => $id > 0)));
    $sql = '
        SELECT santri_id, id, jenis_izin, tanggal_mulai, tanggal_selesai, approval_status, alasan
        FROM perizinan
        WHERE approval_status = "PENDING"';
    $params = [];
    if ($santriIds !== []) {
        $ph = implode(',', array_fill(0, count($santriIds), '?'));
        $sql .= ' AND santri_id IN (' . $ph . ')';
        $params = $santriIds;
    }
    $sql .= ' ORDER BY id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0 && !isset($map[$sid])) {
            $map[$sid] = $row;
        }
    }

    return $map;
}
