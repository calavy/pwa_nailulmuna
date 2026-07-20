<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Map santri → jenis izin non-tetap yang masih berlaku pada tanggal (belum kembali).
 * Santri yang sudah scan presensi HADIR di tanggal itu dikecualikan — izin tidak berlaku.
 *
 * @return array<int, string>
 */
function perizinan_map_izin_berlaku_tanggal(PDO $pdo, string $tanggal): array
{
    if (!table_exists($pdo, 'perizinan') || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return [];
    }

    $approvalFilter = '';
    if (column_exists($pdo, 'perizinan', 'approval_status')) {
        $approvalFilter = ' AND (approval_status = "DISETUJUI" OR approval_status IS NULL)';
    }

    $st = $pdo->prepare('
        SELECT santri_id, jenis_izin
        FROM perizinan
        WHERE status_izin = "IZIN"
          AND waktu_kembali IS NULL
          AND :tanggal BETWEEN tanggal_mulai AND tanggal_selesai' . $approvalFilter . '
    ');
    $st->execute(['tanggal' => $tanggal]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $map[$sid] = (string) ($row['jenis_izin'] ?? 'IZIN');
        }
    }

    if ($map === [] || !table_exists($pdo, 'presensi')) {
        return $map;
    }

    $hadirSt = $pdo->prepare('
        SELECT DISTINCT santri_id
        FROM presensi
        WHERE tanggal_presensi = :tgl
          AND status_presensi = "HADIR"
    ');
    $hadirSt->execute(['tgl' => $tanggal]);
    foreach ($hadirSt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $hadirId) {
        unset($map[(int) $hadirId]);
    }

    return $map;
}

/**
 * Santri izin disetujui yang belum kembali (belum scan kartu di Scan Presensi / belum ditandai selesai).
 *
 * @return list<array<string, mixed>>
 */
function perizinan_aktif_belum_kembali(PDO $pdo, ?string $tingkatan = null): array
{
    if (!table_exists($pdo, 'perizinan')) {
        return [];
    }

    $sql = '
        SELECT i.id, i.santri_id, i.rombongan_id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai,
               i.jam_mulai, i.jam_selesai, i.alasan, i.waktu_keluar, i.waktu_kembali,
               i.grace_menit, i.poin_pelanggaran, i.qr_token,
               s.nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.status_izin = "IZIN"
          AND (i.approval_status = "DISETUJUI" OR i.approval_status IS NULL)
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
 * Tandai izin selesai (kembali) tanpa scan kartu santri di Scan Presensi.
 *
 * @return array{ok:bool,message:string,late_point?:int,late_minutes?:int}
 */
function perizinan_tandai_kembali_manual(PDO $pdo, int $izinId, int $userId): array
{
    if ($izinId <= 0) {
        return ['ok' => false, 'message' => 'ID izin tidak valid.'];
    }

    $st = $pdo->prepare('
        SELECT i.id, i.tanggal_selesai, i.jam_selesai, i.waktu_keluar, i.grace_menit, i.santri_id,
               i.rombongan_id, s.nama_santri
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
          AND i.status_izin = "IZIN"
          AND (i.approval_status = "DISETUJUI" OR i.approval_status IS NULL)
          AND i.waktu_kembali IS NULL
        LIMIT 1
    ');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Izin tidak ditemukan atau sudah selesai.'];
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

    $rombonganId = (int) ($row['rombongan_id'] ?? 0);
    $setRombongan = $rombonganId > 0 && column_exists($pdo, 'perizinan', 'rombongan_kembali')
        ? ', rombongan_kembali = 1'
        : '';
    $up = $pdo->prepare('
        UPDATE perizinan
        SET status_izin = "KEMBALI", waktu_kembali = NOW(), poin_pelanggaran = :poin' . $setRombongan . '
        WHERE id = :id
    ');
    $up->execute(['id' => $izinId, 'poin' => $latePoint]);

    $pdo->prepare('UPDATE santri SET is_aktif = 1 WHERE id = :id')->execute(['id' => (int) $row['santri_id']]);

    require_once __DIR__ . '/perizinan_approval.php';
    perizinan_kirim_wa_pengurus_izin_selesai($pdo, $izinId, $lateMinutes, $latePoint);

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

/**
 * Izin disetujui yang masih berjalan / santri belum kembali (satu baris terbaru per santri).
 *
 * @return array<string, mixed>|null
 */
function perizinan_izin_aktif_santri(PDO $pdo, int $santriId, ?string $tanggal = null): ?array
{
    if ($santriId <= 0 || !table_exists($pdo, 'perizinan')) {
        return null;
    }

    $tanggal = $tanggal !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($tanggal))
        ? trim($tanggal)
        : date('Y-m-d');

    $st = $pdo->prepare('
        SELECT id, santri_id, jenis_izin, syari_kategori, tanggal_mulai, tanggal_selesai,
               jam_mulai, jam_selesai, alasan, tujuan, approval_status, status_izin, waktu_kembali
        FROM perizinan
        WHERE santri_id = :sid
          AND status_izin = "IZIN"
          AND approval_status = "DISETUJUI"
          AND waktu_kembali IS NULL
          AND tanggal_mulai <= :tgl
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'tgl' => $tanggal]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return list<string> */
function perizinan_perpanjang_jenis_allowed(PDO $pdo, bool $includeSyari = false): array
{
    $jenisAllowed = array_values(array_filter(array_map(
        'trim',
        explode(',', strtoupper((string) app_setting($pdo, 'izin_perpanjangan_jenis', 'SAKIT,KELUAR')))
    )));
    if ($includeSyari && !in_array('SYARI', $jenisAllowed, true)) {
        $jenisAllowed[] = 'SYARI';
    }

    return $jenisAllowed;
}

/**
 * Perpanjang tanggal selesai izin yang masih disetujui.
 *
 * @return array{ok:bool,message:string}
 */
function perizinan_perpanjang_izin(
    PDO $pdo,
    int $izinId,
    string $tglBaru,
    string $alasanPerpanjangan = '',
    bool $includeSyariJenis = false,
    string $sumberCatatan = 'admin'
): array {
    if ($izinId <= 0 || $tglBaru === '') {
        return ['ok' => false, 'message' => 'Data perpanjangan tidak lengkap.'];
    }

    $maxHari = max(1, (int) app_setting($pdo, 'izin_perpanjangan_max_hari', '7'));
    $jenisAllowed = perizinan_perpanjang_jenis_allowed($pdo, $includeSyariJenis);

    $st = $pdo->prepare('
        SELECT id, jenis_izin, tanggal_mulai, tanggal_selesai, approval_status, status_izin, waktu_kembali, alasan
        FROM perizinan
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $izinId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Izin tidak ditemukan.'];
    }

    if (($row['approval_status'] ?? '') !== 'DISETUJUI') {
        return ['ok' => false, 'message' => 'Hanya izin yang sudah disetujui dapat diperpanjang.'];
    }
    if (($row['status_izin'] ?? '') !== 'IZIN' || ($row['waktu_kembali'] ?? null) !== null) {
        return ['ok' => false, 'message' => 'Izin sudah selesai — tidak dapat diperpanjang.'];
    }

    $jenis = strtoupper((string) ($row['jenis_izin'] ?? ''));
    if (!in_array($jenis, $jenisAllowed, true)) {
        return ['ok' => false, 'message' => 'Jenis izin ini tidak dapat diperpanjang sesuai pengaturan pondok.'];
    }

    $tglLama = (string) ($row['tanggal_selesai'] ?? '');
    $tsLama = strtotime($tglLama);
    $tsBaru = strtotime($tglBaru);
    if ($tsBaru === false || $tsLama === false || $tsBaru < $tsLama) {
        return ['ok' => false, 'message' => 'Tanggal selesai baru harus sama atau setelah tanggal selesai saat ini.'];
    }

    $selisih = (int) round(($tsBaru - $tsLama) / 86400);
    if ($selisih > $maxHari) {
        return ['ok' => false, 'message' => 'Perpanjangan melebihi batas ' . $maxHari . ' hari (pengaturan pondok).'];
    }

    $alasanPerpanjangan = trim($alasanPerpanjangan);
    $params = ['tgl' => $tglBaru, 'id' => $izinId];
    $sql = 'UPDATE perizinan SET tanggal_selesai = :tgl';
    if ($alasanPerpanjangan !== '') {
        $labelSumber = $sumberCatatan === 'wali' ? 'portal wali' : 'pengurus';
        $catatan = "\n[Perpanjangan " . date('d/m/Y H:i') . ' via ' . $labelSumber . ']: ' . $alasanPerpanjangan;
        $sql .= ', alasan = :alasan';
        $params['alasan'] = trim((string) ($row['alasan'] ?? '')) . $catatan;
    }
    $sql .= ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    $tglFormatted = function_exists('app_format_tanggal_id')
        ? app_format_tanggal_id($tglBaru)
        : $tglBaru;

    return [
        'ok' => true,
        'message' => 'Perpanjangan izin disimpan sampai ' . $tglFormatted . '.',
    ];
}
