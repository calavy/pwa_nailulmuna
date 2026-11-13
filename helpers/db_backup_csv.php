<?php

declare(strict_types=1);

/**
 * Unduhan ZIP: satu CSV per tabel database.
 * Bukan dump SQL. Kolom dan baris rahasia dikosongkan di file, bukan di database.
 */

function db_backup_csv_column_is_secret(string $column): bool
{
    $name = strtolower($column);
    foreach (['password', 'pin_hash', 'token', 'secret', 'api_key', 'private_key', 'server_key'] as $needle) {
        if (str_contains($name, $needle)) {
            return true;
        }
    }

    return false;
}

function db_backup_csv_setting_key_is_secret(string $key): bool
{
    $name = strtolower($key);
    foreach (['password', 'token', 'secret', 'api_key', 'server_key', 'cron_key', 'private_key'] as $needle) {
        if (str_contains($name, $needle)) {
            return true;
        }
    }

    return false;
}

function db_backup_csv_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nama tabel atau kolom tidak aman: ' . $name);
    }

    return '`' . $name . '`';
}

function db_backup_csv_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return (string) $value;
}

/**
 * @param resource $fh
 * @param list<string> $fields
 */
function db_backup_csv_put($fh, array $fields): void
{
    $ok = PHP_VERSION_ID >= 80100
        ? fputcsv($fh, $fields, ';', '"', '"')
        : fputcsv($fh, $fields, ';');
    if ($ok === false) {
        throw new RuntimeException('Gagal menulis CSV.');
    }
}

function db_backup_csv_cleanup(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @return list<string>
 */
function db_backup_csv_tables(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );
    if ($stmt === false) {
        throw new RuntimeException('Gagal membaca daftar tabel.');
    }
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = (string) $name;
        if ($name !== '') {
            $tables[] = $name;
        }
    }
    $stmt->closeCursor();

    return $tables;
}

/**
 * @return array<string, list<string>>
 */
function db_backup_csv_columns_by_table(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    if ($stmt === false) {
        throw new RuntimeException('Gagal membaca daftar kolom.');
    }
    $byTable = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        $column = (string) ($row['COLUMN_NAME'] ?? '');
        if ($table === '' || $column === '') {
            continue;
        }
        $byTable[$table][] = $column;
    }
    $stmt->closeCursor();

    return $byTable;
}

/**
 * @param list<string> $columns
 * @return array{table:string, entry:string, file:string, rows:int, redacted:string}
 */
function db_backup_csv_write_table(PDO $pdo, string $dir, string $table, array $columns, bool $buffered, bool $redact = true): array
{
    if ($columns === []) {
        throw new RuntimeException('Tabel tanpa kolom: ' . $table);
    }

    $entry = $table . '.csv';
    if ($entry === '_manifest.csv') {
        $entry = '_manifest_tabel.csv';
    }
    $path = $dir . DIRECTORY_SEPARATOR . $entry;
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Gagal membuat CSV untuk tabel ' . $table . '.');
    }

    $secretIdx = [];
    $secretCols = [];
    foreach ($columns as $i => $column) {
        if ($redact && db_backup_csv_column_is_secret($column)) {
            $secretIdx[$i] = true;
            $secretCols[] = $column;
        }
    }

    $keyIdx = null;
    $valueIdx = null;
    if (strtolower($table) === 'app_settings') {
        foreach ($columns as $i => $column) {
            $lc = strtolower($column);
            if ($lc === 'setting_key') {
                $keyIdx = $i;
            } elseif ($lc === 'setting_value') {
                $valueIdx = $i;
            }
        }
    }

    db_backup_csv_put($fh, $columns);

    $select = implode(', ', array_map('db_backup_csv_ident', $columns));
    $sql = 'SELECT ' . $select . ' FROM ' . db_backup_csv_ident($table);
    $stmt = null;
    $count = 0;
    $redactedSetting = false;
    try {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Gagal membaca tabel ' . $table . '.');
        }
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $line = [];
            foreach ($columns as $i => $_column) {
                if (isset($secretIdx[$i])) {
                    $line[] = '';
                    continue;
                }
                if ($redact && $keyIdx !== null && $valueIdx === $i) {
                    $key = (string) ($row[$keyIdx] ?? '');
                    if (db_backup_csv_setting_key_is_secret($key)) {
                        $line[] = '';
                        $redactedSetting = true;
                        continue;
                    }
                }
                $line[] = db_backup_csv_cell($row[$i] ?? null);
            }
            db_backup_csv_put($fh, $line);
            $count++;
        }
    } finally {
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
        fclose($fh);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    }

    if ($redactedSetting) {
        $secretCols[] = 'setting_value (baris kunci rahasia)';
    }

    return [
        'table' => $table,
        'entry' => $entry,
        'file' => $path,
        'rows' => $count,
        'redacted' => implode(', ', $secretCols),
    ];
}

/**
 * @return array{dir:string, zip:string, table_count:int, row_count:int}
 */
function db_backup_csv_build(PDO $pdo, bool $redact = true): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi ZipArchive tidak tersedia di PHP.');
    }

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pwa_csv_backup_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat folder sementara.');
    }

    $buffered = true;
    try {
        $buffered = (bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    } catch (Throwable $e) {
        $buffered = true;
    }

    try {
        $tables = db_backup_csv_tables($pdo);
        $columnsByTable = db_backup_csv_columns_by_table($pdo);
        $written = [];
        $rowCount = 0;
        foreach ($tables as $table) {
            $columns = $columnsByTable[$table] ?? [];
            $item = db_backup_csv_write_table($pdo, $dir, $table, $columns, $buffered, $redact);
            $written[] = $item;
            $rowCount += $item['rows'];
        }

        $manifestPath = $dir . DIRECTORY_SEPARATOR . '_manifest.csv';
        $mf = fopen($manifestPath, 'wb');
        if ($mf === false) {
            throw new RuntimeException('Gagal membuat daftar isi backup.');
        }
        try {
            fwrite($mf, "\xEF\xBB\xBF");
            db_backup_csv_put($mf, ['tabel', 'jumlah_baris', 'kolom_dikosongkan']);
            foreach ($written as $item) {
                db_backup_csv_put($mf, [$item['table'], (string) $item['rows'], $item['redacted']]);
            }
        } finally {
            fclose($mf);
        }

        $zipPath = $dir . DIRECTORY_SEPARATOR . 'backup.zip';
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Gagal membuat file ZIP.');
        }
        $zip->addFile($manifestPath, '_manifest.csv');
        foreach ($written as $item) {
            $zip->addFile($item['file'], $item['entry']);
        }
        $zip->close();

        return [
            'dir' => $dir,
            'zip' => $zipPath,
            'table_count' => count($written),
            'row_count' => $rowCount,
        ];
    } catch (Throwable $e) {
        try {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        } catch (Throwable $ignored) {
        }
        db_backup_csv_cleanup($dir);
        throw $e;
    }
}

function db_backup_csv_send(PDO $pdo): void
{
    @set_time_limit(0);
    $built = db_backup_csv_build($pdo);
    $filename = 'Backup_Database_' . date('Y-m-d_His') . '.zip';
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($built['zip']));
        header('Cache-Control: no-store');
        $fp = fopen($built['zip'], 'rb');
        if ($fp === false) {
            throw new RuntimeException('File ZIP tidak bisa dibaca.');
        }
        fpassthru($fp);
        fclose($fp);
    } finally {
        db_backup_csv_cleanup($built['dir']);
    }
    exit;
}
