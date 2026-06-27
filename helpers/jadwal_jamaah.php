<?php

declare(strict_types=1);

require_once __DIR__ . '/jadwal_ui.php';
require_once __DIR__ . '/operasional_audit.php';

/** @return list<string> */
function jadwal_jamaah_kelompok_valid(): array
{
    return ['putra', 'putri'];
}

function jadwal_jamaah_kelompok_label(string $kelompok): string
{
    return match (strtolower(trim($kelompok))) {
        'putra' => 'Putra',
        'putri' => 'Putri',
        default => ucfirst($kelompok),
    };
}

/**
 * Apakah nama tingkatan untuk kelompok Putri (sufiks (putri) saja).
 */
function jadwal_tingkatan_is_putri(string $tingkatan): bool
{
    return preg_match('/\(\s*putri\s*\)/iu', trim($tingkatan)) === 1;
}

/**
 * Kelompok Putra/Putri dari nama tingkatan.
 * Putri: hanya yang ber-sufiks (putri), contoh "Tsanawiyah (putri)".
 * Putra: default — semua tingkatan lain (tanpa penanda khusus).
 */
function jadwal_tingkatan_kelompok_dari_nama(string $tingkatan): ?string
{
    static $cache = [];
    $key = trim($tingkatan);
    if ($key === '' || strcasecmp($key, 'Semua Tingkatan') === 0) {
        return null;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (jadwal_tingkatan_is_putri($key)) {
        return $cache[$key] = 'putri';
    }

    return $cache[$key] = 'putra';
}

/**
 * @return array{putra:list<string>,putri:list<string>,lain:list<string>}
 */
function jadwal_jamaah_tingkatan_kelompok_map(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $names = [];
    if (table_exists($pdo, 'tingkatan')) {
        $names = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    if (table_exists($pdo, 'jadwal_kegiatan')) {
        $jadwalNames = $pdo->query('
            SELECT DISTINCT TRIM(tingkatan) AS tg FROM jadwal_kegiatan
            WHERE TRIM(tingkatan) <> "" AND TRIM(tingkatan) <> "Semua Tingkatan"
        ')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($jadwalNames as $jn) {
            $jn = trim((string) $jn);
            if ($jn !== '' && !in_array($jn, $names, true)) {
                $names[] = $jn;
            }
        }
    }

    $out = ['putra' => [], 'putri' => [], 'lain' => []];
    foreach ($names as $nama) {
        $nama = trim((string) $nama);
        if ($nama === '' || strcasecmp($nama, 'Semua Tingkatan') === 0) {
            continue;
        }
        if (jadwal_tingkatan_is_putri($nama)) {
            $out['putri'][] = $nama;
        } else {
            $out['putra'][] = $nama;
        }
    }

    sort($out['putra'], SORT_NATURAL | SORT_FLAG_CASE);
    sort($out['putri'], SORT_NATURAL | SORT_FLAG_CASE);
    sort($out['lain'], SORT_NATURAL | SORT_FLAG_CASE);

    return $cached = $out;
}

/** @return list<string> */
function jadwal_jamaah_tingkatan_list_kelompok(PDO $pdo, string $kelompok): array
{
    $kelompok = strtolower(trim($kelompok));
    $map = jadwal_jamaah_tingkatan_kelompok_map($pdo);

    return $map[$kelompok] ?? [];
}

/** Urutan tampilan sholat / jamaah berdasarkan nama kegiatan. */
function jadwal_jamaah_urutan_nama(string $nama): int
{
    $n = strtolower(trim($nama));
    $order = [
        'subuh' => 1,
        'dhuha' => 2,
        'dhuhur' => 3,
        'duhur' => 3,
        'ashar' => 4,
        'asar' => 4,
        'magrib' => 5,
        'maghrib' => 5,
        'isya' => 6,
        'isyak' => 6,
    ];
    foreach ($order as $needle => $prio) {
        if (str_contains($n, $needle)) {
            return $prio;
        }
    }

    return 50;
}

/**
 * @return array{jam_mulai:string,jam_selesai:string}|null
 */
function jadwal_jamaah_saran_waktu(string $namaKegiatan): ?array
{
    $n = strtolower(trim($namaKegiatan));
    $map = [
        'subuh' => ['04:30', '05:30'],
        'dhuha' => ['06:00', '06:45'],
        'dhuhur' => ['11:45', '12:30'],
        'duhur' => ['11:45', '12:30'],
        'ashar' => ['15:00', '15:45'],
        'asar' => ['15:00', '15:45'],
        'magrib' => ['18:00', '18:30'],
        'maghrib' => ['18:00', '18:30'],
        'isya' => ['19:15', '20:00'],
        'isyak' => ['19:15', '20:00'],
    ];
    foreach ($map as $needle => $pair) {
        if (str_contains($n, $needle)) {
            return ['jam_mulai' => $pair[0], 'jam_selesai' => $pair[1]];
        }
    }

    return null;
}

/**
 * @return array{
 *   slot_count:int,
 *   jam_mulai:string,
 *   jam_selesai:string,
 *   jam_mulai_tampil:string,
 *   jam_selesai_tampil:string,
 *   waktu_seragam:bool,
 *   variasi_count:int,
 *   tingkatan_tercover:list<string>
 * }
 */
function jadwal_jamaah_waktu_ringkasan_kelompok(PDO $pdo, int $kegiatanId, string $kelompok): array
{
    $empty = [
        'slot_count' => 0,
        'jam_mulai' => '',
        'jam_selesai' => '',
        'jam_mulai_tampil' => '',
        'jam_selesai_tampil' => '',
        'waktu_seragam' => true,
        'variasi_count' => 0,
        'tingkatan_tercover' => [],
    ];
    if ($kegiatanId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return $empty;
    }

    $tingkatanList = jadwal_jamaah_tingkatan_list_kelompok($pdo, $kelompok);
    if ($tingkatanList === []) {
        return $empty;
    }

    $ph = implode(',', array_fill(0, count($tingkatanList), '?'));
    $st = $pdo->prepare('
        SELECT
            COUNT(*) AS slot_count,
            MIN(jam_mulai) AS jm_min,
            MAX(jam_mulai) AS jm_max,
            MIN(jam_selesai) AS js_min,
            MAX(jam_selesai) AS js_max,
            COUNT(DISTINCT CONCAT(jam_mulai, "|", jam_selesai)) AS variasi_count,
            GROUP_CONCAT(DISTINCT TRIM(tingkatan) ORDER BY tingkatan SEPARATOR "||") AS tg_list
        FROM jadwal_kegiatan
        WHERE kegiatan_id = ? AND TRIM(tingkatan) IN (' . $ph . ')
    ');
    $st->execute(array_merge([$kegiatanId], $tingkatanList));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $empty;
    }

    $cnt = (int) ($row['slot_count'] ?? 0);
    if ($cnt <= 0) {
        return $empty;
    }

    $jm = (string) ($row['jm_min'] ?? '');
    $js = (string) ($row['js_min'] ?? '');
    $variasi = (int) ($row['variasi_count'] ?? 1);
    $tgRaw = (string) ($row['tg_list'] ?? '');
    $tgCover = $tgRaw !== '' ? array_values(array_filter(explode('||', $tgRaw))) : [];

    return [
        'slot_count' => $cnt,
        'jam_mulai' => $jm,
        'jam_selesai' => $js,
        'jam_mulai_tampil' => app_format_jam($jm),
        'jam_selesai_tampil' => app_format_jam($js),
        'waktu_seragam' => $variasi <= 1
            && jadwal_norm_jam((string) ($row['jm_min'] ?? '')) === jadwal_norm_jam((string) ($row['jm_max'] ?? ''))
            && jadwal_norm_jam((string) ($row['js_min'] ?? '')) === jadwal_norm_jam((string) ($row['js_max'] ?? '')),
        'variasi_count' => max(1, $variasi),
        'tingkatan_tercover' => $tgCover,
    ];
}

function jadwal_jamaah_semua_tingkatan_count(PDO $pdo, int $kegiatanId): int
{
    if ($kegiatanId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE kegiatan_id = :id AND TRIM(tingkatan) = "Semua Tingkatan"');
    $st->execute(['id' => $kegiatanId]);

    return (int) $st->fetchColumn();
}

/**
 * @return list<array{
 *   id:int,
 *   nama_kegiatan:string,
 *   is_active:int,
 *   semua_tingkatan_count:int,
 *   putra:array<string,mixed>,
 *   putri:array<string,mixed>,
 *   saran:array{jam_mulai:string,jam_selesai:string}|null,
 *   urutan:int,
 *   tingkatan_putra:list<string>,
 *   tingkatan_putri:list<string>
 * }>
 */
function jadwal_jamaah_daftar_editor(PDO $pdo): array
{
    if (!table_exists($pdo, 'kegiatan')) {
        return [];
    }
    ensure_kegiatan_kategori_column($pdo);
    $tkMap = jadwal_jamaah_tingkatan_kelompok_map($pdo);
    $rows = $pdo->query('
        SELECT id, nama_kegiatan, COALESCE(is_active, 1) AS is_active
        FROM kegiatan
        WHERE kategori_kegiatan = "JAMAAH"
        ORDER BY nama_kegiatan ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $kid = (int) ($row['id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $nama = (string) ($row['nama_kegiatan'] ?? '');
        $out[] = [
            'id' => $kid,
            'nama_kegiatan' => $nama,
            'is_active' => (int) ($row['is_active'] ?? 1),
            'semua_tingkatan_count' => jadwal_jamaah_semua_tingkatan_count($pdo, $kid),
            'putra' => jadwal_jamaah_waktu_ringkasan_kelompok($pdo, $kid, 'putra'),
            'putri' => jadwal_jamaah_waktu_ringkasan_kelompok($pdo, $kid, 'putri'),
            'saran' => jadwal_jamaah_saran_waktu($nama),
            'urutan' => jadwal_jamaah_urutan_nama($nama),
            'tingkatan_putra' => $tkMap['putra'],
            'tingkatan_putri' => $tkMap['putri'],
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = ($a['urutan'] ?? 50) <=> ($b['urutan'] ?? 50);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcasecmp((string) ($a['nama_kegiatan'] ?? ''), (string) ($b['nama_kegiatan'] ?? ''));
    });

    return $out;
}

function jadwal_jamaah_validasi_kelompok(string $kelompok): ?string
{
    $kelompok = strtolower(trim($kelompok));
    if (!in_array($kelompok, jadwal_jamaah_kelompok_valid(), true)) {
        return null;
    }

    return $kelompok;
}

/** Terapkan jam ke slot jadwal satu kegiatan jamaah untuk kelompok Putra atau Putri. */
function jadwal_jamaah_terapkan_waktu(
    PDO $pdo,
    int $kegiatanId,
    string $kelompok,
    string $jamMulai,
    string $jamSelesai,
    int $auditUserId
): array {
    $kelompok = jadwal_jamaah_validasi_kelompok($kelompok) ?? '';
    if ($kegiatanId <= 0 || $kelompok === '') {
        return ['ok' => false, 'message' => 'Data tidak valid.', 'updated' => 0];
    }
    if (jadwal_norm_jam($jamSelesai) <= jadwal_norm_jam($jamMulai)) {
        return ['ok' => false, 'message' => 'Jam selesai harus setelah jam mulai.', 'updated' => 0];
    }

    $stK = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id AND kategori_kegiatan = "JAMAAH" LIMIT 1');
    $stK->execute(['id' => $kegiatanId]);
    $namaKeg = (string) ($stK->fetchColumn() ?: '');
    if ($namaKeg === '') {
        return ['ok' => false, 'message' => 'Kegiatan jamaah tidak ditemukan.', 'updated' => 0];
    }

    $tingkatanList = jadwal_jamaah_tingkatan_list_kelompok($pdo, $kelompok);
    if ($tingkatanList === []) {
        $hint = $kelompok === 'putri'
            ? 'Tambahkan tingkatan ber-sufiks (putri), contoh: Tsanawiyah (putri).'
            : 'Pastikan ada tingkatan di pengaturan (tanpa sufiks (putri) = Putra).';

        return [
            'ok' => false,
            'message' => 'Tidak ada tingkatan ' . jadwal_jamaah_kelompok_label($kelompok) . ' terdeteksi. ' . $hint,
            'updated' => 0,
        ];
    }

    if (jadwal_jamaah_semua_tingkatan_count($pdo, $kegiatanId) > 0) {
        return [
            'ok' => false,
            'message' => 'Masih ada slot "Semua Tingkatan". Gunakan tombol "Pisahkan Putra & Putri" terlebih dahulu.',
            'updated' => 0,
        ];
    }

    $ph = implode(',', array_fill(0, count($tingkatanList), '?'));
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE kegiatan_id = ? AND TRIM(tingkatan) IN (' . $ph . ')');
    $stCnt->execute(array_merge([$kegiatanId], $tingkatanList));
    $beforeCount = (int) $stCnt->fetchColumn();
    if ($beforeCount <= 0) {
        return [
            'ok' => false,
            'message' => 'Belum ada jadwal ' . jadwal_jamaah_kelompok_label($kelompok) . '. Gunakan "Buat jadwal dasar" untuk kelompok ini.',
            'updated' => 0,
        ];
    }

    $upd = $pdo->prepare('
        UPDATE jadwal_kegiatan
        SET jam_mulai = ?, jam_selesai = ?
        WHERE kegiatan_id = ? AND TRIM(tingkatan) IN (' . $ph . ')
    ');
    $upd->execute(array_merge(
        [$jamMulai, $jamSelesai, $kegiatanId],
        $tingkatanList
    ));
    $updated = $upd->rowCount();

    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'UPDATE',
        $kegiatanId,
        ['kegiatan_id' => $kegiatanId, 'kelompok' => $kelompok, 'slot_count' => $beforeCount],
        ['jam_mulai' => $jamMulai, 'jam_selesai' => $jamSelesai, 'slot_updated' => $updated],
        $auditUserId,
        'Waktu jamaah ' . jadwal_jamaah_kelompok_label($kelompok) . ' "' . $namaKeg . '" (' . $updated . ' slot)'
    );

    return [
        'ok' => true,
        'message' => 'Waktu "' . $namaKeg . '" (' . jadwal_jamaah_kelompok_label($kelompok) . ') diperbarui pada ' . $updated . ' slot.',
        'updated' => $updated,
    ];
}

/**
 * Buat slot dasar per tingkatan dalam kelompok Putra/Putri.
 *
 * @return array{ok:bool,message:string,created:int}
 */
function jadwal_jamaah_buat_slot_dasar(
    PDO $pdo,
    int $kegiatanId,
    string $kelompok,
    string $jamMulai,
    string $jamSelesai,
    int $auditUserId
): array {
    $kelompok = jadwal_jamaah_validasi_kelompok($kelompok) ?? '';
    if ($kegiatanId <= 0 || $kelompok === '') {
        return ['ok' => false, 'message' => 'Data tidak valid.', 'created' => 0];
    }
    if (jadwal_norm_jam($jamSelesai) <= jadwal_norm_jam($jamMulai)) {
        return ['ok' => false, 'message' => 'Jam selesai harus setelah jam mulai.', 'created' => 0];
    }

    $stK = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id AND kategori_kegiatan = "JAMAAH" LIMIT 1');
    $stK->execute(['id' => $kegiatanId]);
    $namaKeg = (string) ($stK->fetchColumn() ?: '');
    if ($namaKeg === '') {
        return ['ok' => false, 'message' => 'Kegiatan jamaah tidak ditemukan.', 'created' => 0];
    }

    if (jadwal_jamaah_semua_tingkatan_count($pdo, $kegiatanId) > 0) {
        return ['ok' => false, 'message' => 'Pisahkan slot "Semua Tingkatan" terlebih dahulu.', 'created' => 0];
    }

    $tingkatanList = jadwal_jamaah_tingkatan_list_kelompok($pdo, $kelompok);
    if ($tingkatanList === []) {
        $hint = $kelompok === 'putri'
            ? 'Tambahkan tingkatan ber-sufiks (putri), contoh: Tsanawiyah (putri).'
            : 'Pastikan ada tingkatan di pengaturan (tanpa sufiks (putri) = Putra).';

        return [
            'ok' => false,
            'message' => 'Tidak ada tingkatan ' . jadwal_jamaah_kelompok_label($kelompok) . ' terdeteksi. ' . $hint,
            'created' => 0,
        ];
    }

    $hariLabels = [0 => 'Setiap Hari', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
    $ins = $pdo->prepare('
        INSERT INTO jadwal_kegiatan (kegiatan_id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, tempat)
        VALUES (:kegiatan_id, :tingkatan, :hari_ke, :jam_mulai, :jam_selesai, NULL, NULL)
    ');
    $created = 0;
    $createdIds = [];

    foreach ($tingkatanList as $tingkatan) {
        $stExist = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE kegiatan_id = :kid AND TRIM(tingkatan) = :tg');
        $stExist->execute(['kid' => $kegiatanId, 'tg' => $tingkatan]);
        if ((int) $stExist->fetchColumn() > 0) {
            continue;
        }

        $bentrok = jadwal_cek_bentrok($pdo, $tingkatan, 0, $jamMulai, $jamSelesai);
        if ($bentrok !== null) {
            return ['ok' => false, 'message' => jadwal_pesan_bentrok($bentrok, $hariLabels), 'created' => $created];
        }

        $ins->execute([
            'kegiatan_id' => $kegiatanId,
            'tingkatan' => $tingkatan,
            'hari_ke' => 0,
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
        ]);
        $newId = (int) $pdo->lastInsertId();
        if ($newId > 0) {
            $createdIds[] = $newId;
        }
        $created++;
    }

    if ($created <= 0) {
        return ['ok' => false, 'message' => 'Semua tingkatan ' . jadwal_jamaah_kelompok_label($kelompok) . ' sudah punya jadwal.', 'created' => 0];
    }

    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'CREATE',
        $createdIds[0] ?? $kegiatanId,
        null,
        ['kegiatan_id' => $kegiatanId, 'kelompok' => $kelompok, 'created' => $created],
        $auditUserId,
        'Buat jadwal dasar jamaah ' . jadwal_jamaah_kelompok_label($kelompok) . ' "' . $namaKeg . '"'
    );

    return [
        'ok' => true,
        'message' => 'Jadwal dasar "' . $namaKeg . '" (' . jadwal_jamaah_kelompok_label($kelompok) . ') dibuat: ' . $created . ' tingkatan.',
        'created' => $created,
    ];
}

/**
 * Ganti slot "Semua Tingkatan" menjadi slot per tingkatan Putra & Putri.
 *
 * @return array{ok:bool,message:string,created:int,deleted:int}
 */
function jadwal_jamaah_pisah_semua_tingkatan(
    PDO $pdo,
    int $kegiatanId,
    string $jamMulaiPutra,
    string $jamSelesaiPutra,
    string $jamMulaiPutri,
    string $jamSelesaiPutri,
    int $auditUserId
): array {
    if ($kegiatanId <= 0) {
        return ['ok' => false, 'message' => 'Kegiatan tidak valid.', 'created' => 0, 'deleted' => 0];
    }
    if (jadwal_norm_jam($jamSelesaiPutra) <= jadwal_norm_jam($jamMulaiPutra)) {
        return ['ok' => false, 'message' => 'Jam selesai Putra harus setelah jam mulai.', 'created' => 0, 'deleted' => 0];
    }
    if (jadwal_norm_jam($jamSelesaiPutri) <= jadwal_norm_jam($jamMulaiPutri)) {
        return ['ok' => false, 'message' => 'Jam selesai Putri harus setelah jam mulai.', 'created' => 0, 'deleted' => 0];
    }

    $semuaCount = jadwal_jamaah_semua_tingkatan_count($pdo, $kegiatanId);
    if ($semuaCount <= 0) {
        return ['ok' => false, 'message' => 'Tidak ada slot "Semua Tingkatan" untuk dipisah.', 'created' => 0, 'deleted' => 0];
    }

    $stK = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id AND kategori_kegiatan = "JAMAAH" LIMIT 1');
    $stK->execute(['id' => $kegiatanId]);
    $namaKeg = (string) ($stK->fetchColumn() ?: '');
    if ($namaKeg === '') {
        return ['ok' => false, 'message' => 'Kegiatan jamaah tidak ditemukan.', 'created' => 0, 'deleted' => 0];
    }

    require_once __DIR__ . '/presensi_admin.php';
    $stSemua = $pdo->prepare('SELECT id FROM jadwal_kegiatan WHERE kegiatan_id = :kid AND TRIM(tingkatan) = "Semua Tingkatan"');
    $stSemua->execute(['kid' => $kegiatanId]);
    $deleted = 0;
    foreach ($stSemua->fetchAll(PDO::FETCH_COLUMN) ?: [] as $delId) {
        $delId = (int) $delId;
        if ($delId <= 0) {
            continue;
        }
        presensi_hapus_untuk_jadwal($pdo, $delId);
        $pdo->prepare('DELETE FROM jadwal_kegiatan WHERE id = :id')->execute(['id' => $delId]);
        $deleted++;
    }

    foreach (['putra' => [$jamMulaiPutra, $jamSelesaiPutra], 'putri' => [$jamMulaiPutri, $jamSelesaiPutri]] as $grp => [$jm, $js]) {
        $buat = jadwal_jamaah_buat_slot_dasar($pdo, $kegiatanId, $grp, $jm, $js, $auditUserId);
        if (!$buat['ok'] && (int) ($buat['created'] ?? 0) === 0) {
            $ring = jadwal_jamaah_waktu_ringkasan_kelompok($pdo, $kegiatanId, $grp);
            if ((int) ($ring['slot_count'] ?? 0) === 0) {
                return $buat + ['deleted' => $deleted];
            }
        }
        $upd = jadwal_jamaah_terapkan_waktu($pdo, $kegiatanId, $grp, $jm, $js, $auditUserId);
        if (!$upd['ok']) {
            return $upd + ['deleted' => $deleted];
        }
    }

    operasional_audit_log(
        $pdo,
        OPERASIONAL_AUDIT_MODUL_JADWAL,
        'UPDATE',
        $kegiatanId,
        ['semua_tingkatan_deleted' => $deleted],
        ['putra' => [$jamMulaiPutra, $jamSelesaiPutra], 'putri' => [$jamMulaiPutri, $jamSelesaiPutri]],
        $auditUserId,
        'Pisah jadwal Semua Tingkatan → Putra/Putri "' . $namaKeg . '"'
    );

    return [
        'ok' => true,
        'message' => 'Jadwal "' . $namaKeg . '" dipisah: ' . $deleted . ' slot lama dihapus, waktu Putra & Putri disimpan per tingkatan.',
        'created' => 0,
        'deleted' => $deleted,
    ];
}
