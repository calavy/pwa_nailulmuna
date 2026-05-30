<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** @return list<array{id:int,kode:string,nama:string,is_aktif:int}> */
function cashless_koperasi_definitions(): array
{
    return [
        ['id' => 1, 'kode' => '1', 'nama' => 'Koperasi 1', 'is_aktif' => 1],
        ['id' => 2, 'kode' => '2', 'nama' => 'Koperasi 2', 'is_aktif' => 1],
        ['id' => 3, 'kode' => '3', 'nama' => 'Koperasi 3', 'is_aktif' => 1],
    ];
}

function cashless_koperasi_password_setting_key(int $koperasiId): string
{
    return 'cashless_koperasi_' . max(1, min(3, $koperasiId)) . '_password';
}

function cashless_koperasi_nama_setting_key(int $koperasiId): string
{
    return 'cashless_koperasi_' . max(1, min(3, $koperasiId)) . '_nama';
}

function cashless_koperasi_ensure_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cashless_koperasi (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            kode VARCHAR(10) NOT NULL,
            nama VARCHAR(120) NOT NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cashless_koperasi_kode (kode)
        )
    ');

    foreach (cashless_koperasi_definitions() as $row) {
        $pdo->prepare('
            INSERT IGNORE INTO cashless_koperasi (id, kode, nama, is_aktif)
            VALUES (:id, :kode, :nama, 1)
        ')->execute([
            'id' => (int) $row['id'],
            'kode' => (string) $row['kode'],
            'nama' => (string) $row['nama'],
        ]);
    }

    if (table_exists($pdo, 'cashless_transactions') && !column_exists($pdo, 'cashless_transactions', 'koperasi_id')) {
        try {
            $pdo->exec('ALTER TABLE cashless_transactions ADD COLUMN koperasi_id TINYINT UNSIGNED NULL AFTER santri_id');
            $pdo->exec('ALTER TABLE cashless_transactions ADD INDEX idx_cashless_tx_koperasi (koperasi_id, tanggal)');
        } catch (PDOException $e) {
        }
    }
}

/** @return list<array{id:int,kode:string,nama:string,is_aktif:int,label:string}> */
function cashless_koperasi_list(PDO $pdo, bool $aktifOnly = true): array
{
    cashless_koperasi_ensure_schema($pdo);
    $out = [];
    foreach (cashless_koperasi_definitions() as $def) {
        $id = (int) $def['id'];
        $namaCustom = trim((string) app_setting($pdo, cashless_koperasi_nama_setting_key($id), ''));
        $nama = $namaCustom !== '' ? $namaCustom : (string) $def['nama'];
        $row = [
            'id' => $id,
            'kode' => (string) $def['kode'],
            'nama' => $nama,
            'is_aktif' => (int) ($def['is_aktif'] ?? 1),
            'label' => $nama,
        ];
        if ($aktifOnly && (int) $row['is_aktif'] !== 1) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

function cashless_koperasi_by_id(PDO $pdo, int $koperasiId): ?array
{
    foreach (cashless_koperasi_list($pdo, false) as $row) {
        if ((int) $row['id'] === $koperasiId) {
            return $row;
        }
    }

    return null;
}

/** Tema warna kartu laporan per koperasi (id 1–3). */
function cashless_koperasi_card_theme(int $koperasiId): array
{
    $themes = [
        1 => [
            'accent' => '#0d9488',
            'accent_dark' => '#0f766e',
            'gradient' => 'linear-gradient(145deg, #0f766e 0%, #14b8a6 55%, #5eead4 100%)',
            'icon' => 'fa-store',
            'chip' => '01',
        ],
        2 => [
            'accent' => '#4f46e5',
            'accent_dark' => '#4338ca',
            'gradient' => 'linear-gradient(145deg, #4338ca 0%, #6366f1 55%, #a5b4fc 100%)',
            'icon' => 'fa-basket-shopping',
            'chip' => '02',
        ],
        3 => [
            'accent' => '#d97706',
            'accent_dark' => '#b45309',
            'gradient' => 'linear-gradient(145deg, #b45309 0%, #f59e0b 55%, #fcd34d 100%)',
            'icon' => 'fa-cart-shopping',
            'chip' => '03',
        ],
    ];

    return $themes[max(1, min(3, $koperasiId))] ?? $themes[1];
}

function cashless_koperasi_resolve_id_from_request(): int
{
    $fromGet = (int) ($_GET['k'] ?? $_GET['koperasi_id'] ?? $_GET['koperasi'] ?? 0);
    if ($fromGet >= 1 && $fromGet <= 3) {
        return $fromGet;
    }
    $fromPost = (int) ($_POST['koperasi_id'] ?? 0);
    if ($fromPost >= 1 && $fromPost <= 3) {
        return $fromPost;
    }
    if (isset($_SESSION['koperasi_cashless']['id'])) {
        return (int) $_SESSION['koperasi_cashless']['id'];
    }
    if (isset($_SESSION['cashless_scan_koperasi_id'])) {
        return (int) $_SESSION['cashless_scan_koperasi_id'];
    }

    return 0;
}

function cashless_koperasi_is_portal_request(): bool
{
    return defined('CASHLESS_KOPERASI_PORTAL') && CASHLESS_KOPERASI_PORTAL === true;
}

function cashless_koperasi_session_active(): bool
{
    return isset($_SESSION['koperasi_cashless']['id']) && (int) $_SESSION['koperasi_cashless']['id'] >= 1;
}

function cashless_koperasi_require_session(PDO $pdo, ?int $expectedId = null): array
{
    cashless_koperasi_ensure_schema($pdo);
    if (!cashless_koperasi_session_active()) {
        set_flash('error', 'Silakan login koperasi terlebih dahulu.');
        $kid = $expectedId ?? cashless_koperasi_resolve_id_from_request();
        header('Location: ' . app_href('/koperasi/login.php' . ($kid > 0 ? '?k=' . $kid : '')));
        exit;
    }
    $session = $_SESSION['koperasi_cashless'];
    $id = (int) ($session['id'] ?? 0);
    if ($expectedId !== null && $expectedId > 0 && $id !== $expectedId) {
        set_flash('error', 'Sesi koperasi tidak sesuai. Login ulang.');
        header('Location: ' . app_href('/koperasi/login.php?k=' . $expectedId));
        exit;
    }
    $row = cashless_koperasi_by_id($pdo, $id);
    if (!is_array($row)) {
        unset($_SESSION['koperasi_cashless']);
        set_flash('error', 'Data koperasi tidak valid.');
        header('Location: ' . app_href('/koperasi/index.php'));
        exit;
    }

    return $row;
}

function cashless_koperasi_verify_password(PDO $pdo, int $koperasiId, string $password): bool
{
    if ($koperasiId < 1 || $koperasiId > 3 || $password === '') {
        return false;
    }
    $stored = trim((string) app_setting($pdo, cashless_koperasi_password_setting_key($koperasiId), ''));
    if ($stored === '') {
        return false;
    }
    $info = password_get_info($stored);
    if ($info['algo'] !== 0) {
        return password_verify($password, $stored);
    }

    return hash_equals($stored, $password);
}

function cashless_koperasi_login(PDO $pdo, int $koperasiId, string $password): bool
{
    $row = cashless_koperasi_by_id($pdo, $koperasiId);
    if (!is_array($row) || !cashless_koperasi_verify_password($pdo, $koperasiId, $password)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['koperasi_cashless'] = [
        'id' => $koperasiId,
        'kode' => (string) $row['kode'],
        'nama' => (string) $row['nama'],
        'logged_at' => time(),
    ];
    unset($_SESSION['cashless_verified'], $_SESSION['cashless_auto_nominal_scan']);

    return true;
}

function cashless_koperasi_logout(): void
{
    unset($_SESSION['koperasi_cashless'], $_SESSION['cashless_verified'], $_SESSION['cashless_auto_nominal_scan']);
}

function cashless_koperasi_hub_url(): string
{
    return '/koperasi/index.php';
}

function cashless_koperasi_scan_url(int $koperasiId): string
{
    if (cashless_koperasi_is_portal_request() || cashless_koperasi_session_active()) {
        return '/koperasi/scan.php';
    }

    return '/keuangan/cashless_scan.php?koperasi_id=' . $koperasiId;
}

function cashless_koperasi_laporan_url(int $koperasiId, bool $portal = false): string
{
    if ($portal || cashless_koperasi_session_active()) {
        return '/koperasi/laporan.php';
    }

    return '/keuangan/cashless_laporan.php?koperasi_id=' . $koperasiId;
}

/**
 * @return array{id:int,nama:string,portal:bool,admin:bool}
 */
function cashless_koperasi_resolve_context(PDO $pdo): array
{
    cashless_koperasi_ensure_schema($pdo);
    $portal = cashless_koperasi_is_portal_request();
    if ($portal) {
        $row = cashless_koperasi_require_session($pdo);
        return ['id' => (int) $row['id'], 'nama' => (string) $row['nama'], 'portal' => true, 'admin' => false];
    }

    $id = cashless_koperasi_resolve_id_from_request();
    if ($id >= 1 && $id <= 3 && isset($_SESSION['user'])) {
        $_SESSION['cashless_scan_koperasi_id'] = $id;
        $row = cashless_koperasi_by_id($pdo, $id);
        return ['id' => $id, 'nama' => (string) ($row['nama'] ?? ('Koperasi ' . $id)), 'portal' => false, 'admin' => true];
    }

    if (isset($_SESSION['cashless_scan_koperasi_id'])) {
        $sid = (int) $_SESSION['cashless_scan_koperasi_id'];
        if ($sid >= 1 && $sid <= 3) {
            $row = cashless_koperasi_by_id($pdo, $sid);
            return ['id' => $sid, 'nama' => (string) ($row['nama'] ?? ('Koperasi ' . $sid)), 'portal' => false, 'admin' => true];
        }
    }

    return ['id' => 0, 'nama' => 'Umum', 'portal' => false, 'admin' => isset($_SESSION['user'])];
}

function cashless_koperasi_insert_debit(PDO $pdo, int $santriId, int $nominal, string $keterangan, int $createdBy, ?int $koperasiId): void
{
    $cols = ['santri_id', 'jenis', 'nominal', 'keterangan', 'created_by'];
    $vals = [':santri_id', "'DEBIT'", ':nominal', ':keterangan', ':created_by'];
    $params = [
        'santri_id' => $santriId,
        'nominal' => $nominal,
        'keterangan' => $keterangan,
        'created_by' => $createdBy,
    ];
    if ($koperasiId > 0 && column_exists($pdo, 'cashless_transactions', 'koperasi_id')) {
        $cols[] = 'koperasi_id';
        $vals[] = ':koperasi_id';
        $params['koperasi_id'] = $koperasiId;
    }
    $sql = 'INSERT INTO cashless_transactions (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $pdo->prepare($sql)->execute($params);
}

/**
 * @return list<array<string,mixed>>
 */
function cashless_koperasi_fetch_debit_hari_ini(PDO $pdo, ?int $koperasiId, int $limit = 20): array
{
    if (!table_exists($pdo, 'cashless_transactions')) {
        return [];
    }
    $hasKop = column_exists($pdo, 'cashless_transactions', 'koperasi_id');
    $sql = '
        SELECT ct.tanggal, ct.nominal, ct.keterangan, s.nis,
               COALESCE(NULLIF(s.nama_santri,\'\'), s.nama) AS nama_santri';
    if ($hasKop) {
        $sql .= ', ct.koperasi_id';
    }
    $sql .= '
        FROM cashless_transactions ct
        INNER JOIN santri s ON s.id = ct.santri_id
        WHERE ct.jenis = \'DEBIT\' AND DATE(ct.tanggal) = CURDATE()';
    $params = [];
    if ($hasKop && $koperasiId !== null && $koperasiId > 0) {
        $sql .= ' AND ct.koperasi_id = :koperasi_id';
        $params['koperasi_id'] = $koperasiId;
    }
    $sql .= ' ORDER BY ct.id DESC LIMIT ' . max(1, min(100, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{rows:list<array<string,mixed>>,total_debit:int,total_transaksi:int,jumlah_santri:int}
 */
function cashless_koperasi_laporan_ringkas(PDO $pdo, ?int $koperasiId, string $dari, string $sampai): array
{
    if (!table_exists($pdo, 'cashless_transactions')) {
        return ['rows' => [], 'total_debit' => 0, 'total_transaksi' => 0, 'jumlah_santri' => 0];
    }
    $hasKop = column_exists($pdo, 'cashless_transactions', 'koperasi_id');
    $sql = '
        SELECT ct.tanggal, ct.nominal, ct.keterangan, s.nis,
               COALESCE(NULLIF(s.nama_santri,\'\'), s.nama) AS nama_santri,
               s.tingkatan';
    if ($hasKop) {
        $sql .= ', ct.koperasi_id';
    }
    $sql .= '
        FROM cashless_transactions ct
        INNER JOIN santri s ON s.id = ct.santri_id
        WHERE ct.jenis = \'DEBIT\'
          AND DATE(ct.tanggal) BETWEEN :dari AND :sampai';
    $params = ['dari' => $dari, 'sampai' => $sampai];
    if ($hasKop && $koperasiId !== null && $koperasiId > 0) {
        $sql .= ' AND ct.koperasi_id = :koperasi_id';
        $params['koperasi_id'] = $koperasiId;
    }
    $sql .= ' ORDER BY ct.tanggal DESC, ct.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total = 0;
    $santriIds = [];
    foreach ($rows as $r) {
        $total += (int) round((float) ($r['nominal'] ?? 0));
    }
    if ($rows !== []) {
        $agg = $pdo->prepare('
            SELECT COUNT(DISTINCT ct.santri_id) FROM cashless_transactions ct
            WHERE ct.jenis = \'DEBIT\' AND DATE(ct.tanggal) BETWEEN :dari AND :sampai'
            . ($hasKop && $koperasiId !== null && $koperasiId > 0 ? ' AND ct.koperasi_id = :koperasi_id' : '')
        );
        $agg->execute($params);
        $jumlahSantri = (int) $agg->fetchColumn();
    } else {
        $jumlahSantri = 0;
    }

    return [
        'rows' => $rows,
        'total_debit' => $total,
        'total_transaksi' => count($rows),
        'jumlah_santri' => $jumlahSantri,
    ];
}

/**
 * @return list<array{koperasi_id:int,nama:string,total_debit:int,total_transaksi:int,jumlah_santri:int}>
 */
function cashless_koperasi_laporan_per_koperasi(PDO $pdo, string $dari, string $sampai): array
{
    cashless_koperasi_ensure_schema($pdo);
    if (!table_exists($pdo, 'cashless_transactions') || !column_exists($pdo, 'cashless_transactions', 'koperasi_id')) {
        return [];
    }
    $out = [];
    foreach (cashless_koperasi_list($pdo) as $kop) {
        $id = (int) $kop['id'];
        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(nominal),0) AS total, COUNT(*) AS cnt, COUNT(DISTINCT santri_id) AS santri_cnt
            FROM cashless_transactions
            WHERE jenis = \'DEBIT\' AND koperasi_id = :kid
              AND DATE(tanggal) BETWEEN :dari AND :sampai
        ');
        $stmt->execute(['kid' => $id, 'dari' => $dari, 'sampai' => $sampai]);
        $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out[] = [
            'koperasi_id' => $id,
            'nama' => (string) $kop['nama'],
            'total_debit' => (int) round((float) ($agg['total'] ?? 0)),
            'total_transaksi' => (int) ($agg['cnt'] ?? 0),
            'jumlah_santri' => (int) ($agg['santri_cnt'] ?? 0),
        ];
    }

    return $out;
}
