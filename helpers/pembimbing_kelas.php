<?php

/**
 * Helper turunan "Kelas yang dikaji" untuk pembimbing.
 *
 * Sumber data: TABEL `jadwal_kegiatan`. "Kelas yang dikaji" adalah
 * himpunan tingkatan yang ada di slot jadwal milik pembimbing tersebut
 * (jadwal_kegiatan.pembimbing_id). Tidak ada lagi input manual; admin
 * menetapkan tingkatan via halaman Jadwal saat menambah/mengedit slot.
 *
 * Bila pembimbing belum punya slot jadwal sama sekali, helper mengembalikan
 * array kosong — UI yang memutuskan menampilkan keterangan "Belum mendapatkan
 * jadwal".
 */

declare(strict_types=1);

/**
 * Backward-compat: dulunya membuat tabel junction `pembimbing_tingkatan`.
 * Sekarang tabel itu tidak dipakai lagi (kelas dikaji diturunkan dari
 * jadwal_kegiatan). Fungsi ini sengaja dibiarkan no-op agar pemanggilan
 * lama di file lain tidak error.
 */
function pembimbing_kelas_ensure_schema(PDO $pdo): void
{
    // No-op: junction table tidak dipakai lagi.
    unset($pdo);
}

/**
 * Daftar tingkatan unik yang sedang diampu pembimbing — diambil
 * langsung dari slot jadwal yang sudah di-assign ke pembimbing ini.
 *
 * @return list<string>
 */
function pembimbing_kelas_list(PDO $pdo, int $pembimbingId): array
{
    if ($pembimbingId <= 0) {
        return [];
    }
    if (!function_exists('table_exists') || !table_exists($pdo, 'jadwal_kegiatan')) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT tingkatan
             FROM jadwal_kegiatan
             WHERE pembimbing_id = :id
               AND tingkatan IS NOT NULL
               AND TRIM(tingkatan) <> \'\'
             ORDER BY tingkatan ASC'
        );
        $st->execute(['id' => $pembimbingId]);
        /** @var list<string> $rows */
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Map pembimbing_id => list<string tingkatan unik> — diturunkan dari
 * jadwal_kegiatan. Dipakai di halaman daftar agar query satu kali
 * (hindari N+1).
 *
 * @return array<int, list<string>>
 */
function pembimbing_kelas_map_all(PDO $pdo): array
{
    $map = [];
    if (!function_exists('table_exists') || !table_exists($pdo, 'jadwal_kegiatan')) {
        return $map;
    }
    try {
        $rows = $pdo->query(
            "SELECT pembimbing_id, tingkatan
             FROM jadwal_kegiatan
             WHERE pembimbing_id IS NOT NULL
               AND tingkatan IS NOT NULL
               AND TRIM(tingkatan) <> ''
             GROUP BY pembimbing_id, tingkatan
             ORDER BY tingkatan ASC"
        )->fetchAll();
    } catch (PDOException $e) {
        return $map;
    }
    foreach ($rows as $r) {
        $pid = (int) ($r['pembimbing_id'] ?? 0);
        $tg = trim((string) ($r['tingkatan'] ?? ''));
        if ($pid <= 0 || $tg === '') {
            continue;
        }
        $map[$pid][] = $tg;
    }
    return $map;
}

/**
 * Ringkasan jumlah slot jadwal aktif per pembimbing — untuk badge di tabel.
 *
 * @return array<int, int> map pembimbing_id => jumlah slot
 */
function pembimbing_kelas_jadwal_count_map(PDO $pdo): array
{
    $map = [];
    if (function_exists('table_exists') && table_exists($pdo, 'jadwal_kegiatan')) {
        try {
            $rows = $pdo->query('SELECT pembimbing_id, COUNT(*) AS jml FROM jadwal_kegiatan WHERE pembimbing_id IS NOT NULL GROUP BY pembimbing_id')->fetchAll();
            foreach ($rows as $r) {
                $pid = (int) ($r['pembimbing_id'] ?? 0);
                if ($pid > 0) {
                    $map[$pid] = (int) ($r['jml'] ?? 0);
                }
            }
        } catch (PDOException $e) {
            // abaikan — lanjut ke PKPPS
        }
    }
    if (function_exists('table_exists') && table_exists($pdo, 'pkpps_jadwal')) {
        try {
            $rows = $pdo->query('SELECT pembimbing_id, COUNT(*) AS jml FROM pkpps_jadwal WHERE pembimbing_id IS NOT NULL GROUP BY pembimbing_id')->fetchAll();
            foreach ($rows as $r) {
                $pid = (int) ($r['pembimbing_id'] ?? 0);
                if ($pid > 0) {
                    $map[$pid] = ($map[$pid] ?? 0) + (int) ($r['jml'] ?? 0);
                }
            }
        } catch (PDOException $e) {
            // abaikan
        }
    }
    return $map;
}

/** Apakah pembimbing sudah punya slot jadwal (kajian atau PKPPS) sehingga kartu boleh dicetak? */
function pembimbing_has_jadwal_for_kartu(PDO $pdo, int $pembimbingId): bool
{
    if ($pembimbingId <= 0) {
        return false;
    }
    $count = 0;
    if (function_exists('table_exists') && table_exists($pdo, 'jadwal_kegiatan')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE pembimbing_id = :id');
        $st->execute(['id' => $pembimbingId]);
        $count += (int) ($st->fetchColumn() ?: 0);
    }
    if (function_exists('table_exists') && table_exists($pdo, 'pkpps_jadwal')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM pkpps_jadwal WHERE pembimbing_id = :id');
        $st->execute(['id' => $pembimbingId]);
        $count += (int) ($st->fetchColumn() ?: 0);
    }
    return $count > 0;
}

/** Super admin boleh cetak kartu kapan saja; selain itu harus punya jadwal. */
function pembimbing_can_print_kartu(PDO $pdo, int $pembimbingId): bool
{
    if ($pembimbingId <= 0) {
        return false;
    }
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }

    return pembimbing_has_jadwal_for_kartu($pdo, $pembimbingId);
}

/**
 * Backward-compat: dulunya menyimpan ke junction & sync ke jadwal.
 * Sekarang no-op — kelas dikaji diturunkan dari jadwal saat dibutuhkan.
 *
 * @param list<string> $tingkatan
 */
function pembimbing_kelas_simpan(PDO $pdo, int $pembimbingId, array $tingkatan, bool $syncJadwal = true): void
{
    unset($pdo, $pembimbingId, $tingkatan, $syncJadwal);
}

/**
 * Backward-compat: dulunya sinkron pembimbing_id ke jadwal. Sekarang
 * tidak diperlukan karena admin yang menetapkan pembimbing langsung
 * pada slot jadwal lewat halaman Jadwal.
 *
 * @param list<string> $tingkatan
 */
function pembimbing_kelas_sync_jadwal(PDO $pdo, int $pembimbingId, array $tingkatan): void
{
    unset($pdo, $pembimbingId, $tingkatan);
}
