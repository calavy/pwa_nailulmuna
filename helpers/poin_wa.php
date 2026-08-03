<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * WA otomatis ke pengurus saat total poin bulanan mencapai ambang (5, 10, 15, …).
 * Tiap ambang punya jam kirim sendiri. Data ledger lama tidak dihapus.
 */

function poin_wa_notif_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'poin_wa_notif_enabled', '1')) === '1';
}

function ensure_poin_tier_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS poin_tier_notif (
                id INT AUTO_INCREMENT PRIMARY KEY,
                threshold INT NOT NULL,
                label VARCHAR(120) NOT NULL DEFAULT "",
                wa VARCHAR(500) NOT NULL DEFAULT "",
                jam_kirim VARCHAR(5) NOT NULL DEFAULT "",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_poin_tier_threshold (threshold),
                INDEX idx_poin_tier_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS poin_tier_dispatch_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                santri_id INT NOT NULL,
                tier_id INT NOT NULL,
                periode_key VARCHAR(20) NOT NULL,
                poin_total INT NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_poin_santri_tier_periode (santri_id, tier_id, periode_key),
                INDEX idx_poin_santri_periode (santri_id, periode_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        poin_tier_seed_defaults($pdo);
    } catch (PDOException $e) {
        error_log('[poin_wa] ensure_poin_tier_tables: ' . $e->getMessage());
    }
    $done = true;
}

function poin_tier_seed_defaults(PDO $pdo): void
{
    if (!table_exists($pdo, 'poin_tier_notif')) {
        return;
    }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM poin_tier_notif')->fetchColumn();
    if ($n > 0) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT INTO poin_tier_notif (threshold, label, wa, jam_kirim, is_active)
        VALUES (:t, :l, "", :j, 1)
    ');
    foreach ([
        [5, 'Ambang 5 poin', '07:00'],
        [10, 'Ambang 10 poin', '07:00'],
        [15, 'Ambang 15 poin', '07:00'],
    ] as $row) {
        $ins->execute(['t' => $row[0], 'l' => $row[1], 'j' => $row[2]]);
    }
}

/**
 * @return list<array{id:int,threshold:int,label:string,wa:string,jam_kirim:string,is_active:int}>
 */
function poin_tier_list(PDO $pdo, bool $activeOnly = true): array
{
    ensure_poin_tier_tables($pdo);
    if (!table_exists($pdo, 'poin_tier_notif')) {
        return [];
    }
    $sql = 'SELECT id, threshold, label, wa, jam_kirim, is_active FROM poin_tier_notif';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY threshold ASC, id ASC';
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'threshold' => (int) $r['threshold'],
            'label' => (string) $r['label'],
            'wa' => (string) $r['wa'],
            'jam_kirim' => poin_tier_normalize_jam((string) ($r['jam_kirim'] ?? '')),
            'is_active' => (int) $r['is_active'],
        ];
    }

    return $out;
}

function poin_tier_normalize_jam(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    return '';
}

/** Apakah jam sekarang sudah melewati jam kirim tier (kosong = langsung). */
function poin_tier_jam_ok(string $jamKirim, ?string $nowHi = null): bool
{
    $jam = poin_tier_normalize_jam($jamKirim);
    if ($jam === '') {
        return true;
    }
    require_once __DIR__ . '/datetime_display.php';

    return app_jam_sudah_lewat($jam, $nowHi);
}

function poin_tier_periode_key(?string $tanggal = null): string
{
    $ts = $tanggal !== null && $tanggal !== '' ? (strtotime($tanggal) ?: time()) : time();

    return date('Y-m', $ts);
}

/**
 * Total poin terhitung periode bulan (hormati filter auto-presensi sebelum tanggal mulai scan).
 */
function poin_tier_total_bulan(PDO $pdo, int $santriId, ?string $tanggal = null): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'point_ledger')) {
        return 0;
    }
    if (!function_exists('rekap_poin_presensi_eligible_sql')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    $ts = $tanggal !== null && $tanggal !== '' ? (strtotime($tanggal) ?: time()) : time();
    $start = date('Y-m-01', $ts);
    $end = date('Y-m-t', $ts);
    $eligible = rekap_poin_presensi_eligible_sql($pdo, 'pl');
    $st = $pdo->prepare('
        SELECT COALESCE(SUM(pl.point_delta), 0)
        FROM point_ledger pl
        WHERE pl.santri_id = :sid
          AND pl.tanggal BETWEEN :a AND :b
          ' . $eligible . '
    ');
    $st->execute(['sid' => $santriId, 'a' => $start, 'b' => $end]);

    return (int) $st->fetchColumn();
}

function poin_tier_dispatch_exists(PDO $pdo, int $santriId, int $tierId, string $periodeKey): bool
{
    if (!table_exists($pdo, 'poin_tier_dispatch_log')) {
        return false;
    }
    try {
        $st = $pdo->prepare('
            SELECT 1 FROM poin_tier_dispatch_log
            WHERE santri_id = :s AND tier_id = :t AND periode_key = :p LIMIT 1
        ');
        $st->execute(['s' => $santriId, 't' => $tierId, 'p' => $periodeKey]);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function poin_tier_dispatch_record(PDO $pdo, int $santriId, int $tierId, string $periodeKey, int $poinTotal): void
{
    if (!table_exists($pdo, 'poin_tier_dispatch_log')) {
        return;
    }
    try {
        $st = $pdo->prepare('
            INSERT INTO poin_tier_dispatch_log (santri_id, tier_id, periode_key, poin_total)
            VALUES (:s, :t, :p, :c)
            ON DUPLICATE KEY UPDATE poin_total = VALUES(poin_total), sent_at = CURRENT_TIMESTAMP
        ');
        $st->execute(['s' => $santriId, 't' => $tierId, 'p' => $periodeKey, 'c' => $poinTotal]);
    } catch (PDOException $e) {
        error_log('[poin_wa] dispatch_record: ' . $e->getMessage());
    }
}

function poin_tier_wa_targets(PDO $pdo, string $tierWa): string
{
    $tierWa = trim($tierWa);
    if ($tierWa !== '') {
        return $tierWa;
    }

    return wa_alpa_notif_target($pdo);
}

function poin_tier_format_message(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $tingkatan,
    int $threshold,
    int $poinTotal,
    string $periodeLabel,
    string $tierLabel
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $msg = wa_template_render($pdo, 'poin_ambang_pengurus', [
        'nama_santri' => $namaSantri,
        'nis' => $nis !== '' ? $nis : '-',
        'tingkatan' => $tingkatan !== '' ? $tingkatan : '-',
        'ambang' => (string) $threshold,
        'total_poin' => (string) $poinTotal,
        'periode' => $periodeLabel,
        'label_tier' => $tierLabel !== '' ? $tierLabel : ('Ambang ' . $threshold),
        'nama_ponpes' => app_brand_nama_ponpes($pdo),
    ]);
    if (trim($msg) !== '') {
        return $msg;
    }

    return wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . "*NOTIFIKASI POIN KEDISIPLINAN*\n"
        . 'Ambang: *' . $threshold . "* poin\n"
        . 'Periode: *' . $periodeLabel . "*\n\n"
        . 'Santri: *' . $namaSantri . "*\n"
        . ($nis !== '' ? 'NIS: *' . $nis . "*\n" : '')
        . ($tingkatan !== '' ? 'Tingkatan: *' . $tingkatan . "*\n" : '')
        . 'Total poin bulan ini: *' . $poinTotal . "*\n\n"
        . "Mohon ditindaklanjuti.\n\n"
        . '_Sistem Informasi_';
}

/**
 * Cek & kirim WA untuk santri yang mencapai ambang (hormati jam per tier).
 *
 * @return array{sent:int,pending:int}
 */
function poin_wa_maybe_notify_santri(PDO $pdo, int $santriId, ?string $tanggalRef = null): array
{
    $result = ['sent' => 0, 'pending' => 0];
    if ($santriId <= 0 || !poin_wa_notif_enabled($pdo)) {
        return $result;
    }
    if (function_exists('wa_otomatis_gateway_error') && wa_otomatis_gateway_error($pdo) !== null) {
        return $result;
    }

    ensure_poin_tier_tables($pdo);
    $tiers = poin_tier_list($pdo, true);
    if ($tiers === []) {
        return $result;
    }

    $tanggalRef = $tanggalRef !== null && $tanggalRef !== '' ? $tanggalRef : date('Y-m-d');
    $periodeKey = poin_tier_periode_key($tanggalRef);
    $total = poin_tier_total_bulan($pdo, $santriId, $tanggalRef);
    if ($total <= 0) {
        return $result;
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare('SELECT nis, ' . $nameCol . ' AS nama_santri, tingkatan FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $santri = $st->fetch(PDO::FETCH_ASSOC);
    if (!$santri) {
        return $result;
    }

    $bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($tanggalRef) ?: time();
    $periodeLabel = ($bulanId[(int) date('n', $ts)] ?? date('F', $ts)) . ' ' . date('Y', $ts);
    $nama = (string) ($santri['nama_santri'] ?? '-');
    $nis = trim((string) ($santri['nis'] ?? ''));
    $tingkat = trim((string) ($santri['tingkatan'] ?? ''));

    foreach ($tiers as $tier) {
        $threshold = (int) $tier['threshold'];
        $tierId = (int) $tier['id'];
        if ($total < $threshold) {
            continue;
        }
        if (poin_tier_dispatch_exists($pdo, $santriId, $tierId, $periodeKey)) {
            continue;
        }
        if (!poin_tier_jam_ok((string) $tier['jam_kirim'])) {
            $result['pending']++;
            continue;
        }
        $targets = poin_tier_wa_targets($pdo, (string) $tier['wa']);
        if (trim($targets) === '') {
            continue;
        }
        $msg = poin_tier_format_message(
            $pdo,
            $nama,
            $nis,
            $tingkat,
            $threshold,
            $total,
            $periodeLabel,
            (string) $tier['label']
        );
        $sent = send_wa_bulk($pdo, $targets, $msg, [
            'kind' => 'poin',
            'dedup_key' => 'poin:' . $periodeKey . ':tier:' . $tierId . ':santri:' . $santriId,
        ]);
        if ($sent > 0) {
            poin_tier_dispatch_record($pdo, $santriId, $tierId, $periodeKey, $total);
            $result['sent'] += $sent;
        }
    }

    return $result;
}

/**
 * Cron: kirim notifikasi yang tertunda karena belum masuk jam kirim.
 *
 * @return array{checked:int,sent:int,pending:int}
 */
function poin_wa_cron_flush(PDO $pdo): array
{
    $out = ['checked' => 0, 'sent' => 0, 'pending' => 0];
    if (!poin_wa_notif_enabled($pdo)) {
        return $out;
    }
    ensure_poin_tier_tables($pdo);
    $tiers = poin_tier_list($pdo, true);
    if ($tiers === []) {
        return $out;
    }
    $minThreshold = min(array_map(static fn(array $t): int => (int) $t['threshold'], $tiers));
    if ($minThreshold <= 0) {
        return $out;
    }

    if (!function_exists('rekap_poin_presensi_eligible_sql')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    $start = date('Y-m-01');
    $end = date('Y-m-t');
    $eligible = rekap_poin_presensi_eligible_sql($pdo, 'pl');
    $st = $pdo->prepare('
        SELECT pl.santri_id, COALESCE(SUM(pl.point_delta), 0) AS total_poin
        FROM point_ledger pl
        WHERE pl.tanggal BETWEEN :a AND :b
        ' . $eligible . '
        GROUP BY pl.santri_id
        HAVING total_poin >= :min_t
    ');
    $st->execute(['a' => $start, 'b' => $end, 'min_t' => $minThreshold]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $out['checked']++;
        $r = poin_wa_maybe_notify_santri($pdo, $sid, date('Y-m-d'));
        $out['sent'] += (int) $r['sent'];
        $out['pending'] += (int) $r['pending'];
    }
    save_setting($pdo, 'poin_wa_last_cron_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'poin_wa_last_cron_stats', json_encode($out, JSON_UNESCAPED_UNICODE));

    return $out;
}
