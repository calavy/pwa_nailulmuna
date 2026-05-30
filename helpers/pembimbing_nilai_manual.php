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
