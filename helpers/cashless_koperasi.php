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

    if (table_exists($pdo, 'cashless_transactions') && !column_exists($pdo, 'cashless_transactions', 'setor_at')) {
        try {
            $pdo->exec('ALTER TABLE cashless_transactions ADD COLUMN setor_at DATETIME NULL AFTER keterangan');
            $pdo->exec('ALTER TABLE cashless_transactions ADD INDEX idx_cashless_tx_setor (setor_at, tanggal)');
        } catch (PDOException $e) {
        }
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cashless_setor_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            koperasi_id TINYINT UNSIGNED NULL,
            tanggal DATE NOT NULL,
            total_nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            jumlah_transaksi INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cashless_setor_tgl (tanggal, koperasi_id)
        )
    ');
    try {
        $pdo->exec('ALTER TABLE cashless_setor_log ADD UNIQUE KEY uk_cashless_setor_kop_tgl (koperasi_id, tanggal)');
    } catch (PDOException $e) {
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

function cashless_koperasi_insert_debit(PDO $pdo, int $santriId, int $nominal, string $keterangan, int $createdBy, ?int $koperasiId): int
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

    return (int) $pdo->lastInsertId();
}

/** Akun kas operasional default untuk setor cashless. */
function cashless_koperasi_default_akun_kas_id(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return 0;
    }
    $row = $pdo->query('
        SELECT id FROM keuangan_akun
        WHERE is_active = 1
        ORDER BY is_default DESC, jenis_akun ASC, id ASC
        LIMIT 1
    ')->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['id'] ?? 0);
}

/**
 * Jurnal belanja scan: titipan saku berkurang, menunggu setor ke koperasi.
 * Uang fisik masih di bendahara sampai setor harian.
 */
function cashless_jurnal_belanja_scan(PDO $pdo, int $txId, string $tanggal, int $nominal, int $userId, string $keterangan): void
{
    if ($txId <= 0 || $nominal <= 0) {
        return;
    }
    require_once __DIR__ . '/keuangan_jurnal.php';
    // DDL meng-commit transaksi MySQL — jangan panggil saat sudah beginTransaction().
    if (!$pdo->inTransaction()) {
        ensure_keuangan_jurnal_tables($pdo);
    }
    $ket = $keterangan !== '' ? $keterangan : 'Belanja cashless koperasi';
    keuangan_jurnal_post($pdo, $tanggal, [
        ['kode_akun' => '2101', 'debit' => $nominal, 'kredit' => 0],
        ['kode_akun' => '2103', 'debit' => 0, 'kredit' => $nominal],
    ], 'cashless_debit', $txId, $userId, 'Jurnal belanja saku (scan) #' . $txId . ' — ' . $ket);
}

/**
 * Jurnal setor harian: uang fisik keluar dari kas bendahara ke koperasi.
 */
function cashless_jurnal_setor_koperasi(PDO $pdo, int $setorLogId, string $tanggal, int $nominal, int $akunKasId, int $userId, string $keterangan): void
{
    if ($setorLogId <= 0 || $nominal <= 0) {
        return;
    }
    require_once __DIR__ . '/keuangan_jurnal.php';
    if (!$pdo->inTransaction()) {
        ensure_keuangan_jurnal_tables($pdo);
    }
    $kasKode = keuangan_akun_coa_kode($pdo, $akunKasId);
    keuangan_jurnal_post($pdo, $tanggal, [
        ['kode_akun' => '2103', 'debit' => $nominal, 'kredit' => 0],
        ['kode_akun' => $kasKode, 'debit' => 0, 'kredit' => $nominal],
    ], 'cashless_setor', $setorLogId, $userId, $keterangan);
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
        SELECT ct.id, ct.tanggal, ct.nominal, ct.keterangan, ct.setor_at, s.nis,
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

/**
 * Ringkasan debit hari ini yang belum disetor.
 *
 * @return array{total:int,jumlah:int,rows:list<array<string,mixed>>}
 */
function cashless_koperasi_ringkas_harian_belum_setor(PDO $pdo, ?int $koperasiId, string $tanggal): array
{
    cashless_koperasi_ensure_schema($pdo);
    if (!table_exists($pdo, 'cashless_transactions')) {
        return ['total' => 0, 'jumlah' => 0, 'rows' => []];
    }
    $hasKop = column_exists($pdo, 'cashless_transactions', 'koperasi_id');
    $hasSetor = column_exists($pdo, 'cashless_transactions', 'setor_at');
    $sql = '
        SELECT ct.id, ct.santri_id, ct.nominal, ct.keterangan, ct.tanggal,
               s.nis, COALESCE(NULLIF(s.nama_santri,\'\'), s.nama) AS nama_santri
        FROM cashless_transactions ct
        INNER JOIN santri s ON s.id = ct.santri_id
        WHERE ct.jenis = \'DEBIT\' AND DATE(ct.tanggal) = :tgl';
    if ($hasSetor) {
        $sql .= ' AND ct.setor_at IS NULL';
    }
    $params = ['tgl' => $tanggal];
    if ($hasKop && $koperasiId !== null && $koperasiId > 0) {
        $sql .= ' AND ct.koperasi_id = :koperasi_id';
        $params['koperasi_id'] = $koperasiId;
    }
    $sql .= ' ORDER BY ct.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total = 0;
    foreach ($rows as $r) {
        $total += (int) round((float) ($r['nominal'] ?? 0));
    }

    return ['total' => $total, 'jumlah' => count($rows), 'rows' => $rows];
}

/**
 * Total debit hari ini yang belum disetor (saldo belum terpotong).
 */
function cashless_santri_pending_debit_total(PDO $pdo, int $santriId, ?string $tanggal = null): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return 0;
    }
    cashless_koperasi_ensure_schema($pdo);
    $tgl = $tanggal !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : date('Y-m-d');
    $sql = "
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE santri_id = :sid AND jenis = 'DEBIT' AND DATE(tanggal) = :tgl
    ";
    if (column_exists($pdo, 'cashless_transactions', 'setor_at')) {
        $sql .= ' AND setor_at IS NULL';
    }
    $st = $pdo->prepare($sql);
    $st->execute(['sid' => $santriId, 'tgl' => $tgl]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/** Apakah koperasi pada tanggal tertentu sudah disetor. */
function cashless_koperasi_setor_sudah(PDO $pdo, int $koperasiId, string $tanggal): bool
{
    if ($koperasiId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return false;
    }
    cashless_koperasi_ensure_schema($pdo);
    if (!table_exists($pdo, 'cashless_setor_log')) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM cashless_setor_log WHERE koperasi_id = :kop AND tanggal = :tgl LIMIT 1');
    $st->execute(['kop' => $koperasiId, 'tgl' => $tanggal]);

    return (bool) $st->fetchColumn();
}

/** @return array<string, mixed>|null */
function cashless_koperasi_setor_log_row(PDO $pdo, int $koperasiId, string $tanggal): ?array
{
    if ($koperasiId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return null;
    }
    cashless_koperasi_ensure_schema($pdo);
    if (!table_exists($pdo, 'cashless_setor_log')) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT id, koperasi_id, tanggal, total_nominal, jumlah_transaksi, created_by, created_at
        FROM cashless_setor_log
        WHERE koperasi_id = :kop AND tanggal = :tgl
        LIMIT 1
    ');
    $st->execute(['kop' => $koperasiId, 'tgl' => $tanggal]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Semua transaksi debit satu koperasi pada tanggal (termasuk yang sudah disetor).
 *
 * @return list<array<string,mixed>>
 */
function cashless_koperasi_transaksi_harian(PDO $pdo, int $koperasiId, string $tanggal): array
{
    cashless_koperasi_ensure_schema($pdo);
    if ($koperasiId < 1 || !table_exists($pdo, 'cashless_transactions')) {
        return [];
    }
    $hasKop = column_exists($pdo, 'cashless_transactions', 'koperasi_id');
    $sql = '
        SELECT ct.id, ct.tanggal, ct.nominal, ct.keterangan, ct.setor_at,
               s.nis, COALESCE(NULLIF(s.nama_santri,\'\'), s.nama) AS nama_santri, s.tingkatan
        FROM cashless_transactions ct
        INNER JOIN santri s ON s.id = ct.santri_id
        WHERE ct.jenis = \'DEBIT\' AND DATE(ct.tanggal) = :tgl';
    $params = ['tgl' => $tanggal];
    if ($hasKop) {
        $sql .= ' AND ct.koperasi_id = :koperasi_id';
        $params['koperasi_id'] = $koperasiId;
    }
    $sql .= ' ORDER BY ct.tanggal ASC, ct.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Panel setor per koperasi untuk satu tanggal.
 *
 * @return list<array<string,mixed>>
 */
function cashless_koperasi_panel_setor_harian(PDO $pdo, string $tanggal): array
{
    $out = [];
    foreach (cashless_koperasi_list($pdo) as $kop) {
        $kid = (int) ($kop['id'] ?? 0);
        if ($kid < 1) {
            continue;
        }
        $belum = cashless_koperasi_ringkas_harian_belum_setor($pdo, $kid, $tanggal);
        $transaksi = cashless_koperasi_transaksi_harian($pdo, $kid, $tanggal);
        $totalHari = 0;
        foreach ($transaksi as $tx) {
            $totalHari += (int) round((float) ($tx['nominal'] ?? 0));
        }
        $sudahSetor = cashless_koperasi_setor_sudah($pdo, $kid, $tanggal);
        $log = cashless_koperasi_setor_log_row($pdo, $kid, $tanggal);
        $out[] = [
            'koperasi_id' => $kid,
            'nama' => (string) ($kop['nama'] ?? ('Koperasi ' . $kid)),
            'theme' => cashless_koperasi_card_theme($kid),
            'total_hari' => $totalHari,
            'jumlah_transaksi' => count($transaksi),
            'belum_setor' => $belum,
            'sudah_setor' => $sudahSetor,
            'setor_log' => $log,
            'transaksi' => $transaksi,
        ];
    }

    return $out;
}

/**
 * Ringkasan total debit per tanggal (semua koperasi) dalam rentang.
 *
 * @return list<array{tanggal:string,koperasi:list<array{koperasi_id:int,nama:string,total:int,jumlah:int,sudah_setor:bool}>}>
 */
function cashless_koperasi_rekap_tanggal_range(PDO $pdo, string $dari, string $sampai): array
{
    cashless_koperasi_ensure_schema($pdo);
    if (!table_exists($pdo, 'cashless_transactions') || !column_exists($pdo, 'cashless_transactions', 'koperasi_id')) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT DATE(ct.tanggal) AS tgl, ct.koperasi_id,
               COALESCE(SUM(ct.nominal), 0) AS total,
               COUNT(*) AS jumlah
        FROM cashless_transactions ct
        WHERE ct.jenis = \'DEBIT\'
          AND DATE(ct.tanggal) BETWEEN :dari AND :sampai
          AND ct.koperasi_id IS NOT NULL
        GROUP BY DATE(ct.tanggal), ct.koperasi_id
        ORDER BY tgl DESC, ct.koperasi_id ASC
    ');
    $stmt->execute(['dari' => $dari, 'sampai' => $sampai]);
    /** @var array<string, array{koperasi:list<array<string,mixed>>}> $byDate */
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $tgl = (string) ($row['tgl'] ?? '');
        $kid = (int) ($row['koperasi_id'] ?? 0);
        if ($tgl === '' || $kid < 1) {
            continue;
        }
        if (!isset($byDate[$tgl])) {
            $byDate[$tgl] = ['tanggal' => $tgl, 'koperasi' => []];
        }
        $kop = cashless_koperasi_by_id($pdo, $kid);
        $byDate[$tgl]['koperasi'][] = [
            'koperasi_id' => $kid,
            'nama' => (string) ($kop['nama'] ?? ('Koperasi ' . $kid)),
            'total' => (int) round((float) ($row['total'] ?? 0)),
            'jumlah' => (int) ($row['jumlah'] ?? 0),
            'sudah_setor' => cashless_koperasi_setor_sudah($pdo, $kid, $tgl),
        ];
    }

    return array_values($byDate);
}

/** Total saldo uang saku (cashless) seluruh santri aktif — nilai real di database. */
function cashless_saku_total_real(PDO $pdo): array
{
    if (!table_exists($pdo, 'cashless_accounts') || !table_exists($pdo, 'santri')) {
        return ['total' => 0, 'jumlah_santri' => 0, 'jumlah_bersaldo' => 0];
    }
    require_once __DIR__ . '/santri_operasional.php';
    $aktif = santri_sql_aktif_only('s');
    $row = $pdo->query("
        SELECT COALESCE(SUM(ca.balance), 0) AS total,
               COUNT(DISTINCT ca.santri_id) AS jumlah_santri,
               SUM(CASE WHEN ca.balance > 0 THEN 1 ELSE 0 END) AS jumlah_bersaldo
        FROM cashless_accounts ca
        INNER JOIN santri s ON s.id = ca.santri_id AND {$aktif}
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int) round((float) ($row['total'] ?? 0)),
        'jumlah_santri' => (int) ($row['jumlah_santri'] ?? 0),
        'jumlah_bersaldo' => (int) ($row['jumlah_bersaldo'] ?? 0),
    ];
}

/** Total transaksi belum disetor pada tanggal (semua koperasi). */
function cashless_koperasi_total_belum_setor_tanggal(PDO $pdo, string $tanggal): int
{
    $total = 0;
    foreach (cashless_koperasi_list($pdo) as $kop) {
        $kid = (int) ($kop['id'] ?? 0);
        if ($kid < 1) {
            continue;
        }
        $ringkas = cashless_koperasi_ringkas_harian_belum_setor($pdo, $kid, $tanggal);
        $total += (int) ($ringkas['total'] ?? 0);
    }

    return $total;
}

/** Hanya admin/pengurus (atau super admin) yang boleh setor harian. */
function cashless_user_can_setor_harian(): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) {
        return true;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));

    return in_array($role, ['admin', 'pengurus'], true);
}

/**
 * Setor harian satu koperasi: serahkan uang fisik ke koperasi (kas berkurang).
 * Saldo Saku santri sudah terpotong saat scan.
 *
 * @return array{ok:bool,message:string,total?:int,jumlah?:int,koperasi_id?:int}
 */
function cashless_koperasi_setor_harian(PDO $pdo, ?int $koperasiId, string $tanggal, int $userId): array
{
    if (!cashless_user_can_setor_harian()) {
        return ['ok' => false, 'message' => 'Setor harian hanya untuk admin atau pengurus.'];
    }
    if ($koperasiId === null || $koperasiId < 1) {
        return ['ok' => false, 'message' => 'Pilih koperasi untuk setor.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Tanggal tidak valid.'];
    }
    if (cashless_koperasi_setor_sudah($pdo, $koperasiId, $tanggal)) {
        return ['ok' => false, 'message' => 'Koperasi ini sudah disetor untuk tanggal tersebut.'];
    }

    cashless_koperasi_ensure_schema($pdo);
    $ringkas = cashless_koperasi_ringkas_harian_belum_setor($pdo, $koperasiId, $tanggal);
    if ($ringkas['jumlah'] <= 0) {
        return ['ok' => false, 'message' => 'Tidak ada transaksi yang perlu disetor untuk tanggal ini.'];
    }

    require_once __DIR__ . '/keuangan_jurnal.php';
    ensure_keuangan_jurnal_tables($pdo);

    $pdo->beginTransaction();
    try {
        $ids = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $ringkas['rows']);
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            $pdo->rollBack();

            return ['ok' => false, 'message' => 'Data transaksi tidak valid.'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if (column_exists($pdo, 'cashless_transactions', 'setor_at')) {
            $upd = $pdo->prepare('UPDATE cashless_transactions SET setor_at = NOW() WHERE id IN (' . $placeholders . ') AND setor_at IS NULL');
            $upd->execute($ids);
        }

        $log = $pdo->prepare('
            INSERT INTO cashless_setor_log (koperasi_id, tanggal, total_nominal, jumlah_transaksi, created_by)
            VALUES (:kop, :tgl, :total, :jumlah, :uid)
        ');
        $log->execute([
            'kop' => $koperasiId,
            'tgl' => $tanggal,
            'total' => $ringkas['total'],
            'jumlah' => $ringkas['jumlah'],
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $setorLogId = (int) $pdo->lastInsertId();
        $namaKop = (string) (cashless_koperasi_by_id($pdo, $koperasiId)['nama'] ?? 'koperasi');
        $akunKasId = cashless_koperasi_default_akun_kas_id($pdo);
        cashless_jurnal_setor_koperasi(
            $pdo,
            $setorLogId,
            $tanggal,
            (int) $ringkas['total'],
            $akunKasId,
            $userId,
            'Setor harian ' . $namaKop . ' — ' . $ringkas['jumlah'] . ' transaksi'
        );
        $pdo->commit();

        return [
            'ok' => true,
            'message' => 'Setor ' . $namaKop . ': Rp ' . number_format($ringkas['total'], 0, ',', '.')
                . ' (' . $ringkas['jumlah'] . ' transaksi). Kas bendahara berkurang.',
            'total' => $ringkas['total'],
            'jumlah' => $ringkas['jumlah'],
            'koperasi_id' => $koperasiId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal setor: ' . $e->getMessage()];
    }
}

/**
 * Setor beberapa koperasi sekaligus untuk tanggal yang sama.
 *
 * @param list<int> $koperasiIds
 * @return array{ok:bool,message:string,sukses:int,gagal:int,total:int,details:list<string>}
 */
function cashless_koperasi_setor_multi(PDO $pdo, array $koperasiIds, string $tanggal, int $userId): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $koperasiIds), static fn(int $id): bool => $id >= 1)));
    if ($ids === []) {
        return ['ok' => false, 'message' => 'Pilih minimal satu koperasi.', 'sukses' => 0, 'gagal' => 0, 'total' => 0, 'details' => []];
    }

    $sukses = 0;
    $gagal = 0;
    $total = 0;
    $details = [];
    foreach ($ids as $kid) {
        $res = cashless_koperasi_setor_harian($pdo, $kid, $tanggal, $userId);
        if (!empty($res['ok'])) {
            $sukses++;
            $total += (int) ($res['total'] ?? 0);
            $details[] = (string) ($res['message'] ?? 'OK');
        } else {
            $gagal++;
            $details[] = (string) ($res['message'] ?? 'Gagal');
        }
    }

    if ($sukses === 0) {
        return [
            'ok' => false,
            'message' => 'Setor gagal untuk semua koperasi terpilih.',
            'sukses' => 0,
            'gagal' => $gagal,
            'total' => 0,
            'details' => $details,
        ];
    }

    $msg = 'Setor berhasil untuk ' . $sukses . ' koperasi. Total Rp ' . number_format($total, 0, ',', '.')
        . '. Kas bendahara berkurang; uang saku menunggu setor berkurang.';
    if ($gagal > 0) {
        $msg .= ' (' . $gagal . ' koperasi gagal.)';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'sukses' => $sukses,
        'gagal' => $gagal,
        'total' => $total,
        'details' => $details,
    ];
}

/**
 * Hapus satu transaksi debit (super admin). Saldo uang saku dikembalikan (sudah terpotong saat scan).
 *
 * @return array{ok:bool,message:string}
 */
function cashless_koperasi_hapus_debit(PDO $pdo, int $txId): array
{
    cashless_koperasi_ensure_schema($pdo);
    if ($txId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return ['ok' => false, 'message' => 'Transaksi tidak valid.'];
    }
    $stmt = $pdo->prepare('
        SELECT id, santri_id, jenis, nominal, setor_at
        FROM cashless_transactions
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $txId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || strtoupper((string) ($row['jenis'] ?? '')) !== 'DEBIT') {
        return ['ok' => false, 'message' => 'Transaksi debit tidak ditemukan.'];
    }
    $santriId = (int) ($row['santri_id'] ?? 0);
    $nominal = (int) round((float) ($row['nominal'] ?? 0));
    if ($santriId <= 0 || $nominal <= 0) {
        return ['ok' => false, 'message' => 'Data transaksi tidak lengkap.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM cashless_transactions WHERE id = :id')->execute(['id' => $txId]);
        $pdo->prepare('UPDATE cashless_accounts SET balance = balance + :nominal WHERE santri_id = :sid')->execute([
            'nominal' => $nominal,
            'sid' => $santriId,
        ]);
        require_once __DIR__ . '/keuangan_jurnal.php';
        keuangan_jurnal_delete_by_ref($pdo, 'cashless_debit', $txId);
        $pdo->commit();

        return ['ok' => true, 'message' => 'Transaksi dihapus. Saldo uang saku dikembalikan Rp ' . number_format($nominal, 0, ',', '.') . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }
}

/** Cek QR/NIS santri terdaftar (untuk notifikasi scan), termasuk kartu sementara aktif. */
function cashless_lookup_santri_by_code(PDO $pdo, string $code): ?array
{
    require_once __DIR__ . '/santri_kartu_sementara.php';
    $row = santri_resolve_by_scan_code($pdo, $code);
    if (!is_array($row)) {
        return null;
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    return [
        'id' => (int) ($row['id'] ?? 0),
        'nis' => (string) ($row['nis'] ?? ''),
        'nama_santri' => (string) ($row[$nameCol] ?? $row['nama'] ?? ''),
    ];
}
