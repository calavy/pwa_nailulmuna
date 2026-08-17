<?php

declare(strict_types=1);

/**
 * Buku nomor WhatsApp penerima notifikasi pondok.
 */

/** @return array<string, array{label:string,setting:string,desc:string,group:string}> */
function wa_nomor_peran_definitions(): array
{
    return [
        'pengurus' => [
            'label' => 'Pengurus / ALPA',
            'setting' => 'wa_pengurus',
            'desc' => 'Notifikasi alpa otomatis & fallback umum (legacy putra)',
            'group' => 'Presensi & Alpa',
        ],
        'alpa_putra' => [
            'label' => 'ALPA santri putra',
            'setting' => 'wa_alpa_pengurus_putra',
            'desc' => 'Notifikasi crossing alpa khusus putra',
            'group' => 'Presensi & Alpa',
        ],
        'alpa_putri' => [
            'label' => 'ALPA santri putri',
            'setting' => 'wa_alpa_pengurus_putri',
            'desc' => 'Notifikasi crossing alpa khusus putri',
            'group' => 'Presensi & Alpa',
        ],
        'petugas_pendidikan' => [
            'label' => 'Petugas pendidikan',
            'setting' => 'wa_petugas_pendidikan',
            'desc' => 'Munawib belum hadir, kelas kosong',
            'group' => 'Presensi & Alpa',
        ],
        'kelas_kosong' => [
            'label' => 'Kelas kosong (eskalasi)',
            'setting' => 'wa_kelas_kosong_target_1',
            'desc' => 'Target eskalasi kelas kosong',
            'group' => 'Presensi & Alpa',
        ],
        'izin_baru' => [
            'label' => 'Permohonan izin baru',
            'setting' => 'wa_permohonan_izin',
            'desc' => 'Pengajuan izin status pending',
            'group' => 'Perizinan',
        ],
        'izin_pengurus' => [
            'label' => 'Izin disetujui / selesai',
            'setting' => 'wa_izin_pengurus',
            'desc' => 'Laporan izin ke pengurus surat',
            'group' => 'Perizinan',
        ],
        'izin_putra' => [
            'label' => 'Izin santri putra',
            'setting' => 'wa_izin_pengurus_putra',
            'desc' => 'Pengurus izin khusus putra',
            'group' => 'Perizinan',
        ],
        'izin_putri' => [
            'label' => 'Izin santri putri',
            'setting' => 'wa_izin_pengurus_putri',
            'desc' => 'Pengurus izin khusus putri',
            'group' => 'Perizinan',
        ],
        'cashless' => [
            'label' => 'Cashless / laporan',
            'setting' => 'cashless_laporan_harian_wa_targets',
            'desc' => 'Laporan harian transaksi cashless',
            'group' => 'Keuangan',
        ],
        'musyawarah' => [
            'label' => 'Musyawarah yayasan',
            'setting' => 'wa_musyawarah_target',
            'desc' => 'Notifikasi musyawarah & agenda',
            'group' => 'Yayasan',
        ],
    ];
}

function wa_nomor_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS wa_nomor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(120) NOT NULL,
            no_wa VARCHAR(40) NOT NULL,
            peran VARCHAR(500) NOT NULL DEFAULT "",
            catatan TEXT NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            urutan INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wa_nomor_aktif (is_aktif),
            INDEX idx_wa_nomor_urutan (urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $done = true;
    wa_nomor_migrate_legacy($pdo);
}

/** Impor nomor dari app_settings ke tabel wa_nomor (sekali). */
function wa_nomor_migrate_legacy(PDO $pdo): void
{
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_nomor')) {
        return;
    }

    $count = (int) ($pdo->query('SELECT COUNT(*) FROM wa_nomor')->fetchColumn() ?: 0);
    if ($count > 0) {
        return;
    }

    $defs = wa_nomor_peran_definitions();
    /** @var array<string, array{nama:string,no_wa:string,peran:list<string>}> $merged */
    $merged = [];

    foreach ($defs as $peranKey => $meta) {
        $settingKey = (string) ($meta['setting'] ?? '');
        if ($settingKey === '') {
            continue;
        }
        $raw = trim((string) app_setting($pdo, $settingKey, ''));
        if ($raw === '') {
            continue;
        }

        require_once __DIR__ . '/wa_otomatis.php';
        $phones = wa_otomatis_parse_targets($raw);
        foreach ($phones as $phone) {
            if (!isset($merged[$phone])) {
                $merged[$phone] = [
                    'nama' => 'Kontak ' . $phone,
                    'no_wa' => $phone,
                    'peran' => [],
                ];
            }
            if (!in_array($peranKey, $merged[$phone]['peran'], true)) {
                $merged[$phone]['peran'][] = $peranKey;
            }
        }
    }

    if ($merged === []) {
        return;
    }

    $st = $pdo->prepare('
        INSERT INTO wa_nomor (nama, no_wa, peran, catatan, is_aktif, urutan)
        VALUES (:nama, :no_wa, :peran, :catatan, 1, :urutan)
    ');
    $urutan = 0;
    foreach ($merged as $entry) {
        $st->execute([
            'nama' => $entry['nama'],
            'no_wa' => $entry['no_wa'],
            'peran' => implode(',', $entry['peran']),
            'catatan' => 'Diimpor otomatis dari pengaturan WA lama.',
            'urutan' => $urutan++,
        ]);
    }
}

/** @return list<string> */
function wa_nomor_parse_peran(string $raw): array
{
    $defs = wa_nomor_peran_definitions();
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $key = trim((string) $part);
        if ($key !== '' && isset($defs[$key])) {
            $out[$key] = $key;
        }
    }

    return array_values($out);
}

/** @return list<array<string, mixed>> */
function wa_nomor_list(PDO $pdo, ?string $filterPeran = null, bool $activeOnly = false): array
{
    wa_nomor_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_nomor')) {
        return [];
    }

    $sql = 'SELECT * FROM wa_nomor';
    $where = [];
    if ($activeOnly) {
        $where[] = 'is_aktif = 1';
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY urutan ASC, nama ASC';

    $st = $pdo->query($sql);
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    if ($filterPeran === null || $filterPeran === '') {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row) use ($filterPeran): bool {
        $peran = wa_nomor_parse_peran((string) ($row['peran'] ?? ''));

        return in_array($filterPeran, $peran, true);
    }));
}

/** @return array<string, mixed>|null */
function wa_nomor_get(PDO $pdo, int $id): ?array
{
    wa_nomor_ensure_schema($pdo);
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM wa_nomor WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Ambil nomor WA (comma-separated) untuk peran tertentu dari tabel wa_nomor.
 * Fallback ke app_settings jika tabel kosong untuk peran itu.
 */
function wa_nomor_targets(PDO $pdo, string $peran): string
{
    wa_nomor_ensure_schema($pdo);
    $peran = trim($peran);
    $defs = wa_nomor_peran_definitions();
    if ($peran === '' || !isset($defs[$peran])) {
        return '';
    }

    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_nomor')) {
        return trim((string) app_setting($pdo, (string) $defs[$peran]['setting'], ''));
    }

    require_once __DIR__ . '/wa_otomatis.php';
    $rows = wa_nomor_list($pdo, $peran, true);
    $phones = [];
    foreach ($rows as $row) {
        $phone = wa_otomatis_normalize_target((string) ($row['no_wa'] ?? ''));
        if ($phone !== '') {
            $phones[] = $phone;
        }
    }
    $phones = array_values(array_unique($phones));
    if ($phones !== []) {
        return implode(',', $phones);
    }

    return trim((string) app_setting($pdo, (string) $defs[$peran]['setting'], ''));
}

/** Sinkronkan semua peran dari tabel wa_nomor ke app_settings (backward compat). */
function wa_nomor_sync_settings(PDO $pdo): void
{
    wa_nomor_ensure_schema($pdo);
    foreach (wa_nomor_peran_definitions() as $peranKey => $meta) {
        $targets = wa_nomor_targets($pdo, $peranKey);
        save_setting($pdo, (string) $meta['setting'], $targets);
    }
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,message:string,id:int}
 */
function wa_nomor_save(PDO $pdo, array $data): array
{
    wa_nomor_ensure_schema($pdo);

    $id = (int) ($data['id'] ?? 0);
    $nama = trim((string) ($data['nama'] ?? ''));
    $noWaRaw = trim((string) ($data['no_wa'] ?? ''));
    $catatan = trim((string) ($data['catatan'] ?? ''));
    $isAktif = !empty($data['is_aktif']) ? 1 : 0;
    $urutan = (int) ($data['urutan'] ?? 0);

    $peranList = [];
    if (isset($data['peran']) && is_array($data['peran'])) {
        foreach ($data['peran'] as $p) {
            $peranList = array_merge($peranList, wa_nomor_parse_peran((string) $p));
        }
    } elseif (isset($data['peran_raw'])) {
        $peranList = wa_nomor_parse_peran((string) $data['peran_raw']);
    }
    $peranList = array_values(array_unique($peranList));

    if ($nama === '') {
        return ['ok' => false, 'message' => 'Nama kontak wajib diisi.', 'id' => 0];
    }

    require_once __DIR__ . '/wa_otomatis.php';
    $noWa = wa_otomatis_normalize_target($noWaRaw);
    if ($noWa === '') {
        return ['ok' => false, 'message' => 'Nomor WhatsApp tidak valid.', 'id' => 0];
    }

    if ($id > 0) {
        $existing = wa_nomor_get($pdo, $id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Kontak tidak ditemukan.', 'id' => 0];
        }
        $st = $pdo->prepare('
            UPDATE wa_nomor
            SET nama = :nama, no_wa = :no_wa, peran = :peran, catatan = :catatan,
                is_aktif = :aktif, urutan = :urutan
            WHERE id = :id
        ');
        $st->execute([
            'nama' => mb_substr($nama, 0, 120),
            'no_wa' => mb_substr($noWa, 0, 40),
            'peran' => implode(',', $peranList),
            'catatan' => $catatan !== '' ? $catatan : null,
            'aktif' => $isAktif,
            'urutan' => $urutan,
            'id' => $id,
        ]);
    } else {
        $st = $pdo->prepare('
            INSERT INTO wa_nomor (nama, no_wa, peran, catatan, is_aktif, urutan)
            VALUES (:nama, :no_wa, :peran, :catatan, :aktif, :urutan)
        ');
        $st->execute([
            'nama' => mb_substr($nama, 0, 120),
            'no_wa' => mb_substr($noWa, 0, 40),
            'peran' => implode(',', $peranList),
            'catatan' => $catatan !== '' ? $catatan : null,
            'aktif' => $isAktif,
            'urutan' => $urutan,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    wa_nomor_sync_settings($pdo);

    return ['ok' => true, 'message' => 'Nomor WA disimpan.', 'id' => $id];
}

/** @return array{ok:bool,message:string} */
function wa_nomor_delete(PDO $pdo, int $id): array
{
    wa_nomor_ensure_schema($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID tidak valid.'];
    }
    $existing = wa_nomor_get($pdo, $id);
    if (!$existing) {
        return ['ok' => false, 'message' => 'Kontak tidak ditemukan.'];
    }

    $pdo->prepare('DELETE FROM wa_nomor WHERE id = :id')->execute(['id' => $id]);
    wa_nomor_sync_settings($pdo);

    return ['ok' => true, 'message' => 'Nomor WA dihapus.'];
}

/** @return array{ok:bool,message:string} */
function wa_nomor_toggle(PDO $pdo, int $id): array
{
    wa_nomor_ensure_schema($pdo);
    $row = wa_nomor_get($pdo, $id);
    if (!$row) {
        return ['ok' => false, 'message' => 'Kontak tidak ditemukan.'];
    }

    $newActive = (int) ($row['is_aktif'] ?? 0) === 1 ? 0 : 1;
    $pdo->prepare('UPDATE wa_nomor SET is_aktif = :a WHERE id = :id')->execute([
        'a' => $newActive,
        'id' => $id,
    ]);
    wa_nomor_sync_settings($pdo);

    return [
        'ok' => true,
        'message' => $newActive === 1 ? 'Kontak diaktifkan.' : 'Kontak dinonaktifkan.',
    ];
}

/** Hitung jumlah kontak per peran. @return array<string, int> */
function wa_nomor_count_by_peran(PDO $pdo): array
{
    $counts = [];
    foreach (wa_nomor_peran_definitions() as $key => $_meta) {
        $counts[$key] = count(wa_nomor_list($pdo, $key, true));
    }

    return $counts;
}

/** Label peran untuk tampilan. */
function wa_nomor_peran_label(string $peranKey): string
{
    $defs = wa_nomor_peran_definitions();

    return (string) ($defs[$peranKey]['label'] ?? $peranKey);
}

/** Format nomor untuk tampilan (08xx dari 62xx). */
function wa_nomor_display(string $phone): string
{
    $phone = trim($phone);
    if (strpos($phone, '62') === 0 && strlen($phone) > 2) {
        return '0' . substr($phone, 2);
    }

    return $phone;
}
