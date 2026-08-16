<?php

declare(strict_types=1);

require_once __DIR__ . '/santri_kartu_sementara.php';
require_once __DIR__ . '/munawib.php';
require_once __DIR__ . '/pkpps.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/jadwal_jamaah_pembimbing.php';
require_once __DIR__ . '/presensi_scan_client.php';

/**
 * @return array{
 *   entity: 'santri'|'pembimbing'|'munawib'|'unknown',
 *   santri: ?array,
 *   pembimbing: ?array,
 *   munawib: ?array
 * }
 */
function scan_smart_classify(PDO $pdo, string $code): array
{
    $code = trim($code);
    $empty = [
        'entity' => 'unknown',
        'santri' => null,
        'pembimbing' => null,
        'munawib' => null,
    ];
    if ($code === '') {
        return $empty;
    }

    $santri = santri_resolve_by_scan_code($pdo, $code);
    if (is_array($santri)) {
        $loadFull = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
        $loadFull->execute(['id' => (int) ($santri['id'] ?? 0)]);
        $santri = $loadFull->fetch(PDO::FETCH_ASSOC) ?: $santri;

        return [
            'entity' => 'santri',
            'santri' => is_array($santri) ? $santri : null,
            'pembimbing' => null,
            'munawib' => null,
        ];
    }

    $pembimbing = null;
    if (table_exists($pdo, 'pembimbing')) {
        $findP = $pdo->prepare('SELECT id, nama_pembimbing FROM pembimbing WHERE qr = :code OR nip = :code LIMIT 1');
        $findP->execute(['code' => $code]);
        $pembimbing = $findP->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (is_array($pembimbing)) {
        return [
            'entity' => 'pembimbing',
            'santri' => null,
            'pembimbing' => $pembimbing,
            'munawib' => null,
        ];
    }

    munawib_ensure_schema($pdo);
    $munawib = munawib_find_by_code($pdo, $code);
    if (is_array($munawib)) {
        return [
            'entity' => 'munawib',
            'santri' => null,
            'pembimbing' => null,
            'munawib' => $munawib,
        ];
    }

    return $empty;
}

function scan_smart_pembimbing_has_jadwal(PDO $pdo, int $pbId, string $tanggal, string $jam): bool
{
    if ($pbId <= 0) {
        return false;
    }
    pkpps_ensure_schema($pdo);

    return jadwal_aktif_for_pembimbing($pdo, $pbId, $tanggal, $jam) !== null;
}

function scan_smart_munawib_has_slots(PDO $pdo, int $mwId, string $tanggal, string $jam): bool
{
    if ($mwId <= 0) {
        return false;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
    if ($modeLiburAktif === 'ALL_BLOCKED') {
        return false;
    }
    $kategoriFilterSql = $modeLiburAktif !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLiburAktif, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';

    $jadwalM = $pdo->prepare('
        SELECT j.kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan, j.jam_mulai, j.jam_selesai, j.tingkatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hk)
          AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
          AND k.is_active = 1
          ' . $kategoriFilterSql . '
        ORDER BY j.jam_mulai ASC, k.nama_kegiatan ASC
    ');
    $jadwalM->execute(['hk' => $hariKe, 'jam' => $jam]);
    $slotsM = $jadwalM->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $slotsM = jadwal_jamaah_munawib_filter_slots_scan($pdo, $mwId, $hariKe, $slotsM);

    return $slotsM !== [];
}

/**
 * @param array<string, mixed> $post
 */
function scan_smart_resolve_clock(array $post): array
{
    return presensi_scan_resolve_clock($post);
}
