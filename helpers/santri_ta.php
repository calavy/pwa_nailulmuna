<?php

declare(strict_types=1);

require_once __DIR__ . '/santri_riwayat.php';
require_once __DIR__ . '/pondok_ta.php';

/**
 * Tingkatan & kelas keuangan per santri untuk satu tahun ajaran.
 *
 * @return array<int, array{tingkatan:string,kategori_kelas:string,status_akademik:string,wali_kelas:string,catatan:string}>
 */
function santri_tingkatan_map_for_ta(PDO $pdo, int $tahunAjaranMulai, int $tahunAjaranSelesai): array
{
    static $cache = [];
    $key = $tahunAjaranMulai . ':' . $tahunAjaranSelesai;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    ensure_santri_riwayat_tables($pdo);
    $map = [];
    if (table_exists($pdo, 'santri_riwayat_tingkatan')) {
        $st = $pdo->prepare('
            SELECT santri_id, tingkatan, kategori_kelas, status_akademik, wali_kelas, catatan
            FROM santri_riwayat_tingkatan
            WHERE tahun_ajaran_mulai = :tm AND tahun_ajaran_selesai = :ts
        ');
        $st->execute(['tm' => $tahunAjaranMulai, 'ts' => $tahunAjaranSelesai]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) ($row['santri_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $map[$sid] = [
                'tingkatan' => trim((string) ($row['tingkatan'] ?? '')),
                'kategori_kelas' => trim((string) ($row['kategori_kelas'] ?? '')),
                'status_akademik' => trim((string) ($row['status_akademik'] ?? 'BERJALAN')),
                'wali_kelas' => trim((string) ($row['wali_kelas'] ?? '')),
                'catatan' => trim((string) ($row['catatan'] ?? '')),
            ];
        }
    }

    $cache[$key] = $map;

    return $map;
}

/**
 * Kelas kategori untuk tagihan/keuangan: riwayat TA jika ada, else data santri saat ini.
 */
function santri_kelas_untuk_ta(PDO $pdo, int $santriId, int $tahunAjaranMulai, int $tahunAjaranSelesai, array $santriRow, ?array $tingkatanMap = null): string
{
    $map = $tingkatanMap ?? santri_tingkatan_map_for_ta($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    if (isset($map[$santriId])) {
        $kat = trim((string) ($map[$santriId]['kategori_kelas'] ?? ''));
        if ($kat !== '') {
            return $kat;
        }
        $ting = trim((string) ($map[$santriId]['tingkatan'] ?? ''));
        if ($ting !== '') {
            return $ting;
        }
    }
    $kat = trim((string) ($santriRow['kategori_kelas'] ?? ''));
    if ($kat !== '') {
        return $kat;
    }

    return trim((string) ($santriRow['tingkatan'] ?? ''));
}

/**
 * Kelas keuangan untuk tagihan/pembayaran (satu sumber dengan daftar tagihan).
 */
function keuangan_santri_kelas_tagihan(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    ?array $santriRow = null,
    ?array $tingkatanMap = null
): string {
    if ($santriId <= 0) {
        return '';
    }
    if ($santriRow === null && table_exists($pdo, 'santri')) {
        $cols = ['id'];
        if (column_exists($pdo, 'santri', 'kategori_kelas')) {
            $cols[] = 'kategori_kelas';
        }
        if (column_exists($pdo, 'santri', 'tingkatan')) {
            $cols[] = 'tingkatan';
        }
        $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $santriRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!is_array($santriRow)) {
        return '';
    }

    return santri_kelas_untuk_ta($pdo, $santriId, $tahunAjaranMulai, $tahunAjaranSelesai, $santriRow, $tingkatanMap);
}

/**
 * Simpan banyak baris tingkatan TA sekaligus.
 *
 * @param array<int, array{tingkatan?:string,kategori_kelas?:string,status_akademik?:string,wali_kelas?:string,catatan?:string}> $rowsBySantriId
 * @return array{ok:bool,message:string,jumlah:int}
 */
function santri_tingkatan_bulk_save(PDO $pdo, int $tahunAjaranMulai, int $tahunAjaranSelesai, array $rowsBySantriId): array
{
    ensure_santri_riwayat_tables($pdo);
    $ta = pondok_normalisasi_tahun_ajaran_input($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $tm = (int) $ta['mulai'];
    $ts = (int) $ta['selesai'];
    $sql = '
        INSERT INTO santri_riwayat_tingkatan (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, tingkatan, kategori_kelas, wali_kelas, status_akademik, catatan)
        VALUES (:sid, :tm, :ts, :ting, :kat, :wk, :stak, :cat)
        ON DUPLICATE KEY UPDATE
            tingkatan = VALUES(tingkatan),
            kategori_kelas = VALUES(kategori_kelas),
            wali_kelas = VALUES(wali_kelas),
            status_akademik = VALUES(status_akademik),
            catatan = VALUES(catatan)
    ';
    $st = $pdo->prepare($sql);
    $jumlah = 0;
    foreach ($rowsBySantriId as $sid => $row) {
        $sid = (int) $sid;
        $ting = trim((string) ($row['tingkatan'] ?? ''));
        if ($sid <= 0 || $ting === '') {
            continue;
        }
        $stAk = strtoupper(trim((string) ($row['status_akademik'] ?? 'BERJALAN')));
        if (!in_array($stAk, santri_riwayat_status_akademik_options(), true)) {
            $stAk = 'BERJALAN';
        }
        $st->execute([
            'sid' => $sid,
            'tm' => $tm,
            'ts' => $ts,
            'ting' => mb_substr($ting, 0, 80),
            'kat' => trim((string) ($row['kategori_kelas'] ?? '')) ?: null,
            'wk' => trim((string) ($row['wali_kelas'] ?? '')) ?: null,
            'stak' => $stAk,
            'cat' => trim((string) ($row['catatan'] ?? '')) ?: null,
        ]);
        $jumlah++;
    }

    return [
        'ok' => true,
        'message' => $jumlah > 0
            ? 'Data tingkatan TA ' . pondok_tahun_ajaran_label($pdo, $ta) . ' disimpan (' . $jumlah . ' santri).'
            : 'Tidak ada baris valid untuk disimpan.',
        'jumlah' => $jumlah,
    ];
}

/** Salin riwayat dari TA sebelumnya (mulai-1) ke TA target; opsional naik tingkatan master. */
function santri_tingkatan_salin_dari_ta_sebelumnya(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    bool $naikTingkatan = false
): array {
    $ta = pondok_normalisasi_tahun_ajaran_input($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $tmPrev = (int) $ta['mulai'] - 1;
    $tsPrev = (int) $ta['selesai'] - 1;
    $prev = santri_tingkatan_map_for_ta($pdo, $tmPrev, $tsPrev);
    if ($prev === []) {
        return ['ok' => false, 'message' => 'Tidak ada data tingkatan TA sebelumnya (' . $tmPrev . '/' . $tsPrev . ').', 'jumlah' => 0];
    }

    $tingkatanList = [];
    if ($naikTingkatan && table_exists($pdo, 'tingkatan')) {
        $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $payload = [];
    foreach ($prev as $sid => $row) {
        $ting = (string) $row['tingkatan'];
        if ($naikTingkatan && $tingkatanList !== []) {
            $idx = array_search($ting, $tingkatanList, true);
            if ($idx !== false && isset($tingkatanList[$idx + 1])) {
                $ting = (string) $tingkatanList[$idx + 1];
            }
        }
        $payload[$sid] = [
            'tingkatan' => $ting,
            'kategori_kelas' => (string) $row['kategori_kelas'],
            'status_akademik' => 'BERJALAN',
            'wali_kelas' => (string) $row['wali_kelas'],
            'catatan' => 'Salin dari TA ' . $tmPrev . ($naikTingkatan ? ' (naik tingkatan)' : ''),
        ];
    }

    return santri_tingkatan_bulk_save($pdo, (int) $ta['mulai'], (int) $ta['selesai'], $payload);
}
