<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Santri izin disetujui yang belum kembali (belum scan QR kembali / belum ditandai selesai).
 *
 * @return list<array<string, mixed>>
 */
function perizinan_aktif_belum_kembali(PDO $pdo, ?string $tingkatan = null): array
{
    if (!table_exists($pdo, 'perizinan')) {
        return [];
    }

    $sql = '
        SELECT i.id, i.santri_id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai,
               i.jam_mulai, i.jam_selesai, i.alasan, i.waktu_keluar, i.waktu_kembali,
               i.grace_menit, i.poin_pelanggaran, i.qr_token,
               s.nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.status_izin = "IZIN"
          AND (i.approval_status = "DISETUJUI" OR i.approval_status IS NULL)
          AND (i.rombongan_id IS NULL OR i.rombongan_id = 0)
          AND i.waktu_kembali IS NULL
    ';
    $params = [];
    if ($tingkatan !== null && $tingkatan !== '') {
        $sql .= ' AND LOWER(TRIM(s.tingkatan)) = LOWER(TRIM(:tk))';
        $params['tk'] = $tingkatan;
    }
    $sql .= ' ORDER BY i.tanggal_selesai ASC, i.jam_selesai ASC, s.nama_santri ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Tandai izin selesai (kembali) tanpa scan QR — logika sama dengan scan kembali di gerbang.
 *
 * @return array{ok:bool,message:string,late_point?:int,late_minutes?:int}
 */
function perizinan_tandai_kembali_manual(PDO $pdo, int $izinId, int $userId): array
{
    if ($izinId <= 0) {
        return ['ok' => false, 'message' => 'ID izin tidak valid.'];
    }

    $st = $pdo->prepare('
        SELECT i.id, i.tanggal_selesai, i.jam_selesai, i.waktu_keluar, i.grace_menit, i.santri_id, s.nama_santri
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
          AND i.status_izin = "IZIN"
          AND (i.approval_status = "DISETUJUI" OR i.approval_status IS NULL)
          AND (i.rombongan_id IS NULL OR i.rombongan_id = 0)
          AND i.waktu_kembali IS NULL
        LIMIT 1
    ');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Izin tidak ditemukan atau sudah selesai.'];
    }

    if (empty($row['waktu_keluar'])) {
        $pdo->prepare('UPDATE perizinan SET waktu_keluar = NOW() WHERE id = :id')->execute(['id' => $izinId]);
    }

    $grace = isset($row['grace_menit']) ? (int) $row['grace_menit'] : (int) app_setting($pdo, 'grace_period_menit', '15');
    $latePoint = 0;
    $lateMinutes = 0;
    $batasTs = strtotime((string) $row['tanggal_selesai'] . ' ' . (string) $row['jam_selesai']);
    $nowTs = time();
    if ($batasTs !== false && $nowTs > $batasTs) {
        $lateMinutes = (int) floor(($nowTs - $batasTs) / 60);
        if ($lateMinutes > $grace) {
            $latePoint = max(1, (int) app_setting($pdo, 'point_auto_telat', '1'));
        }
    }

    $up = $pdo->prepare('
        UPDATE perizinan
        SET status_izin = "KEMBALI", waktu_kembali = NOW(), poin_pelanggaran = :poin
        WHERE id = :id
    ');
    $up->execute(['id' => $izinId, 'poin' => $latePoint]);

    $pdo->prepare('UPDATE santri SET is_aktif = 1 WHERE id = :id')->execute(['id' => (int) $row['santri_id']]);

    if ($latePoint > 0 && function_exists('ensure_point_tables')) {
        ensure_point_tables($pdo);
        $ledger = $pdo->prepare('
            INSERT IGNORE INTO point_ledger
            (santri_id, tanggal, jenis_perubahan, point_delta, sumber_data, reference_presensi_id, keterangan, created_by)
            VALUES
            (:santri_id, CURDATE(), "PLUS", :point_delta, "PERIZINAN_TELAT_AUTO", :reference_id, :keterangan, :created_by)
        ');
        $ledger->execute([
            'santri_id' => (int) $row['santri_id'],
            'point_delta' => $latePoint,
            'reference_id' => $izinId,
            'keterangan' => 'Auto poin dari keterlambatan kembali izin (manual). Telat ' . $lateMinutes . ' menit (toleransi ' . $grace . ' menit).',
            'created_by' => $userId > 0 ? $userId : 1,
        ]);
    }

    $nama = (string) ($row['nama_santri'] ?? 'Santri');
    $msg = 'Izin selesai: ' . $nama;
    if ($latePoint > 0) {
        $msg .= ' (terlambat, poin +' . $latePoint . ')';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'late_point' => $latePoint,
        'late_minutes' => $lateMinutes,
    ];
}

/**
 * Akhiri izin aktif saat santri scan kartu/QR identitas (kembali ke pondok).
 *
 * @return array{ok:bool,message:string,izin_id?:int}|null null jika tidak ada izin aktif
 */
function perizinan_selesai_dari_scan_kartu(PDO $pdo, int $santriId, int $userId): ?array
{
    if ($santriId <= 0 || !table_exists($pdo, 'perizinan')) {
        return null;
    }

    $st = $pdo->prepare('
        SELECT id FROM perizinan
        WHERE santri_id = :sid
          AND status_izin = "IZIN"
          AND (approval_status = "DISETUJUI" OR approval_status IS NULL)
          AND (rombongan_id IS NULL OR rombongan_id = 0)
          AND waktu_kembali IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId]);
    $izinId = (int) ($st->fetchColumn() ?: 0);
    if ($izinId <= 0) {
        return null;
    }

    $res = perizinan_tandai_kembali_manual($pdo, $izinId, $userId);

    return $res + ['izin_id' => $izinId];
}
