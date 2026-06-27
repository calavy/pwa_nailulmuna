<?php

declare(strict_types=1);

require_once __DIR__ . '/yayasan.php';
require_once __DIR__ . '/kalender_agenda.php';
require_once __DIR__ . '/yayasan_task_roles.php';

function yayasan_timeline_ensure_schema(PDO $pdo): void
{
    yayasan_ensure_tables($pdo);
    ensure_akademik_agenda_table($pdo);
    yayasan_task_roles_ensure_schema($pdo);

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
            category ENUM("Akademik","Asrama","Yayasan") NOT NULL DEFAULT "Yayasan",
            pic_id INT NULL,
            tanggal_mulai DATE NOT NULL,
            tanggal_target DATE NOT NULL,
            start_at DATETIME NULL,
            due_at DATETIME NULL,
            tanggal_selesai DATE NULL,
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM("BARU","BERJALAN","SELESAI","TERLAMBAT") NOT NULL DEFAULT "BARU",
            attachment VARCHAR(500) NULL,
            sumber ENUM("RAPAT","MANUAL") NOT NULL DEFAULT "MANUAL",
            sync_kalender TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_yt_rapat (rapat_id),
            INDEX idx_yt_notulen (notulen_id),
            INDEX idx_yt_target (tanggal_target),
            INDEX idx_yt_status (status),
            INDEX idx_yt_agenda (agenda_id),
            INDEX idx_yt_pic (pic_id),
            INDEX idx_yt_category (category),
            INDEX idx_yt_due_at (due_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    yayasan_timeline_migrate_columns($pdo);
}

function yayasan_timeline_migrate_columns(PDO $pdo): void
{
    $adds = [
        'category' => 'ALTER TABLE yayasan_tugas ADD COLUMN category ENUM("Akademik","Asrama","Yayasan") NOT NULL DEFAULT "Yayasan" AFTER penanggung_jawab',
        'pic_id' => 'ALTER TABLE yayasan_tugas ADD COLUMN pic_id INT NULL AFTER category',
        'start_at' => 'ALTER TABLE yayasan_tugas ADD COLUMN start_at DATETIME NULL AFTER tanggal_target',
        'due_at' => 'ALTER TABLE yayasan_tugas ADD COLUMN due_at DATETIME NULL AFTER start_at',
        'attachment' => 'ALTER TABLE yayasan_tugas ADD COLUMN attachment VARCHAR(500) NULL AFTER status',
    ];
    foreach ($adds as $col => $sql) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM yayasan_tugas LIKE " . $pdo->quote($col))->fetch(PDO::FETCH_ASSOC);
            if (!$chk) {
                $pdo->exec($sql);
            }
        } catch (PDOException $e) {
            // abaikan
        }
    }

    try {
        $pdo->exec('
            UPDATE yayasan_tugas
            SET start_at = CONCAT(tanggal_mulai, " 08:00:00"),
                due_at = CONCAT(tanggal_target, " 17:00:00")
            WHERE start_at IS NULL OR due_at IS NULL
        ');
    } catch (PDOException $e) {
        // abaikan
    }

    yayasan_tugas_assignee_ensure_schema($pdo);
}

function yayasan_tugas_assignee_ensure_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS yayasan_tugas_assignee (
            tugas_id INT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            peran ENUM("PJ","PEMBANTU") NOT NULL DEFAULT "PJ",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (tugas_id, user_id),
            INDEX idx_yta_user (user_id),
            INDEX idx_yta_peran (peran)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    try {
        $pdo->exec('
            INSERT IGNORE INTO yayasan_tugas_assignee (tugas_id, user_id, peran)
            SELECT id, pic_id, "PJ"
            FROM yayasan_tugas
            WHERE pic_id IS NOT NULL AND pic_id > 0
        ');
    } catch (PDOException $e) {
        // abaikan
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{pj:list<int>,pembantu:list<int>}
 */
function yayasan_tugas_parse_assignee_ids(array $data): array
{
    $pj = [];
    foreach ((array) ($data['pj_ids'] ?? $data['pic_ids'] ?? []) as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $pj[$id] = true;
        }
    }
    $legacyPic = (int) ($data['pic_id'] ?? 0);
    if ($legacyPic > 0) {
        $pj[$legacyPic] = true;
    }

    $pembantu = [];
    foreach ((array) ($data['pembantu_ids'] ?? []) as $rawId) {
        $id = (int) $rawId;
        if ($id > 0 && !isset($pj[$id])) {
            $pembantu[$id] = true;
        }
    }

    return [
        'pj' => array_values(array_map('intval', array_keys($pj))),
        'pembantu' => array_values(array_map('intval', array_keys($pembantu))),
    ];
}

/**
 * @return array{pj:list<array{id:int,nama:string}>,pembantu:list<array{id:int,nama:string}>,pj_ids:list<int>,pembantu_ids:list<int>}
 */
function yayasan_tugas_load_assignees(PDO $pdo, int $taskId): array
{
    yayasan_tugas_assignee_ensure_schema($pdo);
    $empty = ['pj' => [], 'pembantu' => [], 'pj_ids' => [], 'pembantu_ids' => []];
    if ($taskId <= 0) {
        return $empty;
    }
    $st = $pdo->prepare('
        SELECT a.user_id, a.peran,
               COALESCE(NULLIF(TRIM(u.nama), ""), u.username, "") AS nama
        FROM yayasan_tugas_assignee a
        INNER JOIN users u ON u.id = a.user_id
        WHERE a.tugas_id = :tid
        ORDER BY a.peran ASC, nama ASC, a.user_id ASC
    ');
    $st->execute(['tid' => $taskId]);
    $out = $empty;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $uid = (int) ($row['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $item = ['id' => $uid, 'nama' => trim((string) ($row['nama'] ?? '')) ?: ('User #' . $uid)];
        if (strtoupper((string) ($row['peran'] ?? 'PJ')) === 'PEMBANTU') {
            $out['pembantu'][] = $item;
            $out['pembantu_ids'][] = $uid;
        } else {
            $out['pj'][] = $item;
            $out['pj_ids'][] = $uid;
        }
    }

    return $out;
}

/**
 * @param list<int> $pjIds
 * @param list<int> $pembantuIds
 */
function yayasan_tugas_save_assignees(PDO $pdo, int $taskId, array $pjIds, array $pembantuIds): void
{
    yayasan_tugas_assignee_ensure_schema($pdo);
    if ($taskId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM yayasan_tugas_assignee WHERE tugas_id = :id')->execute(['id' => $taskId]);
    $st = $pdo->prepare('INSERT INTO yayasan_tugas_assignee (tugas_id, user_id, peran) VALUES (:tid, :uid, :peran)');
    $used = [];
    foreach ($pjIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || isset($used[$uid])) {
            continue;
        }
        $used[$uid] = true;
        $st->execute(['tid' => $taskId, 'uid' => $uid, 'peran' => 'PJ']);
    }
    foreach ($pembantuIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || isset($used[$uid])) {
            continue;
        }
        $used[$uid] = true;
        $st->execute(['tid' => $taskId, 'uid' => $uid, 'peran' => 'PEMBANTU']);
    }
    $primaryPic = $pjIds !== [] ? (int) $pjIds[0] : null;
    $pdo->prepare('UPDATE yayasan_tugas SET pic_id = :pic WHERE id = :id')->execute([
        'pic' => $primaryPic,
        'id' => $taskId,
    ]);
}

/**
 * @param list<array{id:int,nama:string}> $people
 */
function yayasan_tugas_format_people_names(array $people): string
{
    $names = [];
    foreach ($people as $p) {
        $n = trim((string) ($p['nama'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
    }

    return implode(', ', $names);
}

/**
 * @param array<string, mixed> $task
 */
function yayasan_tugas_user_is_assignee(array $task, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    foreach ((array) ($task['pj_ids'] ?? []) as $id) {
        if ((int) $id === $userId) {
            return true;
        }
    }
    foreach ((array) ($task['pembantu_ids'] ?? []) as $id) {
        if ((int) $id === $userId) {
            return true;
        }
    }

    return (int) ($task['pic_id'] ?? 0) === $userId;
}

/**
 * @param list<int> $userIds
 * @return list<array<string, mixed>>
 */
function yayasan_tugas_find_conflicts_multi(PDO $pdo, array $userIds, string $startAt, string $dueAt, ?int $excludeId = null): array
{
    $seen = [];
    $out = [];
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        foreach (yayasan_tugas_find_conflicts($pdo, $uid, $startAt, $dueAt, $excludeId) as $row) {
            $cid = (int) ($row['id'] ?? 0);
            if ($cid > 0 && !isset($seen[$cid])) {
                $seen[$cid] = true;
                $row['conflict_user_id'] = $uid;
                $out[] = $row;
            }
        }
    }

    return $out;
}

function yayasan_tugas_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/yayasan_tugas';
}

/**
 * @param array{name?:string,tmp_name?:string,error?:int} $file
 * @return array{ok:bool,path?:string,error?:string}
 */
function yayasan_tugas_handle_attachment_upload(array $file, ?string $oldRelativePath = null): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload bukti gagal. Coba lagi.'];
    }
    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
        return ['ok' => false, 'error' => 'Format tidak didukung. Gunakan JPG, PNG, WEBP, atau PDF.'];
    }
    if (!is_uploaded_file($tmpFile)) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }
    $maxBytes = 2 * 1024 * 1024;
    if (@filesize($tmpFile) > $maxBytes) {
        return ['ok' => false, 'error' => 'Ukuran file maksimal 2 MB.'];
    }
    $targetDir = yayasan_tugas_upload_dir();
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'error' => 'Folder upload tidak dapat dibuat.'];
    }
    $safeName = 'tugas-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($tmpFile, $targetPath)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan file ke server.'];
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && function_exists('user_profil_optimize_uploaded_image')) {
        require_once __DIR__ . '/user_profil.php';
        user_profil_optimize_uploaded_image($targetPath);
    }
    if ($oldRelativePath !== null && $oldRelativePath !== '') {
        $oldFull = dirname(__DIR__) . '/' . ltrim($oldRelativePath, '/');
        if (is_file($oldFull) && str_starts_with($oldRelativePath, 'uploads/yayasan_tugas/')) {
            @unlink($oldFull);
        }
    }

    return ['ok' => true, 'path' => 'uploads/yayasan_tugas/' . $safeName];
}

function yayasan_tugas_normalize_datetime(string $value, string $fallbackDate, string $fallbackTime): string
{
    $value = trim($value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
        $ts = strtotime(str_replace('T', ' ', $value));

        return $ts !== false ? date('Y-m-d H:i:s', $ts) : ($fallbackDate . ' ' . $fallbackTime);
    }
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ' ' . $fallbackTime;
    }

    return $fallbackDate . ' ' . $fallbackTime;
}

function yayasan_tugas_task_window(array $row): array
{
    $start = (string) ($row['start_at'] ?? '');
    $due = (string) ($row['due_at'] ?? '');
    if ($start === '' || $due === '') {
        $mulai = (string) ($row['tanggal_mulai'] ?? date('Y-m-d'));
        $target = (string) ($row['tanggal_target'] ?? $mulai);
        $start = $mulai . ' 08:00:00';
        $due = $target . ' 17:00:00';
    }

    return ['start' => $start, 'due' => $due];
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_tugas_find_conflicts(PDO $pdo, int $picId, string $startAt, string $dueAt, ?int $excludeId = null): array
{
    yayasan_timeline_ensure_schema($pdo);
    if ($picId <= 0) {
        return [];
    }
    $sql = '
        SELECT t.*, u.nama AS pic_nama, u.username AS pic_username
        FROM yayasan_tugas t
        LEFT JOIN users u ON u.id = t.pic_id
        WHERE t.status <> "SELESAI"
          AND (
            t.pic_id = :pic
            OR EXISTS (
                SELECT 1 FROM yayasan_tugas_assignee a
                WHERE a.tugas_id = t.id AND a.user_id = :pic2
            )
          )
          AND COALESCE(t.start_at, CONCAT(t.tanggal_mulai, " 08:00:00")) < :due_new
          AND COALESCE(t.due_at, CONCAT(t.tanggal_target, " 17:00:00")) > :start_new
    ';
    $params = [
        'pic' => $picId,
        'pic2' => $picId,
        'start_new' => $startAt,
        'due_new' => $dueAt,
    ];
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND t.id <> :excl';
        $params['excl'] = $excludeId;
    }
    $sql .= ' ORDER BY t.due_at ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        'BERJALAN' => 'Proses',
        'IN PROGRESS' => 'Proses',
        'DONE' => 'Selesai',
        'OVERDUE' => 'Terlambat',
        'PENDING' => 'Belum',
        default => 'Belum',
    };
}

function yayasan_tugas_status_badge(string $status): string
{
    return match (strtoupper($status)) {
        'SELESAI', 'DONE' => 'success',
        'TERLAMBAT', 'OVERDUE' => 'danger',
        'BERJALAN', 'IN PROGRESS' => 'primary',
        default => 'secondary',
    };
}

function yayasan_tugas_category_color(string $category): string
{
    return match (trim($category)) {
        'Asrama' => '#22C55E',
        'Akademik' => '#3B82F6',
        default => '#EF4444',
    };
}

function yayasan_tugas_category_badge(string $category): string
{
    return match (trim($category)) {
        'Asrama' => 'success',
        'Akademik' => 'primary',
        default => 'danger',
    };
}

function yayasan_tugas_status_from_toggle(string $toggle): string
{
    return match (strtolower(trim($toggle))) {
        'proses', 'in progress', 'berjalan' => 'BERJALAN',
        'selesai', 'done' => 'SELESAI',
        default => 'BARU',
    };
}

function yayasan_tugas_toggle_from_status(string $status): string
{
    return match (strtoupper($status)) {
        'BERJALAN', 'IN PROGRESS' => 'proses',
        'SELESAI', 'DONE' => 'selesai',
        default => 'belum',
    };
}

function yayasan_tugas_progress_from_status(string $status, int $current = 0): int
{
    return match (strtoupper($status)) {
        'SELESAI', 'DONE' => 100,
        'BERJALAN', 'IN PROGRESS' => max(1, min(99, $current > 0 ? $current : 50)),
        default => 0,
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
 * Sinkronkan baris timeline notulen (JSON) → tugas yayasan.
 *
 * @param list<array{
 *   judul:string,
 *   penanggung_jawab:?string,
 *   tanggal_mulai:string,
 *   tanggal_target:string,
 *   start_at:string,
 *   due_at:string,
 *   category:string,
 *   deskripsi:?string,
 *   progress:int
 * }> $items
 */
function yayasan_tugas_sync_from_timeline_table(PDO $pdo, int $notulenId, int $rapatId, array $items, int $userId): int
{
    yayasan_timeline_ensure_schema($pdo);
    if ($notulenId <= 0) {
        return 0;
    }

    $stOld = $pdo->prepare('SELECT * FROM yayasan_tugas WHERE notulen_id = :nid ORDER BY urutan ASC, id ASC');
    $stOld->execute(['nid' => $notulenId]);
    $existing = $stOld->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $usedIds = [];
    $count = 0;
    foreach ($items as $i => $item) {
        $judul = trim((string) ($item['judul'] ?? ''));
        if ($judul === '') {
            continue;
        }
        $progress = (int) ($item['progress'] ?? 0);
        $target = (string) ($item['tanggal_target'] ?? '');
        $mulai = (string) ($item['tanggal_mulai'] ?? $target);
        $startAt = (string) ($item['start_at'] ?? ($mulai . ' 08:00:00'));
        $dueAt = (string) ($item['due_at'] ?? ($target . ' 17:00:00'));
        $category = (string) ($item['category'] ?? 'Yayasan');
        if (!in_array($category, yayasan_task_categories(), true)) {
            $category = 'Yayasan';
        }
        $desk = trim((string) ($item['deskripsi'] ?? '')) ?: null;
        $pj = trim((string) ($item['penanggung_jawab'] ?? '')) ?: null;
        $old = $existing[$i] ?? null;
        if (is_array($old)) {
            $tid = (int) $old['id'];
            $progress = $progress > 0 ? $progress : (int) ($old['progress'] ?? 0);
            $selesai = $old['tanggal_selesai'] ?? null;
            $status = yayasan_tugas_compute_status($progress, $target, is_string($selesai) ? $selesai : null);
            $pdo->prepare('
                UPDATE yayasan_tugas SET
                    judul = :judul, deskripsi = :desk, penanggung_jawab = :pj,
                    category = :cat, tanggal_mulai = :mulai, tanggal_target = :target,
                    start_at = :start_at, due_at = :due_at,
                    progress = :prog, status = :st, urutan = :ur
                WHERE id = :id
            ')->execute([
                'judul' => $judul,
                'desk' => $desk,
                'pj' => $pj,
                'cat' => $category,
                'mulai' => $mulai,
                'target' => $target,
                'start_at' => $startAt,
                'due_at' => $dueAt,
                'prog' => $progress,
                'st' => $status,
                'ur' => $i,
                'id' => $tid,
            ]);
            $row = array_merge($old, [
                'id' => $tid,
                'judul' => $judul,
                'penanggung_jawab' => $pj,
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
                    notulen_id, rapat_id, urutan, judul, deskripsi, penanggung_jawab, category,
                    tanggal_mulai, tanggal_target, start_at, due_at, progress, status, sumber, created_by
                ) VALUES (
                    :nid, :rid, :ur, :judul, :desk, :pj, :cat,
                    :mulai, :target, :start_at, :due_at, :prog, :st, "RAPAT", :uid
                )
            ')->execute([
                'nid' => $notulenId,
                'rid' => $rapatId > 0 ? $rapatId : null,
                'ur' => $i,
                'judul' => $judul,
                'desk' => $desk,
                'pj' => $pj,
                'cat' => $category,
                'mulai' => $mulai,
                'target' => $target,
                'start_at' => $startAt,
                'due_at' => $dueAt,
                'prog' => $progress,
                'st' => $status,
                'uid' => $userId > 0 ? $userId : null,
            ]);
            $tid = (int) $pdo->lastInsertId();
            $row = [
                'id' => $tid,
                'judul' => $judul,
                'penanggung_jawab' => $pj,
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
            yayasan_tugas_delete($pdo, $eid);
        }
    }

    return $count;
}

/**
 * @param array<string, mixed> $data
 */
function yayasan_tugas_insert_manual(PDO $pdo, array $data, int $userId, bool $forceConflict = false): array
{
    yayasan_timeline_ensure_schema($pdo);
    $judul = trim((string) ($data['judul'] ?? ''));
    if ($judul === '') {
        return ['ok' => false, 'id' => 0, 'message' => 'Judul tugas wajib diisi.'];
    }
    $mulai = trim((string) ($data['tanggal_mulai'] ?? date('Y-m-d')));
    $target = trim((string) ($data['tanggal_target'] ?? $mulai));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
        $mulai = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) {
        $target = $mulai;
    }
    $startAt = yayasan_tugas_normalize_datetime(
        (string) ($data['start_at'] ?? ''),
        $mulai,
        (string) ($data['start_time'] ?? '08:00:00')
    );
    $dueAt = yayasan_tugas_normalize_datetime(
        (string) ($data['due_at'] ?? ''),
        $target,
        (string) ($data['due_time'] ?? '17:00:00')
    );
    if (strtotime($dueAt) < strtotime($startAt)) {
        $dueAt = $startAt;
    }
    $category = trim((string) ($data['category'] ?? 'Yayasan'));
    if (!in_array($category, yayasan_task_categories(), true)) {
        $category = 'Yayasan';
    }
    if (!yayasan_task_user_can_manage_category($pdo, $userId, $category)) {
        return ['ok' => false, 'id' => 0, 'message' => 'Anda tidak berhak membuat tugas kategori ini.'];
    }
    $assignees = yayasan_tugas_parse_assignee_ids($data);
    $pjIds = $assignees['pj'];
    $pembantuIds = $assignees['pembantu'];
    $allAssigneeIds = array_values(array_unique(array_merge($pjIds, $pembantuIds)));
    $conflicts = $allAssigneeIds !== []
        ? yayasan_tugas_find_conflicts_multi($pdo, $allAssigneeIds, $startAt, $dueAt)
        : [];
    if ($conflicts !== [] && !$forceConflict) {
        return [
            'ok' => false,
            'id' => 0,
            'conflict' => true,
            'message' => 'Peringatan: Pembimbing bersangkutan memiliki jadwal bentrok!',
            'conflicts' => $conflicts,
        ];
    }
    $progress = max(0, min(100, (int) ($data['progress'] ?? 0)));
    $status = yayasan_tugas_compute_status($progress, $target, null);
    $picId = $pjIds !== [] ? (int) $pjIds[0] : null;
    $pjName = null;
    if ($pjIds !== []) {
        $placeholders = implode(',', array_fill(0, count($pjIds), '?'));
        $stNames = $pdo->prepare('
            SELECT COALESCE(NULLIF(TRIM(nama), ""), username) AS nama
            FROM users WHERE id IN (' . $placeholders . ')
            ORDER BY FIELD(id, ' . $placeholders . ')
        ');
        $stNames->execute(array_merge($pjIds, $pjIds));
        $names = [];
        foreach ($stNames->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $names[] = $n;
            }
        }
        $pjName = $names !== [] ? implode(', ', $names) : null;
    }
    if ($pjName === null) {
        $pjName = trim((string) ($data['penanggung_jawab'] ?? '')) ?: null;
    }

    $pdo->prepare('
        INSERT INTO yayasan_tugas (
            judul, deskripsi, penanggung_jawab, category, pic_id,
            tanggal_mulai, tanggal_target, start_at, due_at,
            progress, status, sumber, sync_kalender, created_by
        ) VALUES (
            :judul, :desk, :pj, :cat, :pic,
            :mulai, :target, :start_at, :due_at,
            :prog, :st, "MANUAL", :sync, :uid
        )
    ')->execute([
        'judul' => mb_substr($judul, 0, 200),
        'desk' => trim((string) ($data['deskripsi'] ?? '')) ?: null,
        'pj' => $pjName !== null ? mb_substr($pjName, 0, 120) : null,
        'cat' => $category,
        'pic' => $picId,
        'mulai' => $mulai,
        'target' => $target,
        'start_at' => $startAt,
        'due_at' => $dueAt,
        'prog' => $progress,
        'st' => $status,
        'sync' => !empty($data['sync_kalender']) ? 1 : 0,
        'uid' => $userId > 0 ? $userId : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    yayasan_tugas_save_assignees($pdo, $id, $pjIds, $pembantuIds);
    $row = [
        'id' => $id,
        'judul' => $judul,
        'penanggung_jawab' => $pjName,
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

    if ($allAssigneeIds !== []) {
        if (!function_exists('yayasan_tugas_wa_notify_assignees')) {
            require_once __DIR__ . '/wa_yayasan_tugas.php';
        }
        yayasan_tugas_wa_notify_assignees($pdo, $id, 'baru');
    }

    return ['ok' => true, 'id' => $id, 'message' => 'Tugas timeline ditambahkan.'];
}

/**
 * @param array<string, mixed> $data
 */
function yayasan_tugas_update_manual(PDO $pdo, int $id, array $data, int $userId, bool $forceConflict = false): array
{
    yayasan_timeline_ensure_schema($pdo);
    $task = yayasan_tugas_get($pdo, $id);
    if ($task === null) {
        return ['ok' => false, 'message' => 'Tugas tidak ditemukan.'];
    }
    $category = trim((string) ($data['category'] ?? (string) ($task['category'] ?? 'Yayasan')));
    if (!in_array($category, yayasan_task_categories(), true)) {
        $category = (string) ($task['category'] ?? 'Yayasan');
    }
    if (!yayasan_task_user_can_manage_category($pdo, $userId, $category)) {
        return ['ok' => false, 'message' => 'Anda tidak berhak mengubah tugas kategori ini.'];
    }

    $judul = trim((string) ($data['judul'] ?? (string) ($task['judul'] ?? '')));
    if ($judul === '') {
        return ['ok' => false, 'message' => 'Judul tugas wajib diisi.'];
    }
    $mulai = trim((string) ($data['tanggal_mulai'] ?? (string) ($task['tanggal_mulai'] ?? date('Y-m-d'))));
    $target = trim((string) ($data['tanggal_target'] ?? (string) ($task['tanggal_target'] ?? $mulai)));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
        $mulai = (string) ($task['tanggal_mulai'] ?? date('Y-m-d'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) {
        $target = $mulai;
    }
    $startAt = yayasan_tugas_normalize_datetime(
        (string) ($data['start_at'] ?? ''),
        $mulai,
        (string) ($data['start_time'] ?? '08:00:00')
    );
    $dueAt = yayasan_tugas_normalize_datetime(
        (string) ($data['due_at'] ?? ''),
        $target,
        (string) ($data['due_time'] ?? '17:00:00')
    );
    if (strtotime($dueAt) < strtotime($startAt)) {
        $dueAt = $startAt;
    }
    $assignees = yayasan_tugas_parse_assignee_ids($data);
    if ($assignees['pj'] === [] && $assignees['pembantu'] === [] && $task !== null) {
        $loaded = yayasan_tugas_load_assignees($pdo, $id);
        $assignees = ['pj' => $loaded['pj_ids'], 'pembantu' => $loaded['pembantu_ids']];
    }
    $pjIds = $assignees['pj'];
    $pembantuIds = $assignees['pembantu'];
    $allAssigneeIds = array_values(array_unique(array_merge($pjIds, $pembantuIds)));
    $conflicts = $allAssigneeIds !== []
        ? yayasan_tugas_find_conflicts_multi($pdo, $allAssigneeIds, $startAt, $dueAt, $id)
        : [];
    if ($conflicts !== [] && !$forceConflict) {
        return [
            'ok' => false,
            'conflict' => true,
            'message' => 'Peringatan: Pembimbing bersangkutan memiliki jadwal bentrok!',
            'conflicts' => $conflicts,
        ];
    }
    $progress = array_key_exists('progress', $data)
        ? max(0, min(100, (int) $data['progress']))
        : (int) ($task['progress'] ?? 0);
    $selesai = $task['tanggal_selesai'] ?? null;
    if ($progress >= 100) {
        $selesai = date('Y-m-d');
    }
    $status = yayasan_tugas_compute_status($progress, $target, is_string($selesai) ? $selesai : null);
    $picId = $pjIds !== [] ? (int) $pjIds[0] : null;
    $pjName = null;
    if ($pjIds !== []) {
        $placeholders = implode(',', array_fill(0, count($pjIds), '?'));
        $stNames = $pdo->prepare('
            SELECT COALESCE(NULLIF(TRIM(nama), ""), username) AS nama
            FROM users WHERE id IN (' . $placeholders . ')
            ORDER BY FIELD(id, ' . $placeholders . ')
        ');
        $stNames->execute(array_merge($pjIds, $pjIds));
        $names = [];
        foreach ($stNames->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $names[] = $n;
            }
        }
        $pjName = $names !== [] ? implode(', ', $names) : null;
    }
    if ($pjName === null) {
        $pjName = trim((string) ($data['penanggung_jawab'] ?? (string) ($task['penanggung_jawab'] ?? ''))) ?: null;
    }
    $deskripsi = array_key_exists('deskripsi', $data)
        ? (trim((string) $data['deskripsi']) ?: null)
        : ($task['deskripsi'] ?? null);
    $syncKalender = array_key_exists('sync_kalender', $data)
        ? (!empty($data['sync_kalender']) ? 1 : 0)
        : (int) ($task['sync_kalender'] ?? 1);

    $pdo->prepare('
        UPDATE yayasan_tugas SET
            judul = :judul, deskripsi = :desk, penanggung_jawab = :pj, category = :cat, pic_id = :pic,
            tanggal_mulai = :mulai, tanggal_target = :target, start_at = :start_at, due_at = :due_at,
            progress = :prog, status = :st, tanggal_selesai = :selesai, sync_kalender = :sync
        WHERE id = :id
    ')->execute([
        'judul' => mb_substr($judul, 0, 200),
        'desk' => $deskripsi,
        'pj' => $pjName !== null ? mb_substr($pjName, 0, 120) : null,
        'cat' => $category,
        'pic' => $picId,
        'mulai' => $mulai,
        'target' => $target,
        'start_at' => $startAt,
        'due_at' => $dueAt,
        'prog' => $progress,
        'st' => $status,
        'selesai' => $selesai,
        'sync' => $syncKalender,
        'id' => $id,
    ]);

    yayasan_tugas_save_assignees($pdo, $id, $pjIds, $pembantuIds);

    $row = yayasan_tugas_get($pdo, $id);
    if ($row !== null) {
        $agendaId = yayasan_tugas_sync_agenda($pdo, $row, $userId);
        if ($agendaId !== null && (int) ($row['agenda_id'] ?? 0) !== $agendaId) {
            $pdo->prepare('UPDATE yayasan_tugas SET agenda_id = :aid WHERE id = :id')->execute(['aid' => $agendaId, 'id' => $id]);
        }
    }

    if ($allAssigneeIds !== []) {
        if (!function_exists('yayasan_tugas_wa_notify_assignees')) {
            require_once __DIR__ . '/wa_yayasan_tugas.php';
        }
        yayasan_tugas_wa_notify_assignees($pdo, $id, 'ubah');
    }

    return ['ok' => true, 'id' => $id, 'message' => 'Tugas diperbarui.'];
}

function yayasan_tugas_get(PDO $pdo, int $id): ?array
{
    yayasan_timeline_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT t.*, r.judul AS rapat_judul, r.tanggal_rapat, n.judul AS notulen_judul,
               u.nama AS pic_nama, u.username AS pic_username
        FROM yayasan_tugas t
        LEFT JOIN yayasan_rapat r ON r.id = t.rapat_id
        LEFT JOIN yayasan_notulen n ON n.id = t.notulen_id
        LEFT JOIN users u ON u.id = t.pic_id
        WHERE t.id = :id LIMIT 1
    ');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? yayasan_tugas_enrich_row($pdo, $row) : null;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function yayasan_tugas_enrich_row(PDO $pdo, array $row): array
{
    $row['status'] = yayasan_tugas_compute_status(
        (int) ($row['progress'] ?? 0),
        (string) ($row['tanggal_target'] ?? ''),
        isset($row['tanggal_selesai']) && $row['tanggal_selesai'] !== '' ? (string) $row['tanggal_selesai'] : null
    );
    $row['status_label'] = yayasan_tugas_status_label((string) $row['status']);
    $row['status_badge'] = yayasan_tugas_status_badge((string) $row['status']);
    $row['toggle_status'] = yayasan_tugas_toggle_from_status((string) $row['status']);
    $category = (string) ($row['category'] ?? 'Yayasan');
    $row['category'] = $category;
    $row['category_badge'] = yayasan_tugas_category_badge($category);
    $row['category_color'] = yayasan_tugas_category_color($category);
    $win = yayasan_tugas_task_window($row);
    $row['start_at'] = $win['start'];
    $row['due_at'] = $win['due'];
    $target = (string) ($row['tanggal_target'] ?? '');
    $mulai = (string) ($row['tanggal_mulai'] ?? $target);
    $today = date('Y-m-d');
    $totalDays = max(1, (int) ((strtotime($target) - strtotime($mulai)) / 86400) + 1);
    $elapsed = max(0, (int) ((strtotime(min($today, $target)) - strtotime($mulai)) / 86400));
    $row['timeline_pct'] = min(100, round(100 * $elapsed / $totalDays));

    $taskId = (int) ($row['id'] ?? 0);
    if ($taskId > 0) {
        $assignees = yayasan_tugas_load_assignees($pdo, $taskId);
        $row['pj_list'] = $assignees['pj'];
        $row['pembantu_list'] = $assignees['pembantu'];
        $row['pj_ids'] = $assignees['pj_ids'];
        $row['pembantu_ids'] = $assignees['pembantu_ids'];
        $row['pj_nama'] = yayasan_tugas_format_people_names($assignees['pj']);
        $row['pembantu_nama'] = yayasan_tugas_format_people_names($assignees['pembantu']);
        if ($row['pj_nama'] !== '') {
            $row['pic_nama'] = $row['pj_nama'];
            $row['penanggung_jawab'] = $row['pj_nama'];
        }
    }

    return $row;
}

function yayasan_tugas_update_status_toggle(PDO $pdo, int $id, string $toggle, int $userId, ?array $attachmentFile = null): array
{
    yayasan_timeline_ensure_schema($pdo);
    $task = yayasan_tugas_get($pdo, $id);
    if ($task === null) {
        return ['ok' => false, 'message' => 'Tugas tidak ditemukan.'];
    }
    if (!yayasan_task_user_can_update_status($pdo, $userId, $task)) {
        return ['ok' => false, 'message' => 'Anda tidak berhak memperbarui tugas ini.'];
    }
    $status = yayasan_tugas_status_from_toggle($toggle);
    $progress = yayasan_tugas_progress_from_status($status, (int) ($task['progress'] ?? 0));
    $selesai = ($status === 'SELESAI') ? date('Y-m-d') : ($task['tanggal_selesai'] ?? null);
    $target = (string) ($task['tanggal_target'] ?? '');
    $status = yayasan_tugas_compute_status($progress, $target, is_string($selesai) ? $selesai : null);
    $attachment = (string) ($task['attachment'] ?? '');
    if (is_array($attachmentFile)) {
        $up = yayasan_tugas_handle_attachment_upload($attachmentFile, $attachment !== '' ? $attachment : null);
        if (!$up['ok']) {
            return ['ok' => false, 'message' => (string) ($up['error'] ?? 'Upload gagal.')];
        }
        if (!empty($up['path'])) {
            $attachment = (string) $up['path'];
        }
    }
    $pdo->prepare('
        UPDATE yayasan_tugas
        SET progress = :p, status = :st, tanggal_selesai = :selesai, attachment = :att
        WHERE id = :id
    ')->execute([
        'p' => $progress,
        'st' => $status,
        'selesai' => $selesai,
        'att' => $attachment !== '' ? $attachment : null,
        'id' => $id,
    ]);
    $task['progress'] = $progress;
    $task['status'] = $status;
    $task['attachment'] = $attachment;
    $agendaId = yayasan_tugas_sync_agenda($pdo, $task, $userId);
    if ($agendaId !== null && (int) ($task['agenda_id'] ?? 0) !== $agendaId) {
        $pdo->prepare('UPDATE yayasan_tugas SET agenda_id = :aid WHERE id = :id')->execute(['aid' => $agendaId, 'id' => $id]);
    }

    return ['ok' => true, 'message' => 'Status tugas diperbarui.', 'task' => yayasan_tugas_get($pdo, $id)];
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
    $pdo->prepare('DELETE FROM yayasan_tugas_assignee WHERE tugas_id = :id')->execute(['id' => $id]);
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
        SELECT t.*, r.judul AS rapat_judul, r.tanggal_rapat, n.judul AS notulen_judul,
               u.nama AS pic_nama, u.username AS pic_username
        FROM yayasan_tugas t
        LEFT JOIN yayasan_rapat r ON r.id = t.rapat_id
        LEFT JOIN yayasan_notulen n ON n.id = t.notulen_id
        LEFT JOIN users u ON u.id = t.pic_id
        ' . $where . '
        ORDER BY COALESCE(t.start_at, CONCAT(t.tanggal_mulai, " 08:00:00")) ASC, t.id ASC
    ';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = yayasan_tugas_enrich_row($pdo, $row);
    }
    unset($row);

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_tugas_list_for_pic(PDO $pdo, int $picId, bool $activeOnly = true): array
{
    yayasan_timeline_ensure_schema($pdo);
    if ($picId <= 0) {
        return [];
    }
    $sql = '
        SELECT DISTINCT t.*, u.nama AS pic_nama, u.username AS pic_username
        FROM yayasan_tugas t
        LEFT JOIN users u ON u.id = t.pic_id
        WHERE (
            t.pic_id = :uid
            OR EXISTS (
                SELECT 1 FROM yayasan_tugas_assignee a
                WHERE a.tugas_id = t.id AND a.user_id = :uid2
            )
        )
    ';
    if ($activeOnly) {
        $sql .= ' AND t.status <> "SELESAI"';
    }
    $sql .= ' ORDER BY COALESCE(t.due_at, CONCAT(t.tanggal_target, " 17:00:00")) ASC';
    $st = $pdo->prepare($sql);
    $st->execute(['uid' => $picId, 'uid2' => $picId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = yayasan_tugas_enrich_row($pdo, $row);
    }
    unset($row);

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_tugas_all_conflicts(PDO $pdo): array
{
    yayasan_timeline_ensure_schema($pdo);
    $rows = yayasan_tugas_list($pdo, 'aktif');
    $byUser = [];
    foreach ($rows as $row) {
        $uids = array_values(array_unique(array_merge(
            (array) ($row['pj_ids'] ?? []),
            (array) ($row['pembantu_ids'] ?? []),
            (int) ($row['pic_id'] ?? 0) > 0 ? [(int) $row['pic_id']] : []
        )));
        foreach ($uids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = ['nama' => '', 'tasks' => []];
            }
            $byUser[$uid]['tasks'][(int) ($row['id'] ?? 0)] = $row;
            if ($byUser[$uid]['nama'] === '') {
                foreach ((array) ($row['pj_list'] ?? []) as $p) {
                    if ((int) ($p['id'] ?? 0) === $uid) {
                        $byUser[$uid]['nama'] = (string) ($p['nama'] ?? '');
                        break;
                    }
                }
                if ($byUser[$uid]['nama'] === '') {
                    foreach ((array) ($row['pembantu_list'] ?? []) as $p) {
                        if ((int) ($p['id'] ?? 0) === $uid) {
                            $byUser[$uid]['nama'] = (string) ($p['nama'] ?? '');
                            break;
                        }
                    }
                }
            }
        }
    }

    $conflicts = [];
    $seen = [];
    foreach ($byUser as $uid => $pack) {
        $tasks = array_values($pack['tasks']);
        $count = count($tasks);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $tasks[$i];
                $b = $tasks[$j];
                $idA = (int) ($a['id'] ?? 0);
                $idB = (int) ($b['id'] ?? 0);
                $winA = yayasan_tugas_task_window($a);
                $winB = yayasan_tugas_task_window($b);
                if ($winA['start'] < $winB['due'] && $winA['due'] > $winB['start']) {
                    $key = $uid . ':' . min($idA, $idB) . '-' . max($idA, $idB);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $conflicts[] = [
                            'pic_id' => (int) $uid,
                            'pic_nama' => (string) ($pack['nama'] ?? ''),
                            'task_a' => $a,
                            'task_b' => $b,
                        ];
                    }
                }
            }
        }
    }

    return $conflicts;
}

/**
 * @return list<array{pic_id:int,pic_nama:string,active:int}>
 */
function yayasan_tugas_workload(PDO $pdo): array
{
    yayasan_timeline_ensure_schema($pdo);
    $sql = '
        SELECT a.user_id AS pic_id,
               COALESCE(NULLIF(TRIM(u.nama), ""), u.username, "Tanpa nama") AS pic_nama,
               COUNT(DISTINCT t.id) AS active
        FROM yayasan_tugas t
        INNER JOIN yayasan_tugas_assignee a ON a.tugas_id = t.id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE t.status <> "SELESAI"
        GROUP BY a.user_id, pic_nama
        HAVING active > 0
        ORDER BY active DESC, pic_nama ASC
    ';
    $out = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'pic_id' => (int) ($row['pic_id'] ?? 0),
            'pic_nama' => (string) ($row['pic_nama'] ?? ''),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }
    if ($out !== []) {
        return $out;
    }

    $sqlLegacy = '
        SELECT t.pic_id,
               COALESCE(NULLIF(TRIM(u.nama), ""), u.username, t.penanggung_jawab, "Tanpa PIC") AS pic_nama,
               COUNT(*) AS active
        FROM yayasan_tugas t
        LEFT JOIN users u ON u.id = t.pic_id
        WHERE t.status <> "SELESAI" AND t.pic_id IS NOT NULL AND t.pic_id > 0
        GROUP BY t.pic_id, pic_nama
        HAVING active > 0
        ORDER BY active DESC, pic_nama ASC
    ';
    foreach ($pdo->query($sqlLegacy)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'pic_id' => (int) ($row['pic_id'] ?? 0),
            'pic_nama' => (string) ($row['pic_nama'] ?? ''),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{range_start:string,range_end:string,items:list<array<string,mixed>>}
 */
function yayasan_tugas_gantt_pack(array $rows): array
{
    if ($rows === []) {
        $today = date('Y-m-d');

        return ['range_start' => $today, 'range_end' => date('Y-m-d', strtotime($today . ' +30 days')), 'items' => []];
    }
    $minTs = PHP_INT_MAX;
    $maxTs = 0;
    $items = [];
    foreach ($rows as $row) {
        $win = yayasan_tugas_task_window($row);
        $s = strtotime($win['start']) ?: time();
        $e = strtotime($win['due']) ?: $s;
        $minTs = min($minTs, $s);
        $maxTs = max($maxTs, $e);
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['judul'] ?? ''),
            'category' => (string) ($row['category'] ?? 'Yayasan'),
            'color' => yayasan_tugas_category_color((string) ($row['category'] ?? 'Yayasan')),
            'start' => date('Y-m-d', $s),
            'end' => date('Y-m-d', $e),
            'start_at' => $win['start'],
            'due_at' => $win['due'],
            'status' => (string) ($row['status'] ?? ''),
            'status_label' => (string) ($row['status_label'] ?? ''),
            'progress' => (int) ($row['progress'] ?? 0),
            'pic_nama' => trim(
                (string) ($row['pj_nama'] ?? $row['pic_nama'] ?? $row['penanggung_jawab'] ?? '')
                . ((string) ($row['pembantu_nama'] ?? '') !== '' ? ' · Pembantu: ' . (string) $row['pembantu_nama'] : '')
            ),
        ];
    }
    $pad = 86400 * 3;

    return [
        'range_start' => date('Y-m-d', $minTs - $pad),
        'range_end' => date('Y-m-d', $maxTs + $pad),
        'items' => $items,
    ];
}

function yayasan_tugas_ical_escape(string $value): string
{
    return str_replace(["\r\n", "\n", "\r", ',', ';'], ['\\n', '\\n', '\\n', '\\,', '\\;'], $value);
}

function yayasan_tugas_ical_datetime(string $dt): string
{
    $ts = strtotime($dt);

    return $ts !== false ? gmdate('Ymd\THis\Z', $ts) : gmdate('Ymd\THis\Z');
}

/**
 * @param list<array<string, mixed>> $rows
 */
function yayasan_tugas_build_ics(PDO $pdo, array $rows, string $calendarName = 'Tugas Pesantren'): string
{
    require_once __DIR__ . '/app_path.php';
    $host = parse_url(app_public_url(), PHP_URL_HOST) ?: 'pesantren.local';
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//PWA Nailul Muna//Timeline Tugas//ID',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . yayasan_tugas_ical_escape($calendarName),
        'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        'X-PUBLISHED-TTL:PT1H',
    ];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $win = yayasan_tugas_task_window($row);
        $category = (string) ($row['category'] ?? 'Yayasan');
        $color = yayasan_tugas_category_color($category);
        $title = (string) ($row['judul'] ?? 'Tugas');
        $desc = trim((string) ($row['deskripsi'] ?? ''));
        $status = yayasan_tugas_status_label((string) ($row['status'] ?? ''));
        if ($desc !== '') {
            $desc .= '\\n';
        }
        $desc .= 'Status: ' . $status;
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:yayasan-tugas-' . $id . '@' . $host;
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . yayasan_tugas_ical_datetime($win['start']);
        $lines[] = 'DTEND:' . yayasan_tugas_ical_datetime($win['due']);
        $lines[] = 'SUMMARY:' . yayasan_tugas_ical_escape($title);
        $lines[] = 'DESCRIPTION:' . yayasan_tugas_ical_escape($desc);
        $lines[] = 'CATEGORIES:' . yayasan_tugas_ical_escape($category);
        $lines[] = 'COLOR:' . $color;
        $lines[] = 'STATUS:' . (strtoupper((string) ($row['status'] ?? '')) === 'SELESAI' ? 'CONFIRMED' : 'TENTATIVE');
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
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
