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

    cashless_reconcile_account_balances_if_needed($pdo);
}

/** Tanggal operasional hari ini (Asia/Jakarta via PHP). */
function cashless_tanggal_hari_ini(): string
{
    return date('Y-m-d');
}

/**
 * Rentang waktu satu hari operasional [start, end) — reset batas harian tiap pergantian tanggal.
 *
 * @return array{start:string,end:string}
 */
function cashless_tanggal_rentang_harian(string $tanggal): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = cashless_tanggal_hari_ini();
    }

    return [
        'start' => $tanggal . ' 00:00:00',
        'end' => date('Y-m-d', strtotime($tanggal . ' +1 day')) . ' 00:00:00',
    ];
}

/** Batalkan sesi scan PIN jika sudah berganti tanggal operasional. */
function cashless_verified_session_normalize(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (empty($_SESSION['cashless_verified']) || !is_array($_SESSION['cashless_verified'])) {
        return;
    }
    $today = cashless_tanggal_hari_ini();
    $sessDate = (string) ($_SESSION['cashless_verified']['operational_date'] ?? '');
    if ($sessDate === '' || $sessDate !== $today) {
        unset($_SESSION['cashless_verified'], $_SESSION['cashless_auto_nominal_scan']);
    }
}

function cashless_verified_session_mark(int $santriId): void
{
    $_SESSION['cashless_verified'] = [
        'santri_id' => $santriId,
        'verified_at' => time(),
        'operational_date' => cashless_tanggal_hari_ini(),
    ];
}

/**
 * Samakan cashless_accounts.balance dengan ledger transaksi (top-up − debit).
 */
function cashless_sync_account_balance(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    $saldo = cashless_santri_saldo_tampil($pdo, $santriId);
    $pdo->prepare('INSERT IGNORE INTO cashless_accounts (santri_id, balance) VALUES (:id, 0)')->execute(['id' => $santriId]);
    $pdo->prepare('UPDATE cashless_accounts SET balance = :saldo WHERE santri_id = :id')->execute([
        'saldo' => $saldo,
        'id' => $santriId,
    ]);
}

/** Rekonsiliasi saldo semua santri yang punya akun/transaksi cashless. */
function cashless_sync_all_account_balances(PDO $pdo): int
{
    if (!table_exists($pdo, 'cashless_accounts')) {
        return 0;
    }
    $ids = [];
    if (table_exists($pdo, 'cashless_transactions')) {
        foreach ($pdo->query('SELECT DISTINCT santri_id FROM cashless_transactions')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
            $ids[(int) $sid] = true;
        }
    }
    foreach ($pdo->query('SELECT santri_id FROM cashless_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
        $ids[(int) $sid] = true;
    }
    $n = 0;
    foreach (array_keys($ids) as $sid) {
        if ($sid <= 0) {
            continue;
        }
        cashless_sync_account_balance($pdo, $sid);
        $n++;
    }

    return $n;
}

/** Sekali per sesi: perbaiki drift balance vs transaksi (debit lama tanpa update balance). */
function cashless_reconcile_account_balances_if_needed(PDO $pdo): void
{
    if (!table_exists($pdo, 'cashless_accounts') || !table_exists($pdo, 'cashless_transactions')) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['cashless_balance_reconciled_v2'])) {
        return;
    }
    static $doneCli = false;
    if (session_status() !== PHP_SESSION_ACTIVE && $doneCli) {
        return;
    }
    cashless_sync_all_account_balances($pdo);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['cashless_balance_reconciled_v2'] = 1;
    } else {
        $doneCli = true;
    }
}

/** Total debit (belanja) santri pada satu tanggal — sumber «terpakai hari ini». */
function cashless_santri_debit_total_tanggal(PDO $pdo, int $santriId, ?string $tanggal = null): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return 0;
    }
    $tgl = $tanggal ?? cashless_tanggal_hari_ini();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        $tgl = cashless_tanggal_hari_ini();
    }
    $range = cashless_tanggal_rentang_harian($tgl);
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE santri_id = :sid AND UPPER(jenis) = 'DEBIT'
          AND tanggal >= :tgl_start AND tanggal < :tgl_end
    ");
    $st->execute(['sid' => $santriId, 'tgl_start' => $range['start'], 'tgl_end' => $range['end']]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/** Total belanja cashless semua santri pada tanggal (termasuk tanpa koperasi_id). */
function cashless_koperasi_total_debit_tanggal(PDO $pdo, string $tanggal): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !table_exists($pdo, 'cashless_transactions')) {
        return 0;
    }
    $range = cashless_tanggal_rentang_harian($tanggal);
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE UPPER(jenis) = 'DEBIT'
          AND tanggal >= :tgl_start AND tanggal < :tgl_end
    ");
    $st->execute(['tgl_start' => $range['start'], 'tgl_end' => $range['end']]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

function cashless_koperasi_ensure_schema_deferred(PDO $pdo): void
{
    if (!empty($_SESSION['cashless_koperasi_schema_ready_v1'])) {
        return;
    }
    cashless_koperasi_ensure_schema($pdo);
    $_SESSION['cashless_koperasi_schema_ready_v1'] = 1;
}

/** @return list<array{id:int,kode:string,nama:string,is_aktif:int,label:string}> */
function cashless_koperasi_list(PDO $pdo, bool $aktifOnly = true): array
{
    cashless_koperasi_ensure_schema_deferred($pdo);
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
    $txId = (int) $pdo->lastInsertId();
    cashless_sync_account_balance($pdo, $santriId);

    return $txId;
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
 * Jurnal setor harian: uang fisik keluar dari kas bendahara ke koperasi (2103 sudah terbentuk saat scan).
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
        WHERE ct.jenis = \'DEBIT\' AND ct.tanggal >= :tgl_start AND ct.tanggal < :tgl_end';
    $range = cashless_tanggal_rentang_harian(cashless_tanggal_hari_ini());
    $params = ['tgl_start' => $range['start'], 'tgl_end' => $range['end']];
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
        SELECT ct.id, ct.tanggal, ct.nominal, ct.keterangan, ct.setor_at, s.nis,
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
        WHERE ct.jenis = \'DEBIT\' AND ct.tanggal >= :tgl_start AND ct.tanggal < :tgl_end';
    if ($hasSetor) {
        $sql .= ' AND ct.setor_at IS NULL';
    }
    $range = cashless_tanggal_rentang_harian($tanggal);
    $params = ['tgl_start' => $range['start'], 'tgl_end' => $range['end']];
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
 * Total debit belum disetor (hanya untuk laporan setor bendahara — tidak memblokir scan).
 * $tanggal null = semua tanggal.
 */
function cashless_santri_pending_debit_total(PDO $pdo, int $santriId, ?string $tanggal = null): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return 0;
    }
    cashless_koperasi_ensure_schema($pdo);
    $sql = "
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE santri_id = :sid AND jenis = 'DEBIT'
    ";
    $params = ['sid' => $santriId];
    if ($tanggal !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $range = cashless_tanggal_rentang_harian($tanggal);
        $sql .= ' AND tanggal >= :tgl_start AND tanggal < :tgl_end';
        $params['tgl_start'] = $range['start'];
        $params['tgl_end'] = $range['end'];
    }
    if (column_exists($pdo, 'cashless_transactions', 'setor_at')) {
        $sql .= ' AND setor_at IS NULL';
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/** Saldo tampil = top-up − semua debit. Status setor tidak mempengaruhi saldo maupun batas harian. */
function cashless_santri_saldo_tampil(PDO $pdo, int $santriId): int
{
    if ($santriId <= 0) {
        return 0;
    }
    if (table_exists($pdo, 'cashless_transactions')) {
        $st = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN UPPER(jenis) = 'TOPUP' THEN nominal ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN UPPER(jenis) = 'DEBIT' THEN nominal ELSE 0 END), 0) AS saldo
            FROM cashless_transactions
            WHERE santri_id = :sid
        ");
        $st->execute(['sid' => $santriId]);

        return max(0, (int) round((float) ($st->fetchColumn() ?: 0)));
    }
    if (!table_exists($pdo, 'cashless_accounts')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :sid LIMIT 1');
    $st->execute(['sid' => $santriId]);

    return max(0, (int) round((float) ($st->fetchColumn() ?: 0)));
}

/** @deprecated Gunakan cashless_santri_saldo_tampil() */
function cashless_santri_saldo_efektif(PDO $pdo, int $santriId): int
{
    return cashless_santri_saldo_tampil($pdo, $santriId);
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
        WHERE ct.jenis = \'DEBIT\' AND ct.tanggal >= :tgl_start AND ct.tanggal < :tgl_end';
    $range = cashless_tanggal_rentang_harian($tanggal);
    $params = ['tgl_start' => $range['start'], 'tgl_end' => $range['end']];
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

/**
 * Rekap saldo uang saku per santri aktif + status PIN (satu sumber untuk laporan & pengaturan).
 *
 * @return array{
 *   rows: list<array<string,mixed>>,
 *   summary: array{total_santri:int,total_saldo:int,jumlah_bersaldo:int,pin_sudah:int,pin_belum:int},
 *   daily_limit: int
 * }
 */
function cashless_rekap_saldo_santri(PDO $pdo, ?string $tanggalHari = null): array
{
    require_once __DIR__ . '/santri_list_sort.php';

    cashless_koperasi_ensure_schema($pdo);

    $dailyLimit = max(0, (int) app_setting($pdo, 'cashless_daily_limit', '10000'));
    $tglHari = $tanggalHari ?? cashless_tanggal_hari_ini();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglHari)) {
        $tglHari = cashless_tanggal_hari_ini();
    }
    $emptySummary = [
        'total_santri' => 0,
        'total_saldo' => 0,
        'jumlah_bersaldo' => 0,
        'pin_sudah' => 0,
        'pin_belum' => 0,
    ];
    if (!table_exists($pdo, 'santri')) {
        return ['rows' => [], 'summary' => $emptySummary, 'daily_limit' => $dailyLimit];
    }

    $namaSql = santri_list_select_nama_sql($pdo, 's', 'nama_santri');
    $tingkatanExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
    $joinKelas = '';
    if (!column_exists($pdo, 'santri', 'tingkatan') && column_exists($pdo, 'santri', 'kelas_id') && table_exists($pdo, 'kelas')) {
        $joinKelas = ' LEFT JOIN kelas k ON k.id = s.kelas_id ';
        $tingkatanExpr = 'k.nama_kelas';
    }
    $whereAktif = column_exists($pdo, 'santri', 'is_aktif') ? ' WHERE s.is_aktif = 1 ' : '';
    $orderBy = santri_list_order_sql('s', $pdo);

    $txJoin = '';
    $topupExpr = '0';
    $debitExpr = '0';
    $debitHariExpr = '0';
    if (table_exists($pdo, 'cashless_transactions')) {
        $rangeHari = cashless_tanggal_rentang_harian($tglHari);
        $txJoin = '
            LEFT JOIN (
                SELECT santri_id,
                    COALESCE(SUM(CASE WHEN UPPER(jenis) = \'TOPUP\' THEN nominal ELSE 0 END), 0) AS total_topup,
                    COALESCE(SUM(CASE WHEN UPPER(jenis) = \'DEBIT\' THEN nominal ELSE 0 END), 0) AS total_debit,
                    COALESCE(SUM(CASE WHEN UPPER(jenis) = \'DEBIT\' AND tanggal >= :tgl_hari_start AND tanggal < :tgl_hari_end THEN nominal ELSE 0 END), 0) AS debit_hari_ini
                FROM cashless_transactions
                GROUP BY santri_id
            ) tx ON tx.santri_id = s.id
        ';
        $topupExpr = 'COALESCE(tx.total_topup, 0)';
        $debitExpr = 'COALESCE(tx.total_debit, 0)';
        $debitHariExpr = 'COALESCE(tx.debit_hari_ini, 0)';
    }

    $saldoExpr = table_exists($pdo, 'cashless_transactions')
        ? 'GREATEST(0, ROUND(' . $topupExpr . ' - ' . $debitExpr . '))'
        : 'COALESCE(ca.balance, 0)';

    $sql = '
        SELECT s.id, s.nis, ' . $namaSql . ', ' . $tingkatanExpr . ' AS tingkatan,
               ' . $saldoExpr . ' AS saldo,
               ' . $topupExpr . ' AS total_topup,
               ' . $debitExpr . ' AS total_debit,
               ' . $debitHariExpr . ' AS debit_hari_ini,
               (ca.pin_hash IS NOT NULL AND ca.pin_hash <> \'\') AS pin_terpasang
        FROM santri s
        ' . $joinKelas . '
        LEFT JOIN cashless_accounts ca ON ca.santri_id = s.id
        ' . $txJoin . '
        ' . $whereAktif . '
        ORDER BY ' . $orderBy;

    if (table_exists($pdo, 'cashless_transactions')) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'tgl_hari_start' => $rangeHari['start'],
            'tgl_hari_end' => $rangeHari['end'],
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $totalSaldo = 0;
    $jumlahBersaldo = 0;
    $pinSudah = 0;
    foreach ($rows as &$row) {
        $saldo = max(0, (int) round((float) ($row['saldo'] ?? 0)));
        $debitHari = (int) round((float) ($row['debit_hari_ini'] ?? 0));
        $row['saldo'] = $saldo;
        $row['total_topup'] = (int) round((float) ($row['total_topup'] ?? 0));
        $row['total_debit'] = (int) round((float) ($row['total_debit'] ?? 0));
        $row['debit_hari_ini'] = $debitHari;
        $row['pin_terpasang'] = (int) ($row['pin_terpasang'] ?? 0);
        $row['sisa_jatah_hari'] = max(0, $dailyLimit - $debitHari);
        $totalSaldo += $saldo;
        if ($saldo > 0) {
            $jumlahBersaldo++;
        }
        if ((int) $row['pin_terpasang'] === 1) {
            $pinSudah++;
        }
    }
    unset($row);

    return [
        'rows' => $rows,
        'summary' => [
            'total_santri' => count($rows),
            'total_saldo' => $totalSaldo,
            'jumlah_bersaldo' => $jumlahBersaldo,
            'pin_sudah' => $pinSudah,
            'pin_belum' => max(0, count($rows) - $pinSudah),
        ],
        'daily_limit' => $dailyLimit,
    ];
}

/** Total saldo uang saku seluruh santri aktif — dihitung dari transaksi (top-up − belanja). */
function cashless_saku_total_real(PDO $pdo): array
{
    if (!table_exists($pdo, 'santri')) {
        return ['total' => 0, 'jumlah_santri' => 0, 'jumlah_bersaldo' => 0];
    }
    require_once __DIR__ . '/santri_operasional.php';
    $aktif = santri_sql_aktif_only('s');
    if (table_exists($pdo, 'cashless_transactions')) {
        $rows = $pdo->query("
            SELECT ct.santri_id,
                COALESCE(SUM(CASE WHEN UPPER(ct.jenis) = 'TOPUP' THEN ct.nominal ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN UPPER(ct.jenis) = 'DEBIT' THEN ct.nominal ELSE 0 END), 0) AS saldo
            FROM cashless_transactions ct
            INNER JOIN santri s ON s.id = ct.santri_id AND {$aktif}
            GROUP BY ct.santri_id
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = 0;
        $bersaldo = 0;
        foreach ($rows as $r) {
            $saldo = max(0, (int) round((float) ($r['saldo'] ?? 0)));
            $total += $saldo;
            if ($saldo > 0) {
                $bersaldo++;
            }
        }

        return [
            'total' => $total,
            'jumlah_santri' => count($rows),
            'jumlah_bersaldo' => $bersaldo,
        ];
    }
    if (!table_exists($pdo, 'cashless_accounts')) {
        return ['total' => 0, 'jumlah_santri' => 0, 'jumlah_bersaldo' => 0];
    }
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
 * Saldo santri sudah berkurang saat scan (berdasarkan transaksi).
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
        . '. Kas bendahara berkurang.';
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
 * Hapus satu transaksi debit (super admin). Saldo dikembalikan sesuai transaksi.
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
        require_once __DIR__ . '/keuangan_jurnal.php';
        keuangan_jurnal_delete_by_ref($pdo, 'cashless_debit', $txId);
        cashless_sync_account_balance($pdo, $santriId);
        $pdo->commit();

        return ['ok' => true, 'message' => 'Transaksi dihapus. Saldo dikembalikan Rp ' . number_format($nominal, 0, ',', '.') . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }
}

/**
 * Ubah nominal/keterangan transaksi debit (super admin). Sesuaikan saldo santri dan jurnal.
 *
 * @return array{ok:bool,message:string}
 */
function cashless_koperasi_ubah_debit(PDO $pdo, int $txId, int $newNominal, string $newKeterangan, int $userId): array
{
    cashless_koperasi_ensure_schema($pdo);
    if ($txId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return ['ok' => false, 'message' => 'Transaksi tidak valid.'];
    }
    if ($newNominal <= 0) {
        return ['ok' => false, 'message' => 'Nominal harus lebih dari nol.'];
    }

    $stmt = $pdo->prepare('
        SELECT id, santri_id, jenis, nominal, keterangan, tanggal, setor_at
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
    $oldNominal = (int) round((float) ($row['nominal'] ?? 0));
    $oldKeterangan = trim((string) ($row['keterangan'] ?? ''));
    if ($santriId <= 0 || $oldNominal <= 0) {
        return ['ok' => false, 'message' => 'Data transaksi tidak lengkap.'];
    }
    if ($newNominal === $oldNominal && $newKeterangan === $oldKeterangan) {
        return ['ok' => true, 'message' => 'Tidak ada perubahan.'];
    }

    $diff = $newNominal - $oldNominal;
    if ($diff > 0) {
        $saldoTampil = cashless_santri_saldo_tampil($pdo, $santriId);
        if ($saldoTampil < $diff) {
            $kurang = $diff - $saldoTampil;

            return [
                'ok' => false,
                'message' => 'Saldo santri tidak cukup untuk menaikkan nominal (kurang Rp ' . number_format($kurang, 0, ',', '.') . ').',
            ];
        }
    }

    $tanggalJurnal = date('Y-m-d', strtotime((string) ($row['tanggal'] ?? 'now')));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            UPDATE cashless_transactions
            SET nominal = :nominal, keterangan = :keterangan
            WHERE id = :id
        ')->execute([
            'nominal' => $newNominal,
            'keterangan' => $newKeterangan !== '' ? $newKeterangan : null,
            'id' => $txId,
        ]);
        require_once __DIR__ . '/keuangan_jurnal.php';
        keuangan_jurnal_delete_by_ref($pdo, 'cashless_debit', $txId);
        cashless_jurnal_belanja_scan($pdo, $txId, $tanggalJurnal, $newNominal, $userId, $newKeterangan);
        cashless_sync_account_balance($pdo, $santriId);
        $pdo->commit();

        $msg = 'Transaksi diperbarui.';
        if (!empty($row['setor_at'])) {
            $msg .= ' Catatan: transaksi sudah pernah disetor — periksa kesesuaian setor harian.';
        }

        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal memperbarui: ' . $e->getMessage()];
    }
}

/**
 * Aksi super admin: hapus atau ubah transaksi debit cashless.
 *
 * @return array{ok:bool,message:string}|null null jika action tidak dikenali
 */
function cashless_koperasi_admin_aksi_transaksi(PDO $pdo, string $action, array $post, int $userId, bool $isSuperAdmin): ?array
{
    if (!$isSuperAdmin) {
        return ['ok' => false, 'message' => 'Hanya super admin yang dapat mengubah transaksi cashless.'];
    }

    $txId = (int) ($post['tx_id'] ?? 0);
    if ($action === 'delete_debit_tx') {
        return cashless_koperasi_hapus_debit($pdo, $txId);
    }
    if ($action === 'edit_debit_tx') {
        $nominal = (int) ($post['nominal'] ?? 0);
        $keterangan = trim((string) ($post['keterangan'] ?? ''));

        return cashless_koperasi_ubah_debit($pdo, $txId, $nominal, $keterangan, $userId);
    }

    return null;
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
