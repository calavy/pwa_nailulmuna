<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

const USER_CATATAN_MAX_ROWS = 500;
const USER_CATATAN_MAX_COLS = 50;
const USER_CATATAN_MAX_JSON_BYTES = 524288;
const USER_CATATAN_JUDUL_MAX = 120;
const USER_CATATAN_IMPORT_MAX_BYTES = 2097152;

function user_catatan_ensure_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'users')) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS user_catatan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            judul VARCHAR(120) NOT NULL,
            tipe VARCHAR(20) NOT NULL DEFAULT "internal",
            sheet_url VARCHAR(500) NULL,
            grid_json LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_catatan_user_updated (user_id, updated_at),
            CONSTRAINT fk_user_catatan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    if (!column_exists($pdo, 'user_catatan', 'tipe')) {
        try {
            $pdo->exec('ALTER TABLE user_catatan ADD COLUMN tipe VARCHAR(20) NOT NULL DEFAULT "internal" AFTER judul');
        } catch (PDOException $e) {
            /* abaikan jika sudah ada */
        }
    }
    if (!column_exists($pdo, 'user_catatan', 'sheet_url')) {
        try {
            $pdo->exec('ALTER TABLE user_catatan ADD COLUMN sheet_url VARCHAR(500) NULL AFTER tipe');
        } catch (PDOException $e) {
            /* abaikan */
        }
    }
}

/** @return list<list<string>> */
function user_catatan_default_grid(): array
{
    $header = ['Tanggal', 'Uraian', 'Catatan'];
    $grid = [$header];
    for ($i = 0; $i < 11; $i++) {
        $grid[] = ['', '', ''];
    }

    return $grid;
}

/** @return 'internal'|'shared' */
function user_catatan_normalize_tipe(?string $tipe): string
{
    $tipe = strtolower(trim((string) $tipe));
    if (in_array($tipe, ['shared', 'google', 'google_sheets', 'sheets', 'external'], true)) {
        return 'shared';
    }

    return 'internal';
}

function user_catatan_is_shared(array $row): bool
{
    return user_catatan_normalize_tipe((string) ($row['tipe'] ?? 'internal')) === 'shared';
}

function user_catatan_sanitize_sheet_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    return mb_substr($url, 0, 500);
}

/** URL untuk membuka spreadsheet di tab baru. */
function user_catatan_shared_open_url(string $url): string
{
    $url = user_catatan_sanitize_sheet_url($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/edit?usp=sharing';
    }

    return $url;
}

/** URL embed (Google Sheets). Null jika tidak didukung embed. */
function user_catatan_shared_embed_url(string $url): ?string
{
    $url = user_catatan_sanitize_sheet_url($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/edit?usp=sharing&rm=minimal&widget=true&headers=false';
    }
    if (preg_match('#docs\.google\.com/spreadsheets/d/e/([a-zA-Z0-9-_]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/e/' . $m[1] . '/pubhtml?widget=true&headers=false';
    }

    return null;
}

function user_catatan_tipe_label(string $tipe): string
{
    return user_catatan_normalize_tipe($tipe) === 'shared'
        ? 'Spreadsheet dibagikan'
        : 'Catatan internal';
}

function user_catatan_col_letter_to_index(string $letter): int
{
    $letter = strtoupper(trim($letter));
    if ($letter === '' || !preg_match('/^[A-Z]+$/', $letter)) {
        return 0;
    }
    $n = 0;
    $len = strlen($letter);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letter[$i]) - 64);
    }

    return max(0, $n - 1);
}

function user_catatan_sanitize_judul(string $judul): string
{
    $judul = trim(preg_replace('/\s+/u', ' ', $judul) ?? '');
    if ($judul === '') {
        return 'Catatan tanpa judul';
    }

    return mb_substr($judul, 0, USER_CATATAN_JUDUL_MAX);
}

/**
 * @param mixed $grid
 * @return list<list<string>>
 */
function user_catatan_normalize_grid(mixed $grid): array
{
    if (!is_array($grid)) {
        return user_catatan_default_grid();
    }

    $out = [];
    foreach ($grid as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = [];
        foreach ($row as $cell) {
            if (is_int($cell) || is_float($cell)) {
                $line[] = (string) $cell;
            } else {
                $line[] = trim((string) $cell);
            }
        }
        $out[] = $line;
    }

    if ($out === []) {
        return user_catatan_default_grid();
    }

    $maxCols = 0;
    foreach ($out as $line) {
        $maxCols = max($maxCols, count($line));
    }
    $maxCols = min(max(1, $maxCols), USER_CATATAN_MAX_COLS);

    $normalized = [];
    foreach ($out as $line) {
        while (count($line) < $maxCols) {
            $line[] = '';
        }
        $normalized[] = array_slice($line, 0, $maxCols);
    }

    if (count($normalized) > USER_CATATAN_MAX_ROWS) {
        $normalized = array_slice($normalized, 0, USER_CATATAN_MAX_ROWS);
    }

    return $normalized;
}

/** @return list<list<string>> */
function user_catatan_decode_grid_json(string $json): array
{
    if (trim($json) === '') {
        return user_catatan_default_grid();
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return user_catatan_default_grid();
    }

    return user_catatan_normalize_grid($decoded);
}

/**
 * @param list<list<string>> $grid
 */
function user_catatan_encode_grid_json(array $grid): string
{
    $grid = user_catatan_normalize_grid($grid);
    $json = json_encode($grid, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Grid tidak bisa diserialisasi.');
    }
    if (strlen($json) > USER_CATATAN_MAX_JSON_BYTES) {
        throw new RuntimeException('Ukuran catatan melebihi batas (' . (int) (USER_CATATAN_MAX_JSON_BYTES / 1024) . ' KB).');
    }

    return $json;
}

/**
 * @return list<array<string, mixed>>
 */
function user_catatan_list(PDO $pdo, int $userId): array
{
    user_catatan_ensure_schema($pdo);
    if ($userId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT id, judul, tipe, sheet_url, created_at, updated_at
        FROM user_catatan
        WHERE user_id = :uid
        ORDER BY updated_at DESC, id DESC
    ');
    $st->execute(['uid' => $userId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return null|array<string, mixed>
 */
function user_catatan_get(PDO $pdo, int $id, int $userId): ?array
{
    user_catatan_ensure_schema($pdo);
    if ($id <= 0 || $userId <= 0) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT id, user_id, judul, tipe, sheet_url, grid_json, created_at, updated_at
        FROM user_catatan
        WHERE id = :id AND user_id = :uid
        LIMIT 1
    ');
    $st->execute(['id' => $id, 'uid' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function user_catatan_create(PDO $pdo, int $userId, string $judul, string $tipe = 'internal', string $sheetUrl = ''): int
{
    user_catatan_ensure_schema($pdo);
    if ($userId <= 0) {
        throw new RuntimeException('User tidak valid.');
    }
    $judul = user_catatan_sanitize_judul($judul);
    $tipeNorm = user_catatan_normalize_tipe($tipe);
    $sheetUrl = user_catatan_sanitize_sheet_url($sheetUrl);
    if ($tipeNorm === 'shared' && $sheetUrl === '') {
        throw new RuntimeException('Untuk spreadsheet dibagikan, isi link Google Sheets terlebih dahulu.');
    }
    $gridJson = user_catatan_encode_grid_json(user_catatan_default_grid());
    $st = $pdo->prepare('
        INSERT INTO user_catatan (user_id, judul, tipe, sheet_url, grid_json)
        VALUES (:uid, :judul, :tipe, :sheet_url, :grid)
    ');
    $st->execute([
        'uid' => $userId,
        'judul' => $judul,
        'tipe' => $tipeNorm,
        'sheet_url' => $sheetUrl !== '' ? $sheetUrl : null,
        'grid' => $gridJson,
    ]);

    return (int) $pdo->lastInsertId();
}

function user_catatan_update_judul(PDO $pdo, int $id, int $userId, string $judul): bool
{
    if (user_catatan_get($pdo, $id, $userId) === null) {
        return false;
    }
    $st = $pdo->prepare('
        UPDATE user_catatan
        SET judul = :judul
        WHERE id = :id AND user_id = :uid
        LIMIT 1
    ');
    $st->execute([
        'judul' => user_catatan_sanitize_judul($judul),
        'id' => $id,
        'uid' => $userId,
    ]);

    return $st->rowCount() > 0;
}

function user_catatan_update_sheet_url(PDO $pdo, int $id, int $userId, string $sheetUrl): bool
{
    $row = user_catatan_get($pdo, $id, $userId);
    if ($row === null || !user_catatan_is_shared($row)) {
        return false;
    }
    $sheetUrl = user_catatan_sanitize_sheet_url($sheetUrl);
    if ($sheetUrl === '') {
        throw new RuntimeException('Link spreadsheet tidak valid.');
    }
    $st = $pdo->prepare('
        UPDATE user_catatan
        SET sheet_url = :url
        WHERE id = :id AND user_id = :uid
        LIMIT 1
    ');
    $st->execute([
        'url' => $sheetUrl,
        'id' => $id,
        'uid' => $userId,
    ]);

    return true;
}

/**
 * @param list<list<string>> $grid
 */
function user_catatan_save_grid(PDO $pdo, int $id, int $userId, array $grid): bool
{
    $row = user_catatan_get($pdo, $id, $userId);
    if ($row === null || user_catatan_is_shared($row)) {
        return false;
    }
    $gridJson = user_catatan_encode_grid_json($grid);
    $st = $pdo->prepare('
        UPDATE user_catatan
        SET grid_json = :grid
        WHERE id = :id AND user_id = :uid
        LIMIT 1
    ');
    $st->execute([
        'grid' => $gridJson,
        'id' => $id,
        'uid' => $userId,
    ]);

    return true;
}

function user_catatan_delete(PDO $pdo, int $id, int $userId): bool
{
    $st = $pdo->prepare('DELETE FROM user_catatan WHERE id = :id AND user_id = :uid LIMIT 1');
    $st->execute(['id' => $id, 'uid' => $userId]);

    return $st->rowCount() > 0;
}

/**
 * @param list<list<string>> $grid
 * @return list<list<string>>
 */
function user_catatan_grid_to_xlsx_rows(array $grid): array
{
    return user_catatan_normalize_grid($grid);
}

/**
 * @param list<array<string, string>> $rows hasil parse_xlsx_rows()
 * @return list<list<string>>
 */
function user_catatan_xlsx_rows_to_grid(array $rows): array
{
    if ($rows === []) {
        return user_catatan_default_grid();
    }

    $colLetters = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (array_keys($row) as $col) {
            $colLetters[(string) $col] = true;
        }
    }
    if ($colLetters === []) {
        return user_catatan_default_grid();
    }

    $cols = array_keys($colLetters);
    usort($cols, static function (string $a, string $b): int {
        return user_catatan_col_letter_to_index($a) <=> user_catatan_col_letter_to_index($b);
    });

    $grid = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = [];
        foreach ($cols as $col) {
            $line[] = trim((string) ($row[$col] ?? ''));
        }
        $grid[] = $line;
    }

    return user_catatan_normalize_grid($grid);
}

/** @return list<list<string>> */
function user_catatan_grid_from_row(array $row): array
{
    return user_catatan_decode_grid_json((string) ($row['grid_json'] ?? ''));
}

function user_catatan_safe_filename(string $judul, int $id): string
{
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $judul) ?? 'catatan';
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'catatan';
    }

    return mb_substr($slug, 0, 60) . '_' . $id . '.xlsx';
}
