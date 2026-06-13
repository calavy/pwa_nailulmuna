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
    $munawibId = (int) ($_SESSION['munawib_id'] ?? 0);
    if ($munawibId > 0) {
        require_once __DIR__ . '/munawib_portal.php';
        $konteks = munawib_portal_konteks();
        $pbId = (int) ($konteks['pembimbing_id'] ?? 0);
        $pbNama = trim((string) ($konteks['pembimbing_nama'] ?? ''));
        $munawibNama = trim((string) ($_SESSION['user']['nama'] ?? 'Munawib'));
        $nip = trim((string) ($_SESSION['user']['username'] ?? ''));
        $displayNama = $pbNama !== '' ? $pbNama : $munawibNama;

        return [
            'id' => $pbId > 0 ? $pbId : $munawibId,
            'nama' => $displayNama,
            'nip' => $nip,
            'munawib_id' => $munawibId,
            'munawib_mode' => true,
            'munawib_nama' => $munawibNama,
        ];
    }

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
 * Apakah pembimbing punya slot jadwal "Semua Tingkatan".
 */
function pembimbing_dashboard_ampu_semua_tingkatan(PDO $pdo, int $pembimbingId): bool
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return false;
    }
    try {
        $st = $pdo->prepare('
            SELECT 1 FROM jadwal_kegiatan
            WHERE pembimbing_id = :pid AND TRIM(tingkatan) = "Semua Tingkatan"
            LIMIT 1
        ');
        $st->execute(['pid' => $pembimbingId]);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Daftar tingkatan unik yang diasuh pembimbing (otomatis dari jadwal_kegiatan.pembimbing_id).
 * Jika ada slot "Semua Tingkatan" → semua tingkatan santri aktif.
 * Admin/pengurus → semua tingkatan di data santri.
 *
 * @return list<string>
 */
function pembimbing_dashboard_tingkatan_list(PDO $pdo, ?int $pembimbingId, bool $bolehSemua): array
{
    $munawibTk = $_SESSION['munawib_tingkatan'] ?? null;
    if (is_array($munawibTk) && $munawibTk !== []) {
        return array_values(array_filter(array_map(static fn ($t): string => trim((string) $t), $munawibTk)));
    }

    if ($bolehSemua || $pembimbingId === null || $pembimbingId <= 0) {
        return pembimbing_dashboard_semua_tingkatan($pdo);
    }
    if (pembimbing_dashboard_ampu_semua_tingkatan($pdo, $pembimbingId)) {
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
          AND TRIM(tingkatan) <> "Semua Tingkatan"
        ORDER BY tingkatan ASC
    ');
    $st->execute(['pid' => $pembimbingId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tk) {
        $t = trim((string) $tk);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    require_once __DIR__ . '/pembimbing_pkpps.php';
    foreach (pembimbing_pkpps_tingkatan_labels($pdo, $pembimbingId) as $lbl) {
        if (!in_array($lbl, $out, true)) {
            $out[] = $lbl;
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
    $map = pembimbing_dashboard_jumlah_santri_map($pdo, $tingkatanList);
    $total = 0;
    $putra = 0;
    $putri = 0;
    foreach ($map as $row) {
        $total += (int) ($row['total'] ?? 0);
        $putra += (int) ($row['putra'] ?? 0);
        $putri += (int) ($row['putri'] ?? 0);
    }

    return ['total' => $total, 'putra' => $putra, 'putri' => $putri];
}

/**
 * Jumlah santri per tingkatan (satu query) — kunci = nama tingkatan.
 *
 * @return array<string, array{total:int,putra:int,putri:int}>
 */
function pembimbing_dashboard_jumlah_santri_map(PDO $pdo, array $tingkatanList): array
{
    if ($tingkatanList === []) {
        return [];
    }
    require_once __DIR__ . '/pembimbing_pkpps.php';
    $kajianList = [];
    $pkppsLabels = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        if (pembimbing_pkpps_is_label($tk)) {
            $pkppsLabels[] = $tk;
        } else {
            $kajianList[] = $tk;
        }
    }
    $out = [];
    if ($kajianList !== [] && table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tingkatan')) {
        $aktifSql = santri_sql_aktif_only('s');
        [$inSql, $params] = pembimbing_dashboard_in_clause($kajianList, 'tk');
        $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin');
        $sql = 'SELECT TRIM(s.tingkatan) AS tingkatan,
                    COUNT(*) AS total'
            . ($hasJk
                ? ', SUM(CASE WHEN TRIM(s.jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra'
                . ', SUM(CASE WHEN TRIM(s.jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri'
                : ', 0 AS putra, 0 AS putri')
            . ' FROM santri s
                WHERE ' . $aktifSql . '
                  AND TRIM(s.tingkatan) IN (' . $inSql . ')
                GROUP BY TRIM(s.tingkatan)';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $tk = trim((string) ($row['tingkatan'] ?? ''));
            if ($tk === '') {
                continue;
            }
            $out[$tk] = [
                'total' => (int) ($row['total'] ?? 0),
                'putra' => (int) ($row['putra'] ?? 0),
                'putri' => (int) ($row['putri'] ?? 0),
            ];
        }
        foreach ($kajianList as $tk) {
            if (!isset($out[$tk])) {
                $out[$tk] = ['total' => 0, 'putra' => 0, 'putri' => 0];
            }
        }
    }
    if ($pkppsLabels !== []) {
        $out = array_merge($out, pembimbing_pkpps_jumlah_santri_map($pdo, $pkppsLabels));
    }

    return $out;
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
function pembimbing_dashboard_presensi_hari_ini(PDO $pdo, array $tingkatanList, string $today, bool $runFinalize = true): array
{
    $empty = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return $empty;
    }
    if ($runFinalize) {
        require_once __DIR__ . '/presensi_jadwal.php';
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $today, $today, $auditUserId > 0 ? $auditUserId : 1);
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
function pembimbing_dashboard_tahun_presensi_bounds(int $tahun): array
{
    $start = sprintf('%04d-01-01', $tahun);
    $end = sprintf('%04d-12-31', $tahun);
    $today = date('Y-m-d');
    if ($end > $today) {
        $end = $today;
    }

    return [$start, $end];
}

/**
 * Nilai manual pengasuh hanya untuk santri dalam scope tingkatan (hindari load seluruh pondok).
 *
 * @return array<int, array{nilai:string,label:string,catatan:string}>
 */
function pembimbing_dashboard_nilai_manual_map_scoped(PDO $pdo, int $tahun, array $tingkatanList): array
{
    require_once __DIR__ . '/santri_keaktifan_nilai.php';
    if ($tahun <= 0 || $tingkatanList === [] || !table_exists($pdo, 'santri_nilai_keaktifan')) {
        return [];
    }
    ensure_santri_nilai_keaktifan_table($pdo);
    require_once __DIR__ . '/pembimbing_pkpps.php';
    $kajianList = [];
    $pkppsTingkatanIds = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        if (pembimbing_pkpps_is_label($tk)) {
            $nama = trim(substr($tk, strlen(PEMBIMBING_PKPPS_LABEL_PREFIX)));
            if ($nama !== '') {
                $stT = $pdo->prepare('SELECT id FROM pkpps_tingkatan WHERE nama_tingkatan = :n LIMIT 1');
                $stT->execute(['n' => $nama]);
                $tid = (int) ($stT->fetchColumn() ?: 0);
                if ($tid > 0) {
                    $pkppsTingkatanIds[$tid] = true;
                }
            }
        } else {
            $kajianList[] = $tk;
        }
    }

    $santriIds = [];
    if ($kajianList !== [] && column_exists($pdo, 'santri', 'tingkatan')) {
        $aktifSql = santri_sql_aktif_only('s');
        [$inSql, $params] = pembimbing_dashboard_in_clause($kajianList, 'tk');
        $st = $pdo->prepare('SELECT s.id FROM santri s WHERE ' . $aktifSql . ' AND s.tingkatan IN (' . $inSql . ')');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
            $santriIds[(int) $sid] = true;
        }
    }
    if ($pkppsTingkatanIds !== [] && table_exists($pdo, 'pkpps_santri')) {
        pkpps_ensure_schema($pdo);
        $ph = implode(',', array_fill(0, count($pkppsTingkatanIds), '?'));
        $st = $pdo->prepare(
            'SELECT santri_id FROM pkpps_santri WHERE is_aktif = 1 AND pkpps_tingkatan_id IN (' . $ph . ')'
        );
        $st->execute(array_keys($pkppsTingkatanIds));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
            $santriIds[(int) $sid] = true;
        }
    }
    $ids = array_keys($santriIds);
    if ($ids === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT santri_id, nilai, catatan FROM santri_nilai_keaktifan WHERE tahun = ? AND santri_id IN (' . $ph . ')'
    );
    $st->execute(array_merge([$tahun], $ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kode = (string) ($row['nilai'] ?? '');
        $out[$sid] = [
            'nilai' => $kode,
            'label' => santri_keaktifan_nilai_label_dari_kode($kode),
            'catatan' => trim((string) ($row['catatan'] ?? '')),
        ];
    }

    return $out;
}

/**
 * Data keaktivan + rekap kegiatan (cache sesi singkat untuk halaman Keaktivan pembimbing).
 *
 * @return array{rows:list<array<string,mixed>>,rekap:list<array<string,mixed>>,kategori:array{bagus:int,sedang:int,buruk:int,belum:int}}
 */
function pembimbing_dashboard_keaktivan_bundle(
    PDO $pdo,
    array $tingkatanScope,
    int $tahun,
    int $userId,
    int $limit = 300,
    bool $useCache = true
): array {
    $scopeKey = implode("\0", array_map(static fn (string $t): string => trim($t), $tingkatanScope));
    $cacheKey = 'pb_keaktivan_v1_' . $userId . '_' . $tahun . '_' . md5($scopeKey);
    if ($useCache && isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        $cached = $_SESSION[$cacheKey];
        if (($cached['exp'] ?? 0) > time() && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }
    }

    $rows = pembimbing_dashboard_keaktivan_santri($pdo, $tingkatanScope, $tahun, $limit);
    $rekap = pembimbing_dashboard_presensi_rekap_per_kegiatan($pdo, $tingkatanScope, $tahun);
    $data = [
        'rows' => $rows,
        'rekap' => $rekap,
        'kategori' => pembimbing_dashboard_ringkasan_kategori($rows),
    ];
    if ($useCache) {
        $_SESSION[$cacheKey] = ['exp' => time() + 90, 'data' => $data];
    }

    return $data;
}

function pembimbing_dashboard_keaktivan_santri(PDO $pdo, array $tingkatanList, int $tahun, int $limit = 200, bool $runFinalize = false): array
{
    if (!table_exists($pdo, 'santri') || $tingkatanList === []) {
        return [];
    }
    if ($runFinalize) {
        require_once __DIR__ . '/presensi_jadwal.php';
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $monthStart, $today, $auditUserId > 0 ? $auditUserId : 1);
    }
    [$presStart, $presEnd] = pembimbing_dashboard_tahun_presensi_bounds($tahun);
    require_once __DIR__ . '/pembimbing_pkpps.php';
    $kajianList = [];
    $pkppsLabels = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        if (pembimbing_pkpps_is_label($tk)) {
            $pkppsLabels[] = $tk;
        } else {
            $kajianList[] = $tk;
        }
    }

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $manualNilai = pembimbing_dashboard_nilai_manual_map_scoped($pdo, $tahun, $tingkatanList);
    $limit = max(1, min(500, $limit));
    $out = [];

    $appendRow = static function (array $r) use (&$out, $manualNilai, $goodMax, $mediumMax): void {
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
    };

    if ($kajianList !== [] && column_exists($pdo, 'santri', 'tingkatan')) {
        $aktifSql = santri_sql_aktif_only('s');
        [$inSql, $params] = pembimbing_dashboard_in_clause($kajianList, 'tk');
        $params['pres_start'] = $presStart;
        $params['pres_end'] = $presEnd;
        $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin') ? 's.jenis_kelamin' : '""';
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
            LEFT JOIN presensi p ON p.santri_id = s.id
                AND p.tanggal_presensi >= :pres_start AND p.tanggal_presensi <= :pres_end
            WHERE ' . $aktifSql . '
              AND s.tingkatan IN (' . $inSql . ')
            GROUP BY s.id, s.nis, s.nama_santri, s.tingkatan, ' . $hasJk . '
            ORDER BY s.tingkatan ASC, s.nama_santri ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $appendRow($r);
        }
    }

    if ($pkppsLabels !== [] && table_exists($pdo, 'pkpps_santri')) {
        pkpps_ensure_schema($pdo);
        $tingkatanIds = [];
        foreach ($pkppsLabels as $lbl) {
            $nama = trim(substr(trim($lbl), strlen(PEMBIMBING_PKPPS_LABEL_PREFIX)));
            if ($nama === '') {
                continue;
            }
            $stT = $pdo->prepare('SELECT id FROM pkpps_tingkatan WHERE nama_tingkatan = :n LIMIT 1');
            $stT->execute(['n' => $nama]);
            $tid = (int) ($stT->fetchColumn() ?: 0);
            if ($tid > 0) {
                $tingkatanIds[$tid] = $lbl;
            }
        }
        if ($tingkatanIds !== []) {
            $aktifSql = santri_sql_aktif_only('s');
            $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin') ? 's.jenis_kelamin' : '""';
            $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
            $ph = implode(',', array_fill(0, count($tingkatanIds), '?'));
            $params = array_merge([$presStart, $presEnd], array_keys($tingkatanIds));
            $sql = '
                SELECT
                    s.id AS santri_id,
                    s.nis,
                    s.' . $nameCol . ' AS nama_santri,
                    t.nama_tingkatan AS pkpps_nama,
                    COALESCE(' . $hasJk . ', "") AS jenis_kelamin,
                    COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
                    COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
                    COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
                    COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
                    COALESCE(COUNT(p.id), 0) AS total
                FROM pkpps_santri ps
                INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
                INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
                LEFT JOIN presensi p ON p.santri_id = s.id
                    AND p.tanggal_presensi >= ? AND p.tanggal_presensi <= ?
                WHERE ps.is_aktif = 1 AND ps.pkpps_tingkatan_id IN (' . $ph . ')
                GROUP BY s.id, s.nis, s.' . $nameCol . ', t.nama_tingkatan, ' . $hasJk . '
                ORDER BY t.urutan ASC, s.' . $nameCol . ' ASC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $r['tingkatan'] = pembimbing_pkpps_label((string) ($r['pkpps_nama'] ?? ''));
                $appendRow($r);
            }
        }
    }

    if (count($out) > $limit) {
        $out = array_slice($out, 0, $limit);
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
/**
 * Presensi hari ini per tingkatan (satu query).
 *
 * @return array<string, array{hadir:int,alpa:int}>
 */
function pembimbing_dashboard_presensi_hari_ini_map(PDO $pdo, array $tingkatanList, string $today): array
{
    $map = [];
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return $map;
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['today'] = $today;
    $st = $pdo->prepare('
        SELECT s.tingkatan,
            SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
            SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END) AS alpa
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $aktifSql . '
        WHERE p.tanggal_presensi = :today
          AND s.tingkatan IN (' . $inSql . ')
        GROUP BY s.tingkatan
    ');
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $tk = trim((string) ($row['tingkatan'] ?? ''));
        if ($tk === '') {
            continue;
        }
        $map[$tk] = [
            'hadir' => (int) ($row['hadir'] ?? 0),
            'alpa' => (int) ($row['alpa'] ?? 0),
        ];
    }

    return $map;
}

/**
 * Jumlah izin hari ini per tingkatan (satu query).
 *
 * @return array<string, int>
 */
function pembimbing_dashboard_izin_hari_ini_map(PDO $pdo, array $tingkatanList, string $today): array
{
    $map = [];
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return $map;
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['today'] = $today;
    $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
        ? ' AND i.approval_status = "DISETUJUI"'
        : '';
    $st = $pdo->prepare('
        SELECT s.tingkatan, COUNT(*) AS c
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND ' . $aktifSql . '
        WHERE i.status_izin = "IZIN"
          AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql . '
          AND s.tingkatan IN (' . $inSql . ')
        GROUP BY s.tingkatan
    ');
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $tk = trim((string) ($row['tingkatan'] ?? ''));
        if ($tk !== '') {
            $map[$tk] = (int) ($row['c'] ?? 0);
        }
    }

    return $map;
}

function pembimbing_dashboard_per_tingkatan_stats(
    PDO $pdo,
    array $tingkatanList,
    string $today,
    array $keaktivanRows
): array {
    if ($tingkatanList === []) {
        return [];
    }
    $jmlMap = pembimbing_dashboard_jumlah_santri_map($pdo, $tingkatanList);
    $presensiMap = pembimbing_dashboard_presensi_hari_ini_map($pdo, $tingkatanList, $today);
    $izinMap = pembimbing_dashboard_izin_hari_ini_map($pdo, $tingkatanList, $today);
    $katPerTk = [];
    foreach ($keaktivanRows as $r) {
        $tk = trim((string) ($r['tingkatan'] ?? '')) ?: '—';
        if (!isset($katPerTk[$tk])) {
            $katPerTk[$tk] = [];
        }
        $katPerTk[$tk][] = $r;
    }
    $out = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        $jml = $jmlMap[$tk] ?? ['total' => 0, 'putra' => 0, 'putri' => 0];
        $presensi = $presensiMap[$tk] ?? ['hadir' => 0, 'alpa' => 0];
        $kat = pembimbing_dashboard_ringkasan_kategori($katPerTk[$tk] ?? []);

        $out[] = [
            'tingkatan' => $tk,
            'total' => (int) $jml['total'],
            'putra' => (int) $jml['putra'],
            'putri' => (int) $jml['putri'],
            'izin' => (int) ($izinMap[$tk] ?? 0),
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
 * Indeks warna konsisten (0–7) untuk UI tingkatan.
 */
function pembimbing_dashboard_tingkatan_color_index(string $tingkatan): int
{
    $tingkatan = trim($tingkatan);
    if ($tingkatan === '') {
        return 0;
    }

    return (int) ((crc32($tingkatan) & 0x7FFFFFFF) % 8);
}

/**
 * Kegiatan berlangsung saat ini untuk tingkatan terpilih.
 *
 * @return list<array<string,mixed>>
 */
function pembimbing_dashboard_kegiatan_aktif(
    PDO $pdo,
    array $tingkatanList,
    int $hariKe,
    string $jamSekarang,
    ?int $pembimbingId = null
): array {
    $out = [];
    if ($tingkatanList !== [] && table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
        [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
        $params['hari'] = $hariKe;
        $params['jam'] = $jamSekarang;
        $sql = '
            SELECT
                k.id AS kegiatan_id,
                k.nama_kegiatan,
                j.tingkatan,
                j.jam_mulai,
                j.jam_selesai,
                j.tempat,
                j.pembimbing_id,
                p.nama_pembimbing
            FROM jadwal_kegiatan j
            INNER JOIN kegiatan k ON k.id = j.kegiatan_id
            LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
            WHERE (j.hari_ke = 0 OR j.hari_ke = :hari)
              AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
              AND COALESCE(k.is_active, 1) = 1
              AND (j.tingkatan = "Semua Tingkatan" OR j.tingkatan IN (' . $inSql . '))
            ORDER BY j.jam_mulai ASC, j.tingkatan ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($pembimbingId !== null && $pembimbingId > 0 && table_exists($pdo, 'pkpps_jadwal') && table_exists($pdo, 'kegiatan')) {
        require_once __DIR__ . '/pembimbing_pkpps.php';
        pkpps_ensure_schema($pdo);
        $stPk = $pdo->prepare('
            SELECT k.id AS kegiatan_id, k.nama_kegiatan, t.nama_tingkatan, j.jam_mulai, j.jam_selesai, j.tempat,
                   j.pembimbing_id, p.nama_pembimbing
            FROM pkpps_jadwal j
            INNER JOIN kegiatan k ON k.id = j.kegiatan_id
            INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
            LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
            WHERE j.pembimbing_id = :pid AND j.is_aktif = 1
              AND (j.hari_ke = 0 OR j.hari_ke = :hari)
              AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
              AND COALESCE(k.is_active, 1) = 1
            ORDER BY j.jam_mulai ASC, t.urutan ASC
        ');
        $stPk->execute(['pid' => $pembimbingId, 'hari' => $hariKe, 'jam' => $jamSekarang]);
        foreach ($stPk->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0),
                'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
                'tingkatan' => pembimbing_pkpps_label((string) ($row['nama_tingkatan'] ?? '')),
                'jam_mulai' => (string) ($row['jam_mulai'] ?? ''),
                'jam_selesai' => (string) ($row['jam_selesai'] ?? ''),
                'tempat' => (string) ($row['tempat'] ?? ''),
                'pembimbing_id' => (int) ($row['pembimbing_id'] ?? 0),
                'nama_pembimbing' => trim((string) ($row['nama_pembimbing'] ?? '')),
            ];
        }
    }

    return $out;
}

/**
 * Ringkasan presensi hari ini per kegiatan yang sedang berlangsung (untuk banner dashboard).
 *
 * @param array<string, list<array<string, mixed>>> $kegiatanAktifGrouped
 * @return list<array<string, mixed>>
 */
function pembimbing_dashboard_presensi_kegiatan_berlangsung(PDO $pdo, array $kegiatanAktifGrouped, string $today, bool $runFinalize = true): array
{
    if ($kegiatanAktifGrouped === [] || !table_exists($pdo, 'santri') || !table_exists($pdo, 'jadwal_kegiatan')) {
        return [];
    }
    require_once __DIR__ . '/presensi_jadwal.php';
    $hariKe = (int) date('N', strtotime($today) ?: time());
    if ($runFinalize) {
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $today, $today, $auditUserId > 0 ? $auditUserId : 1);
    }

    $pembimbingIds = [];
    $kegiatanIds = [];
    foreach ($kegiatanAktifGrouped as $slotRows) {
        if (!is_array($slotRows)) {
            continue;
        }
        foreach ($slotRows as $row) {
            $pid = (int) ($row['pembimbing_id'] ?? 0);
            if ($pid > 0) {
                $pembimbingIds[$pid] = true;
            }
            $kid = (int) ($row['kegiatan_id'] ?? 0);
            if ($kid > 0) {
                $kegiatanIds[$kid] = true;
            }
        }
    }
    $scanMap = pembimbing_dashboard_scan_map_hari_ini(
        $pdo,
        array_keys($pembimbingIds),
        array_keys($kegiatanIds),
        $today
    );

    $aktifSql = santri_sql_aktif_only('s');
    $out = [];

    foreach ($kegiatanAktifGrouped as $namaKegiatan => $slotRows) {
        if (!is_array($slotRows) || $slotRows === []) {
            continue;
        }
        $kid = 0;
        $semuaTingkatan = false;
        $tingkatans = [];
        foreach ($slotRows as $row) {
            if ($kid <= 0) {
                $kid = (int) ($row['kegiatan_id'] ?? 0);
            }
            $tk = trim((string) ($row['tingkatan'] ?? ''));
            if ($tk === '' || strcasecmp($tk, 'Semua Tingkatan') === 0) {
                $semuaTingkatan = true;
            } else {
                $tingkatans[$tk] = true;
            }
        }
        if ($kid <= 0 && table_exists($pdo, 'kegiatan')) {
            $stKid = $pdo->prepare('SELECT id FROM kegiatan WHERE nama_kegiatan = :n LIMIT 1');
            $stKid->execute(['n' => (string) $namaKegiatan]);
            $kid = (int) ($stKid->fetchColumn() ?: 0);
        }
        if ($kid <= 0) {
            continue;
        }

        $jamMulai = substr((string) ($slotRows[0]['jam_mulai'] ?? ''), 0, 5);
        $jamSelesai = substr((string) ($slotRows[0]['jam_selesai'] ?? ''), 0, 5);
        $jamLabel = $jamMulai !== '' && $jamSelesai !== '' ? $jamMulai . '–' . $jamSelesai : '';

        $tkSql = '';
        $params = ['today' => $today, 'kid' => $kid, 'hari' => $hariKe];
        if (!$semuaTingkatan && $tingkatans !== []) {
            [$inSql, $inParams] = pembimbing_dashboard_in_clause(array_keys($tingkatans), 'tk');
            $tkSql = ' AND s.tingkatan IN (' . $inSql . ')';
            $params = array_merge($params, $inParams);
        }

        $sql = '
            SELECT s.id,
                   s.tingkatan,
                   COALESCE(NULLIF(TRIM(p.status_presensi), ""), "") AS status_hari_ini
            FROM santri s
            LEFT JOIN presensi p ON p.santri_id = s.id
                AND p.tanggal_presensi = :today
                AND p.kegiatan_id = :kid
            WHERE ' . $aktifSql . $tkSql . '
              AND EXISTS (
                  SELECT 1 FROM jadwal_kegiatan j
                  INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
                  WHERE j.kegiatan_id = :kid
                    AND (j.hari_ke = 0 OR j.hari_ke = :hari)
                    AND (
                        j.tingkatan = "Semua Tingkatan"
                        OR j.tingkatan = s.tingkatan
                    )
              )
            ORDER BY s.tingkatan ASC, s.nama_santri ASC
            LIMIT 500
        ';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            foreach ($rows as &$rowRef) {
                $rowRef['kegiatan_id'] = $kid;
            }
            unset($rowRef);
            $rows = presensi_apply_status_efektif_rows($pdo, $rows, $today);
        }

        $counts = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $counts['total']++;
            $st = strtoupper(trim((string) ($r['status_hari_ini'] ?? '')));
            if ($st === 'HADIR') {
                $counts['hadir']++;
            } elseif ($st === 'IZIN') {
                $counts['izin']++;
            } elseif ($st === 'SAKIT') {
                $counts['sakit']++;
            } elseif ($st === 'ALPA') {
                $counts['alpa']++;
            }
        }

        $total = (int) $counts['total'];
        $hadir = (int) $counts['hadir'];
        $semuaHadir = $total > 0 && $hadir === $total;
        $pembimbingList = pembimbing_dashboard_pembimbing_slot_list($pdo, $slotRows, $kid, $today, $scanMap);
        $tingkatanLabels = [];
        foreach ($slotRows as $sr) {
            $tkLbl = trim((string) ($sr['tingkatan'] ?? ''));
            if ($tkLbl !== '') {
                $tingkatanLabels[$tkLbl] = true;
            }
        }

        $out[] = [
            'nama_kegiatan' => (string) $namaKegiatan,
            'kegiatan_id' => $kid,
            'jam_label' => $jamLabel,
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'tingkatan_list' => array_keys($tingkatanLabels),
            'pembimbing_list' => $pembimbingList,
            'hadir' => $hadir,
            'izin' => (int) $counts['izin'],
            'sakit' => (int) $counts['sakit'],
            'alpa' => (int) $counts['alpa'],
            'total' => $total,
            'semua_hadir' => $semuaHadir,
            'ratio_label' => $total > 0 ? $hadir . '/' . $total : '—',
        ];
    }

    return $out;
}

/**
 * Kegiatan terdekat hari ini yang belum dimulai (jam_mulai > sekarang).
 *
 * @return list<array<string,mixed>>
 */
function pembimbing_dashboard_kegiatan_mendekati(
    PDO $pdo,
    array $tingkatanList,
    int $hariKe,
    string $jamSekarang,
    int $limit = 5,
    ?int $pembimbingId = null
): array {
    $out = [];
    if ($tingkatanList !== [] && table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
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
              AND j.jam_mulai > :jam
              AND COALESCE(k.is_active, 1) = 1
              AND (j.tingkatan = "Semua Tingkatan" OR j.tingkatan IN (' . $inSql . '))
            ORDER BY j.jam_mulai ASC, j.tingkatan ASC
            LIMIT ' . max(1, min(20, $limit)) . '
        ';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($pembimbingId !== null && $pembimbingId > 0 && table_exists($pdo, 'pkpps_jadwal') && table_exists($pdo, 'kegiatan')) {
        require_once __DIR__ . '/pembimbing_pkpps.php';
        pkpps_ensure_schema($pdo);
        $stPk = $pdo->prepare('
            SELECT k.nama_kegiatan, t.nama_tingkatan, j.jam_mulai, j.jam_selesai, j.tempat
            FROM pkpps_jadwal j
            INNER JOIN kegiatan k ON k.id = j.kegiatan_id
            INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
            WHERE j.pembimbing_id = :pid AND j.is_aktif = 1
              AND (j.hari_ke = 0 OR j.hari_ke = :hari)
              AND j.jam_mulai > :jam
              AND COALESCE(k.is_active, 1) = 1
            ORDER BY j.jam_mulai ASC, t.urutan ASC
            LIMIT ' . max(1, min(20, $limit)) . '
        ');
        $stPk->execute(['pid' => $pembimbingId, 'hari' => $hariKe, 'jam' => $jamSekarang]);
        foreach ($stPk->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
                'tingkatan' => pembimbing_pkpps_label((string) ($row['nama_tingkatan'] ?? '')),
                'jam_mulai' => (string) ($row['jam_mulai'] ?? ''),
                'jam_selesai' => (string) ($row['jam_selesai'] ?? ''),
                'tempat' => (string) ($row['tempat'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? '')));
        $out = array_slice($out, 0, max(1, min(20, $limit)));
    }

    return $out;
}

/** Sisa waktu menuju jam mulai, dibulatkan ke jam (minimal 1 jika masih di hari yang sama). */
function pembimbing_dashboard_jam_menuju(string $jamTarget, string $jamSekarang): int
{
    $t0 = strtotime('1970-01-01 ' . substr($jamTarget, 0, 8));
    $t1 = strtotime('1970-01-01 ' . substr($jamSekarang, 0, 8));
    if ($t0 === false || $t1 === false || $t0 <= $t1) {
        return 0;
    }
    $hours = (int) ceil(($t0 - $t1) / 3600);

    return max(1, $hours);
}

/**
 * Daftar santri aktif per tingkatan (untuk panel dashboard pembimbing).
 *
 * @return array<string, list<array{id:int,nis:string,nama_santri:string,tingkatan:string}>>
 */
function pembimbing_dashboard_santri_list_map(PDO $pdo, array $tingkatanList, int $limit = 400, ?int $pembimbingId = null): array
{
    if ($tingkatanList === [] || !table_exists($pdo, 'santri')) {
        return [];
    }
    require_once __DIR__ . '/santri_operasional.php';
    require_once __DIR__ . '/pembimbing_pkpps.php';

    $kajianList = [];
    $pkppsLabels = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        if (pembimbing_pkpps_is_label($tk)) {
            $pkppsLabels[] = $tk;
        } else {
            $kajianList[] = $tk;
        }
    }

    $map = [];
    if ($kajianList !== []) {
        [$inSql, $params] = pembimbing_dashboard_in_clause($kajianList, 'tk');
        $aktifSql = santri_sql_aktif_only('s');
        $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $st = $pdo->prepare('
            SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan
            FROM santri s
            WHERE ' . $aktifSql . '
              AND s.tingkatan IN (' . $inSql . ')
            ORDER BY s.tingkatan ASC, s.' . $nameCol . ' ASC
            LIMIT ' . max(1, min(800, $limit)) . '
        ');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $tk = trim((string) ($row['tingkatan'] ?? '')) ?: '—';
            if (!isset($map[$tk])) {
                $map[$tk] = [];
            }
            $map[$tk][] = [
                'id' => (int) ($row['id'] ?? 0),
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'tingkatan' => $tk,
            ];
        }
    }

    if ($pkppsLabels !== [] && $pembimbingId !== null && $pembimbingId > 0) {
        $pkppsIds = [];
        foreach ($pkppsLabels as $lbl) {
            $tid = pembimbing_pkpps_id_from_label($lbl, $pdo, $pembimbingId);
            if ($tid > 0) {
                $pkppsIds[] = $tid;
            }
        }
        if ($pkppsIds !== []) {
            foreach (pembimbing_pkpps_santri_list($pdo, $pembimbingId, $pkppsIds, $limit) as $row) {
                $tk = trim((string) ($row['tingkatan'] ?? '')) ?: '—';
                if (!isset($map[$tk])) {
                    $map[$tk] = [];
                }
                $map[$tk][] = [
                    'id' => (int) ($row['santri_id'] ?? 0),
                    'nis' => (string) ($row['nis'] ?? ''),
                    'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                    'tingkatan' => $tk,
                ];
            }
        }
    }

    return $map;
}

/**
 * Teks ticker kegiatan kelas (berlangsung atau mendekati).
 *
 * @return list<string>
 */
function pembimbing_dashboard_ticker_kegiatan(
    array $kegiatanAktifGrouped,
    array $kegiatanMendekati,
    string $jamSekarang,
    array $presensiBerlangsung = []
): array {
    $items = [];
    if ($kegiatanAktifGrouped !== []) {
        $presensiByNama = [];
        foreach ($presensiBerlangsung as $pb) {
            $n = trim((string) ($pb['nama_kegiatan'] ?? ''));
            if ($n !== '') {
                $presensiByNama[$n] = $pb;
            }
        }
        foreach ($kegiatanAktifGrouped as $namaKegiatan => $slotRows) {
            $pb = $presensiByNama[(string) $namaKegiatan] ?? null;
            if (is_array($pb) && !empty($pb['semua_hadir']) && (int) ($pb['total'] ?? 0) > 0) {
                $items[] = 'Berlangsung: ' . (string) $namaKegiatan . ' · ' . (string) ($pb['ratio_label'] ?? '');
                continue;
            }
            $tkList = array_values(array_unique(array_filter(array_map(
                static fn (array $r): string => trim((string) ($r['tingkatan'] ?? '')),
                $slotRows
            ))));
            $jamMulai = substr((string) ($slotRows[0]['jam_mulai'] ?? ''), 0, 5);
            $jamSelesai = substr((string) ($slotRows[0]['jam_selesai'] ?? ''), 0, 5);
            $line = 'Berlangsung: ' . (string) $namaKegiatan . ' · ' . $jamMulai . '–' . $jamSelesai;
            if (is_array($pb) && (int) ($pb['total'] ?? 0) > 0) {
                $line .= ' · ' . (string) ($pb['ratio_label'] ?? '');
            } elseif ($tkList !== []) {
                $line .= ' · ' . implode(', ', $tkList);
            }
            $items[] = $line;
        }

        return $items;
    }
    foreach ($kegiatanMendekati as $kg) {
        $jamMulai = substr((string) ($kg['jam_mulai'] ?? ''), 0, 5);
        $jamSisa = pembimbing_dashboard_jam_menuju((string) ($kg['jam_mulai'] ?? ''), $jamSekarang);
        $tk = trim((string) ($kg['tingkatan'] ?? ''));
        $items[] = 'Mendekati: ' . (string) ($kg['nama_kegiatan'] ?? '—')
            . ' · mulai ' . $jamMulai
            . ' · ' . $jamSisa . ' jam lagi'
            . ($tk !== '' ? ' · ' . $tk : '');
    }
    if ($items === []) {
        $items[] = 'Belum ada jadwal kelas mendatang hari ini';
    }

    return $items;
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

/**
 * Prefetch status scan pembimbing (1 query) untuk hindari N+1 di kartu kegiatan live.
 *
 * @param list<int> $pembimbingIds
 * @param list<int> $kegiatanIds
 * @return array<string,bool> key: "{pid}|{kid}" atau "{pid}|0" (hadir hari ini)
 */
function pembimbing_dashboard_scan_map_hari_ini(PDO $pdo, array $pembimbingIds, array $kegiatanIds, string $today): array
{
    $map = [];
    $pids = array_values(array_filter(array_map('intval', $pembimbingIds), static fn (int $id): bool => $id > 0));
    if ($pids === [] || !table_exists($pdo, 'presensi_pembimbing')) {
        return $map;
    }

    [$inSql, $params] = pembimbing_dashboard_in_clause($pids, 'pid');
    $params['t'] = $today;
    $hasKegiatanCol = column_exists($pdo, 'presensi_pembimbing', 'kegiatan_id');
    $sql = $hasKegiatanCol
        ? 'SELECT pembimbing_id, kegiatan_id FROM presensi_pembimbing WHERE tanggal = :t AND pembimbing_id IN (' . $inSql . ')'
        : 'SELECT pembimbing_id FROM presensi_pembimbing WHERE tanggal = :t AND pembimbing_id IN (' . $inSql . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $pid = (int) ($r['pembimbing_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $map[$pid . '|0'] = true;
        if ($hasKegiatanCol) {
            $kid = (int) ($r['kegiatan_id'] ?? 0);
            if ($kid > 0) {
                $map[$pid . '|' . $kid] = true;
            }
        }
    }

    return $map;
}

/**
 * Apakah pembimbing sudah scan untuk kegiatan tertentu hari ini.
 */
function pembimbing_dashboard_sudah_scan_kegiatan(PDO $pdo, int $pembimbingId, int $kegiatanId, string $today, ?array $scanMap = null): bool
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'presensi_pembimbing')) {
        return false;
    }
    if ($scanMap !== null) {
        if ($kegiatanId > 0 && !empty($scanMap[$pembimbingId . '|' . $kegiatanId])) {
            return true;
        }

        return !empty($scanMap[$pembimbingId . '|0']);
    }
    if ($kegiatanId > 0 && column_exists($pdo, 'presensi_pembimbing', 'kegiatan_id')) {
        $st = $pdo->prepare('
            SELECT 1 FROM presensi_pembimbing
            WHERE pembimbing_id = :pid AND tanggal = :t AND kegiatan_id = :kid
            LIMIT 1
        ');
        $st->execute(['pid' => $pembimbingId, 't' => $today, 'kid' => $kegiatanId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }

    return pembimbing_dashboard_sudah_hadir_hari_ini($pdo, $pembimbingId, $today);
}

/**
 * Daftar pembimbing unik dari slot kegiatan + status scan hari ini.
 *
 * @param list<array<string,mixed>> $slotRows
 * @return list<array{id:int,nama:string,sudah_scan:bool}>
 */
function pembimbing_dashboard_pembimbing_slot_list(PDO $pdo, array $slotRows, int $kegiatanId, string $today, ?array $scanMap = null): array
{
    $map = [];
    foreach ($slotRows as $row) {
        $pid = (int) ($row['pembimbing_id'] ?? 0);
        $nama = trim((string) ($row['nama_pembimbing'] ?? ''));
        if ($pid <= 0 && $nama === '') {
            continue;
        }
        $key = $pid > 0 ? 'id_' . $pid : 'nm_' . strtolower($nama);
        if (isset($map[$key])) {
            continue;
        }
        $map[$key] = [
            'id' => $pid,
            'nama' => $nama !== '' ? $nama : ('Pembimbing #' . $pid),
            'sudah_scan' => pembimbing_dashboard_sudah_scan_kegiatan($pdo, $pid, $kegiatanId, $today, $scanMap),
        ];
    }

    return array_values($map);
}

/**
 * Apakah pembimbing sudah scan hadir hari ini (minimal satu kegiatan).
 */
function pembimbing_dashboard_sudah_hadir_hari_ini(PDO $pdo, int $pembimbingId, string $today): bool
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'presensi_pembimbing')) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM presensi_pembimbing WHERE pembimbing_id = :id AND tanggal = :t LIMIT 1');
    $st->execute(['id' => $pembimbingId, 't' => $today]);

    return (bool) $st->fetchColumn();
}

/**
 * Tingkatan dari slot jadwal yang sedang berlangsung untuk pembimbing ini.
 *
 * @param list<array<string,mixed>> $kegiatanAktif
 * @return list<string>
 */
function pembimbing_dashboard_tingkatan_dari_kegiatan_aktif(array $kegiatanAktif): array
{
    $out = [];
    foreach ($kegiatanAktif as $k) {
        $tk = trim((string) ($k['tingkatan'] ?? ''));
        if ($tk !== '' && strcasecmp($tk, 'Semua Tingkatan') !== 0) {
            $out[] = $tk;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Daftar santri + status presensi hari ini per kegiatan aktif (untuk kelas mengajar).
 *
 * @param list<int> $kegiatanIds
 * @return list<array<string,mixed>>
 */
function pembimbing_dashboard_roster_hari_ini(PDO $pdo, array $tingkatanList, string $today, array $kegiatanIds = []): array
{
    if (!table_exists($pdo, 'santri') || $tingkatanList === []) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');

    $kegiatanId = 0;
    foreach ($kegiatanIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $kegiatanId = $id;
            break;
        }
    }

    if (table_exists($pdo, 'presensi') && $kegiatanId > 0) {
        require_once __DIR__ . '/presensi_jadwal.php';
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $today, $today, $auditUserId > 0 ? $auditUserId : 1);

        $params['today'] = $today;
        $params['kid'] = $kegiatanId;
        $params['hari'] = (int) date('N', strtotime($today));
        $sql = '
            SELECT s.id, s.nis, s.nama_santri, s.tingkatan,
                   :kid AS kegiatan_id,
                   COALESCE(NULLIF(TRIM(p.status_presensi), ""), "") AS status_hari_ini,
                   p.jam_presensi
            FROM santri s
            LEFT JOIN presensi p ON p.santri_id = s.id
                AND p.tanggal_presensi = :today
                AND p.kegiatan_id = :kid
            WHERE ' . $aktifSql . '
              AND s.tingkatan IN (' . $inSql . ')
              AND EXISTS (
                  SELECT 1 FROM jadwal_kegiatan j
                  INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
                  WHERE j.kegiatan_id = :kid
                    AND (j.hari_ke = 0 OR j.hari_ke = :hari)
                    AND (
                        j.tingkatan = "Semua Tingkatan"
                        OR j.tingkatan = s.tingkatan
                    )
              )
            ORDER BY s.tingkatan ASC, s.nama_santri ASC
            LIMIT 500
        ';
    } else {
        $sql = '
            SELECT s.id, s.nis, s.nama_santri, s.tingkatan,
                   "" AS status_hari_ini,
                   NULL AS jam_presensi
            FROM santri s
            WHERE ' . $aktifSql . '
              AND s.tingkatan IN (' . $inSql . ')
            ORDER BY s.tingkatan ASC, s.nama_santri ASC
            LIMIT 500
        ';
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($kegiatanId > 0 && $rows !== []) {
        require_once __DIR__ . '/presensi_jadwal.php';

        return presensi_apply_status_efektif_rows($pdo, $rows, $today);
    }

    return $rows;
}

/**
 * Nilai ikhtibar hari ini untuk santri pada tingkatan terpilih saja.
 *
 * @return list<array<string,mixed>>
 */
function pembimbing_dashboard_nilai_kelas_hari_ini(PDO $pdo, array $tingkatanList, string $today, int $pembimbingUserId, bool $bolehSemua): array
{
    if (!table_exists($pdo, 'ikhtibar_tugas') || $tingkatanList === []) {
        return [];
    }
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $params['today'] = $today;
    $creatorSql = '';
    if (!$bolehSemua && $pembimbingUserId > 0) {
        $creatorSql = ' AND t.created_by = :uid';
        $params['uid'] = $pembimbingUserId;
    }
    $sql = '
        SELECT s.nama_santri, s.nis, s.tingkatan, t.judul AS tugas_judul,
               j.nilai, j.submitted_at
        FROM ikhtibar_jawaban j
        INNER JOIN ikhtibar_sesi ses ON ses.id = j.sesi_id
        INNER JOIN ikhtibar_tugas t ON t.id = ses.tugas_id
        INNER JOIN santri s ON s.id = j.santri_id
        WHERE DATE(j.submitted_at) = :today
          AND s.tingkatan IN (' . $inSql . ')' . $creatorSql . '
        ORDER BY s.tingkatan, s.nama_santri, j.submitted_at DESC
        LIMIT 200
    ';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Kegiatan unik dari jadwal yang diampu pembimbing (untuk penilaian manual).
 *
 * @return list<array{id:int,nama_kegiatan:string}>
 */
function pembimbing_dashboard_kegiatan_dari_jadwal(PDO $pdo, ?int $pembimbingId, bool $bolehSemua): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    try {
        $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
    } catch (PDOException $e) {
        // ignore
    }
    $sql = '
        SELECT DISTINCT k.id, k.nama_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE COALESCE(k.is_active, 1) = 1
    ';
    $params = [];
    if (!$bolehSemua && $pembimbingId !== null && $pembimbingId > 0) {
        $sql .= ' AND j.pembimbing_id = :pid';
        $params['pid'] = $pembimbingId;
    }
    $sql .= ' ORDER BY k.nama_kegiatan ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
        ];
    }
    if (!$bolehSemua && $pembimbingId !== null && $pembimbingId > 0) {
        require_once __DIR__ . '/pembimbing_pkpps.php';
        $seen = array_flip(array_column($out, 'id'));
        foreach (pembimbing_pkpps_kegiatan_dari_jadwal($pdo, $pembimbingId) as $pk) {
            if (!isset($seen[$pk['id']])) {
                $out[] = $pk;
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['nama_kegiatan'], (string) $b['nama_kegiatan']));
    }

    return $out;
}

/**
 * Slot jadwal yang diampu pembimbing (untuk izin & ubah jam).
 *
 * @return list<array{id:int,kegiatan_id:int,nama_kegiatan:string,tingkatan:string,hari_ke:int,jam_mulai:string,jam_selesai:string}>
 */
function pembimbing_dashboard_jadwal_slots(PDO $pdo, int $pembimbingId): array
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    try {
        $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
    } catch (PDOException $e) {
        // ignore
    }
    $st = $pdo->prepare('
        SELECT j.id, j.kegiatan_id, k.nama_kegiatan, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.pembimbing_id = :pid
        ORDER BY j.hari_ke ASC, j.jam_mulai ASC, k.nama_kegiatan ASC
    ');
    $st->execute(['pid' => $pembimbingId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0),
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
            'tingkatan' => (string) ($row['tingkatan'] ?? ''),
            'hari_ke' => (int) ($row['hari_ke'] ?? 0),
            'jam_mulai' => (string) ($row['jam_mulai'] ?? ''),
            'jam_selesai' => (string) ($row['jam_selesai'] ?? ''),
        ];
    }

    return $out;
}

/**
 *
 * @param list<string> $tingkatanList
 * @return list<string>
 */
function pembimbing_dashboard_kelas_list(PDO $pdo, array $tingkatanList): array
{
    if ($tingkatanList === [] || !table_exists($pdo, 'santri') || !column_exists($pdo, 'santri', 'kategori_kelas')) {
        return [];
    }
    [$inSql, $params] = pembimbing_dashboard_in_clause($tingkatanList, 'tk');
    $aktifSql = function_exists('santri_sql_aktif_only') ? santri_sql_aktif_only('s') : 'COALESCE(s.is_aktif, 1) = 1';
    $st = $pdo->prepare('
        SELECT DISTINCT TRIM(s.kategori_kelas) AS kelas
        FROM santri s
        WHERE ' . $aktifSql . '
          AND s.tingkatan IN (' . $inSql . ')
          AND s.kategori_kelas IS NOT NULL
          AND TRIM(s.kategori_kelas) <> ""
        ORDER BY kelas ASC
    ');
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $kelas) {
        $k = trim((string) $kelas);
        if ($k !== '') {
            $out[] = $k;
        }
    }

    return $out;
}

/**
 * Apakah santri termasuk dalam lingkup asuhan pembimbing (tingkatan dari jadwal).
 */
function pembimbing_dashboard_santri_dalam_scope(
    PDO $pdo,
    int $santriId,
    ?int $pembimbingId,
    bool $bolehSemua
): bool {
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return false;
    }
    if ($bolehSemua) {
        return true;
    }
    if ($pembimbingId === null || $pembimbingId <= 0) {
        return false;
    }
    $tingkatanAllowed = pembimbing_dashboard_tingkatan_list($pdo, $pembimbingId, false);
    if ($tingkatanAllowed === []) {
        return false;
    }
    require_once __DIR__ . '/pembimbing_pkpps.php';
    if (pembimbing_pkpps_santri_in_scope($pdo, $santriId, $pembimbingId)) {
        return true;
    }
    $st = $pdo->prepare('SELECT tingkatan FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $tk = trim((string) ($st->fetchColumn() ?: ''));
    if ($tk === '') {
        return false;
    }
    foreach ($tingkatanAllowed as $allowed) {
        if (function_exists('perizinan_pembimbing_tingkatan_cocok')) {
            if (perizinan_pembimbing_tingkatan_cocok($pdo, $tk, (string) $allowed)) {
                return true;
            }
        } elseif ($tk === trim((string) $allowed)) {
            return true;
        }
    }

    return false;
}

/**
 * Rekap presensi santri diasuh per kegiatan (tahun berjalan).
 *
 * @return list<array{kegiatan_id:int,nama_kegiatan:string,hadir:int,izin:int,sakit:int,alpa:int,total:int}>
 */
function pembimbing_dashboard_presensi_rekap_per_kegiatan(PDO $pdo, array $tingkatanList, int $tahun, bool $runFinalize = false): array
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || $tingkatanList === []) {
        return [];
    }
    if ($runFinalize) {
        require_once __DIR__ . '/presensi_jadwal.php';
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $monthStart, $today, $auditUserId > 0 ? $auditUserId : 1);
    }
    [$presStart, $presEnd] = pembimbing_dashboard_tahun_presensi_bounds($tahun);
    require_once __DIR__ . '/pembimbing_pkpps.php';
    $kajianList = [];
    $pkppsLabels = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        if (pembimbing_pkpps_is_label($tk)) {
            $pkppsLabels[] = $tk;
        } else {
            $kajianList[] = $tk;
        }
    }

    $santriFilter = [];
    if ($kajianList !== [] && column_exists($pdo, 'santri', 'tingkatan')) {
        [$inSql, $params] = pembimbing_dashboard_in_clause($kajianList, 'tk');
        $aktifSql = santri_sql_aktif_only('s');
        $st = $pdo->prepare('SELECT s.id FROM santri s WHERE ' . $aktifSql . ' AND s.tingkatan IN (' . $inSql . ')');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
            $santriFilter[(int) $sid] = true;
        }
    }
    if ($pkppsLabels !== [] && table_exists($pdo, 'pkpps_santri')) {
        pkpps_ensure_schema($pdo);
        foreach ($pkppsLabels as $lbl) {
            $nama = trim(substr(trim($lbl), strlen(PEMBIMBING_PKPPS_LABEL_PREFIX)));
            if ($nama === '') {
                continue;
            }
            $stT = $pdo->prepare('SELECT id FROM pkpps_tingkatan WHERE nama_tingkatan = :n LIMIT 1');
            $stT->execute(['n' => $nama]);
            $tid = (int) ($stT->fetchColumn() ?: 0);
            if ($tid <= 0) {
                continue;
            }
            $st = $pdo->prepare('SELECT santri_id FROM pkpps_santri WHERE is_aktif = 1 AND pkpps_tingkatan_id = :tid');
            $st->execute(['tid' => $tid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
                $santriFilter[(int) $sid] = true;
            }
        }
    }
    $santriIds = array_keys($santriFilter);
    if ($santriIds === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($santriIds), '?'));
    $params = array_merge([$presStart, $presEnd], $santriIds);
    $sql = '
        SELECT
            COALESCE(p.kegiatan_id, 0) AS kegiatan_id,
            COALESCE(k.nama_kegiatan, "Lainnya / tanpa kegiatan") AS nama_kegiatan,
            COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
            COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
            COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
            COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
            COALESCE(COUNT(p.id), 0) AS total
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.tanggal_presensi >= ? AND p.tanggal_presensi <= ?
          AND p.santri_id IN (' . $ph . ')
        GROUP BY p.kegiatan_id, k.nama_kegiatan
        ORDER BY k.nama_kegiatan ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
