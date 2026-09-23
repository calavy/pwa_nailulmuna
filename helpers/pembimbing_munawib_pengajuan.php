<?php

declare(strict_types=1);

/**
 * Terapkan munawib + override untuk satu tanggal (setelah disetujui pengasuh).
 *
 * @param array<string,mixed> $slot
 * @param list<array{hal:string,isi:string}> $materiRows
 * @return array{ok:bool,pesan:string}
 */
function pb_jadwal_terapkan_munawib_hari(
    PDO $pdo,
    int $pembimbingId,
    array $slot,
    string $tanggal,
    int $munawibId,
    string $alasan,
    int $userId,
    array $materiRows,
    bool $kirimNotifPetugas = false
): array {
    pb_jadwal_override_ensure_schema($pdo);
    munawib_ensure_schema($pdo);
    if ($munawibId <= 0) {
        return ['ok' => false, 'pesan' => 'Munawib tidak valid.'];
    }
    $materiJson = pb_jadwal_materi_to_json($materiRows);

    $stMw = $pdo->prepare('SELECT id, nama FROM munawib WHERE id = :id AND COALESCE(is_aktif,1)=1 LIMIT 1');
    $stMw->execute(['id' => $munawibId]);
    $mw = $stMw->fetch(PDO::FETCH_ASSOC);
    if (!$mw) {
        return ['ok' => false, 'pesan' => 'Munawib tidak ditemukan.'];
    }

    $jadwalId = (int) ($slot['jadwal_id'] ?? 0);
    $kegiatanId = (int) ($slot['kegiatan_id'] ?? 0);

    $pdo->prepare('
        INSERT INTO munawib_penugasan (pembimbing_id, munawib_id, jadwal_kegiatan_id, kegiatan_id, tanggal_mulai, tanggal_selesai, alasan, status, created_by)
        VALUES (:pb, :mid, :jid, :kid, :tgl, :tgl, :alasan, "AKTIF", :uid)
    ')->execute([
        'pb' => $pembimbingId,
        'mid' => $munawibId,
        'jid' => $jadwalId,
        'kid' => $kegiatanId,
        'tgl' => $tanggal,
        'alasan' => $alasan,
        'uid' => $userId > 0 ? $userId : null,
    ]);

    $stExist = $pdo->prepare('SELECT id FROM pembimbing_jadwal_override WHERE pembimbing_id = :pb AND jadwal_id = :jid AND tanggal = :tgl AND jenis = "CARI_MUNAWIB" LIMIT 1');
    $stExist->execute(['pb' => $pembimbingId, 'jid' => $jadwalId, 'tgl' => $tanggal]);
    $existId = (int) ($stExist->fetchColumn() ?: 0);
    if ($existId > 0) {
        $pdo->prepare('UPDATE pembimbing_jadwal_override SET munawib_id = :mid, materi_pengganti = :mat, alasan = :alasan, updated_at = NOW() WHERE id = :id')
            ->execute(['mid' => $munawibId, 'mat' => $materiJson, 'alasan' => $alasan, 'id' => $existId]);
    } else {
        $pdo->prepare('
            INSERT INTO pembimbing_jadwal_override
            (pembimbing_id, jadwal_id, kegiatan_id, tanggal, jenis, jam_mulai_asli, jam_selesai_asli, munawib_id, materi_pengganti, alasan)
            VALUES (:pb, :jid, :kid, :tgl, "CARI_MUNAWIB", :jma, :jsa, :mid, :mat, :alasan)
        ')->execute([
            'pb' => $pembimbingId,
            'jid' => $jadwalId,
            'kid' => $kegiatanId,
            'tgl' => $tanggal,
            'jma' => $slot['jam_mulai'],
            'jsa' => $slot['jam_selesai'],
            'mid' => $munawibId,
            'mat' => $materiJson,
            'alasan' => $alasan,
        ]);
    }

    if ($kirimNotifPetugas) {
        pb_jadwal_kirim_notifikasi(
            $pdo,
            '👤 Permintaan munawib',
            (string) ($slot['nama_kegiatan'] ?? '') . ' · ' . $tanggal . "\n"
            . 'Munawib: ' . (string) ($mw['nama'] ?? '') . "\n"
            . 'Tugas: ' . pb_jadwal_materi_ringkas($materiJson) . "\nAlasan: " . $alasan,
            'pb_jadwal:' . $pembimbingId . ':' . $jadwalId . ':' . $tanggal . ':cari_munawib'
        );
    }

    return ['ok' => true, 'pesan' => ''];
}

/**
 * @param list<array{hal:string,isi:string}> $materiRows
 * @return array{ok:bool,pesan:string,id?:int}
 */
function pb_munawib_pengajuan_simpan(
    PDO $pdo,
    int $pembimbingId,
    int $jadwalId,
    string $tanggalMulai,
    string $tanggalSelesai,
    int $munawibId,
    string $alasan,
    int $userId,
    array $materiRows
): array {
    pb_munawib_pengajuan_ensure_schema($pdo);
    pb_jadwal_override_ensure_schema($pdo);
    munawib_ensure_schema($pdo);

    if ($pembimbingId <= 0 || $jadwalId <= 0) {
        return ['ok' => false, 'pesan' => 'Data jadwal tidak valid.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalSelesai)) {
        return ['ok' => false, 'pesan' => 'Format tanggal tidak valid.'];
    }
    if ($tanggalSelesai < $tanggalMulai) {
        return ['ok' => false, 'pesan' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
    }
    if ($munawibId <= 0) {
        return ['ok' => false, 'pesan' => 'Pilih munawib pengganti.'];
    }
    $alasan = trim($alasan);
    if ($alasan === '') {
        return ['ok' => false, 'pesan' => 'Alasan wajib diisi (jelaskan sendiri).'];
    }
    if ($materiRows === []) {
        return ['ok' => false, 'pesan' => 'Isi tugas/materi per halaman untuk munawib.'];
    }

    $stMw = $pdo->prepare('SELECT id FROM munawib WHERE id = :id AND COALESCE(is_aktif,1)=1 LIMIT 1');
    $stMw->execute(['id' => $munawibId]);
    if (!$stMw->fetch()) {
        return ['ok' => false, 'pesan' => 'Munawib tidak ditemukan.'];
    }

    $slotRes = pb_jadwal_ambil_slot_pembimbing($pdo, $pembimbingId, $jadwalId, $tanggalMulai);
    if (!$slotRes['ok']) {
        return ['ok' => false, 'pesan' => $slotRes['pesan']];
    }

    $kegiatanId = (int) ($slotRes['slot']['kegiatan_id'] ?? 0);

    $hariGagal = [];
    $hariValid = 0;
    $tsMulai = strtotime($tanggalMulai);
    $tsSelesai = strtotime($tanggalSelesai);
    if ($tsMulai === false || $tsSelesai === false) {
        return ['ok' => false, 'pesan' => 'Rentang tanggal tidak valid.'];
    }
    for ($ts = $tsMulai; $ts <= $tsSelesai; $ts += 86400) {
        $tgl = date('Y-m-d', $ts);
        $slotHari = pb_jadwal_ambil_slot_pembimbing($pdo, $pembimbingId, $jadwalId, $tgl);
        if (!$slotHari['ok']) {
            continue;
        }
        $cek = pb_jadwal_cek_batas_pengajuan($tgl, (string) $slotHari['slot']['jam_mulai']);
        if (!$cek['ok']) {
            $hariGagal[] = $tgl;
            continue;
        }
        $hariValid++;
    }
    if ($hariValid === 0) {
        if ($hariGagal !== []) {
            return [
                'ok' => false,
                'pesan' => 'Tidak ada hari yang memenuhi batas pengajuan (min. ' . PB_JADWAL_BATAS_HARI_PENGAJUAN . ' hari sebelum jadwal). Tanggal bermasalah: ' . implode(', ', $hariGagal) . '.',
            ];
        }

        return ['ok' => false, 'pesan' => 'Tidak ada jadwal kegiatan ini dalam rentang tanggal yang dipilih.'];
    }
    if ($hariGagal !== []) {
        return [
            'ok' => false,
            'pesan' => 'Sebagian tanggal sudah melewati batas pengajuan: ' . implode(', ', $hariGagal) . '. Sesuaikan rentang atau ajukan lebih awal.',
        ];
    }

    $stPending = $pdo->prepare('
        SELECT id FROM pembimbing_munawib_pengajuan
        WHERE pembimbing_id = :pb AND jadwal_id = :jid AND status = "MENUNGGU"
          AND NOT (tanggal_selesai < :mul OR tanggal_mulai > :sel)
        LIMIT 1
    ');
    $stPending->execute(['pb' => $pembimbingId, 'jid' => $jadwalId, 'mul' => $tanggalMulai, 'sel' => $tanggalSelesai]);
    if ((int) ($stPending->fetchColumn() ?: 0) > 0) {
        return ['ok' => false, 'pesan' => 'Masih ada pengajuan munawib menunggu persetujuan untuk rentang tanggal yang tumpang tindih.'];
    }

    $materiJson = pb_jadwal_materi_to_json($materiRows);
    $pdo->prepare('
        INSERT INTO pembimbing_munawib_pengajuan
        (pembimbing_id, jadwal_id, kegiatan_id, tanggal_mulai, tanggal_selesai, munawib_id, materi_pengganti, alasan, status, created_by)
        VALUES (:pb, :jid, :kid, :tmul, :tsel, :mid, :mat, :alasan, "MENUNGGU", :uid)
    ')->execute([
        'pb' => $pembimbingId,
        'jid' => $jadwalId,
        'kid' => $kegiatanId,
        'tmul' => $tanggalMulai,
        'tsel' => $tanggalSelesai,
        'mid' => $munawibId,
        'mat' => $materiJson,
        'alasan' => $alasan,
        'uid' => $userId > 0 ? $userId : null,
    ]);
    $pengajuanId = (int) $pdo->lastInsertId();

    if ($tanggalSelesai > $tanggalMulai) {
        pb_munawib_pengajuan_kirim_wa_pengasuh($pdo, $pengajuanId);
    }

    $pesan = $tanggalMulai === $tanggalSelesai
        ? 'Pengajuan munawib terkirim. Menunggu persetujuan pengasuh.'
        : 'Pengajuan munawib multi-hari terkirim. Menunggu persetujuan pengasuh.';

    return ['ok' => true, 'pesan' => $pesan, 'id' => $pengajuanId];
}

/**
 * @return array{ok:bool,pesan:string,hari_diterapkan?:int}
 */
function pb_munawib_pengajuan_setujui(PDO $pdo, int $pengajuanId, int $pengasuhUserId): array
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM pembimbing_munawib_pengajuan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $pengajuanId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.'];
    }
    if ((string) ($row['status'] ?? '') !== 'MENUNGGU') {
        return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses.'];
    }

    $pembimbingId = (int) ($row['pembimbing_id'] ?? 0);
    $jadwalId = (int) ($row['jadwal_id'] ?? 0);
    $munawibId = (int) ($row['munawib_id'] ?? 0);
    $alasan = (string) ($row['alasan'] ?? '');
    $materiRows = pb_jadwal_materi_from_json((string) ($row['materi_pengganti'] ?? ''));
    $tmul = (string) ($row['tanggal_mulai'] ?? '');
    $tsel = (string) ($row['tanggal_selesai'] ?? '');
    $createdBy = (int) ($row['created_by'] ?? 0);

    $tsMulai = strtotime($tmul);
    $tsSelesai = strtotime($tsel);
    if ($tsMulai === false || $tsSelesai === false) {
        return ['ok' => false, 'pesan' => 'Rentang tanggal tidak valid.'];
    }

    $diterapkan = 0;
    for ($ts = $tsMulai; $ts <= $tsSelesai; $ts += 86400) {
        $tgl = date('Y-m-d', $ts);
        $slotRes = pb_jadwal_ambil_slot_pembimbing($pdo, $pembimbingId, $jadwalId, $tgl);
        if (!$slotRes['ok']) {
            continue;
        }
        $res = pb_jadwal_terapkan_munawib_hari(
            $pdo,
            $pembimbingId,
            $slotRes['slot'],
            $tgl,
            $munawibId,
            $alasan,
            $createdBy,
            $materiRows,
            false
        );
        if ($res['ok']) {
            $diterapkan++;
        }
    }
    if ($diterapkan === 0) {
        return ['ok' => false, 'pesan' => 'Tidak ada hari jadwal yang bisa diterapkan.'];
    }

    $pdo->prepare('
        UPDATE pembimbing_munawib_pengajuan
        SET status = "DISETUJUI", pengasuh_approved_by = :uid, pengasuh_approved_at = NOW(), updated_at = NOW()
        WHERE id = :id
    ')->execute(['uid' => $pengasuhUserId > 0 ? $pengasuhUserId : null, 'id' => $pengajuanId]);

    return ['ok' => true, 'pesan' => 'Pengajuan disetujui. Munawib diterapkan untuk ' . $diterapkan . ' hari jadwal.', 'hari_diterapkan' => $diterapkan];
}

function pb_munawib_pengajuan_tolak(PDO $pdo, int $pengajuanId, int $pengasuhUserId, string $catatan = ''): array
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, status FROM pembimbing_munawib_pengajuan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $pengajuanId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.'];
    }
    if ((string) ($row['status'] ?? '') !== 'MENUNGGU') {
        return ['ok' => false, 'pesan' => 'Pengajuan sudah diproses.'];
    }
    $pdo->prepare('
        UPDATE pembimbing_munawib_pengajuan
        SET status = "DITOLAK", pengasuh_approved_by = :uid, pengasuh_approved_at = NOW(),
            catatan_pengasuh = :cat, updated_at = NOW()
        WHERE id = :id
    ')->execute([
        'uid' => $pengasuhUserId > 0 ? $pengasuhUserId : null,
        'cat' => trim($catatan) !== '' ? trim($catatan) : null,
        'id' => $pengajuanId,
    ]);

    return ['ok' => true, 'pesan' => 'Pengajuan munawib ditolak.'];
}

function pb_munawib_pengajuan_batal(PDO $pdo, int $pembimbingId, int $pengajuanId): array
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, status FROM pembimbing_munawib_pengajuan WHERE id = :id AND pembimbing_id = :pb LIMIT 1');
    $st->execute(['id' => $pengajuanId, 'pb' => $pembimbingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'pesan' => 'Pengajuan tidak ditemukan.'];
    }
    if ((string) ($row['status'] ?? '') !== 'MENUNGGU') {
        return ['ok' => false, 'pesan' => 'Hanya pengajuan menunggu yang bisa dibatalkan.'];
    }
    $pdo->prepare('UPDATE pembimbing_munawib_pengajuan SET status = "DIBATALKAN", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $pengajuanId]);

    return ['ok' => true, 'pesan' => 'Pengajuan dibatalkan.'];
}

/**
 * @return list<array<string,mixed>>
 */
function pb_munawib_pengajuan_list_pembimbing(PDO $pdo, int $pembimbingId, int $limit = 30): array
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    if ($pembimbingId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT p.*, k.nama_kegiatan, m.nama AS munawib_nama
        FROM pembimbing_munawib_pengajuan p
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        LEFT JOIN munawib m ON m.id = p.munawib_id
        WHERE p.pembimbing_id = :pb
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT ' . max(1, min($limit, 100))
    );
    $st->execute(['pb' => $pembimbingId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function pb_munawib_pengajuan_pending_list(PDO $pdo, int $limit = 50): array
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT p.*, k.nama_kegiatan, m.nama AS munawib_nama, b.nama_pembimbing, b.nip
        FROM pembimbing_munawib_pengajuan p
        INNER JOIN pembimbing b ON b.id = p.pembimbing_id
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        LEFT JOIN munawib m ON m.id = p.munawib_id
        WHERE p.status = "MENUNGGU"
        ORDER BY p.tanggal_mulai ASC, p.id ASC
        LIMIT ' . max(1, min($limit, 100))
    );
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pb_munawib_pengajuan_pending_count(PDO $pdo): int
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    if (!table_exists($pdo, 'pembimbing_munawib_pengajuan')) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM pembimbing_munawib_pengajuan WHERE status = "MENUNGGU"')->fetchColumn();
}

/**
 * WA ke pengasuh untuk pengajuan munawib multi-hari.
 *
 * @return array{sent:int,skipped:bool,reason:string}
 */
function pb_munawib_pengajuan_kirim_wa_pengasuh(PDO $pdo, int $pengajuanId): array
{
    pb_munawib_pengajuan_ensure_schema($pdo);
    if (!function_exists('wa_izin_pengasuh_pending_enabled')) {
        require_once __DIR__ . '/app.php';
    }
    if (!wa_izin_pengasuh_pending_enabled($pdo)) {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'disabled'];
    }
    if (trim((string) app_setting($pdo, 'wa_otomatis_master_enabled', '1')) !== '1') {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'master_off'];
    }

    require_once __DIR__ . '/wa_otomatis.php';
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'gateway'];
    }
    require_once __DIR__ . '/perizinan_approval.php';
    $target = wa_pengasuh_pending_targets($pdo);
    if ($target === '') {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'no_pengasuh_wa'];
    }

    $st = $pdo->prepare('
        SELECT p.*, k.nama_kegiatan, m.nama AS munawib_nama, b.nama_pembimbing, b.nip
        FROM pembimbing_munawib_pengajuan p
        INNER JOIN pembimbing b ON b.id = p.pembimbing_id
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        LEFT JOIN munawib m ON m.id = p.munawib_id
        WHERE p.id = :id LIMIT 1
    ');
    $st->execute(['id' => $pengajuanId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'not_found'];
    }
    if ((string) ($row['tanggal_mulai'] ?? '') >= (string) ($row['tanggal_selesai'] ?? '')) {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'single_day'];
    }

    require_once __DIR__ . '/wa_templates.php';
    $nip = trim((string) ($row['nip'] ?? ''));
    $msg = wa_template_render($pdo, 'pb_munawib_pengajuan_pengasuh', [
        'nama_pembimbing' => (string) ($row['nama_pembimbing'] ?? '-'),
        'nip_pembimbing' => $nip,
        'nip_baris' => $nip !== '' ? '• NIP: ' . $nip . "\n" : '',
        'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? '-'),
        'tanggal_mulai' => (string) ($row['tanggal_mulai'] ?? ''),
        'tanggal_selesai' => (string) ($row['tanggal_selesai'] ?? ''),
        'munawib_nama' => (string) ($row['munawib_nama'] ?? '-'),
        'materi_ringkas' => pb_jadwal_materi_ringkas((string) ($row['materi_pengganti'] ?? '')),
        'alasan' => (string) ($row['alasan'] ?? ''),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);

    $sent = send_wa_bulk($pdo, $target, $msg, [
        'kind' => 'general',
        'dedup_key' => 'pb_munawib_pengajuan:' . $pengajuanId,
        'dedup_key_once' => true,
    ]);

    return [
        'sent' => $sent,
        'skipped' => $sent === 0,
        'reason' => $sent === 0 ? 'send_failed' : '',
    ];
}
