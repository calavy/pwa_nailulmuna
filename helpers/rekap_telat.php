<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Rekap telat kembali dari perizinan (status KEMBALI melewati batas).
 *
 * @return list<array<string, mixed>>
 */
function rekap_telat_izin_kembali(PDO $pdo, string $startDate, string $endDate, int $lateTolerance, string $namaFilter = '', string $tingkatan = ''): array
{
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $query = '
        SELECT i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai, i.durasi_jam, i.alasan, i.jenis_izin, i.status_izin, i.waktu_kembali,
               s.nama_santri, s.tingkatan, s.nis
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.status_izin = "KEMBALI"
          AND i.waktu_kembali IS NOT NULL
          AND i.tanggal_selesai BETWEEN :start_date AND :end_date
          AND i.jam_selesai IS NOT NULL
          AND TIMESTAMPDIFF(MINUTE, TIMESTAMP(i.tanggal_selesai, i.jam_selesai), i.waktu_kembali) > :late_tolerance
    ';
    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'late_tolerance' => max(0, $lateTolerance),
    ];
    if ($tingkatan !== '') {
        $query .= ' AND LOWER(s.tingkatan) = LOWER(:tingkatan)';
        $params['tingkatan'] = $tingkatan;
    }
    if ($namaFilter !== '') {
        $query .= ' AND (s.nama_santri LIKE :nama OR s.nis LIKE :nama)';
        $params['nama'] = '%' . $namaFilter . '%';
    }
    $query .= ' ORDER BY i.tanggal_selesai DESC, i.jam_selesai DESC';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Rekap telat kegiatan (scan hadir melewati jam_mulai + toleransi).
 *
 * @return list<array<string, mixed>>
 */
function rekap_telat_kegiatan(PDO $pdo, string $startDate, string $endDate, int $lateTolerance, string $namaFilter = '', string $kegiatanFilter = '', string $tingkatan = ''): array
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }

    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'late_tolerance' => max(0, $lateTolerance),
    ];
    $where = '
        WHERE p.status_presensi = "HADIR"
          AND p.tanggal_presensi BETWEEN :start_date AND :end_date
          AND p.jam_presensi IS NOT NULL
    ';
    if ($tingkatan !== '') {
        $where .= ' AND LOWER(s.tingkatan) = LOWER(:tingkatan)';
        $params['tingkatan'] = $tingkatan;
    }
    if ($namaFilter !== '') {
        $where .= ' AND (s.nama_santri LIKE :nama OR s.nis LIKE :nama)';
        $params['nama'] = '%' . $namaFilter . '%';
    }
    if ($kegiatanFilter !== '') {
        $where .= ' AND k.nama_kegiatan LIKE :kegiatan';
        $params['kegiatan'] = '%' . $kegiatanFilter . '%';
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    $sql = '
        SELECT
            p.tanggal_presensi,
            p.jam_presensi,
            p.catatan,
            s.' . $nameCol . ' AS nama_santri,
            s.nis,
            s.tingkatan,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS nama_kegiatan,
            COALESCE(j.jam_mulai, "") AS jam_mulai_jadwal
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        LEFT JOIN jadwal_kegiatan j ON j.id = p.jadwal_kegiatan_id
        ' . $where . '
        ORDER BY p.tanggal_presensi DESC, p.jam_presensi DESC
        LIMIT 2000
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];

    foreach ($raw as $row) {
        $lateMinutes = rekap_telat_kegiatan_hitung_menit($row, $lateTolerance);
        if ($lateMinutes <= 0) {
            continue;
        }
        $row['telat_menit'] = $lateMinutes;
        $out[] = $row;
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 */
function rekap_telat_kegiatan_hitung_menit(array $row, int $lateTolerance): int
{
    $catatan = (string) ($row['catatan'] ?? '');
    if (preg_match('/Terlambat\s+(\d+)\s+menit/i', $catatan, $m)) {
        return max(0, (int) $m[1]);
    }

    $jamMulai = trim((string) ($row['jam_mulai_jadwal'] ?? ''));
    $jamPresensi = trim((string) ($row['jam_presensi'] ?? ''));
    if ($jamMulai === '' || $jamPresensi === '') {
        return 0;
    }

    $start = DateTime::createFromFormat('H:i:s', strlen($jamMulai) === 5 ? $jamMulai . ':00' : $jamMulai);
    $scan = DateTime::createFromFormat('H:i:s', strlen($jamPresensi) === 5 ? $jamPresensi . ':00' : $jamPresensi);
    if (!$start || !$scan) {
        return 0;
    }
    $threshold = (clone $start)->modify('+' . max(0, $lateTolerance) . ' minutes');
    if ($scan <= $threshold) {
        return 0;
    }

    return (int) floor(($scan->getTimestamp() - $threshold->getTimestamp()) / 60);
}

/**
 * @param list<array<string, mixed>> $rowsIzin
 * @return array{total:int,menit:int,rata:int,berat:int}
 */
function rekap_telat_izin_stats(array $rowsIzin): array
{
    $totalMenit = 0;
    $kasusBerat = 0;
    foreach ($rowsIzin as $r) {
        $limitTs = strtotime((string) ($r['tanggal_selesai'] ?? '') . ' ' . (string) ($r['jam_selesai'] ?? ''));
        $backTs = strtotime((string) ($r['waktu_kembali'] ?? ''));
        if ($limitTs === false || $backTs === false || $backTs <= $limitTs) {
            continue;
        }
        $diffMin = (int) floor(($backTs - $limitTs) / 60);
        $totalMenit += $diffMin;
        if ($diffMin >= 60) {
            $kasusBerat++;
        }
    }
    $total = count($rowsIzin);

    return [
        'total' => $total,
        'menit' => $totalMenit,
        'rata' => $total > 0 ? (int) round($totalMenit / $total) : 0,
        'berat' => $kasusBerat,
    ];
}

/**
 * @param list<array<string, mixed>> $rowsKeg
 * @return array{total:int,menit:int,rata:int,berat:int}
 */
function rekap_telat_kegiatan_stats(array $rowsKeg): array
{
    $totalMenit = 0;
    $kasusBerat = 0;
    foreach ($rowsKeg as $r) {
        $m = (int) ($r['telat_menit'] ?? 0);
        $totalMenit += $m;
        if ($m >= 60) {
            $kasusBerat++;
        }
    }
    $total = count($rowsKeg);

    return [
        'total' => $total,
        'menit' => $totalMenit,
        'rata' => $total > 0 ? (int) round($totalMenit / $total) : 0,
        'berat' => $kasusBerat,
    ];
}

function rekap_telat_badge_class(int $lateMinutes): string
{
    if ($lateMinutes >= 60) {
        return 'danger';
    }
    if ($lateMinutes >= 30) {
        return 'warning';
    }

    return 'secondary';
}
