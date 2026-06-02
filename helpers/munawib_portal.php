<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/munawib.php';
require_once __DIR__ . '/akademik.php';

function munawib_session_id(): int
{
    return (int) ($_SESSION['munawib_id'] ?? 0);
}

function munawib_is_portal_session(): bool
{
    return munawib_session_id() > 0;
}

/**
 * @return array<string, mixed>|null
 */
function munawib_portal_konteks(): ?array
{
    if (!munawib_is_portal_session()) {
        return null;
    }
    $pbId = (int) ($_SESSION['munawib_pembimbing_id'] ?? 0);
    $kid = (int) ($_SESSION['munawib_kegiatan_id'] ?? 0);
    if ($pbId <= 0 || $kid <= 0) {
        return null;
    }

    return [
        'penugasan_id' => (int) ($_SESSION['munawib_penugasan_id'] ?? 0),
        'pembimbing_id' => $pbId,
        'kegiatan_id' => $kid,
        'pembimbing_nama' => trim((string) ($_SESSION['munawib_pembimbing_nama'] ?? '')),
        'kegiatan_nama' => trim((string) ($_SESSION['munawib_kegiatan_nama'] ?? '')),
        'tingkatan' => trim((string) ($_SESSION['munawib_portal_tingkatan'] ?? '')),
        'jam_mulai' => substr(trim((string) ($_SESSION['munawib_portal_jam_mulai'] ?? '')), 0, 5),
        'jam_selesai' => substr(trim((string) ($_SESSION['munawib_portal_jam_selesai'] ?? '')), 0, 5),
    ];
}

function munawib_portal_clear_konteks(): void
{
    unset(
        $_SESSION['munawib_pembimbing_id'],
        $_SESSION['munawib_kegiatan_id'],
        $_SESSION['munawib_penugasan_id'],
        $_SESSION['munawib_pembimbing_nama'],
        $_SESSION['munawib_kegiatan_nama'],
        $_SESSION['munawib_portal_tingkatan'],
        $_SESSION['munawib_portal_jam_mulai'],
        $_SESSION['munawib_portal_jam_selesai']
    );
}

/**
 * @param array<string, mixed> $row
 */
function munawib_portal_set_konteks(array $row): void
{
    $pbId = (int) ($row['pembimbing_id'] ?? 0);
    $kid = (int) ($row['kegiatan_id'] ?? 0);
    if ($pbId <= 0 || $kid <= 0) {
        return;
    }

    $_SESSION['munawib_pembimbing_id'] = $pbId;
    $_SESSION['munawib_kegiatan_id'] = $kid;
    $_SESSION['munawib_penugasan_id'] = (int) ($row['penugasan_id'] ?? 0);
    $_SESSION['munawib_pembimbing_nama'] = trim((string) ($row['pembimbing_nama'] ?? ''));
    $_SESSION['munawib_kegiatan_nama'] = trim((string) ($row['nama_kegiatan'] ?? ''));
    $_SESSION['munawib_portal_tingkatan'] = trim((string) ($row['tingkatan'] ?? ''));
    $_SESSION['munawib_portal_jam_mulai'] = (string) ($row['jam_mulai'] ?? '');
    $_SESSION['munawib_portal_jam_selesai'] = (string) ($row['jam_selesai'] ?? '');

    $tk = trim((string) ($row['tingkatan'] ?? ''));
    if ($tk !== '' && $tk !== 'Semua Tingkatan') {
        $_SESSION['munawib_tingkatan'] = [$tk];
    }
}

/**
 * Penugasan munawib yang kegiatan-nya sedang berlangsung (jam aktif hari ini).
 *
 * @return list<array<string, mixed>>
 */
function munawib_portal_penugasan_berlangsung(PDO $pdo, int $munawibId, ?string $tanggal = null, ?string $jam = null): array
{
    munawib_ensure_schema($pdo);
    if ($munawibId <= 0 || !table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }

    $tanggal = $tanggal ?: date('Y-m-d');
    $jam = $jam ?: date('H:i:s');
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());

    ensure_akademik_libur_table($pdo);
    $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
    if ($modeLiburAktif === 'ALL_BLOCKED') {
        return [];
    }
    $kategoriFilterSql = $modeLiburAktif !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLiburAktif, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';

    $jadwalJoin = '
        INNER JOIN jadwal_kegiatan j ON j.kegiatan_id = mp.kegiatan_id
            AND (mp.pembimbing_id IS NULL OR j.pembimbing_id = mp.pembimbing_id)
            AND (j.hari_ke = 0 OR j.hari_ke = :hari)
            AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
    ';
    if (column_exists($pdo, 'munawib_penugasan', 'jadwal_kegiatan_id')) {
        $jadwalJoin = '
            INNER JOIN jadwal_kegiatan j ON (
                (mp.jadwal_kegiatan_id IS NOT NULL AND mp.jadwal_kegiatan_id > 0 AND j.id = mp.jadwal_kegiatan_id)
                OR (
                    (mp.jadwal_kegiatan_id IS NULL OR mp.jadwal_kegiatan_id = 0)
                    AND j.kegiatan_id = mp.kegiatan_id
                    AND (mp.pembimbing_id IS NULL OR j.pembimbing_id = mp.pembimbing_id)
                )
            )
            AND (j.hari_ke = 0 OR j.hari_ke = :hari)
            AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
        ';
    }

    $sql = '
        SELECT mp.id AS penugasan_id,
               mp.pembimbing_id,
               mp.kegiatan_id,
               mp.munawib_id,
               b.nama_pembimbing AS pembimbing_nama,
               k.nama_kegiatan,
               j.tingkatan,
               j.jam_mulai,
               j.jam_selesai,
               j.tempat
        FROM munawib_penugasan mp
        INNER JOIN pembimbing b ON b.id = mp.pembimbing_id
        INNER JOIN kegiatan k ON k.id = mp.kegiatan_id AND k.is_active = 1
        ' . $jadwalJoin . '
        WHERE mp.munawib_id = :mid
          AND mp.status = "AKTIF"
          AND :tgl BETWEEN mp.tanggal_mulai AND mp.tanggal_selesai
          AND mp.pembimbing_id IS NOT NULL
          AND mp.pembimbing_id > 0
          AND mp.kegiatan_id IS NOT NULL
          AND mp.kegiatan_id > 0
          ' . $kategoriFilterSql . '
        ORDER BY j.jam_mulai ASC, k.nama_kegiatan ASC, b.nama_pembimbing ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        'mid' => $munawibId,
        'tgl' => $tanggal,
        'hari' => $hariKe,
        'jam' => $jam,
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $key = (int) ($row['penugasan_id'] ?? 0) . ':' . (int) ($row['pembimbing_id'] ?? 0) . ':' . (int) ($row['kegiatan_id'] ?? 0);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function munawib_portal_group_by_kegiatan(array $rows): array
{
    $byKeg = [];
    foreach ($rows as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        if (!isset($byKeg[$kid])) {
            $mulai = substr((string) ($row['jam_mulai'] ?? ''), 0, 5);
            $selesai = substr((string) ($row['jam_selesai'] ?? ''), 0, 5);
            $byKeg[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
                'jam_mulai' => $mulai,
                'jam_selesai' => $selesai,
                'jam_label' => ($mulai !== '' && $selesai !== '') ? ($mulai . '–' . $selesai) : '',
                'tingkatan' => (string) ($row['tingkatan'] ?? ''),
                'tempat' => trim((string) ($row['tempat'] ?? '')),
                'pembimbing' => [],
            ];
        }
        $pbId = (int) ($row['pembimbing_id'] ?? 0);
        if ($pbId <= 0) {
            continue;
        }
        foreach ($byKeg[$kid]['pembimbing'] as $pb) {
            if ((int) ($pb['pembimbing_id'] ?? 0) === $pbId) {
                continue 2;
            }
        }
        $byKeg[$kid]['pembimbing'][] = [
            'penugasan_id' => (int) ($row['penugasan_id'] ?? 0),
            'pembimbing_id' => $pbId,
            'pembimbing_nama' => (string) ($row['pembimbing_nama'] ?? ''),
            'tingkatan' => (string) ($row['tingkatan'] ?? ''),
            'jam_mulai' => (string) ($row['jam_mulai'] ?? ''),
            'jam_selesai' => (string) ($row['jam_selesai'] ?? ''),
        ];
    }

    return array_values($byKeg);
}

function munawib_portal_current_script(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

/** @return list<string> */
function munawib_portal_allowed_scripts(): array
{
    return [
        'munawib_portal.php',
        'dashboard.php',
        'nilai_manual.php',
        'logout.php',
    ];
}

function munawib_portal_require_konteks(): void
{
    if (!munawib_is_portal_session()) {
        return;
    }
    if (munawib_portal_konteks() !== null) {
        return;
    }
    if (munawib_portal_current_script() === 'munawib_portal.php') {
        return;
    }
    app_redirect('pembimbing/munawib_portal.php');
}

function munawib_portal_guard_halaman(): void
{
    if (!munawib_is_portal_session()) {
        return;
    }

    $script = munawib_portal_current_script();
    if ($script === 'munawib_portal.php') {
        return;
    }

    munawib_portal_require_konteks();

    if (in_array($script, munawib_portal_allowed_scripts(), true)) {
        return;
    }

    set_flash('warning', 'Portal munawib hanya dapat mengakses Penilaian dan Keaktivan santri.');
    app_redirect('pembimbing/dashboard.php');
}
