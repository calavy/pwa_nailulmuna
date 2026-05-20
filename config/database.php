<?php

/**
 * Lokal (XAMPP): biarkan tanpa database.local.php → pakai default di bawah.
 * Hosting (InfinityFree, dll.): buat config/database.local.php
 * (salin dari database.local.example.php) dengan host/user/pass dari panel hosting.
 *
 * Prioritas: getenv DB_* → database.local.php → default XAMPP.
 */
$host = '127.0.0.1';
$port = '3306';
$dbName = 'pwa_nailulmuna';
$dbUser = 'root';
$dbPass = '';

$envHost = getenv('DB_HOST');
if ($envHost !== false && trim((string) $envHost) !== '') {
    $host = trim((string) $envHost);
}
$envPort = getenv('DB_PORT');
if ($envPort !== false && trim((string) $envPort) !== '') {
    $port = trim((string) $envPort);
}
$envName = getenv('DB_NAME');
if ($envName !== false && trim((string) $envName) !== '') {
    $dbName = trim((string) $envName);
}
$envUser = getenv('DB_USER');
if ($envUser !== false && trim((string) $envUser) !== '') {
    $dbUser = trim((string) $envUser);
}
$envPass = getenv('DB_PASS');
if ($envPass !== false) {
    $dbPass = (string) $envPass;
}

$localFile = __DIR__ . '/database.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        if (!empty($local['host'])) {
            $host = (string) $local['host'];
        }
        if (isset($local['port']) && (string) $local['port'] !== '') {
            $port = (string) $local['port'];
        }
        if (!empty($local['dbname'])) {
            $dbName = (string) $local['dbname'];
        }
        if (!empty($local['user'])) {
            $dbUser = (string) $local['user'];
        }
        if (array_key_exists('pass', $local)) {
            $dbPass = (string) $local['pass'];
        }
    }
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $GLOBALS['pondok_pdo'] = $pdo;
} catch (PDOException $exception) {
    die('Koneksi database gagal: ' . $exception->getMessage());
}

/** PDO terpusat — aman dipanggil dari fungsi/layout (require_once tidak mengisi $pdo lokal). */
function pondok_pdo(): PDO
{
    if (isset($GLOBALS['pondok_pdo']) && $GLOBALS['pondok_pdo'] instanceof PDO) {
        return $GLOBALS['pondok_pdo'];
    }
    require_once __DIR__ . '/database.php';
    if (isset($GLOBALS['pondok_pdo']) && $GLOBALS['pondok_pdo'] instanceof PDO) {
        return $GLOBALS['pondok_pdo'];
    }

    throw new RuntimeException('Koneksi database tidak tersedia.');
}

// Samakan jadwal/presensi/tanggal PHP dengan operasional pondok (cron WA memakai Asia/Jakarta).
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Jakarta');
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $statement = $pdo->prepare('SHOW TABLES LIKE :table_name');
    $statement->execute(['table_name' => $tableName]);

    return (bool) $statement->fetchColumn();
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $statement = $pdo->prepare('SHOW COLUMNS FROM `' . $tableName . '` LIKE :column_name');
    $statement->execute(['column_name' => $columnName]);
    return (bool) $statement->fetchColumn();
}

function ensure_santri_compat_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'santri')) {
        return;
    }
    $done = true;

    $addColumnSafe = static function (string $sqlIfNotExists, string $sqlFallback) use ($pdo): void {
        try {
            $pdo->exec($sqlIfNotExists);
        } catch (PDOException $e) {
            try {
                $pdo->exec($sqlFallback);
            } catch (PDOException $e2) {
                $msg = strtolower($e2->getMessage());
                if (str_contains($msg, 'duplicate column') || str_contains($msg, '1060')) {
                    return;
                }
                throw $e2;
            }
        }
    };

    $addColumnSafe(
        'ALTER TABLE santri ADD COLUMN IF NOT EXISTS nama_santri VARCHAR(100) NULL',
        'ALTER TABLE santri ADD COLUMN nama_santri VARCHAR(100) NULL'
    );
    $addColumnSafe(
        'ALTER TABLE santri ADD COLUMN IF NOT EXISTS tingkatan VARCHAR(80) NULL',
        'ALTER TABLE santri ADD COLUMN tingkatan VARCHAR(80) NULL'
    );
    $addColumnSafe(
        'ALTER TABLE santri ADD COLUMN IF NOT EXISTS qr VARCHAR(120) NULL',
        'ALTER TABLE santri ADD COLUMN qr VARCHAR(120) NULL'
    );

    if (column_exists($pdo, 'santri', 'nama') && column_exists($pdo, 'santri', 'nama_santri')) {
        $pdo->exec("UPDATE santri SET nama_santri = nama WHERE (nama_santri IS NULL OR nama_santri = '') AND nama IS NOT NULL AND nama <> ''");
    }
    if (column_exists($pdo, 'santri', 'kelas_id') && column_exists($pdo, 'santri', 'tingkatan') && table_exists($pdo, 'kelas')) {
        $pdo->exec("
            UPDATE santri s
            LEFT JOIN kelas k ON k.id = s.kelas_id
            SET s.tingkatan = COALESCE(k.nama_kelas, s.tingkatan)
            WHERE (s.tingkatan IS NULL OR s.tingkatan = '')
        ");
    }
}

ensure_santri_compat_schema($pdo);

require_once __DIR__ . '/../helpers/wali.php';
ensure_wali_santri_table($pdo);

require_once __DIR__ . '/../helpers/surat_nomor.php';
ensure_surat_nomor_schema($pdo);
