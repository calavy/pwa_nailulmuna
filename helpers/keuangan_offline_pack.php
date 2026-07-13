<?php

declare(strict_types=1);

/**
 * Paket unduhan database keuangan (baca-saja) untuk IndexedDB di perangkat.
 */

require_once __DIR__ . '/app.php';

/** Versi skema pack — naikkan jika struktur chunk berubah. */
function keuangan_offline_pack_schema_version(): string
{
    return 'keuangan-offline-v1';
}

/**
 * Definisi chunk unduhan.
 *
 * @return list<array{
 *   id:string,
 *   label:string,
 *   table:string,
 *   pk:string,
 *   columns:?list<string>,
 *   exclude_columns:list<string>,
 *   date_column:?string,
 *   settings_prefix:?list<string>,
 *   order:string
 * }>
 */
function keuangan_offline_pack_chunk_defs(): array
{
    return [
        [
            'id' => 'akun',
            'label' => 'Akun kas/bank',
            'table' => 'keuangan_akun',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'alokasi',
            'label' => 'Alokasi dana',
            'table' => 'keuangan_alokasi',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'kelas_keuangan',
            'label' => 'Kelas keuangan',
            'table' => 'kelas_keuangan',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'settings',
            'label' => 'Pengaturan keuangan',
            'table' => 'app_settings',
            'pk' => 'setting_key',
            'columns' => ['setting_key', 'setting_value'],
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => ['keuangan_', 'cashless_'],
            'order' => 'setting_key ASC',
        ],
        [
            'id' => 'santri',
            'label' => 'Santri (ringkas)',
            'table' => 'santri',
            'pk' => 'id',
            'columns' => null, // diisi dinamis
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'pembayaran',
            'label' => 'Pembayaran',
            'table' => 'keuangan_pembayaran',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => 'tanggal_bayar',
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'pembayaran_detail',
            'label' => 'Detail pembayaran',
            'table' => 'keuangan_pembayaran_detail',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'pemasukan',
            'label' => 'Pemasukan',
            'table' => 'keuangan_pemasukan',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => 'tanggal',
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'pengeluaran',
            'label' => 'Pengeluaran',
            'table' => 'keuangan_pengeluaran',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => 'tanggal',
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'cashless_accounts',
            'label' => 'Saldo cashless',
            'table' => 'cashless_accounts',
            'pk' => 'santri_id',
            'columns' => null,
            'exclude_columns' => ['pin_hash'],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'santri_id ASC',
        ],
        [
            'id' => 'cashless_transactions',
            'label' => 'Transaksi cashless',
            'table' => 'cashless_transactions',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => 'tanggal',
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'cashless_koperasi',
            'label' => 'Master koperasi',
            'table' => 'cashless_koperasi',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => ['password_hash', 'pin_hash', 'password'],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'cashless_nominal_qr_map',
            'label' => 'Peta QR nominal',
            'table' => 'cashless_nominal_qr_map',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'cashless_setor_log',
            'label' => 'Log setor cashless',
            'table' => 'cashless_setor_log',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'tarif_bulanan',
            'label' => 'Tarif bulanan',
            'table' => 'keuangan_tarif_bulanan',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'santri_opsional',
            'label' => 'Opsional santri',
            'table' => 'keuangan_santri_opsional',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'syahriyah_potongan',
            'label' => 'Potongan syahriyah',
            'table' => 'keuangan_santri_syahriyah_potongan',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'syahriyah_potongan_jeda',
            'label' => 'Jeda potongan',
            'table' => 'keuangan_syahriyah_potongan_jeda',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'tagihan_masuk',
            'label' => 'Riwayat tagihan masuk',
            'table' => 'santri_tagihan_masuk_riwayat',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'tagihan_khusus',
            'label' => 'Tagihan khusus',
            'table' => 'keuangan_tagihan_khusus',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'talangan',
            'label' => 'Talangan internal',
            'table' => 'keuangan_talangan_internal',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'coa',
            'label' => 'Chart of accounts',
            'table' => 'akuntansi_chart_of_accounts',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'jurnal',
            'label' => 'Jurnal umum',
            'table' => 'akuntansi_jurnal_umum',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => 'tanggal',
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'aset_tetap',
            'label' => 'Aset tetap',
            'table' => 'akuntansi_aset_tetap',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'jurnal_penyesuaian',
            'label' => 'Jurnal penyesuaian',
            'table' => 'akuntansi_jurnal_penyesuaian',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'gaji',
            'label' => 'Gaji pembimbing',
            'table' => 'keuangan_gaji_pembimbing',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => 'tanggal_bayar',
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'tarif_payroll',
            'label' => 'Tarif payroll',
            'table' => 'tarif_payroll_pembimbing',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
        [
            'id' => 'snapshots',
            'label' => 'Snapshot laporan',
            'table' => '_snapshots',
            'pk' => 'id',
            'columns' => null,
            'exclude_columns' => [],
            'date_column' => null,
            'settings_prefix' => null,
            'order' => 'id ASC',
        ],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function keuangan_offline_pack_chunk_map(): array
{
    $map = [];
    foreach (keuangan_offline_pack_chunk_defs() as $def) {
        $map[(string) $def['id']] = $def;
    }

    return $map;
}

/** @return list<string> */
function keuangan_offline_pack_santri_columns(PDO $pdo): array
{
    $wanted = [
        'id', 'nis', 'nama_santri', 'nama', 'tingkatan', 'kategori_kelas',
        'kelas_id', 'is_aktif', 'status', 'qr', 'jenis_kelamin',
    ];
    $out = [];
    foreach ($wanted as $col) {
        if (column_exists($pdo, 'santri', $col)) {
            $out[] = $col;
        }
    }
    if ($out === [] && table_exists($pdo, 'santri')) {
        $out = ['id'];
    }

    return $out;
}

/**
 * @param array<string, mixed> $def
 * @return list<string>
 */
function keuangan_offline_pack_resolve_columns(PDO $pdo, array $def): array
{
    $table = (string) ($def['table'] ?? '');
    if ($table === '_snapshots') {
        return ['id', 'kind', 'payload', 'generated_at'];
    }
    if ($table === 'santri') {
        return keuangan_offline_pack_santri_columns($pdo);
    }
    if (!table_exists($pdo, $table)) {
        return [];
    }
    $exclude = array_map('strtolower', (array) ($def['exclude_columns'] ?? []));
    if (is_array($def['columns'] ?? null) && $def['columns'] !== []) {
        $cols = [];
        foreach ($def['columns'] as $c) {
            $c = (string) $c;
            if ($c !== '' && column_exists($pdo, $table, $c) && !in_array(strtolower($c), $exclude, true)) {
                $cols[] = $c;
            }
        }

        return $cols;
    }

    $cols = [];
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field === '' || in_array(strtolower($field), $exclude, true)) {
                continue;
            }
            $cols[] = $field;
        }
    } catch (Throwable $e) {
        return [];
    }

    return $cols;
}

function keuangan_offline_pack_years_default(): int
{
    return 2;
}

/**
 * Fingerprint versi pack dari agregat tabel.
 */
function keuangan_offline_pack_version(PDO $pdo, ?string $sinceDate = null): string
{
    $parts = [keuangan_offline_pack_schema_version()];
    foreach (keuangan_offline_pack_chunk_defs() as $def) {
        $table = (string) $def['table'];
        if ($table === '_snapshots' || !table_exists($pdo, $table)) {
            $parts[] = $def['id'] . ':0';
            continue;
        }
        $pk = (string) $def['pk'];
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
        $safePk = preg_replace('/[^a-zA-Z0-9_]/', '', $pk) ?? $pk;
        try {
            if ($table === 'app_settings') {
                $st = $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'keuangan_%' OR setting_key LIKE 'cashless_%'");
                $parts[] = 'settings:' . (int) ($st ? $st->fetchColumn() : 0);
                continue;
            }
            $where = '';
            $params = [];
            $dateCol = $def['date_column'] ?? null;
            if ($sinceDate !== null && $sinceDate !== '' && is_string($dateCol) && $dateCol !== '' && column_exists($pdo, $table, $dateCol)) {
                $safeDate = preg_replace('/[^a-zA-Z0-9_]/', '', $dateCol) ?? $dateCol;
                $where = " WHERE `{$safeDate}` >= :since";
                $params['since'] = $sinceDate;
            }
            $sql = "SELECT COUNT(*) AS c, COALESCE(MAX(`{$safePk}`), 0) AS m FROM `{$safeTable}`{$where}";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $parts[] = $def['id'] . ':' . (int) ($row['c'] ?? 0) . ':' . (string) ($row['m'] ?? '0');
        } catch (Throwable $e) {
            $parts[] = $def['id'] . ':err';
        }
    }

    return substr(hash('sha256', implode('|', $parts)), 0, 24);
}

/**
 * @return array{
 *   ok:bool,
 *   schema_version:string,
 *   pack_version:string,
 *   generated_at:string,
 *   years:int,
 *   since_date:string,
 *   chunks:list<array<string,mixed>>,
 *   table_counts:array<string,int>,
 *   approx_bytes:int
 * }
 */
function keuangan_offline_pack_meta(PDO $pdo, ?int $years = null, bool $allTime = false): array
{
    $years = $years ?? keuangan_offline_pack_years_default();
    $years = max(1, min(20, $years));
    $sinceDate = $allTime ? '2000-01-01' : date('Y-m-d', strtotime('-' . $years . ' years') ?: time());
    $packVersion = keuangan_offline_pack_version($pdo, $allTime ? null : $sinceDate);
    $chunks = [];
    $counts = [];
    $approx = 0;

    foreach (keuangan_offline_pack_chunk_defs() as $def) {
        $id = (string) $def['id'];
        $table = (string) $def['table'];
        $count = 0;
        $exists = $table === '_snapshots' || table_exists($pdo, $table);
        if ($table === '_snapshots') {
            $count = 4;
        } elseif ($exists) {
            try {
                if ($table === 'app_settings') {
                    $st = $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'keuangan_%' OR setting_key LIKE 'cashless_%'");
                    $count = (int) ($st ? $st->fetchColumn() : 0);
                } elseif (
                    !$allTime
                    && $id === 'pembayaran_detail'
                    && table_exists($pdo, 'keuangan_pembayaran')
                    && column_exists($pdo, 'keuangan_pembayaran', 'tanggal_bayar')
                    && column_exists($pdo, 'keuangan_pembayaran_detail', 'pembayaran_id')
                ) {
                    $st = $pdo->prepare('
                        SELECT COUNT(*)
                        FROM keuangan_pembayaran_detail d
                        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
                        WHERE p.tanggal_bayar >= :since
                    ');
                    $st->execute(['since' => $sinceDate]);
                    $count = (int) $st->fetchColumn();
                } else {
                    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
                    $where = '';
                    $params = [];
                    $dateCol = $def['date_column'] ?? null;
                    if (!$allTime && is_string($dateCol) && $dateCol !== '' && column_exists($pdo, $table, $dateCol)) {
                        $safeDate = preg_replace('/[^a-zA-Z0-9_]/', '', $dateCol) ?? $dateCol;
                        $where = " WHERE `{$safeDate}` >= :since";
                        $params['since'] = $sinceDate;
                    }
                    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$safeTable}`{$where}");
                    $st->execute($params);
                    $count = (int) $st->fetchColumn();
                }
            } catch (Throwable $e) {
                $count = 0;
            }
        }
        $counts[$id] = $count;
        // Estimasi kasar ~180 byte/baris JSON
        $approx += $count * 180;
        $chunks[] = [
            'id' => $id,
            'label' => (string) $def['label'],
            'table' => $table,
            'exists' => $exists,
            'count' => $count,
            'pk' => (string) $def['pk'],
        ];
    }

    return [
        'ok' => true,
        'schema_version' => keuangan_offline_pack_schema_version(),
        'pack_version' => $packVersion,
        'generated_at' => date('c'),
        'years' => $years,
        'all_time' => $allTime,
        'since_date' => $sinceDate,
        'chunks' => $chunks,
        'table_counts' => $counts,
        'approx_bytes' => $approx,
        'chunk_limit' => keuangan_offline_pack_chunk_limit(),
    ];
}

function keuangan_offline_pack_chunk_limit(): int
{
    return 2000;
}

/**
 * @return array<string, mixed>
 */
function keuangan_offline_pack_fetch_chunk(
    PDO $pdo,
    string $chunkId,
    int $afterId = 0,
    ?string $afterKey = null,
    ?int $years = null,
    bool $allTime = false,
    ?int $limit = null
): array {
    $map = keuangan_offline_pack_chunk_map();
    if (!isset($map[$chunkId])) {
        return ['ok' => false, 'message' => 'Chunk tidak dikenal: ' . $chunkId];
    }
    $def = $map[$chunkId];
    $limit = $limit ?? keuangan_offline_pack_chunk_limit();
    $limit = max(1, min(5000, $limit));
    $years = $years ?? keuangan_offline_pack_years_default();
    $years = max(1, min(20, $years));
    $sinceDate = $allTime ? '2000-01-01' : date('Y-m-d', strtotime('-' . $years . ' years') ?: time());

    if ($chunkId === 'snapshots') {
        return keuangan_offline_pack_build_snapshots($pdo, $afterId, $sinceDate);
    }

    $table = (string) $def['table'];
    if (!table_exists($pdo, $table)) {
        return [
            'ok' => true,
            'chunk' => $chunkId,
            'table' => $table,
            'pk' => (string) $def['pk'],
            'rows' => [],
            'has_more' => false,
            'next_after_id' => 0,
            'next_after_key' => null,
            'since_date' => $sinceDate,
        ];
    }

    $cols = keuangan_offline_pack_resolve_columns($pdo, $def);
    if ($cols === []) {
        return [
            'ok' => true,
            'chunk' => $chunkId,
            'table' => $table,
            'pk' => (string) $def['pk'],
            'rows' => [],
            'has_more' => false,
            'next_after_id' => 0,
            'next_after_key' => null,
            'since_date' => $sinceDate,
        ];
    }

    $pk = (string) $def['pk'];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
    $safePk = preg_replace('/[^a-zA-Z0-9_]/', '', $pk) ?? $pk;
    $selectCols = implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '', $c) . '`', $cols));
    // Prefiks alias jika join parent (detail pembayaran).
    $selectPrefix = '';

    $whereParts = [];
    $params = [];
    $fromSql = "`{$safeTable}`";

    if ($table === 'app_settings') {
        $whereParts[] = "(setting_key LIKE 'keuangan_%' OR setting_key LIKE 'cashless_%')";
        if ($afterKey !== null && $afterKey !== '') {
            $whereParts[] = 'setting_key > :after_key';
            $params['after_key'] = $afterKey;
        }
    } else {
        if ($afterId > 0) {
            $whereParts[] = "`{$safePk}` > :after_id";
            $params['after_id'] = $afterId;
        }
        $dateCol = $def['date_column'] ?? null;
        if (!$allTime && is_string($dateCol) && $dateCol !== '' && column_exists($pdo, $table, $dateCol)) {
            $safeDate = preg_replace('/[^a-zA-Z0-9_]/', '', $dateCol) ?? $dateCol;
            $whereParts[] = "`{$safeDate}` >= :since";
            $params['since'] = $sinceDate;
        }
        // Detail pembayaran: batasi lewat tanggal parent agar tidak unduh seluruh history.
        if (
            !$allTime
            && $chunkId === 'pembayaran_detail'
            && table_exists($pdo, 'keuangan_pembayaran')
            && column_exists($pdo, 'keuangan_pembayaran', 'tanggal_bayar')
            && column_exists($pdo, 'keuangan_pembayaran_detail', 'pembayaran_id')
        ) {
            $fromSql = '`keuangan_pembayaran_detail` d INNER JOIN `keuangan_pembayaran` p ON p.id = d.pembayaran_id';
            $selectPrefix = 'd.';
            $selectCols = implode(', ', array_map(
                static fn (string $c): string => 'd.`' . str_replace('`', '', $c) . '`',
                $cols
            ));
            $whereParts = [];
            if ($afterId > 0) {
                $whereParts[] = 'd.`id` > :after_id';
                $params['after_id'] = $afterId;
            }
            $whereParts[] = 'p.`tanggal_bayar` >= :since';
            $params['since'] = $sinceDate;
        }
    }

    $whereSql = $whereParts !== [] ? (' WHERE ' . implode(' AND ', $whereParts)) : '';
    if ($selectPrefix === 'd.') {
        $order = 'd.id ASC';
    } else {
        $order = $table === 'app_settings' ? 'setting_key ASC' : (string) ($def['order'] ?? ($safePk . ' ASC'));
    }
    $sql = "SELECT {$selectCols} FROM {$fromSql}{$whereSql} ORDER BY {$order} LIMIT " . ($limit + 1);

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Gagal membaca ' . $chunkId . ': ' . $e->getMessage()];
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }

    $nextAfterId = 0;
    $nextAfterKey = null;
    if ($rows !== []) {
        $last = $rows[count($rows) - 1];
        if ($table === 'app_settings') {
            $nextAfterKey = (string) ($last['setting_key'] ?? '');
        } else {
            $nextAfterId = (int) ($last[$pk] ?? 0);
        }
    }

    return [
        'ok' => true,
        'chunk' => $chunkId,
        'table' => $table,
        'pk' => $pk,
        'columns' => $cols,
        'rows' => $rows,
        'has_more' => $hasMore,
        'next_after_id' => $nextAfterId,
        'next_after_key' => $nextAfterKey,
        'since_date' => $sinceDate,
        'count' => count($rows),
    ];
}

/**
 * Snapshot laporan siap tampil offline (satu "halaman" chunk).
 *
 * @return array<string, mixed>
 */
function keuangan_offline_pack_build_snapshots(PDO $pdo, int $afterId, string $sinceDate): array
{
    // afterId dipakai sebagai indeks snapshot (0 = mulai)
    $kinds = ['neraca', 'arus_kas', 'riwayat_ringkas', 'cashless_ringkas'];
    if ($afterId >= count($kinds)) {
        return [
            'ok' => true,
            'chunk' => 'snapshots',
            'table' => '_snapshots',
            'pk' => 'id',
            'rows' => [],
            'has_more' => false,
            'next_after_id' => $afterId,
            'next_after_key' => null,
            'since_date' => $sinceDate,
        ];
    }

    $kind = $kinds[$afterId];
    $payload = null;
    $generatedAt = date('c');

    try {
        if ($kind === 'neraca') {
            require_once __DIR__ . '/keuangan_neraca.php';
            $neraca = keuangan_build_neraca($pdo, date('Y-m-d'));
            $payload = [
                'as_of' => $neraca['as_of'] ?? date('Y-m-d'),
                'as_of_label' => $neraca['as_of_label'] ?? '',
                'nama_lembaga' => $neraca['nama_lembaga'] ?? '',
                'selisih' => (int) ($neraca['selisih'] ?? 0),
                'total_aset' => (int) ($neraca['aset']['total'] ?? 0),
                'total_pasiva' => (int) ($neraca['total_pasiva'] ?? 0),
                'ringkasan' => $neraca['ringkasan'] ?? [],
                'aset' => $neraca['aset'] ?? [],
                'liabilitas' => $neraca['liabilitas'] ?? [],
                'aset_neto' => $neraca['aset_neto'] ?? [],
            ];
        } elseif ($kind === 'arus_kas') {
            require_once __DIR__ . '/keuangan_aruskas.php';
            $dari = date('Y-m-01');
            $sampai = date('Y-m-d');
            $lak = keuangan_build_arus_kas($pdo, $dari, $sampai);
            $ops = is_array($lak['operasi'] ?? null) ? $lak['operasi'] : [];
            $payload = [
                'date_from' => $lak['date_from'] ?? $dari,
                'date_to' => $lak['date_to'] ?? $sampai,
                'nama_lembaga' => $lak['nama_lembaga'] ?? '',
                'kas_awal' => (int) ($lak['kas_awal'] ?? 0),
                'kas_akhir' => (int) ($lak['kas_akhir'] ?? 0),
                'total_masuk' => (int) ($ops['total_masuk'] ?? $lak['total_masuk'] ?? 0),
                'total_keluar' => (int) ($ops['total_keluar'] ?? $lak['total_keluar'] ?? 0),
                'operasi' => $ops,
                'sections' => $lak['sections'] ?? [],
                'ringkasan' => $lak['ringkasan'] ?? [],
            ];
        } elseif ($kind === 'riwayat_ringkas') {
            require_once __DIR__ . '/keuangan_riwayat_pembayaran.php';
            $dari = date('Y-m-01');
            $sampai = date('Y-m-d');
            $filter = keuangan_riwayat_pembayaran_parse_filter([
                'dari' => $dari,
                'sampai' => $sampai,
            ]);
            $data = keuangan_riwayat_pembayaran_fetch($pdo, $filter);
            $rows = array_slice((array) ($data['rows'] ?? []), 0, 200);
            $payload = [
                'dari' => $dari,
                'sampai' => $sampai,
                'total_rows' => count((array) ($data['rows'] ?? [])),
                'rows' => $rows,
                'total_masuk' => (int) ($data['total_masuk'] ?? 0),
                'total_keluar' => (int) ($data['total_keluar'] ?? 0),
                'jumlah_masuk' => (int) ($data['jumlah_masuk'] ?? 0),
                'jumlah_keluar' => (int) ($data['jumlah_keluar'] ?? 0),
            ];
        } elseif ($kind === 'cashless_ringkas') {
            require_once __DIR__ . '/cashless_koperasi.php';
            if (function_exists('cashless_koperasi_ensure_schema')) {
                cashless_koperasi_ensure_schema($pdo);
            }
            $dari = date('Y-m-01');
            $sampai = date('Y-m-d');
            $payload = [
                'dari' => $dari,
                'sampai' => $sampai,
                'per_koperasi' => function_exists('cashless_koperasi_laporan_per_koperasi')
                    ? cashless_koperasi_laporan_per_koperasi($pdo, $dari, $sampai)
                    : [],
                'ringkas' => function_exists('cashless_koperasi_laporan_ringkas')
                    ? cashless_koperasi_laporan_ringkas($pdo, null, $dari, $sampai)
                    : [],
            ];
        }
    } catch (Throwable $e) {
        $payload = ['error' => $e->getMessage()];
    }

    $row = [
        'id' => $afterId + 1,
        'kind' => $kind,
        'payload' => $payload,
        'generated_at' => $generatedAt,
    ];

    return [
        'ok' => true,
        'chunk' => 'snapshots',
        'table' => '_snapshots',
        'pk' => 'id',
        'rows' => [$row],
        'has_more' => ($afterId + 1) < count($kinds),
        'next_after_id' => $afterId + 1,
        'next_after_key' => null,
        'since_date' => $sinceDate,
        'count' => 1,
    ];
}

/**
 * Mulai output JSON (opsional gzip).
 *
 * @param array<string, mixed> $payload
 */
function keuangan_offline_pack_json_response(array $payload, bool $preferGzip = true): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo '{"ok":false,"message":"Gagal encode JSON"}';
        return;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    if ($preferGzip && function_exists('gzencode') && str_contains($accept, 'gzip') && strlen($json) > 2048) {
        $gz = gzencode($json, 6);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            echo $gz;
            return;
        }
    }
    echo $json;
}
