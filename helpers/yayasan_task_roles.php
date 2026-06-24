<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** @return list<string> */
function yayasan_task_role_tags(): array
{
    return ['Akademik', 'Asrama'];
}

/** @return list<string> */
function yayasan_task_categories(): array
{
    return ['Akademik', 'Asrama', 'Yayasan'];
}

/** @return list<string> */
function yayasan_task_access_levels(): array
{
    return ['yayasan', 'kepala_divisi', 'pembimbing'];
}

function yayasan_task_roles_ensure_schema(PDO $pdo): void
{
    if (!function_exists('table_exists') || !table_exists($pdo, 'users')) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN timeline_access ENUM('yayasan','kepala_divisi','pembimbing') NULL DEFAULT NULL");
    } catch (PDOException $e) {
        // kolom sudah ada
    }
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN calendar_sync_token VARCHAR(64) NULL DEFAULT NULL');
    } catch (PDOException $e) {
        // kolom sudah ada
    }
    try {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uk_users_calendar_sync_token (calendar_sync_token)');
    } catch (PDOException $e) {
        // index sudah ada
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS user_task_role_tags (
            user_id INT NOT NULL,
            tag ENUM("Akademik","Asrama") NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, tag),
            INDEX idx_utrt_tag (tag)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

function yayasan_task_access_label(string $level): string
{
    return match ($level) {
        'yayasan' => 'Yayasan (Super Admin)',
        'kepala_divisi' => 'Kepala Divisi / Pengasuhan',
        'pembimbing' => 'Pembimbing (Executor)',
        default => $level,
    };
}

/**
 * Tentukan level akses timeline pengguna saat ini.
 */
function yayasan_task_user_access(PDO $pdo, int $userId, ?string $role = null): string
{
    yayasan_task_roles_ensure_schema($pdo);
    if ($userId <= 0) {
        return 'pembimbing';
    }
    if (function_exists('is_super_admin') && is_super_admin()) {
        return 'yayasan';
    }
    $role = strtolower(trim((string) ($role ?? ($_SESSION['user']['role'] ?? ''))));
    if ($role === 'admin') {
        return 'yayasan';
    }

    $st = $pdo->prepare('SELECT timeline_access FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $stored = trim((string) ($st->fetchColumn() ?: ''));
    if ($stored !== '' && in_array($stored, yayasan_task_access_levels(), true)) {
        return $stored;
    }

    if ($role === 'pembimbing') {
        return 'pembimbing';
    }
    if (in_array($role, ['pengurus', 'kiai'], true)) {
        return 'kepala_divisi';
    }

    return 'pembimbing';
}

function yayasan_task_user_can_manage_all(PDO $pdo, int $userId, ?string $role = null): bool
{
    return yayasan_task_user_access($pdo, $userId, $role) === 'yayasan';
}

/**
 * @return list<string>
 */
function yayasan_task_user_tags(PDO $pdo, int $userId): array
{
    yayasan_task_roles_ensure_schema($pdo);
    if ($userId <= 0) {
        return [];
    }
    $st = $pdo->prepare('SELECT tag FROM user_task_role_tags WHERE user_id = :id ORDER BY tag ASC');
    $st->execute(['id' => $userId]);
    $tags = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '' && in_array($tag, yayasan_task_role_tags(), true)) {
            $tags[] = $tag;
        }
    }

    return $tags;
}

function yayasan_task_user_can_manage_category(PDO $pdo, int $userId, string $category, ?string $role = null): bool
{
    $access = yayasan_task_user_access($pdo, $userId, $role);
    if ($access === 'yayasan') {
        return true;
    }
    if ($access !== 'kepala_divisi') {
        return false;
    }
    $category = trim($category);
    if ($category === 'Yayasan') {
        return false;
    }

    return in_array($category, yayasan_task_user_tags($pdo, $userId), true);
}

function yayasan_task_user_can_edit_task(PDO $pdo, int $userId, array $task, ?string $role = null): bool
{
    $access = yayasan_task_user_access($pdo, $userId, $role);
    if ($access === 'yayasan') {
        return true;
    }
    if ($access === 'kepala_divisi') {
        return yayasan_task_user_can_manage_category($pdo, $userId, (string) ($task['category'] ?? 'Yayasan'), $role);
    }

    if (!function_exists('yayasan_tugas_user_is_assignee')) {
        return (int) ($task['pic_id'] ?? 0) === $userId;
    }

    return yayasan_tugas_user_is_assignee($task, $userId);
}

function yayasan_task_user_can_update_status(PDO $pdo, int $userId, array $task, ?string $role = null): bool
{
    if (yayasan_task_user_can_edit_task($pdo, $userId, $task, $role)) {
        return true;
    }
    $access = yayasan_task_user_access($pdo, $userId, $role);
    if ($access === 'pembimbing') {
        if (function_exists('yayasan_tugas_user_is_assignee')) {
            return yayasan_tugas_user_is_assignee($task, $userId);
        }

        return (int) ($task['pic_id'] ?? 0) === $userId;
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function yayasan_task_pic_users(PDO $pdo): array
{
    yayasan_task_roles_ensure_schema($pdo);
    if (!table_exists($pdo, 'pembimbing')) {
        $sql = '
            SELECT u.id, u.username, u.nama, u.role
            FROM users u
            WHERE u.role IN ("pembimbing","pengurus","admin")
            ORDER BY u.nama ASC, u.username ASC
        ';
    } else {
        $sql = '
            SELECT u.id, u.username,
                   COALESCE(NULLIF(TRIM(u.nama), ""), p.nama_pembimbing, u.username) AS nama,
                   u.role, p.id AS pembimbing_id
            FROM users u
            LEFT JOIN pembimbing p ON TRIM(p.nip) = TRIM(u.username)
            WHERE u.role IN ("pembimbing","pengurus","admin","kiai")
               OR p.id IS NOT NULL
            ORDER BY nama ASC, u.username ASC
        ';
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function yayasan_task_set_user_tags(PDO $pdo, int $userId, array $tags): void
{
    yayasan_task_roles_ensure_schema($pdo);
    if ($userId <= 0) {
        return;
    }
    $valid = [];
    foreach ($tags as $tag) {
        $tag = trim((string) $tag);
        if (in_array($tag, yayasan_task_role_tags(), true)) {
            $valid[$tag] = true;
        }
    }
    $pdo->prepare('DELETE FROM user_task_role_tags WHERE user_id = :id')->execute(['id' => $userId]);
    $st = $pdo->prepare('INSERT INTO user_task_role_tags (user_id, tag) VALUES (:uid, :tag)');
    foreach (array_keys($valid) as $tag) {
        $st->execute(['uid' => $userId, 'tag' => $tag]);
    }
}

function yayasan_task_set_user_access(PDO $pdo, int $userId, ?string $access): void
{
    yayasan_task_roles_ensure_schema($pdo);
    if ($userId <= 0) {
        return;
    }
    $access = trim((string) $access);
    if ($access === '' || !in_array($access, yayasan_task_access_levels(), true)) {
        $pdo->prepare('UPDATE users SET timeline_access = NULL WHERE id = :id')->execute(['id' => $userId]);

        return;
    }
    $pdo->prepare('UPDATE users SET timeline_access = :acc WHERE id = :id')->execute(['acc' => $access, 'id' => $userId]);
}

function yayasan_task_calendar_token(PDO $pdo, int $userId): string
{
    yayasan_task_roles_ensure_schema($pdo);
    if ($userId <= 0) {
        return '';
    }
    $st = $pdo->prepare('SELECT calendar_sync_token FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $token = trim((string) ($st->fetchColumn() ?: ''));
    if ($token !== '') {
        return $token;
    }
    do {
        $token = bin2hex(random_bytes(24));
        $chk = $pdo->prepare('SELECT id FROM users WHERE calendar_sync_token = :t LIMIT 1');
        $chk->execute(['t' => $token]);
        $exists = (int) ($chk->fetchColumn() ?: 0) > 0;
    } while ($exists);
    $pdo->prepare('UPDATE users SET calendar_sync_token = :t WHERE id = :id')->execute(['t' => $token, 'id' => $userId]);

    return $token;
}

function yayasan_task_calendar_sync_url(PDO $pdo, int $userId): string
{
    $token = yayasan_task_calendar_token($pdo, $userId);
    if ($token === '') {
        return '';
    }
    require_once __DIR__ . '/app_path.php';

    return rtrim(app_public_url(), '/') . app_href('/api/calendar/sync.php?token=' . rawurlencode($token));
}

function yayasan_task_user_by_calendar_token(PDO $pdo, string $token): ?array
{
    yayasan_task_roles_ensure_schema($pdo);
    $token = trim($token);
    if ($token === '' || strlen($token) < 16) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, username, nama, role FROM users WHERE calendar_sync_token = :t LIMIT 1');
    $st->execute(['t' => $token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}
