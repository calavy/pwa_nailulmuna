<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Notifikasi Alpa Bertahap (tiered escalation).
 *
 * Konsep:
 * - Admin men-define beberapa tier (ambang batas) di tabel `alpa_tier_notif`,
 *   misal: 5 → Pengurus A, 10 → Pengurus B, 15 → Pengurus C, dst.
 * - Tiap kali ada alpa baru dicatat, sistem menghitung total alpa santri itu
 *   pada **periode aktif** (mingguan/bulanan/akumulatif sejak awal).
 * - Untuk tiap tier yang sudah tercapai (alpa_count >= threshold) DAN belum
 *   pernah dikirim untuk (santri, tier, periode_key) → kirim WA ke nomor tier
 *   itu dan tulis log dispatch.
 *
 * Periode key:
 * - `weekly`  → "YYYY-Www"  (ISO week, contoh: 2026-W21)
 * - `monthly` → "YYYY-MM"   (contoh: 2026-05)
 * - `default` → "ALL"       (akumulasi sejak awal — tidak pernah reset)
 */

/** Mode periode reset perhitungan: weekly | monthly | default. */
function alpa_tier_periode_mode(PDO $pdo): string
{
    $m = strtolower(trim((string) app_setting($pdo, 'alpa_notif_periode_mode', 'monthly')));
    return in_array($m, ['weekly', 'monthly', 'default'], true) ? $m : 'monthly';
}

/**
 * Tanggal awal perhitungan alpa untuk WA (Y-m-d) atau '' jika belum diset.
 * Sumber tunggal: keaktifan_tanggal_mulai_scan (sama dengan rekap poin / keaktivan).
 */
function alpa_tier_tanggal_mulai(PDO $pdo): string
{
    require_once __DIR__ . '/rekap_keaktifan.php';

    return rekap_keaktifan_tanggal_mulai_scan($pdo);
}

/** Label periode untuk UI. */
function alpa_tier_periode_label(string $mode): string
{
    return match ($mode) {
        'weekly' => 'Mingguan (reset tiap minggu)',
        'default' => 'Akumulasi sejak awal (tidak pernah reset)',
        default => 'Bulanan (reset tiap bulan)',
    };
}

/**
 * Key periode untuk satu tanggal.
 *
 * @param string $mode    weekly | monthly | default
 * @param string $tanggal Y-m-d
 */
function alpa_tier_periode_key(string $mode, string $tanggal): string
{
    $ts = strtotime($tanggal) ?: time();
    return match ($mode) {
        'weekly' => date('o', $ts) . '-W' . str_pad((string) ((int) date('W', $ts)), 2, '0', STR_PAD_LEFT),
        'default' => 'ALL',
        default => date('Y-m', $ts),
    };
}

/**
 * Klausa WHERE + parameter untuk menghitung alpa pada window aktif.
 * $tanggalMulai (Y-m-d, opsional) jadi lower-bound tambahan untuk semua mode.
 *
 * @return array{0:string,1:array<string,mixed>}
 */
function alpa_tier_window_where(string $mode, string $tanggal, string $tanggalMulai = ''): array
{
    $ts = strtotime($tanggal) ?: time();
    $where = '';
    $params = [];

    if ($mode === 'weekly') {
        $dow = (int) date('N', $ts);
        $start = date('Y-m-d', strtotime('-' . ($dow - 1) . ' day', $ts));
        $end = date('Y-m-d', strtotime('+' . (7 - $dow) . ' day', $ts));
        $where = ' AND tanggal_presensi BETWEEN :ws AND :we';
        $params = ['ws' => $start, 'we' => $end];
    } elseif ($mode === 'monthly') {
        $where = ' AND DATE_FORMAT(tanggal_presensi, "%Y-%m") = :wm';
        $params = ['wm' => date('Y-m', $ts)];
    }

    if ($tanggalMulai !== '') {
        $where .= ' AND tanggal_presensi >= :tmulai';
        $params['tmulai'] = $tanggalMulai;
    }

    return [$where, $params];
}

/** Buat / pastikan tabel skema ada. Aman dipanggil berulang — gagal tidak fatal. */
function ensure_alpa_tier_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS alpa_tier_notif (
                id INT AUTO_INCREMENT PRIMARY KEY,
                threshold INT NOT NULL,
                label VARCHAR(120) NOT NULL DEFAULT "",
                wa VARCHAR(500) NOT NULL DEFAULT "",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_alpa_tier_threshold (threshold),
                INDEX idx_alpa_tier_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS alpa_tier_dispatch_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                santri_id INT NOT NULL,
                tier_id INT NOT NULL,
                periode_key VARCHAR(20) NOT NULL,
                alpa_count INT NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_santri_tier_periode (santri_id, tier_id, periode_key),
                INDEX idx_santri_periode (santri_id, periode_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } catch (PDOException $e) {
        // Kalau CREATE TABLE gagal (mis. hosting batasi DDL), jangan fatal — fitur tier
        // sebagian tidak jalan, tapi sisa app tetap hidup.
        error_log('[alpa_tier] ensure_alpa_tier_tables: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Ambil daftar tier (urut threshold ASC).
 *
 * @return list<array{id:int,threshold:int,label:string,wa:string,is_active:int}>
 */
function alpa_tier_list(PDO $pdo, bool $activeOnly = true): array
{
    ensure_alpa_tier_tables($pdo);
    if (!table_exists($pdo, 'alpa_tier_notif')) {
        return [];
    }
    $sql = 'SELECT id, threshold, label, wa, is_active FROM alpa_tier_notif';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY threshold ASC, id ASC';
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[alpa_tier] alpa_tier_list: ' . $e->getMessage());
        return [];
    }
    return array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'threshold' => (int) $r['threshold'],
            'label' => (string) $r['label'],
            'wa' => (string) $r['wa'],
            'is_active' => (int) $r['is_active'],
        ];
    }, $rows);
}

/**
 * Jika belum ada tier, seed kelipatan dari batas_alpa_notif (mis. 5 → 5,10,15,20,25).
 *
 * @return list<array{id:int,threshold:int,label:string,wa:string,is_active:int}>
 */
function alpa_tier_ensure_kelipatan_defaults(PDO $pdo): array
{
    $existing = alpa_tier_list($pdo, false);
    if ($existing !== []) {
        return alpa_tier_list($pdo, true);
    }
    if (!table_exists($pdo, 'alpa_tier_notif')) {
        return [];
    }
    $step = max(1, (int) app_setting($pdo, 'batas_alpa_notif', '5'));
    $ins = $pdo->prepare('
        INSERT INTO alpa_tier_notif (threshold, label, wa, is_active)
        VALUES (:t, :l, "", 1)
    ');
    for ($i = 1; $i <= 5; $i++) {
        $th = $step * $i;
        $ins->execute([
            't' => $th,
            'l' => 'Kelipatan ' . $th,
        ]);
    }

    return alpa_tier_list($pdo, true);
}

/** Nomor WA tier, atau fallback pengurus. */
function alpa_tier_resolve_wa(PDO $pdo, string $tierWa): string
{
    $tierWa = trim($tierWa);
    if ($tierWa !== '') {
        return $tierWa;
    }
    if (function_exists('wa_alpa_notif_target')) {
        return trim(wa_alpa_notif_target($pdo));
    }

    return trim((string) app_setting($pdo, 'wa_pengurus', ''));
}

/**
 * Cron/jam otomatis: kirim hanya santri yang baru mencapai ambang (belum di dispatch log).
 *
 * @return array{sent:int,tiers:list<array<string,mixed>>,pending_santri:int,note:string}
 */
function alpa_tier_cron_flush_crossings(PDO $pdo, ?string $tanggal = null): array
{
    $summary = ['sent' => 0, 'tiers' => [], 'pending_santri' => 0, 'note' => ''];
    ensure_alpa_tier_tables($pdo);
    $tiers = alpa_tier_ensure_kelipatan_defaults($pdo);
    if ($tiers === []) {
        $summary['note'] = 'tidak_ada_tier';

        return $summary;
    }

    $tanggal = $tanggal !== null && $tanggal !== '' ? $tanggal : date('Y-m-d');
    $mode = alpa_tier_periode_mode($pdo);
    $tanggalMulai = alpa_tier_tanggal_mulai($pdo);
    if ($tanggalMulai !== '' && $tanggal < $tanggalMulai) {
        $summary['note'] = 'sebelum_tanggal_mulai';

        return $summary;
    }

    $minTh = min(array_map(static fn(array $t): int => (int) $t['threshold'], $tiers));
    [$where, $params] = alpa_tier_window_where($mode, $tanggal, $tanggalMulai);
    $whereFixed = $where;
    if ($whereFixed !== '') {
        $whereFixed = str_replace('tanggal_presensi', 'p.tanggal_presensi', $whereFixed);
        $whereFixed = str_replace('p.p.tanggal_presensi', 'p.tanggal_presensi', $whereFixed);
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $sql = '
        SELECT s.id, s.nis, ' . $nameCol . ' AS nama_santri, s.tingkatan, COUNT(p.id) AS alpa_count
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.status_presensi = "ALPA"' . $whereFixed . '
        GROUP BY s.id, s.nis, ' . $nameCol . ', s.tingkatan
        HAVING alpa_count >= :min_th
        ORDER BY s.tingkatan ASC, alpa_count DESC, nama_santri ASC
        LIMIT 500
    ';
    $st = $pdo->prepare($sql);
    $bind = array_merge($params, ['min_th' => $minTh]);
    $st->execute($bind);
    $santriRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($santriRows === []) {
        $summary['note'] = 'tidak_ada_kandidat';

        return $summary;
    }

    $periodeKey = alpa_tier_periode_key($mode, $tanggal);
    $periodeLabel = alpa_tier_periode_pesan_label($mode, $tanggal, $tanggalMulai);
    $tanggalIdn = function_exists('wa_laporan_alpa_tanggal_label')
        ? wa_laporan_alpa_tanggal_label($tanggal)
        : $tanggal;

    require_once __DIR__ . '/wa_laporan_alpa.php';

    /** @var array<int, list<array{nama_santri:string,nis:string,tingkatan:string,alpa_count:int,santri_id:int}>> $byTier */
    $byTier = [];
    foreach ($santriRows as $row) {
        $sid = (int) ($row['id'] ?? 0);
        $count = (int) ($row['alpa_count'] ?? 0);
        if ($sid <= 0 || $count <= 0) {
            continue;
        }
        foreach ($tiers as $tier) {
            $th = (int) $tier['threshold'];
            $tid = (int) $tier['id'];
            if ($count < $th) {
                continue;
            }
            if (alpa_tier_dispatch_exists($pdo, $sid, $tid, $periodeKey)) {
                continue;
            }
            $byTier[$tid][] = [
                'santri_id' => $sid,
                'nama_santri' => (string) ($row['nama_santri'] ?? '-'),
                'nis' => (string) ($row['nis'] ?? ''),
                'tingkatan' => (string) ($row['tingkatan'] ?? ''),
                'alpa_count' => $count,
            ];
            $summary['pending_santri']++;
        }
    }

    foreach ($tiers as $tier) {
        $tid = (int) $tier['id'];
        $entries = $byTier[$tid] ?? [];
        if ($entries === []) {
            continue;
        }
        $wa = alpa_tier_resolve_wa($pdo, (string) $tier['wa']);
        if ($wa === '') {
            continue;
        }
        $rowsFmt = [];
        foreach ($entries as $e) {
            $rowsFmt[] = [
                'nama_santri' => $e['nama_santri'],
                'nis' => $e['nis'],
                'tingkatan' => $e['tingkatan'],
                'nama_kegiatan' => 'Akumulasi periode',
                'total_alpha' => $e['alpa_count'],
            ];
        }
        $waMessages = wa_format_rekap_alpa_per_santri_messages(
            $pdo,
            $periodeLabel,
            (int) $tier['threshold'],
            $rowsFmt
        );
        $sent = send_wa_bulk_messages($pdo, $wa, $waMessages, ['kind' => 'alpa']);
        if ($sent <= 0) {
            continue;
        }
        foreach ($entries as $entry) {
            alpa_tier_dispatch_record($pdo, (int) $entry['santri_id'], $tid, $periodeKey, (int) $entry['alpa_count']);
        }
        $summary['sent'] += $sent;
        $summary['tiers'][] = [
            'tier_id' => $tid,
            'threshold' => (int) $tier['threshold'],
            'label' => (string) $tier['label'],
            'santri_count' => count($entries),
            'sent' => $sent,
        ];
    }
    $summary['note'] = $summary['sent'] > 0 ? 'ok' : 'tidak_ada_kiriman_baru';

    return $summary;
}

/** Hitung total alpa santri pada periode aktif (memperhitungkan tanggal mulai). */
function alpa_tier_count_alpa(PDO $pdo, int $santriId, string $mode, string $tanggal, string $tanggalMulai = ''): int
{
    [$where, $params] = alpa_tier_window_where($mode, $tanggal, $tanggalMulai);
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM presensi
        WHERE santri_id = :sid AND status_presensi = "ALPA"' . $where
    );
    $stmt->execute(array_merge(['sid' => $santriId], $params));
    return (int) $stmt->fetchColumn();
}

/** Apakah tier untuk (santri, tier, periode_key) sudah pernah dikirim? */
function alpa_tier_dispatch_exists(PDO $pdo, int $santriId, int $tierId, string $periodeKey): bool
{
    if (!table_exists($pdo, 'alpa_tier_dispatch_log')) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM alpa_tier_dispatch_log WHERE santri_id = :s AND tier_id = :t AND periode_key = :p LIMIT 1');
        $stmt->execute(['s' => $santriId, 't' => $tierId, 'p' => $periodeKey]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[alpa_tier] dispatch_exists: ' . $e->getMessage());
        return false;
    }
}

/** Catat dispatch agar tidak dobel kirim. */
function alpa_tier_dispatch_record(PDO $pdo, int $santriId, int $tierId, string $periodeKey, int $alpaCount): void
{
    if (!table_exists($pdo, 'alpa_tier_dispatch_log')) {
        return;
    }
    try {
        $stmt = $pdo->prepare('
            INSERT INTO alpa_tier_dispatch_log (santri_id, tier_id, periode_key, alpa_count)
            VALUES (:s, :t, :p, :c)
            ON DUPLICATE KEY UPDATE alpa_count = VALUES(alpa_count), sent_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute(['s' => $santriId, 't' => $tierId, 'p' => $periodeKey, 'c' => $alpaCount]);
    } catch (PDOException $e) {
        error_log('[alpa_tier] dispatch_record: ' . $e->getMessage());
    }
}

/** Label periode untuk dipakai di pesan WA. */
function alpa_tier_periode_pesan_label(string $mode, string $tanggal, string $tanggalMulai = ''): string
{
    $ts = strtotime($tanggal) ?: time();
    $bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    if ($mode === 'weekly') {
        $dow = (int) date('N', $ts);
        $start = date('d M Y', strtotime('-' . ($dow - 1) . ' day', $ts));
        $end = date('d M Y', strtotime('+' . (7 - $dow) . ' day', $ts));
        $base = 'pekan ini (' . $start . ' – ' . $end . ')';
    } elseif ($mode === 'default') {
        $base = 'kumulatif sejak awal';
    } else {
        $base = 'bulan ' . ($bulanId[(int) date('n', $ts)] ?? date('F', $ts)) . ' ' . date('Y', $ts);
    }

    if ($tanggalMulai !== '') {
        $tsM = strtotime($tanggalMulai) ?: 0;
        if ($tsM > 0) {
            $base .= ', sejak ' . (int) date('j', $tsM) . ' ' . ($bulanId[(int) date('n', $tsM)] ?? date('F', $tsM)) . ' ' . date('Y', $tsM);
        }
    }
    return $base;
}

/**
 * Format pesan WA untuk satu tier.
 *
 * @param array<int, array{nama_santri:string,nis:string,alpa_count:int}> $santriList
 */
function alpa_tier_wa_message(
    PDO $pdo,
    string $tanggalIdn,
    string $tingkatan,
    string $namaKegiatan,
    string $tierLabel,
    int $threshold,
    string $periodeLabel,
    array $santriList
): string {
    $body = wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . "*LAPORAN ALPA — AMBANG " . $threshold . "*\n";
    if (trim($tierLabel) !== '') {
        $body .= 'Penanggung jawab: *' . trim($tierLabel) . "*\n";
    }
    $body .= 'Periode: *' . $periodeLabel . "*\n"
        . 'Tanggal pencatatan: *' . $tanggalIdn . "*\n"
        . 'Tingkatan: *' . $tingkatan . "*\n"
        . 'Kegiatan: *' . $namaKegiatan . "*\n\n"
        . "Santri berikut telah mencapai ambang *" . $threshold . "* kali ALPA:\n\n";

    foreach ($santriList as $s) {
        $nama = (string) ($s['nama_santri'] ?? '-');
        $nis = trim((string) ($s['nis'] ?? ''));
        $n = (int) ($s['alpa_count'] ?? 0);
        $body .= '• ' . $nama;
        if ($nis !== '') {
            $body .= ' (NIS ' . $nis . ')';
        }
        $body .= ': *' . $n . "* kali ALPA\n";
    }

    $body .= "\nMohon ditindaklanjuti sesuai kewenangan.\n"
        . "Demikian disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';

    return $body;
}

/**
 * Setelah generate alpa, kumpulkan santri per tier yang BARU mencapai ambang
 * pada periode aktif, lalu kirim WA per-tier (batched ke 1 nomor) dan log.
 *
 * @param array<int, array{id:int|string,nama_santri?:string,nis?:string}> $santriRows  Santri yang baru saja di-INSERT alpa pada $tanggal.
 * @return array{tiers:list<array{tier_id:int,threshold:int,label:string,wa:string,sent_count:int}>, sent_total:int}
 */
function alpa_tier_dispatch_batch(
    PDO $pdo,
    array $santriRows,
    string $tanggal,
    string $tanggalIdn,
    string $tingkatan,
    string $namaKegiatan
): array {
    ensure_alpa_tier_tables($pdo);
    $tiers = alpa_tier_ensure_kelipatan_defaults($pdo);
    $summary = ['tiers' => [], 'sent_total' => 0];

    if ($tiers === [] || $santriRows === []) {
        return $summary;
    }

    $mode = alpa_tier_periode_mode($pdo);
    $tanggalMulai = alpa_tier_tanggal_mulai($pdo);
    if ($tanggalMulai !== '' && $tanggal < $tanggalMulai) {
        return $summary;
    }
    $periodeKey = alpa_tier_periode_key($mode, $tanggal);
    $periodeLabel = alpa_tier_periode_pesan_label($mode, $tanggal, $tanggalMulai);

    $byTier = [];
    foreach ($santriRows as $santri) {
        $sid = (int) ($santri['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $count = alpa_tier_count_alpa($pdo, $sid, $mode, $tanggal, $tanggalMulai);
        if ($count <= 0) {
            continue;
        }
        foreach ($tiers as $tier) {
            if ($count < $tier['threshold']) {
                continue;
            }
            if (alpa_tier_dispatch_exists($pdo, $sid, $tier['id'], $periodeKey)) {
                continue;
            }
            $byTier[$tier['id']][] = [
                'santri_id' => $sid,
                'nama_santri' => (string) ($santri['nama_santri'] ?? '-'),
                'nis' => (string) ($santri['nis'] ?? ''),
                'alpa_count' => $count,
            ];
        }
    }

    foreach ($tiers as $tier) {
        $tid = $tier['id'];
        $entries = $byTier[$tid] ?? [];
        if ($entries === []) {
            continue;
        }
        $waTarget = alpa_tier_resolve_wa($pdo, (string) $tier['wa']);
        if ($waTarget === '') {
            continue;
        }

        require_once __DIR__ . '/wa_laporan_alpa.php';
        $waMessages = wa_format_alpa_tier_messages(
            $pdo,
            $tanggalIdn,
            $tingkatan,
            $namaKegiatan,
            $tier['label'],
            $tier['threshold'],
            $periodeLabel,
            $entries
        );
        $sent = send_wa_bulk_messages($pdo, $waTarget, $waMessages, ['kind' => 'alpa']);
        if ($sent <= 0) {
            continue;
        }

        foreach ($entries as $entry) {
            alpa_tier_dispatch_record($pdo, (int) $entry['santri_id'], $tid, $periodeKey, (int) $entry['alpa_count']);
        }
        $summary['sent_total'] += $sent;
        $summary['tiers'][] = [
            'tier_id' => $tid,
            'threshold' => $tier['threshold'],
            'label' => $tier['label'],
            'wa' => $waTarget,
            'sent_count' => $sent,
            'santri_count' => count($entries),
        ];
    }

    return $summary;
}
