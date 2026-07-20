<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/presensi_admin.php';
require_once __DIR__ . '/akademik.php';

/** Koreksi slot kegiatan tanpa scan — hanya super admin. */
function user_can_presensi_tanpa_scan_koreksi(): bool
{
    return function_exists('is_super_admin') && is_super_admin();
}

function presensi_alpa_bebas_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!table_exists($pdo, 'presensi')) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_alpa_bebas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            kegiatan_id INT NOT NULL,
            tanggal_presensi DATE NOT NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_presensi_alpa_bebas (santri_id, kegiatan_id, tanggal_presensi),
            INDEX idx_presensi_alpa_bebas_tgl (tanggal_presensi, kegiatan_id)
        )
    ');
}

function presensi_alpa_bebas_is_set(PDO $pdo, int $santriId, int $kegiatanId, string $tanggal): bool
{
    if ($santriId <= 0 || $kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return false;
    }
    presensi_alpa_bebas_ensure_schema($pdo);
    if (!table_exists($pdo, 'presensi_alpa_bebas')) {
        return false;
    }
    $st = $pdo->prepare('
        SELECT 1 FROM presensi_alpa_bebas
        WHERE santri_id = :sid AND kegiatan_id = :kid AND tanggal_presensi = :tgl
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'kid' => $kegiatanId, 'tgl' => $tanggal]);

    return (bool) $st->fetchColumn();
}

/**
 * @param list<int> $santriIds
 * @return array<int, true>
 */
function presensi_alpa_bebas_map(PDO $pdo, int $kegiatanId, string $tanggal, array $santriIds): array
{
    presensi_alpa_bebas_ensure_schema($pdo);
    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn(int $id): bool => $id > 0)));
    if ($santriIds === [] || $kegiatanId <= 0 || !table_exists($pdo, 'presensi_alpa_bebas')) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($santriIds), '?'));
    $st = $pdo->prepare('
        SELECT santri_id FROM presensi_alpa_bebas
        WHERE kegiatan_id = ? AND tanggal_presensi = ? AND santri_id IN (' . $ph . ')
    ');
    $st->execute(array_merge([$kegiatanId, $tanggal], $santriIds));
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
        $map[(int) $sid] = true;
    }

    return $map;
}

/**
 * @return array{jadwal_kegiatan_id:?int,jam_mulai:?string,jam_selesai:?string}
 */
function presensi_slot_resolve_jadwal(PDO $pdo, int $kegiatanId, string $tanggal, string $tingkatan = ''): array
{
    $empty = ['jadwal_kegiatan_id' => null, 'jam_mulai' => null, 'jam_selesai' => null];
    if ($kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !table_exists($pdo, 'jadwal_kegiatan')) {
        return $empty;
    }
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
    $tingkatan = trim($tingkatan);
    $sql = '
        SELECT id, jam_mulai, jam_selesai FROM jadwal_kegiatan
        WHERE kegiatan_id = :kid
          AND (hari_ke = 0 OR hari_ke = :hari)
          AND (tingkatan = :tk OR tingkatan = "Semua Tingkatan" OR :tk = "")
        ORDER BY jam_mulai ASC
        LIMIT 1
    ';
    $st = $pdo->prepare($sql);
    $st->execute(['kid' => $kegiatanId, 'hari' => $hariKe, 'tk' => $tingkatan]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $empty;
    }

    return [
        'jadwal_kegiatan_id' => (int) ($row['id'] ?? 0) ?: null,
        'jam_mulai' => (string) ($row['jam_mulai'] ?? '') ?: null,
        'jam_selesai' => (string) ($row['jam_selesai'] ?? '') ?: null,
    ];
}

/**
 * @return array{id:int,status_presensi:string}|null
 */
function presensi_slot_row(PDO $pdo, int $santriId, int $kegiatanId, string $tanggal): ?array
{
    if ($santriId <= 0 || $kegiatanId <= 0 || !table_exists($pdo, 'presensi')) {
        return null;
    }
    ensure_presensi_jadwal_column($pdo);
    $st = $pdo->prepare('
        SELECT id, status_presensi FROM presensi
        WHERE santri_id = :sid AND kegiatan_id = :kid AND tanggal_presensi = :tgl
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'kid' => $kegiatanId, 'tgl' => $tanggal]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function presensi_slot_hapus_poin_alpa(PDO $pdo, int $presensiId): void
{
    if ($presensiId <= 0 || !table_exists($pdo, 'point_ledger')) {
        return;
    }
    $pdo->prepare('DELETE FROM point_ledger WHERE reference_presensi_id = :id')->execute(['id' => $presensiId]);
}

/**
 * @param list<int> $santriIds
 * @return array{ok:bool,message:string,hapus:int,bebas:int}
 */
function presensi_slot_hapus_alpa(
    PDO $pdo,
    int $kegiatanId,
    string $tanggal,
    array $santriIds,
    int $userId,
    string $tingkatan = ''
): array {
    if (!user_can_presensi_tanpa_scan_koreksi()) {
        return ['ok' => false, 'message' => 'Hanya admin super yang dapat menghapus ALPA pada slot ini.', 'hapus' => 0, 'bebas' => 0];
    }
    if ($kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Data kegiatan atau tanggal tidak valid.', 'hapus' => 0, 'bebas' => 0];
    }

    presensi_alpa_bebas_ensure_schema($pdo);
    ensure_presensi_jadwal_column($pdo);

    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn(int $id): bool => $id > 0)));
    if ($santriIds === []) {
        return ['ok' => false, 'message' => 'Pilih minimal satu santri.', 'hapus' => 0, 'bebas' => 0];
    }

    $insBebas = $pdo->prepare('
        INSERT INTO presensi_alpa_bebas (santri_id, kegiatan_id, tanggal_presensi, catatan, created_by)
        VALUES (:sid, :kid, :tgl, :cat, :by)
        ON DUPLICATE KEY UPDATE catatan = VALUES(catatan), created_by = VALUES(created_by)
    ');
    $delPresensi = $pdo->prepare('DELETE FROM presensi WHERE id = :id AND status_presensi = "ALPA"');

    $hapus = 0;
    $bebas = 0;
    foreach ($santriIds as $sid) {
        $row = presensi_slot_row($pdo, $sid, $kegiatanId, $tanggal);
        if ($row !== null && strtoupper((string) ($row['status_presensi'] ?? '')) === 'ALPA') {
            presensi_slot_hapus_poin_alpa($pdo, (int) $row['id']);
            $delPresensi->execute(['id' => (int) $row['id']]);
            if ($delPresensi->rowCount() > 0) {
                $hapus++;
            }
        }
        $insBebas->execute([
            'sid' => $sid,
            'kid' => $kegiatanId,
            'tgl' => $tanggal,
            'cat' => 'Dibebaskan admin super (slot tanpa scan)',
            'by' => $userId > 0 ? $userId : null,
        ]);
        $bebas++;
    }

    return [
        'ok' => true,
        'message' => $bebas . ' santri dibebaskan dari ALPA' . ($hapus > 0 ? ' (' . $hapus . ' baris ALPA dihapus).' : '.'),
        'hapus' => $hapus,
        'bebas' => $bebas,
    ];
}

/**
 * @param list<int> $santriIds
 * @return array{ok:bool,message:string,hadir:int,update:int}
 */
function presensi_slot_catat_hadir_manual(
    PDO $pdo,
    int $kegiatanId,
    string $tanggal,
    array $santriIds,
    int $userId,
    string $tingkatan = ''
): array {
    if (!user_can_presensi_tanpa_scan_koreksi()) {
        return ['ok' => false, 'message' => 'Hanya admin super yang dapat mencatat hadir manual.', 'hadir' => 0, 'update' => 0];
    }
    if ($kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Data kegiatan atau tanggal tidak valid.', 'hadir' => 0, 'update' => 0];
    }

    presensi_alpa_bebas_ensure_schema($pdo);
    ensure_presensi_jadwal_column($pdo);

    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn(int $id): bool => $id > 0)));
    if ($santriIds === []) {
        return ['ok' => false, 'message' => 'Pilih minimal satu santri.', 'hadir' => 0, 'update' => 0];
    }

    $jadwal = presensi_slot_resolve_jadwal($pdo, $kegiatanId, $tanggal, $tingkatan);
    $jam = $jadwal['jam_mulai'] ?? date('H:i:s');
    if (strlen($jam) === 5) {
        $jam .= ':00';
    }
    $hijri = akademik_hijri_ym_untuk_masehi($pdo, $tanggal);
    $catatan = 'Hadir manual (admin super — slot tanpa scan)';

    $delBebas = $pdo->prepare('
        DELETE FROM presensi_alpa_bebas
        WHERE santri_id = :sid AND kegiatan_id = :kid AND tanggal_presensi = :tgl
    ');
    $insert = $pdo->prepare('
        INSERT INTO presensi (santri_id, kegiatan_id, jadwal_kegiatan_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by, catatan)
        VALUES (:sid, :kid, :jid, :tgl, :jam, "HADIR", :hijri, :by, :cat)
    ');
    $update = $pdo->prepare('
        UPDATE presensi
        SET status_presensi = "HADIR", jam_presensi = :jam, kalender_hijriyah = :hijri, created_by = :by,
            catatan = :cat, jadwal_kegiatan_id = COALESCE(:jid, jadwal_kegiatan_id)
        WHERE id = :id
    ');

    $hadir = 0;
    $updated = 0;
    foreach ($santriIds as $sid) {
        $delBebas->execute(['sid' => $sid, 'kid' => $kegiatanId, 'tgl' => $tanggal]);

        $row = presensi_slot_row($pdo, $sid, $kegiatanId, $tanggal);
        if ($row !== null && strtoupper((string) ($row['status_presensi'] ?? '')) === 'HADIR') {
            continue;
        }
        if ($row !== null) {
            if (strtoupper((string) ($row['status_presensi'] ?? '')) === 'ALPA') {
                presensi_slot_hapus_poin_alpa($pdo, (int) $row['id']);
            }
            $update->execute([
                'jam' => $jam,
                'hijri' => $hijri,
                'by' => $userId > 0 ? $userId : null,
                'cat' => $catatan,
                'jid' => $jadwal['jadwal_kegiatan_id'],
                'id' => (int) $row['id'],
            ]);
            $updated++;
            $hadir++;
            continue;
        }

        $insert->execute([
            'sid' => $sid,
            'kid' => $kegiatanId,
            'jid' => $jadwal['jadwal_kegiatan_id'],
            'tgl' => $tanggal,
            'jam' => $jam,
            'hijri' => $hijri,
            'by' => $userId > 0 ? $userId : null,
            'cat' => $catatan,
        ]);
        $hadir++;
    }

    if ($hadir === 0) {
        return ['ok' => false, 'message' => 'Santri terpilih sudah tercatat hadir.', 'hadir' => 0, 'update' => 0];
    }

    $msg = $hadir . ' santri dicatat hadir manual';
    if ($updated > 0) {
        $msg .= ' (' . $updated . ' diperbarui dari status lain)';
    }

    return ['ok' => true, 'message' => $msg . '.', 'hadir' => $hadir, 'update' => $updated];
}
