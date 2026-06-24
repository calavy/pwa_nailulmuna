<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/rekap_periode.php';
require_once __DIR__ . '/presensi_jadwal.php';
require_once __DIR__ . '/rekap_keaktifan.php';
require_once __DIR__ . '/hijri_kalender.php';

function ensure_akademik_rapor_columns(PDO $pdo): void
{
    ensure_akademik_rapor_table($pdo);
    akademik_add_column($pdo, 'akademik_rapor', 'periode_mode', "VARCHAR(20) NULL DEFAULT 'hijriyah'");
    akademik_add_column($pdo, 'akademik_rapor', 'periode_bulan', 'TINYINT UNSIGNED NULL');
    akademik_add_column($pdo, 'akademik_rapor', 'periode_tahun', 'SMALLINT UNSIGNED NULL');
}

/** @return array{mode:string,month:int,year:int} */
function rapor_periode_default_dari_tanggal(PDO $pdo, string $tanggalTerbit): array
{
    $cal = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
    $mode = $cal === 'MASEHI' ? 'masehi' : 'hijriyah';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalTerbit)) {
        $tanggalTerbit = date('Y-m-d');
    }
    if ($mode === 'masehi') {
        return [
            'mode' => 'masehi',
            'month' => (int) date('n', strtotime($tanggalTerbit)),
            'year' => (int) date('Y', strtotime($tanggalTerbit)),
        ];
    }
    $h = konversiKeHijriah($pdo, $tanggalTerbit);
    if (is_array($h)) {
        return [
            'mode' => 'hijriyah',
            'month' => max(1, min(12, (int) ($h['bulan_hijriyah'] ?? 1))),
            'year' => (int) ($h['tahun_hijriah'] ?? akademik_hijri_anchor_hari_ini($pdo)['y']),
        ];
    }
    $anchor = akademik_hijri_anchor_hari_ini($pdo);

    return ['mode' => 'hijriyah', 'month' => (int) $anchor['m'], 'year' => (int) $anchor['y']];
}

/**
 * @param array<string,mixed> $row baris akademik_rapor
 * @return array{mode:string,month:int,year:int,start_date:string,end_date:string,label:string,hijri_label:string}
 */
function rapor_periode_dari_row(PDO $pdo, array $row): array
{
    $mode = strtolower(trim((string) ($row['periode_mode'] ?? '')));
    if (!in_array($mode, ['masehi', 'hijriyah'], true)) {
        $def = rapor_periode_default_dari_tanggal($pdo, (string) ($row['tanggal_terbit'] ?? date('Y-m-d')));
        $mode = $def['mode'];
        $month = $def['month'];
        $year = $def['year'];
    } else {
        $month = max(1, min(12, (int) ($row['periode_bulan'] ?? 0)));
        $year = (int) ($row['periode_tahun'] ?? 0);
        if ($month < 1 || $year < 1) {
            $def = rapor_periode_default_dari_tanggal($pdo, (string) ($row['tanggal_terbit'] ?? date('Y-m-d')));
            $month = $def['month'];
            $year = $def['year'];
        }
    }

    return rekap_resolve_periode($pdo, ['mode' => $mode, 'month' => $month, 'year' => $year]);
}

/**
 * @return array<string,mixed>|null satu santri dari rekap_keaktifan_build_per_santri
 */
function rapor_presensi_bulan(PDO $pdo, int $santriId, array $periode): ?array
{
    if ($santriId <= 0) {
        return null;
    }
    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $rows = presensi_fetch_rows_rekap_periode($pdo, $periode, 0);
    $filtered = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['santri_id'] ?? 0) === $santriId));
    if ($filtered === []) {
        return null;
    }
    $ranked = rekap_keaktifan_build_per_santri($filtered, $goodMax, $mediumMax);

    return $ranked[0] ?? null;
}

/**
 * Hasil tugas ikhtibar per pembimbing / mapel dalam rentang periode.
 *
 * @return list<array{pembimbing_nama:string,mapel_label:string,tugas:list<array<string,mixed>>}>
 */
function rapor_tugas_bulan(PDO $pdo, int $santriId, array $periode): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'ikhtibar_tugas')) {
        return [];
    }
    require_once __DIR__ . '/akademik_ikhtibar.php';
    ensure_akademik_ikhtibar_tables($pdo);

    $stmt = $pdo->prepare('
        SELECT
            t.id AS tugas_id,
            t.judul,
            t.tanggal,
            t.mapel_label,
            t.filter_tingkatan,
            k.nama_kegiatan,
            u.nama AS pembimbing_nama,
            ses.status AS sesi_status,
            ses.skor_pg,
            ses.skor_esai,
            ses.nilai_total,
            ses.waktu_mulai,
            ses.waktu_selesai
        FROM ikhtibar_sesi ses
        INNER JOIN ikhtibar_tugas t ON t.id = ses.tugas_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN kegiatan k ON k.id = t.kegiatan_id
        WHERE ses.santri_id = :sid
          AND t.tanggal BETWEEN :start AND :end
        ORDER BY u.nama ASC, t.mapel_label ASC, t.tanggal DESC, t.id DESC
    ');
    $stmt->execute([
        'sid' => $santriId,
        'start' => (string) $periode['start_date'],
        'end' => (string) $periode['end_date'],
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $groups = [];
    foreach ($rows as $r) {
        $mapel = trim((string) ($r['mapel_label'] ?? ''));
        if ($mapel === '') {
            $kg = trim((string) ($r['nama_kegiatan'] ?? ''));
            $tk = trim((string) ($r['filter_tingkatan'] ?? ''));
            $mapel = $kg !== '' ? $kg . ($tk !== '' ? ' — ' . $tk : '') : ($tk !== '' ? $tk : 'Umum');
        }
        $pem = trim((string) ($r['pembimbing_nama'] ?? ''));
        if ($pem === '') {
            $pem = 'Pembimbing';
        }
        $key = $pem . "\0" . $mapel;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'pembimbing_nama' => $pem,
                'mapel_label' => $mapel,
                'tugas' => [],
            ];
        }
        $groups[$key]['tugas'][] = [
            'judul' => (string) ($r['judul'] ?? ''),
            'tanggal' => (string) ($r['tanggal'] ?? ''),
            'sesi_status' => (string) ($r['sesi_status'] ?? ''),
            'skor_pg' => $r['skor_pg'],
            'skor_esai' => $r['skor_esai'],
            'nilai_total' => $r['nilai_total'],
            'waktu_mulai' => $r['waktu_mulai'],
            'waktu_selesai' => $r['waktu_selesai'],
        ];
    }

    return array_values($groups);
}

function rapor_sesi_status_label(string $status): string
{
    return match (strtolower($status)) {
        'selesai' => 'Selesai',
        'berjalan' => 'Sedang dikerjakan',
        'habis_waktu' => 'Waktu habis',
        'menunggu' => 'Belum mulai',
        default => ucfirst($status),
    };
}

function rapor_kategori_badge_class(string $kategori): string
{
    return match ($kategori) {
        'Bagus' => 'success',
        'Baik' => 'primary',
        'Sedang' => 'warning',
        'Buruk' => 'danger',
        default => 'secondary',
    };
}

/**
 * Setoran hafalan santri dalam rentang periode rapor.
 *
 * @return list<array<string,mixed>>
 */
function rapor_setoran_bulan(PDO $pdo, int $santriId, array $periode): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'akademik_hafalan_setoran')) {
        return [];
    }
    require_once __DIR__ . '/akademik.php';
    ensure_akademik_hafalan_setoran_table($pdo);

    $hasKat = column_exists($pdo, 'akademik_hafalan_setoran', 'kategori_setoran');
    $cols = 'h.id, h.tanggal_setoran, h.target_hafalan, h.juz_halaman, h.nilai_skor, h.predikat, h.catatan';
    if ($hasKat) {
        $cols .= ', h.kategori_setoran, h.baris_setor, h.kalender_hijriyah, k.nama_kitab AS bait_nama';
    } else {
        $cols .= ", 'ALQURAN' AS kategori_setoran, NULL AS baris_setor, NULL AS kalender_hijriyah, NULL AS bait_nama";
    }

    $sql = "
        SELECT {$cols}
        FROM akademik_hafalan_setoran h
    ";
    if ($hasKat && table_exists($pdo, 'akademik_bait_kitab')) {
        $sql .= ' LEFT JOIN akademik_bait_kitab k ON k.id = h.bait_kitab_id';
    }
    $sql .= ' WHERE h.santri_id = :sid AND h.tanggal_setoran BETWEEN :start AND :end
        ORDER BY h.tanggal_setoran DESC, h.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute([
        'sid' => $santriId,
        'start' => (string) $periode['start_date'],
        'end' => (string) $periode['end_date'],
    ]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function rapor_setoran_kategori_label(string $kat): string
{
    return strtoupper(trim($kat)) === 'BAIT' ? 'Bait (kitab)' : 'Al-Qur\'an';
}

/** Nama wali kelas dari riwayat tingkatan santri (TA terbaru, cocokkan tingkatan bila ada). */
function rapor_wali_kelas_santri(PDO $pdo, int $santriId, string $tingkatan = ''): string
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri_riwayat_tingkatan')) {
        return '';
    }
    require_once __DIR__ . '/santri_riwayat.php';
    ensure_santri_riwayat_tables($pdo);
    if (!column_exists($pdo, 'santri_riwayat_tingkatan', 'wali_kelas')) {
        return '';
    }

    $tingkatan = trim($tingkatan);
    if ($tingkatan !== '') {
        $st = $pdo->prepare('
            SELECT wali_kelas FROM santri_riwayat_tingkatan
            WHERE santri_id = :sid AND tingkatan = :tg
              AND wali_kelas IS NOT NULL AND TRIM(wali_kelas) <> ""
            ORDER BY tahun_ajaran_mulai DESC, id DESC
            LIMIT 1
        ');
        $st->execute(['sid' => $santriId, 'tg' => $tingkatan]);
        $wk = trim((string) ($st->fetchColumn() ?: ''));
        if ($wk !== '') {
            return $wk;
        }
    }

    $st2 = $pdo->prepare('
        SELECT wali_kelas FROM santri_riwayat_tingkatan
        WHERE santri_id = :sid AND wali_kelas IS NOT NULL AND TRIM(wali_kelas) <> ""
        ORDER BY tahun_ajaran_mulai DESC, id DESC
        LIMIT 1
    ');
    $st2->execute(['sid' => $santriId]);

    return trim((string) ($st2->fetchColumn() ?: ''));
}
