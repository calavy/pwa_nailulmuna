<?php

declare(strict_types=1);

function ensure_akademik_agenda_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_agenda (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            jam_mulai TIME NULL,
            jam_selesai TIME NULL,
            judul VARCHAR(200) NOT NULL,
            jenis ENUM("acara","tugas") NOT NULL DEFAULT "acara",
            catatan TEXT NULL,
            ulang_interval_hari INT UNSIGNED NULL DEFAULT NULL COMMENT "NULL/0=tidak berulang; N=setiap N hari",
            ulang_hingga DATE NULL,
            ingat_menit INT UNSIGNED NULL DEFAULT NULL COMMENT "Menit sebelum acara untuk push (nanti)",
            ingat_terkirim_pada DATETIME NULL,
            untuk_peran VARCHAR(80) NULL COMMENT "admin,pengurus,pembimbing,semua",
            selesai TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agenda_tanggal (tanggal),
            INDEX idx_agenda_jenis (jenis),
            INDEX idx_agenda_created (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $migrations = [
        'ulang_interval_hari' => 'INT UNSIGNED NULL DEFAULT NULL',
        'ulang_hingga' => 'DATE NULL',
        'ingat_menit' => 'INT UNSIGNED NULL DEFAULT NULL',
        'ingat_terkirim_pada' => 'DATETIME NULL',
    ];
    foreach ($migrations as $col => $def) {
        if (!column_exists($pdo, 'akademik_agenda', $col)) {
            $pdo->exec('ALTER TABLE akademik_agenda ADD COLUMN ' . $col . ' ' . $def);
        }
    }
}

/** @return list<string> */
function akademik_agenda_occurrence_dates_in_range(
    string $tanggalMulai,
    int $ulangHari,
    ?string $ulangHingga,
    string $rangeStart,
    string $rangeEnd
): array {
    $tsMulai = strtotime($tanggalMulai);
    $tsStart = strtotime($rangeStart);
    $tsEnd = strtotime($rangeEnd);
    if ($tsMulai === false || $tsStart === false || $tsEnd === false) {
        return [];
    }

    if ($ulangHari < 1) {
        return ($tsMulai >= $tsStart && $tsMulai <= $tsEnd) ? [$tanggalMulai] : [];
    }

    $tsUntil = $ulangHingga !== null && $ulangHingga !== ''
        ? strtotime($ulangHingga)
        : strtotime('+3 years', $tsMulai);
    if ($tsUntil === false) {
        $tsUntil = strtotime('+3 years', $tsMulai);
    }

    $out = [];
    $cursor = $tsMulai;
    if ($cursor < $tsStart) {
        $diffDays = (int) floor(($tsStart - $cursor) / 86400);
        $steps = (int) ceil($diffDays / $ulangHari);
        $cursor += $steps * $ulangHari * 86400;
    }

    $guard = 0;
    while ($cursor <= $tsEnd && $cursor <= $tsUntil && $guard < 500) {
        if ($cursor >= $tsStart) {
            $out[] = date('Y-m-d', $cursor);
        }
        $cursor += $ulangHari * 86400;
        $guard++;
    }

    return $out;
}

/**
 * Baris agenda mentah yang mungkin berulang ke dalam rentang.
 *
 * @return list<array<string, mixed>>
 */
function akademik_agenda_raw_touching_range(PDO $pdo, string $start, string $end): array
{
    ensure_akademik_agenda_table($pdo);
    $st = $pdo->prepare('
        SELECT a.*, u.nama AS pembuat_nama
        FROM akademik_agenda a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE (
            (COALESCE(a.ulang_interval_hari, 0) = 0 AND a.tanggal BETWEEN :s AND :e)
            OR (
                a.ulang_interval_hari > 0
                AND a.tanggal <= :e2
                AND (a.ulang_hingga IS NULL OR a.ulang_hingga >= :s2)
            )
        )
        ORDER BY a.tanggal ASC, COALESCE(a.jam_mulai, "00:00:00") ASC, a.id ASC
    ');
    $st->execute(['s' => $start, 'e' => $end, 's2' => $start, 'e2' => $end]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Perluas ke tiap tanggal kemunculan (untuk grid & daftar bulan).
 *
 * @return list<array<string, mixed>>
 */
function akademik_agenda_expanded_for_range(PDO $pdo, string $start, string $end): array
{
    $rows = akademik_agenda_raw_touching_range($pdo, $start, $end);
    $expanded = [];

    foreach ($rows as $row) {
        if (!empty($row['selesai']) && (int) ($row['ulang_interval_hari'] ?? 0) > 0) {
            continue;
        }
        $interval = (int) ($row['ulang_interval_hari'] ?? 0);
        $dates = akademik_agenda_occurrence_dates_in_range(
            (string) $row['tanggal'],
            $interval,
            isset($row['ulang_hingga']) && $row['ulang_hingga'] !== '' ? (string) $row['ulang_hingga'] : null,
            $start,
            $end
        );
        foreach ($dates as $occ) {
            $item = $row;
            $item['occurrence_date'] = $occ;
            $item['is_ulang'] = $occ !== (string) $row['tanggal'];
            $expanded[] = $item;
        }
    }

    usort($expanded, static function (array $a, array $b): int {
        $da = (string) ($a['occurrence_date'] ?? $a['tanggal'] ?? '');
        $db = (string) ($b['occurrence_date'] ?? $b['tanggal'] ?? '');
        $cmp = strcmp($da, $db);
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    return $expanded;
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function akademik_agenda_by_date_map(PDO $pdo, string $start, string $end): array
{
    $map = [];
    foreach (akademik_agenda_expanded_for_range($pdo, $start, $end) as $item) {
        $d = (string) ($item['occurrence_date'] ?? $item['tanggal'] ?? '');
        if ($d === '') {
            continue;
        }
        $map[$d][] = $item;
    }

    return $map;
}

/**
 * @param list<array<string,mixed>|null> $cells
 * @return list<array<string,mixed>|null>
 */
function akademik_agenda_merge_into_cells(array $cells, array $agendaByDate): array
{
    foreach ($cells as $i => $cell) {
        if (!is_array($cell)) {
            continue;
        }
        $ymd = (string) ($cell['masehi'] ?? '');
        if ($ymd === '') {
            continue;
        }
        $items = $agendaByDate[$ymd] ?? [];
        if ($items !== []) {
            $cell['agenda_items'] = $items;
        }
        $cells[$i] = $cell;
    }

    return $cells;
}

/** @return list<array<string, mixed>> */
function akademik_agenda_for_range(PDO $pdo, string $start, string $end): array
{
    return akademik_agenda_expanded_for_range($pdo, $start, $end);
}

/** @return list<array<string, mixed>> */
function akademik_agenda_for_date(PDO $pdo, string $date): array
{
    return akademik_agenda_for_range($pdo, $date, $date);
}

function akademik_agenda_user_can_manage(array $agenda, int $userId, string $role, bool $isSuperAdmin = false): bool
{
    if ($role === 'admin' || $isSuperAdmin) {
        return true;
    }
    $creator = (int) ($agenda['created_by'] ?? 0);

    return $creator > 0 && $creator === $userId;
}

/** @param array<string, mixed> $post */
function akademik_agenda_insert_from_post(PDO $pdo, array $post, int $userId): int
{
    ensure_akademik_agenda_table($pdo);
    $tanggal = trim((string) ($post['tanggal'] ?? ''));
    $judul = trim((string) ($post['judul'] ?? ''));
    $jenis = trim((string) ($post['jenis'] ?? 'acara'));
    $ulang = max(0, (int) ($post['ulang_interval_hari'] ?? 0));
    $ulangHingga = trim((string) ($post['ulang_hingga'] ?? ''));
    $ingat = max(0, (int) ($post['ingat_menit'] ?? 0));

    $st = $pdo->prepare('
        INSERT INTO akademik_agenda (
            tanggal, jam_mulai, jam_selesai, judul, jenis, catatan,
            ulang_interval_hari, ulang_hingga, ingat_menit, untuk_peran, created_by
        ) VALUES (
            :tgl, :jm, :js, :judul, :jenis, :cat,
            :ulang, :ulang_hingga, :ingat, :peran, :uid
        )
    ');
    $st->execute([
        'tgl' => $tanggal,
        'jm' => trim((string) ($post['jam_mulai'] ?? '')) ?: null,
        'js' => trim((string) ($post['jam_selesai'] ?? '')) ?: null,
        'judul' => mb_substr($judul, 0, 200),
        'jenis' => $jenis === 'tugas' ? 'tugas' : 'acara',
        'cat' => trim((string) ($post['catatan'] ?? '')) ?: null,
        'ulang' => $ulang > 0 ? $ulang : null,
        'ulang_hingga' => ($ulang > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ulangHingga)) ? $ulangHingga : null,
        'ingat' => $ingat > 0 ? $ingat : null,
        'peran' => trim((string) ($post['untuk_peran'] ?? 'semua')) ?: 'semua',
        'uid' => $userId > 0 ? $userId : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function akademik_agenda_delete(PDO $pdo, int $id, int $userId, string $role, bool $isSuperAdmin = false): bool
{
    ensure_akademik_agenda_table($pdo);
    $st = $pdo->prepare('SELECT * FROM akademik_agenda WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (!akademik_agenda_user_can_manage($row, $userId, $role, $isSuperAdmin)) {
        return false;
    }
    $pdo->prepare('DELETE FROM akademik_agenda WHERE id = :id')->execute(['id' => $id]);

    return true;
}

/**
 * Siapkan data ringkas untuk modal JS.
 *
 * @param array<string, list<array<string, mixed>>> $byDate
 * @return array<string, list<array<string, mixed>>>
 */
function akademik_agenda_json_for_modal(array $byDate, int $currentUserId, string $role, bool $isSuperAdmin = false): array
{
    $out = [];
    foreach ($byDate as $date => $items) {
        $list = [];
        foreach ($items as $it) {
            $list[] = [
                'id' => (int) ($it['id'] ?? 0),
                'judul' => (string) ($it['judul'] ?? ''),
                'jenis' => (string) ($it['jenis'] ?? 'acara'),
                'catatan' => (string) ($it['catatan'] ?? ''),
                'jam_mulai' => $it['jam_mulai'] ? substr((string) $it['jam_mulai'], 0, 5) : '',
                'ulang_interval_hari' => (int) ($it['ulang_interval_hari'] ?? 0),
                'is_ulang' => !empty($it['is_ulang']),
                'pembuat_nama' => (string) ($it['pembuat_nama'] ?? ''),
                'can_delete' => akademik_agenda_user_can_manage($it, $currentUserId, $role, $isSuperAdmin),
            ];
        }
        $out[$date] = $list;
    }

    return $out;
}
