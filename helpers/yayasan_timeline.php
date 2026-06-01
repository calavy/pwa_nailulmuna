<?php

declare(strict_types=1);

require_once __DIR__ . '/yayasan.php';
require_once __DIR__ . '/kalender_agenda.php';

function yayasan_timeline_ensure_schema(PDO $pdo): void
{
    yayasan_ensure_tables($pdo);
    ensure_akademik_agenda_table($pdo);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS yayasan_tugas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            notulen_id INT NULL,
            rapat_id INT NULL,
            agenda_id INT UNSIGNED NULL,
            urutan INT NOT NULL DEFAULT 0,
            judul VARCHAR(200) NOT NULL,
            deskripsi TEXT NULL,
            penanggung_jawab VARCHAR(120) NULL,
            tanggal_mulai DATE NOT NULL,
            tanggal_target DATE NOT NULL,
            tanggal_selesai DATE NULL,
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM("BARU","BERJALAN","SELESAI","TERLAMBAT") NOT NULL DEFAULT "BARU",
            sumber ENUM("RAPAT","MANUAL") NOT NULL DEFAULT "MANUAL",
            sync_kalender TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_yt_rapat (rapat_id),
            INDEX idx_yt_notulen (notulen_id),
            INDEX idx_yt_target (tanggal_target),
            INDEX idx_yt_status (status),
            INDEX idx_yt_agenda (agenda_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * @return list<string>
 */
function yayasan_tugas_split_lines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/^[\-\*\•\d]+[\.\)\]]\s*/u', '', $line) ?? $line;
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }

    return $out;
}

function yayasan_tugas_parse_date(string $fragment): ?string
{
    $fragment = trim($fragment);
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $fragment, $m)) {
        return $m[1];
    }
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $fragment, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    $bulan = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];
    if (preg_match('/(\d{1,2})\s+([a-z]+)\s+(\d{4})/iu', $fragment, $m)) {
        $bl = strtolower($m[2]);
        if (isset($bulan[$bl])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], $bulan[$bl], (int) $m[1]);
        }
    }

    return null;
}

/**
 * Parse satu baris tindak lanjut rapat.
 *
 * @return array{judul:string,penanggung_jawab:?string,tanggal_target:?string,progress:int,deskripsi:?string}|null
 */
function yayasan_tugas_parse_line(string $line, string $defaultStart): ?array
{
    $raw = trim($line);
    if ($raw === '') {
        return null;
    }

    $progress = 0;
    if (preg_match('/\[(\d{1,3})%\]/u', $raw, $m)) {
        $progress = max(0, min(100, (int) $m[1]));
        $raw = trim(str_replace($m[0], '', $raw));
    } elseif (preg_match('/^(\d{1,3})%\s+/u', $raw, $m)) {
        $progress = max(0, min(100, (int) $m[1]));
        $raw = trim(substr($raw, strlen($m[0])));
    }

    $pj = null;
    if (preg_match('/\[PJ:\s*([^\]]+)\]/iu', $raw, $m)) {
        $pj = trim($m[1]);
        $raw = trim(str_replace($m[0], '', $raw));
    } elseif (preg_match('/\|\s*PJ:\s*(.+)$/iu', $raw, $m)) {
        $pj = trim($m[1]);
        $raw = trim(substr($raw, 0, -strlen($m[0])));
    }

    $target = null;
    if (preg_match('/(?:sampai|deadline|target|batas)\s*[:\-]?\s*([^|]+)/iu', $raw, $m)) {
        $target = yayasan_tugas_parse_date($m[1]);
        $raw = trim(preg_replace('/(?:sampai|deadline|target|batas)\s*[:\-]?\s*[^|]+/iu', '', $raw) ?? $raw);
    }

    $parts = array_map('trim', explode('|', $raw));
    $judul = trim((string) ($parts[0] ?? ''));
    foreach (array_slice($parts, 1) as $part) {
        if ($part === '') {
            continue;
        }
        if ($target === null) {
            $maybe = yayasan_tugas_parse_date($part);
            if ($maybe !== null) {
                $target = $maybe;
                continue;
            }
        }
        if ($pj === null && preg_match('/^PJ:\s*(.+)$/iu', $part, $m)) {
            $pj = trim($m[1]);
        }
    }

    if ($judul === '') {
        return null;
    }

    if ($target === null) {
        $target = date('Y-m-d', strtotime($defaultStart . ' +14 days'));
    }

    return [
        'judul' => mb_substr($judul, 0, 200),
        'penanggung_jawab' => $pj !== null && $pj !== '' ? mb_substr($pj, 0, 120) : null,
        'tanggal_target' => $target,
        'progress' => $progress,
        'deskripsi' => null,
    ];
}

/**
 * @return list<array{judul:string,penanggung_jawab:?string,tanggal_target:string,progress:int,deskripsi:?string}>
 */
function yayasan_tugas_parse_text(string $text, string $defaultStart): array
{
    $out = [];
    foreach (yayasan_tugas_split_lines($text) as $line) {
        $parsed = yayasan_tugas_parse_line($line, $defaultStart);
        if ($parsed !== null) {
            $out[] = $parsed;
        }
    }

    return $out;
}

function yayasan_tugas_compute_status(int $progress, string $target, ?string $selesai): string
{
    if ($selesai !== null && $selesai !== '' || $progress >= 100) {
        return 'SELESAI';
    }
    if ($target !== '' && $target < date('Y-m-d') && $progress < 100) {
        return 'TERLAMBAT';
    }
    if ($progress > 0) {
        return 'BERJALAN';
    }

    return 'BARU';
}

function yayasan_tugas_status_label(string $status): string
{
    return match (strtoupper($status)) {
        'SELESAI' => 'Selesai',
        'TERLAMBAT' => 'Terlambat',
        'BERJALAN' => 'Berjalan',
        default => 'Baru',
    };
}

function yayasan_tugas_status_badge(string $status): string
{
    return match (strtoupper($status)) {
        'SELESAI' => 'success',
        'TERLAMBAT' => 'danger',
        'BERJALAN' => 'primary',
        default => 'secondary',
    };
}

/**
 * @param array<string, mixed> $row
 */
function yayasan_tugas_sync_agenda(PDO $pdo, array $row, int $userId): ?int
{
    if ((int) ($row['sync_kalender'] ?? 1) !== 1) {
        return isset($row['agenda_id']) ? (int) $row['agenda_id'] : null;
    }

    ensure_akademik_agenda_table($pdo);
    $tugasId = (int) ($row['id'] ?? 0);
    $judul = '[Yayasan] ' . trim((string) ($row['judul'] ?? ''));
    $target = (string) ($row['tanggal_target'] ?? date('Y-m-d'));
    $progress = (int) ($row['progress'] ?? 0);
    $status = (string) ($row['status'] ?? 'BARU');
    $pj = trim((string) ($row['penanggung_jawab'] ?? ''));
    $catatan = 'Tugas yayasan #' . $tugasId;
    if ($pj !== '') {
        $catatan .= ' · PJ: ' . $pj;
    }
    $catatan .= ' · Progres: ' . $progress . '%';
    if ($status === 'SELESAI') {
        $catatan .= ' · SELESAI';
    }

    $agendaId = (int) ($row['agenda_id'] ?? 0);
    if ($agendaId > 0) {
        $pdo->prepare('
            UPDATE akademik_agenda
            SET tanggal = :tgl, judul = :judul, jenis = "tugas", catatan = :cat,
                selesai = :selesai, untuk_peran = "admin,pengurus"
            WHERE id = :id
        ')->execute([
            'tgl' => $target,
            'judul' => mb_substr($judul, 0, 200),
            'cat' => $catatan,
            'selesai' => $status === 'SELESAI' ? 1 : 0,
            'id' => $agendaId,
        ]);

        return $agendaId;
    }

    $st = $pdo->prepare('
        INSERT INTO akademik_agenda (tanggal, judul, jenis, catatan, untuk_peran, selesai, created_by)
        VALUES (:tgl, :judul, "tugas", :cat, "admin,pengurus", :selesai, :uid)
    ');
    $st->execute([
        'tgl' => $target,
        'judul' => mb_substr($judul, 0, 200),
        'cat' => $catatan,
        'selesai' => $status === 'SELESAI' ? 1 : 0,
        'uid' => $userId > 0 ? $userId : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Sinkronkan baris tindak lanjut notulen → tugas timeline.
 */
function yayasan_tugas_sync_from_notulen(PDO $pdo, int $notulenId, int $rapatId, string $text, string $rapatDate, int $userId): int
{
    yayasan_timeline_ensure_schema($pdo);
    if ($notulenId <= 0) {
        return 0;
    }

    $parsed = yayasan_tugas_parse_text($text, $rapatDate);
    $stOld = $pdo->prepare('SELECT * FROM yayasan_tugas WHERE notulen_id = :nid ORDER BY urutan ASC, id ASC');
    $stOld->execute(['nid' => $notulenId]);
    $existing = $stOld->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $usedIds = [];
    $count = 0;
    foreach ($parsed as $i => $item) {
        $progress = (int) ($item['progress'] ?? 0);
        $target = (string) $item['tanggal_target'];
        $old = $existing[$i] ?? null;
        if (is_array($old)) {
            $tid = (int) $old['id'];
            $progress = $progress > 0 ? $progress : (int) ($old['progress'] ?? 0);
            $selesai = $old['tanggal_selesai'] ?? null;
            $status = yayasan_tugas_compute_status($progress, $target, is_string($selesai) ? $selesai : null);
            $pdo->prepare('
                UPDATE yayasan_tugas SET
                    judul = :judul, penanggung_jawab = :pj, tanggal_target = :target,
                    progress = :prog, status = :st, urutan = :ur
                WHERE id = :id
            ')->execute([
                'judul' => $item['judul'],
                'pj' => $item['penanggung_jawab'],
                'target' => $target,
                'prog' => $progress,
                'st' => $status,
                'ur' => $i,
                'id' => $tid,
            ]);
            $row = array_merge($old, [
                'id' => $tid,
                'judul' => $item['judul'],
                'penanggung_jawab' => $item['penanggung_jawab'],
                'tanggal_target' => $target,
                'progress' => $progress,
                'status' => $status,
            ]);
            $agendaId = yayasan_tugas_sync_agenda($pdo, $row, $userId);
            if ($agendaId !== null && (int) ($old['agenda_id'] ?? 0) !== $agendaId) {
                $pdo->prepare('UPDATE yayasan_tugas SET agenda_id = :aid WHERE id = :id')->execute(['aid' => $agendaId, 'id' => $tid]);
            }
            $usedIds[] = $tid;
        } else {
            $status = yayasan_tugas_compute_status($progress, $target, null);
            $pdo->prepare('
                INSERT INTO yayasan_tugas (
                    notulen_id, rapat_id, urutan, judul, penanggung_jawab,
                    tanggal_mulai, tanggal_target, progress, status, sumber, created_by
                ) VALUES (
                    :nid, :rid, :ur, :judul, :pj, :mulai, :target, :prog, :st, "RAPAT", :uid
                )
            ')->execute([
                'nid' => $notulenId,
                'rid' => $rapatId > 0 ? $rapatId : null,
                'ur' => $i,
                'judul' => $item['judul'],
                'pj' => $item['penanggung_jawab'],
                'mulai' => $rapatDate,
                'target' => $target,
                'prog' => $progress,
                'st' => $status,
                'uid' => $userId > 0 ? $userId : null,
            ]);
            $tid = (int) $pdo->lastInsertId();
            $row = [
                'id' => $tid,
                'judul' => $item['judul'],
                'penanggung_jawab' => $item['penanggung_jawab'],
                'tanggal_target' => $target,
                'progress' => $progress,
                'status' => $status,
                'sync_kalender' => 1,
                'agenda_id' => null,
            ];
            $agendaId = yayasan_tugas_sync_agenda($pdo, $row, $userId);
            if ($agendaId !== null) {
                $pdo->prepare('UPDATE yayasan_tugas SET agenda_id = :aid WHERE id = :id')->execute(['aid' => $agendaId, 'id' => $tid]);
            }
            $usedIds[] = $tid;
        }
        $count++;
    }

    foreach ($existing as $ex) {
        $eid = (int) ($ex['id'] ?? 0);
        if ($eid > 0 && !in_array($eid, $usedIds, true)) {
            $aid = (int) ($ex['agenda_id'] ?? 0);
            if ($aid > 0) {
                $pdo->prepare('DELETE FROM akademik_agenda WHERE id = :id')->execute(['id' => $aid]);
            }
            $pdo->prepare('DELETE FROM yayasan_tugas WHERE id = :id')->execute(['id' => $eid]);
        }
    }

    return $count;
}

/**
 * @param array<string, mixed> $data
 */
function yayasan_tugas_insert_manual(PDO $pdo, array $data, int $userId): int
{
    yayasan_timeline_ensure_schema($pdo);
    $judul = trim((string) ($data['judul'] ?? ''));
    if ($judul === '') {
        return 0;
    }
    $mulai = trim((string) ($data['tanggal_mulai'] ?? date('Y-m-d')));
    $target = trim((string) ($data['tanggal_target'] ?? $mulai));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
        $mulai = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) {
        $target = $mulai;
    }
    $progress = max(0, min(100, (int) ($data['progress'] ?? 0)));
    $status = yayasan_tugas_compute_status($progress, $target, null);

    $pdo->prepare('
        INSERT INTO yayasan_tugas (
            judul, deskripsi, penanggung_jawab, tanggal_mulai, tanggal_target,
            progress, status, sumber, sync_kalender, created_by
        ) VALUES (
            :judul, :desk, :pj, :mulai, :target, :prog, :st, "MANUAL", :sync, :uid
        )
    ')->execute([
        'judul' => mb_substr($judul, 0, 200),
        'desk' => trim((string) ($data['deskripsi'] ?? '')) ?: null,
        'pj' => trim((string) ($data['penanggung_jawab'] ?? '')) ?: null,
        'mulai' => $mulai,
        'target' => $target,
        'prog' => $progress,
        'st' => $status,
        'sync' => !empty($data['sync_kalender']) ? 1 : 0,
        'uid' => $userId > 0 ? $userId : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $row = [
        'id' => $id,
        'judul' => $judul,
        'penanggung_jawab' => $data['penanggung_jawab'] ?? null,
        'tanggal_target' => $target,
        'progress' => $progress,
        'status' => $status,
        'sync_kalender' => !empty($data['sync_kalender']) ? 1 : 0,
        'agenda_id' => null,
    ];
    $agendaId = yayasan_tugas_sync_agenda($pdo, $row, $userId);
    if ($agendaId !== null) {
        $pdo->prepare('UPDATE yayasan_tugas SET agenda_id = :aid WHERE id = :id')->execute(['aid' => $agendaId, 'id' => $id]);
    }

    return $id;
}

function yayasan_tugas_update_progress(PDO $pdo, int $id, int $progress, int $userId): bool
{
    yayasan_timeline_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM yayasan_tugas WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $progress = max(0, min(100, $progress));
    $selesai = $row['tanggal_selesai'] ?? null;
    if ($progress >= 100) {
        $selesai = date('Y-m-d');
    }
    $status = yayasan_tugas_compute_status($progress, (string) $row['tanggal_target'], is_string($selesai) ? $selesai : null);
    $pdo->prepare('
        UPDATE yayasan_tugas SET progress = :p, status = :st, tanggal_selesai = :selesai WHERE id = :id
    ')->execute([
        'p' => $progress,
        'st' => $status,
        'selesai' => $selesai,
        'id' => $id,
    ]);
    $row['progress'] = $progress;
    $row['status'] = $status;
    $agendaId = yayasan_tugas_sync_agenda($pdo, $row, $userId);
    if ($agendaId !== null && (int) ($row['agenda_id'] ?? 0) !== $agendaId) {
        $pdo->prepare('UPDATE yayasan_tugas SET agenda_id = :aid WHERE id = :id')->execute(['aid' => $agendaId, 'id' => $id]);
    }

    return true;
}

function yayasan_tugas_delete(PDO $pdo, int $id): bool
{
    yayasan_timeline_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT agenda_id FROM yayasan_tugas WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $aid = (int) ($st->fetchColumn() ?: 0);
    if ($aid > 0) {
        $pdo->prepare('DELETE FROM akademik_agenda WHERE id = :id')->execute(['id' => $aid]);
    }
    $pdo->prepare('DELETE FROM yayasan_tugas WHERE id = :id')->execute(['id' => $id]);

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_tugas_list(PDO $pdo, ?string $filter = null): array
{
    yayasan_timeline_ensure_schema($pdo);
    $where = '';
    if ($filter === 'aktif') {
        $where = ' WHERE t.status <> "SELESAI"';
    } elseif ($filter === 'terlambat') {
        $where = ' WHERE t.status = "TERLAMBAT"';
    } elseif ($filter === 'rapat') {
        $where = ' WHERE t.sumber = "RAPAT"';
    } elseif ($filter === 'manual') {
        $where = ' WHERE t.sumber = "MANUAL"';
    }

    $sql = '
        SELECT t.*, r.judul AS rapat_judul, r.tanggal_rapat, n.judul AS notulen_judul
        FROM yayasan_tugas t
        LEFT JOIN yayasan_rapat r ON r.id = t.rapat_id
        LEFT JOIN yayasan_notulen n ON n.id = t.notulen_id
        ' . $where . '
        ORDER BY t.tanggal_target ASC, t.id ASC
    ';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['status'] = yayasan_tugas_compute_status(
            (int) ($row['progress'] ?? 0),
            (string) ($row['tanggal_target'] ?? ''),
            isset($row['tanggal_selesai']) && $row['tanggal_selesai'] !== '' ? (string) $row['tanggal_selesai'] : null
        );
        $row['status_label'] = yayasan_tugas_status_label((string) $row['status']);
        $row['status_badge'] = yayasan_tugas_status_badge((string) $row['status']);
        $target = (string) ($row['tanggal_target'] ?? '');
        $mulai = (string) ($row['tanggal_mulai'] ?? $target);
        $today = date('Y-m-d');
        $totalDays = max(1, (int) ((strtotime($target) - strtotime($mulai)) / 86400) + 1);
        $elapsed = max(0, (int) ((strtotime(min($today, $target)) - strtotime($mulai)) / 86400));
        $row['timeline_pct'] = min(100, round(100 * $elapsed / $totalDays));
    }
    unset($row);

    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, list<array<string, mixed>>>
 */
function yayasan_tugas_group_by_month(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $t = (string) ($row['tanggal_target'] ?? '');
        $key = preg_match('/^(\d{4}-\d{2})/', $t, $m) ? $m[1] : '0000-00';
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $row;
    }
    ksort($groups);

    return $groups;
}

function yayasan_tugas_stats(array $rows): array
{
    $stats = ['total' => count($rows), 'selesai' => 0, 'terlambat' => 0, 'berjalan' => 0, 'baru' => 0];
    foreach ($rows as $r) {
        match ((string) ($r['status'] ?? '')) {
            'SELESAI' => $stats['selesai']++,
            'TERLAMBAT' => $stats['terlambat']++,
            'BERJALAN' => $stats['berjalan']++,
            default => $stats['baru']++,
        };
    }

    return $stats;
}
