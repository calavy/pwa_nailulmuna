<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/santri_keaktifan_nilai.php';
require_once __DIR__ . '/santri_riwayat.php';
require_once __DIR__ . '/akademik_ikhtibar.php';

/**
 * Identifikasi pembimbing yang sedang login (dari users.username = pembimbing.nip).
 * Mengembalikan {id, nama, nip} atau null jika bukan pembimbing.
 *
 * @return array{id:int,nama:string,nip:string}|null
 */
function pembimbing_dashboard_current_pembimbing(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !table_exists($pdo, 'users') || !table_exists($pdo, 'pembimbing')) {
        return null;
    }
    $st = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $username = trim((string) ($st->fetchColumn() ?: ''));
    if ($username === '') {
        return null;
    }
    $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
        ? ' AND COALESCE(is_aktif, 1) = 1'
        : '';
    $st2 = $pdo->prepare('
        SELECT id, nama_pembimbing, nip
        FROM pembimbing
        WHERE TRIM(nip) = :nip' . $aktifSql . '
        LIMIT 1
    ');
    $st2->execute(['nip' => $username]);
    $row = $st2->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'nama' => (string) ($row['nama_pembimbing'] ?? ''),
        'nip' => (string) ($row['nip'] ?? ''),
    ];
}

/**
 * Daftar tingkatan unik yang dipegang pembimbing (dari jadwal_kegiatan.pembimbing_id).
 * Untuk admin/pengurus/super admin → semua tingkatan yang ada di santri.
 *
 * @return list<string>
 */
function pembimbing_dashboard_tingkatan_list(PDO $pdo, ?int $pembimbingId, bool $bolehSemua): array
{
    if ($bolehSemua || $pembimbingId === null || $pembimbingId <= 0) {
        return pembimbing_dashboard_semua_tingkatan($pdo);
    }
    if (!table_exists($pdo, 'jadwal_kegiatan')) {
        return [];
    }
    try {
        $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
    } catch (PDOException $e) {
        // ignore
    }
    if (!column_exists($pdo, 'jadwal_kegiatan', 'pembimbing_id') || !column_exists($pdo, 'jadwal_kegiatan', 'tingkatan')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT DISTINCT TRIM(tingkatan) AS tingkatan
        FROM jadwal_kegiatan
        WHERE pembimbing_id = :pid
          AND tingkatan IS NOT NULL
          AND TRIM(tingkatan) <> ""
        ORDER BY tingkatan ASC
    ');
    $st->execute(['pid' => $pembimbingId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tk) {
        $t = trim((string) $tk);
        if ($t !== '' && strcasecmp($t, 'Semua Tingkatan') !== 0) {
            $out[] = $t;
        }
    }

    return $out;
}

/**
 * @return list<string>
 */
function pembimbing_dashboard_semua_tingkatan(PDO $pdo): array
{
    if (!table_exists($pdo, 'santri') || !column_exists($pdo, 'santri', 'tingkatan')) {
        return [];
    }
    $rows = $pdo->query('
        SELECT DISTINCT TRIM(tingkatan) AS tingkatan
        FROM santri
        WHERE tingkatan IS NOT NULL AND TRIM(tingkatan) <> ""
        ORDER BY tingkatan ASC
    ')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $out = [];
    foreach ($rows as $tk) {
        $t = trim((string) $tk);
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return $out;
}

/**
 * Statistik jumlah santri (total, putra, putri) untuk tingkatan tertentu.
 *
 * @return array{total:int,putra:int,putri:int}
 */
function pembimbing_dashboard_jumlah_santri(PDO $pdo, array $tingkatanList): array
{
    if (!table_exists($pdo, 'santri') || !column_exists($pdo, 'santri', 'tingkatan')) {
        return ['total' => 0, 'putra' => 0, 'putri' => 0];
    }
    if ($tingkatanList === []) {
        return ['total' => 0, 'putra' => 0, 'putri' => 0];
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin');
    $sql = 'SELECT
                COUNT(*) AS total'
        . ($hasJk
            ? ', SUM(CASE WHEN TRIM(s.jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra'
            . ', SUM(CASE WHEN TRIM(s.jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri'
            : ', 0 AS putra, 0 AS putri')
        . ' FROM santri s
            WHERE ' . $aktifSql . '
              AND s.tingkatan IN (' . $inSql . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'putra' => (int) ($row['putra'] ?? 0),
        'putri' => (int) ($row['putri'] ?? 0),
    ];
}

/**
 * Daftar santri sedang izin hari ini di tingkatan terpilih.
 *
 * @return list<array<string,mixed>>
 */
function pembimbing_dashboard_santri_izin_hari_ini(PDO $pdo, array $tingkatanList, string $today, int $limit = 50): array
{
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['today'] = $today;
    $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
        ? ' AND i.approval_status = "DISETUJUI"'
        : '';
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare('
        SELECT
            i.id,
            i.jenis_izin,
            i.tanggal_mulai,
            i.tanggal_selesai,
            i.alasan,
            s.id AS santri_id,
            s.nis,
            s.nama_santri,
            s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND ' . $aktifSql . '
        WHERE i.status_izin = "IZIN"
          AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql . '
          AND s.tingkatan IN (' . $inSql . ')
        ORDER BY s.tingkatan ASC, s.nama_santri ASC
        LIMIT ' . $limit . '
    ');
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Total santri sedang izin hari ini (count only).
 */
function pembimbing_dashboard_jumlah_izin_hari_ini(PDO $pdo, array $tingkatanList, string $today): int
{
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return 0;
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['today'] = $today;
    $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
        ? ' AND i.approval_status = "DISETUJUI"'
        : '';
    $st = $pdo->prepare('
        SELECT COUNT(*)
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND ' . $aktifSql . '
        WHERE i.status_izin = "IZIN"
          AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql . '
          AND s.tingkatan IN (' . $inSql . ')
    ');
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/**
 * Ringkasan presensi hari ini (HADIR/IZIN/SAKIT/ALPA) untuk tingkatan terpilih.
 *
 * @return array{hadir:int,izin:int,sakit:int,alpa:int,total:int}
 */
function pembimbing_dashboard_presensi_hari_ini(PDO $pdo, array $tingkatanList, string $today): array
{
    $empty = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return $empty;
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['today'] = $today;
    $st = $pdo->prepare('
        SELECT
            SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
            SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END) AS izin,
            SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END) AS sakit,
            SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END) AS alpa,
            COUNT(p.id) AS total
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $aktifSql . '
        WHERE p.tanggal_presensi = :today
          AND s.tingkatan IN (' . $inSql . ')
    ');
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'hadir' => (int) ($row['hadir'] ?? 0),
        'izin' => (int) ($row['izin'] ?? 0),
        'sakit' => (int) ($row['sakit'] ?? 0),
        'alpa' => (int) ($row['alpa'] ?? 0),
        'total' => (int) ($row['total'] ?? 0),
    ];
}

/**
 * Ringkasan keaktivan per santri pada tahun berjalan.
 * Memakai data presensi tahun $tahun; menimpa label dengan nilai pengasuh bila ada.
 *
 * @return list<array{
 *   santri_id:int,
 *   nis:string,
 *   nama_santri:string,
 *   tingkatan:string,
 *   jenis_kelamin:string,
 *   hadir:int,
 *   izin:int,
 *   sakit:int,
 *   alpa:int,
 *   total:int,
 *   persen_hadir:float,
 *   kategori:string,
 *   label:string,
 *   sumber:string
 * }>
 */
function pembimbing_dashboard_keaktivan_santri(PDO $pdo, array $tingkatanList, int $tahun, int $limit = 200): array
{
    if (!table_exists($pdo, 'santri') || !column_exists($pdo, 'santri', 'tingkatan') || $tingkatanList === []) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['th'] = $tahun;
    $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin') ? 's.jenis_kelamin' : '""';
    $limit = max(1, min(500, $limit));
    $sql = '
        SELECT
            s.id AS santri_id,
            s.nis,
            s.nama_santri,
            COALESCE(s.tingkatan, "") AS tingkatan,
            COALESCE(' . $hasJk . ', "") AS jenis_kelamin,
            COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
            COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
            COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
            COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
            COALESCE(COUNT(p.id), 0) AS total
        FROM santri s
        LEFT JOIN presensi p ON p.santri_id = s.id AND YEAR(p.tanggal_presensi) = :th
        WHERE ' . $aktifSql . '
          AND s.tingkatan IN (' . $inSql . ')
        GROUP BY s.id, s.nis, s.nama_santri, s.tingkatan, ' . $hasJk . '
        ORDER BY s.tingkatan ASC, s.nama_santri ASC
        LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $manualNilai = santri_keaktifan_nilai_map_for_tahun($pdo, $tahun);

    $out = [];
    foreach ($rows as $r) {
        $sid = (int) ($r['santri_id'] ?? 0);
        $hadir = (int) ($r['hadir'] ?? 0);
        $izin = (int) ($r['izin'] ?? 0);
        $sakit = (int) ($r['sakit'] ?? 0);
        $alpa = (int) ($r['alpa'] ?? 0);
        $total = (int) ($r['total'] ?? 0);
        $persen = $total > 0 ? round($hadir / $total * 100, 1) : 0.0;
        $kategori = santri_category($alpa, $goodMax, $mediumMax);
        $label = $kategori;
        $sumber = 'presensi';
        if (isset($manualNilai[$sid])) {
            $label = $manualNilai[$sid]['label'];
            $kategori = $manualNilai[$sid]['nilai'];
            $sumber = 'pengasuh';
        } elseif ($total === 0) {
            $label = 'Belum ada data';
            $kategori = '';
            $sumber = 'none';
        }
        $out[] = [
            'santri_id' => $sid,
            'nis' => (string) ($r['nis'] ?? ''),
            'nama_santri' => (string) ($r['nama_santri'] ?? ''),
            'tingkatan' => (string) ($r['tingkatan'] ?? ''),
            'jenis_kelamin' => (string) ($r['jenis_kelamin'] ?? ''),
            'hadir' => $hadir,
            'izin' => $izin,
            'sakit' => $sakit,
            'alpa' => $alpa,
            'total' => $total,
            'persen_hadir' => $persen,
            'kategori' => $kategori,
            'label' => $label,
            'sumber' => $sumber,
        ];
    }

    return $out;
}

/**
 * Hitung distribusi kategori keaktivan (Bagus/Sedang/Buruk) dari array hasil
 * pembimbing_dashboard_keaktivan_santri().
 *
 * @param list<array<string,mixed>> $rows
 * @return array{bagus:int,sedang:int,buruk:int,belum:int}
 */
function pembimbing_dashboard_ringkasan_kategori(array $rows): array
{
    $out = ['bagus' => 0, 'sedang' => 0, 'buruk' => 0, 'belum' => 0];
    foreach ($rows as $r) {
        $kat = strtoupper((string) ($r['kategori'] ?? ''));
        if ($kat === 'BAIK' || $kat === 'BAGUS') {
            $out['bagus']++;
        } elseif ($kat === 'SEDANG') {
            $out['sedang']++;
        } elseif ($kat === 'BURUK' || $kat === 'JELEK') {
            $out['buruk']++;
        } else {
            $out['belum']++;
        }
    }

    return $out;
}

/**
 * Ringkasan per masing-masing tingkatan yang dibimbing.
 * Setiap tingkatan: jumlah santri (total, putra, putri), izin hari ini,
 * presensi hari ini, dan distribusi kategori keaktifan tahun berjalan.
 *
 * @param list<string> $tingkatanList
 * @param list<array<string,mixed>> $keaktivanRows hasil pembimbing_dashboard_keaktivan_santri()
 * @return list<array{
 *   tingkatan:string,
 *   total:int,
 *   putra:int,
 *   putri:int,
 *   izin:int,
 *   hadir_hari_ini:int,
 *   alpa_hari_ini:int,
 *   bagus:int,
 *   sedang:int,
 *   buruk:int,
 *   belum:int
 * }>
 */
function pembimbing_dashboard_per_tingkatan_stats(
    PDO $pdo,
    array $tingkatanList,
    string $today,
    array $keaktivanRows
): array {
    if ($tingkatanList === []) {
        return [];
    }
    $out = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        $one = [$tk];
        $jml = pembimbing_dashboard_jumlah_santri($pdo, $one);
        $izinCount = pembimbing_dashboard_jumlah_izin_hari_ini($pdo, $one, $today);
        $presensi = pembimbing_dashboard_presensi_hari_ini($pdo, $one, $today);

        $rowsTk = array_values(array_filter(
            $keaktivanRows,
            static fn(array $r): bool => strcasecmp((string) ($r['tingkatan'] ?? ''), $tk) === 0
        ));
        $kat = pembimbing_dashboard_ringkasan_kategori($rowsTk);

        $out[] = [
            'tingkatan' => $tk,
            'total' => (int) $jml['total'],
            'putra' => (int) $jml['putra'],
            'putri' => (int) $jml['putri'],
            'izin' => (int) $izinCount,
            'hadir_hari_ini' => (int) $presensi['hadir'],
            'alpa_hari_ini' => (int) $presensi['alpa'],
            'bagus' => (int) $kat['bagus'],
            'sedang' => (int) $kat['sedang'],
            'buruk' => (int) $kat['buruk'],
            'belum' => (int) $kat['belum'],
        ];
    }

    return $out;
}

/**
 * Kegiatan berlangsung saat ini untuk tingkatan terpilih.
 *
 * @return list<array<string,mixed>>
 */
function pembimbing_dashboard_kegiatan_aktif(PDO $pdo, array $tingkatanList, int $hariKe, string $jamSekarang): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan') || $tingkatanList === []) {
        return [];
    }
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['hari'] = $hariKe;
    $params['jam'] = $jamSekarang;
    $sql = '
        SELECT
            k.nama_kegiatan,
            j.tingkatan,
            j.jam_mulai,
            j.jam_selesai,
            j.tempat
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari)
          AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
          AND COALESCE(k.is_active, 1) = 1
          AND (j.tingkatan = "Semua Tingkatan" OR j.tingkatan IN (' . $inSql . '))
        ORDER BY j.jam_mulai ASC, j.tingkatan ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Jumlah tugas Ikhtibar aktif (published) yang dibuat pembimbing ini.
 *
 * @return array{total:int,published:int,draft:int,sesi_selesai:int}
 */
function pembimbing_dashboard_tugas_stats(PDO $pdo, int $userId, bool $bolehSemua): array
{
    if (!table_exists($pdo, 'ikhtibar_tugas')) {
        return ['total' => 0, 'published' => 0, 'draft' => 0, 'sesi_selesai' => 0];
    }
    $sql = 'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) AS draft
            FROM ikhtibar_tugas';
    $params = [];
    if (!$bolehSemua && $userId > 0) {
        $sql .= ' WHERE created_by = :uid';
        $params['uid'] = $userId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $sesiSelesai = 0;
    if (table_exists($pdo, 'ikhtibar_sesi')) {
        $sqlS = 'SELECT COUNT(*) FROM ikhtibar_sesi s';
        $paramsS = [];
        if (!$bolehSemua && $userId > 0) {
            $sqlS .= ' INNER JOIN ikhtibar_tugas t ON t.id = s.tugas_id WHERE t.created_by = :uid AND s.status = "selesai"';
            $paramsS['uid'] = $userId;
        } else {
            $sqlS .= ' WHERE s.status = "selesai"';
        }
        $stS = $pdo->prepare($sqlS);
        $stS->execute($paramsS);
        $sesiSelesai = (int) $stS->fetchColumn();
    }

    return [
        'total' => (int) ($row['total'] ?? 0),
        'published' => (int) ($row['published'] ?? 0),
        'draft' => (int) ($row['draft'] ?? 0),
        'sesi_selesai' => $sesiSelesai,
    ];
}

/**
 * Bangun klausa IN (?, ?, ...) aman untuk PDO dengan named placeholder.
 *
 * @param list<string> $values
 * @return array{0:string,1:array<string,string>}
 */
function pembimbing_dashboard_in_clause(array $values, string $prefix): array
{
    $sqlParts = [];
    $params = [];
    $i = 0;
    foreach ($values as $v) {
        $key = ':' . $prefix . $i;
        $sqlParts[] = $key;
        $params[$prefix . $i] = (string) $v;
        $i++;
    }
    if ($sqlParts === []) {
        return ['NULL', []];
    }

    return [implode(', ', $sqlParts), $params];
}
