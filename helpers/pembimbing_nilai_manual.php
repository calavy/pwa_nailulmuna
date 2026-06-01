<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function pembimbing_nilai_manual_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pembimbing_penilaian_target (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NOT NULL,
            judul VARCHAR(120) NOT NULL,
            deskripsi VARCHAR(500) NULL,
            aspek VARCHAR(20) NOT NULL DEFAULT \'murod\',
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NOT NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_penilaian_target_pb (pembimbing_id, is_aktif),
            KEY idx_penilaian_target_periode (tanggal_mulai, tanggal_selesai)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    if (!column_exists($pdo, 'pembimbing_nilai_manual', 'target_id')) {
        try {
            $pdo->exec('ALTER TABLE pembimbing_nilai_manual ADD COLUMN target_id INT UNSIGNED NULL AFTER pembimbing_id');
        } catch (Throwable $e) {
        }
    }

    $done = true;
}

/** @return list<array<string, mixed>> */
function pembimbing_nilai_manual_targets(PDO $pdo, int $pembimbingId, bool $aktifOnly = true): array
{
    pembimbing_nilai_manual_ensure_schema($pdo);
    if ($pembimbingId <= 0) {
        return [];
    }
    $sql = '
        SELECT id, pembimbing_id, judul, deskripsi, aspek, tanggal_mulai, tanggal_selesai, is_aktif, created_at
        FROM pembimbing_penilaian_target
        WHERE pembimbing_id = :pid
    ';
    if ($aktifOnly) {
        $sql .= ' AND is_aktif = 1';
    }
    $sql .= ' ORDER BY tanggal_mulai DESC, id DESC';
    $st = $pdo->prepare($sql);
    $st->execute(['pid' => $pembimbingId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pembimbing_nilai_manual_target_by_id(PDO $pdo, int $targetId): ?array
{
    if ($targetId <= 0) {
        return null;
    }
    pembimbing_nilai_manual_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM pembimbing_penilaian_target WHERE id = :id LIMIT 1');
    $st->execute(['id' => $targetId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param list<int> $santriIds
 * @return array<int, array{nilai:float,catatan:?string,tanggal:string}>
 */
function pembimbing_nilai_manual_map_for_target(PDO $pdo, int $targetId, array $santriIds): array
{
    pembimbing_nilai_manual_ensure_schema($pdo);
    if ($targetId <= 0 || $santriIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $santriIds));
    $st = $pdo->query('
        SELECT n.santri_id, n.nilai, n.catatan, n.tanggal
        FROM pembimbing_nilai_manual n
        INNER JOIN (
            SELECT santri_id, MAX(tanggal) AS tanggal_terakhir
            FROM pembimbing_nilai_manual
            WHERE target_id = ' . (int) $targetId . ' AND santri_id IN (' . $in . ')
            GROUP BY santri_id
        ) x ON x.santri_id = n.santri_id AND x.tanggal_terakhir = n.tanggal
        WHERE n.target_id = ' . (int) $targetId . ' AND n.santri_id IN (' . $in . ')
    ');
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $out[$sid] = [
                'nilai' => (float) ($row['nilai'] ?? 0),
                'catatan' => $row['catatan'] ?? null,
                'tanggal' => (string) ($row['tanggal'] ?? ''),
            ];
        }
    }

    return $out;
}

/** Jumlah santri scope pembimbing yang belum dinilai pada target manual aktif. */
function pembimbing_dashboard_belum_dinilai_manual(PDO $pdo, int $pembimbingId, array $tingkatanList): int
{
    if ($pembimbingId <= 0 || $tingkatanList === []) {
        return 0;
    }
    require_once __DIR__ . '/pembimbing_dashboard.php';
    require_once __DIR__ . '/pembimbing_pkpps.php';
    require_once __DIR__ . '/santri_operasional.php';

    $targets = pembimbing_nilai_manual_targets($pdo, $pembimbingId, true);
    $today = date('Y-m-d');
    $activeTarget = null;
    foreach ($targets as $t) {
        $mulai = (string) ($t['tanggal_mulai'] ?? '');
        $selesai = (string) ($t['tanggal_selesai'] ?? '');
        if ($mulai !== '' && $selesai !== '' && $today >= $mulai && $today <= $selesai) {
            $activeTarget = $t;
            break;
        }
    }
    if ($activeTarget === null && $targets !== []) {
        $activeTarget = $targets[0];
    }
    if ($activeTarget === null) {
        return 0;
    }
    $targetId = (int) ($activeTarget['id'] ?? 0);
    if ($targetId <= 0) {
        return 0;
    }

    $santriIds = [];
    $kajianTk = array_values(array_filter($tingkatanList, static fn (string $tk): bool => !pembimbing_pkpps_is_label($tk)));
    if ($kajianTk !== [] && table_exists($pdo, 'santri')) {
        $aktifSql = santri_sql_aktif_only('s');
        [$inSql, $params] = pembimbing_dashboard_in_clause($kajianTk, 'tk');
        $st = $pdo->prepare('SELECT s.id FROM santri s WHERE ' . $aktifSql . ' AND s.tingkatan IN (' . $inSql . ')');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
            $sid = (int) $sid;
            if ($sid > 0) {
                $santriIds[$sid] = $sid;
            }
        }
    }
    foreach (pembimbing_pkpps_santri_list($pdo, $pembimbingId, [], 600) as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $santriIds[$sid] = $sid;
        }
    }
    if ($santriIds === []) {
        return 0;
    }
    $nilaiMap = pembimbing_nilai_manual_map_for_target($pdo, $targetId, array_values($santriIds));
    $belum = 0;
    foreach ($santriIds as $sid) {
        if (!isset($nilaiMap[$sid])) {
            $belum++;
        }
    }

    return $belum;
}
