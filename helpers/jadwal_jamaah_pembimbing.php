<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/jadwal_jamaah.php';
require_once __DIR__ . '/jadwal_ui.php';
require_once __DIR__ . '/munawib.php';
require_once __DIR__ . '/operasional_audit.php';

function jadwal_jamaah_munawib_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_kegiatan_kategori_column($pdo);
    munawib_ensure_schema($pdo);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS munawib_jamaah_harian (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kelompok ENUM("putra","putri") NOT NULL,
            hari_ke TINYINT UNSIGNED NOT NULL,
            munawib_id INT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mw_jamaah_harian (kelompok, hari_ke),
            INDEX idx_mw_jamaah_munawib (munawib_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    if (table_exists($pdo, 'pembimbing_jamaah_harian') && !table_exists($pdo, 'munawib_jamaah_harian_migrated_v1')) {
        try {
            $stOld = $pdo->query('SELECT kelompok, hari_ke FROM pembimbing_jamaah_harian WHERE hari_ke BETWEEN 1 AND 7');
            $ins = $pdo->prepare('
                INSERT IGNORE INTO munawib_jamaah_harian (kelompok, hari_ke, munawib_id)
                VALUES (:kel, :hk, NULL)
            ');
            foreach ($stOld->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $ins->execute([
                    'kel' => (string) ($row['kelompok'] ?? ''),
                    'hk' => (int) ($row['hari_ke'] ?? 0),
                ]);
            }
        } catch (PDOException $e) {
            // abaikan migrasi lama
        }
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS munawib_jamaah_harian_migrated_v1 (id TINYINT PRIMARY KEY) ENGINE=InnoDB');
            $pdo->exec('INSERT IGNORE INTO munawib_jamaah_harian_migrated_v1 (id) VALUES (1)');
        } catch (PDOException $e) {
            // abaikan
        }
    }
}

/** @deprecated gunakan jadwal_jamaah_munawib_ensure_schema */
function jadwal_jamaah_pembimbing_ensure_schema(PDO $pdo): void
{
    jadwal_jamaah_munawib_ensure_schema($pdo);
}

/** @return array<string, array<int, int>> kelompok => [hari_ke => munawib_id] */
function jadwal_jamaah_munawib_map(PDO $pdo): array
{
    jadwal_jamaah_munawib_ensure_schema($pdo);
    $out = ['putra' => [], 'putri' => []];
    $rows = $pdo->query('
        SELECT kelompok, hari_ke, munawib_id
        FROM munawib_jamaah_harian
        WHERE hari_ke BETWEEN 1 AND 7
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $kel = strtolower((string) ($row['kelompok'] ?? ''));
        $hk = (int) ($row['hari_ke'] ?? 0);
        if (!in_array($kel, jadwal_jamaah_kelompok_valid(), true) || $hk < 1 || $hk > 7) {
            continue;
        }
        $mid = (int) ($row['munawib_id'] ?? 0);
        $out[$kel][$hk] = $mid > 0 ? $mid : 0;
    }

    return $out;
}

/** @deprecated */
function jadwal_jamaah_pembimbing_map(PDO $pdo): array
{
    return jadwal_jamaah_munawib_map($pdo);
}

function jadwal_jamaah_tingkatan_ke_kelompok(PDO $pdo, string $tingkatan): ?string
{
    $tingkatan = trim($tingkatan);
    if ($tingkatan === '' || strcasecmp($tingkatan, 'Semua Tingkatan') === 0) {
        return null;
    }
    $map = jadwal_jamaah_tingkatan_kelompok_map($pdo);
    if (in_array($tingkatan, $map['putri'] ?? [], true)) {
        return 'putri';
    }
    if (in_array($tingkatan, $map['putra'] ?? [], true)) {
        return 'putra';
    }

    return null;
}

function jadwal_jamaah_munawib_id_hari(PDO $pdo, int $hariKe, string $kelompok): int
{
    $kelompok = jadwal_jamaah_validasi_kelompok($kelompok) ?? '';
    if ($kelompok === '' || $hariKe < 1 || $hariKe > 7) {
        return 0;
    }
    jadwal_jamaah_munawib_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT munawib_id
        FROM munawib_jamaah_harian
        WHERE kelompok = :kel AND hari_ke = :hk
        LIMIT 1
    ');
    $st->execute(['kel' => $kelompok, 'hk' => $hariKe]);

    return (int) ($st->fetchColumn() ?: 0);
}

/** @deprecated */
function jadwal_jamaah_pembimbing_id_hari(PDO $pdo, int $hariKe, string $kelompok): int
{
    return jadwal_jamaah_munawib_id_hari($pdo, $hariKe, $kelompok);
}

function jadwal_jamaah_munawib_cocok(PDO $pdo, int $munawibId, int $hariKe, string $tingkatan): bool
{
    if ($munawibId <= 0 || $hariKe < 1 || $hariKe > 7) {
        return false;
    }
    $kelompok = jadwal_jamaah_tingkatan_ke_kelompok($pdo, $tingkatan);
    if ($kelompok === null) {
        return false;
    }

    return jadwal_jamaah_munawib_id_hari($pdo, $hariKe, $kelompok) === $munawibId;
}

/** @deprecated */
function jadwal_jamaah_pembimbing_cocok(PDO $pdo, int $pembimbingId, int $hariKe, string $tingkatan): bool
{
    return jadwal_jamaah_munawib_cocok($pdo, $pembimbingId, $hariKe, $tingkatan);
}

function jadwal_jamaah_munawib_bersihkan_slot(PDO $pdo): int
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return 0;
    }
    ensure_kegiatan_kategori_column($pdo);

    return (int) $pdo->exec('
        UPDATE jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.kategori_kegiatan = "JAMAAH"
        SET j.pembimbing_id = NULL
        WHERE j.pembimbing_id IS NOT NULL
    ');
}

/** @deprecated */
function jadwal_jamaah_pembimbing_bersihkan_slot(PDO $pdo): int
{
    return jadwal_jamaah_munawib_bersihkan_slot($pdo);
}

/**
 * @param array<string, array<int, int|string>> $input [putra|putri][hari_ke] => munawib_id
 * @return array{ok:bool,message:string,saved:int,cleared_slots:int}
 */
function jadwal_jamaah_munawib_simpan(PDO $pdo, array $input, int $auditUserId): array
{
    jadwal_jamaah_munawib_ensure_schema($pdo);
    $upsert = $pdo->prepare('
        INSERT INTO munawib_jamaah_harian (kelompok, hari_ke, munawib_id)
        VALUES (:kel, :hk, :mid)
        ON DUPLICATE KEY UPDATE munawib_id = VALUES(munawib_id)
    ');
    $saved = 0;
    foreach (jadwal_jamaah_kelompok_valid() as $kel) {
        $hariMap = (array) ($input[$kel] ?? []);
        for ($hk = 1; $hk <= 7; $hk++) {
            $mid = (int) ($hariMap[$hk] ?? 0);
            $upsert->execute([
                'kel' => $kel,
                'hk' => $hk,
                'mid' => $mid > 0 ? $mid : null,
            ]);
            $saved++;
        }
    }
    $cleared = jadwal_jamaah_munawib_bersihkan_slot($pdo);

    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'UPDATE',
        0,
        null,
        ['jenis' => 'munawib_jamaah_harian', 'cells' => $saved, 'cleared_slot_pembimbing' => $cleared],
        $auditUserId,
        'Pengaturan munawib jamaah harian (' . $saved . ' sel)'
    );

    return [
        'ok' => true,
        'message' => 'Munawib jamaah harian disimpan. Penugasan pembimbing per slot jamaah dinonaktifkan (' . $cleared . ' slot dibersihkan).',
        'saved' => $saved,
        'cleared_slots' => $cleared,
    ];
}

/** @deprecated */
function jadwal_jamaah_pembimbing_simpan(PDO $pdo, array $input, int $auditUserId): array
{
    return jadwal_jamaah_munawib_simpan($pdo, $input, $auditUserId);
}

/** Label munawib untuk tampilan kartu jamaah (hari tertentu). */
function jadwal_jamaah_munawib_label_hari(PDO $pdo, int $hariKe, string $kelompok): string
{
    $mid = jadwal_jamaah_munawib_id_hari($pdo, $hariKe, $kelompok);
    if ($mid <= 0 || !table_exists($pdo, 'munawib')) {
        return '';
    }
    $st = $pdo->prepare('SELECT nama FROM munawib WHERE id = :id LIMIT 1');
    $st->execute(['id' => $mid]);
    $nama = trim((string) ($st->fetchColumn() ?: ''));

    return $nama;
}

/** @deprecated */
function jadwal_jamaah_pembimbing_label_hari(PDO $pdo, int $hariKe, string $kelompok): string
{
    return jadwal_jamaah_munawib_label_hari($pdo, $hariKe, $kelompok);
}

function jadwal_jamaah_munawib_nama_untuk_slot(PDO $pdo, string $tingkatan, int $hariKe): string
{
    $kelompok = jadwal_jamaah_tingkatan_ke_kelompok($pdo, $tingkatan);
    if ($kelompok === null) {
        return '';
    }
    if ($hariKe < 1 || $hariKe > 7) {
        $hariKe = (int) date('N');
    }

    return jadwal_jamaah_munawib_label_hari($pdo, $hariKe, $kelompok);
}

/** @deprecated */
function jadwal_jamaah_pembimbing_nama_untuk_slot(PDO $pdo, string $tingkatan, int $hariKe): string
{
    return jadwal_jamaah_munawib_nama_untuk_slot($pdo, $tingkatan, $hariKe);
}

/**
 * Filter slot jadwal untuk scan munawib: jama'ah hanya jika ditugaskan harian.
 *
 * @param list<array<string,mixed>> $slots
 * @return list<array<string,mixed>>
 */
function jadwal_jamaah_munawib_filter_slots_scan(PDO $pdo, int $munawibId, int $hariKe, array $slots): array
{
    if ($munawibId <= 0 || $slots === []) {
        return [];
    }
    $out = [];
    foreach ($slots as $slot) {
        $kat = strtoupper((string) ($slot['kategori_kegiatan'] ?? 'TAALIM'));
        if ($kat === 'JAMAAH') {
            if (jadwal_jamaah_munawib_cocok($pdo, $munawibId, $hariKe, (string) ($slot['tingkatan'] ?? ''))) {
                $out[] = $slot;
            }
            continue;
        }
        $out[] = $slot;
    }

    return $out;
}
