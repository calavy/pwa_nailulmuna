<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** TTL snapshot KPI beranda admin (detik). */
function dashboard_admin_kpi_cache_ttl(): int
{
    return 45;
}

/**
 * @return array{
 *   putra:int,
 *   putri:int,
 *   mukimin:int,
 *   izin_aktif_count:int,
 *   izin_aktif_rows:list<array<string,mixed>>
 * }
 */
function dashboard_admin_kpi_snapshot(PDO $pdo, string $today): array
{
    $sessionKey = 'dash_admin_kpi_v1_' . $today;
    $cached = $_SESSION[$sessionKey] ?? null;
    if (
        is_array($cached)
        && isset($cached['expires'], $cached['data'])
        && is_array($cached['data'])
        && (int) $cached['expires'] >= time()
    ) {
        return $cached['data'];
    }

    $putra = 0;
    $putri = 0;
    if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'jenis_kelamin')) {
        $aktifSql = '';
        if (column_exists($pdo, 'santri', 'status_santri')) {
            $aktifSql = ' WHERE ' . santri_sql_aktif_only('santri');
        } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
            $aktifSql = ' WHERE COALESCE(is_aktif, 1) = 1';
        }
        $row = $pdo->query(
            'SELECT
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra,
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri
         FROM santri' . $aktifSql
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $putra = (int) ($row['putra'] ?? 0);
        $putri = (int) ($row['putri'] ?? 0);
    }

    if (!function_exists('mukimin_count')) {
        require_once __DIR__ . '/mukimin.php';
    }
    $mukiminCount = mukimin_count($pdo);

    $izinAktifCount = 0;
    $izinAktifRows = [];
    $sqlAktifSantri = santri_sql_aktif_only('s');
    if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
        $approvalSql = '';
        if (column_exists($pdo, 'perizinan', 'approval_status')) {
            $approvalSql = ' AND i.approval_status = "DISETUJUI"';
        }
        $cntStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifSantri . '
         WHERE i.status_izin = "IZIN"
           AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql
        );
        $cntStmt->execute(['today' => $today]);
        $izinAktifCount = (int) $cntStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, s.nama_santri, s.nis, s.tingkatan
         FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifSantri . '
         WHERE i.status_izin = "IZIN"
           AND :today2 BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql . '
         ORDER BY s.nama_santri ASC
         LIMIT 24'
        );
        $stmt->execute(['today2' => $today]);
        $izinAktifRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $data = [
        'putra' => $putra,
        'putri' => $putri,
        'mukimin' => $mukiminCount,
        'izin_aktif_count' => $izinAktifCount,
        'izin_aktif_rows' => $izinAktifRows,
    ];

    $_SESSION[$sessionKey] = [
        'expires' => time() + dashboard_admin_kpi_cache_ttl(),
        'data' => $data,
    ];

    return $data;
}
