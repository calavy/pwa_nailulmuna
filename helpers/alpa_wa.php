<?php

declare(strict_types=1);

require_once __DIR__ . '/alpa_tier.php';
require_once __DIR__ . '/wa_laporan_alpa.php';

/**
 * Query santri dengan jumlah alpa pada periode aktif (sampai tanggal referensi).
 *
 * @return list<array{id:int,nis:string,nama_santri:string,tingkatan:string,alpa_count:int}>
 */
function alpa_wa_query_santri_rows(PDO $pdo, string $tanggal, int $minThreshold = 1): array
{
    ensure_alpa_tier_tables($pdo);
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $mode = alpa_tier_periode_mode($pdo);
    $tanggalMulai = alpa_tier_tanggal_mulai($pdo);
    if ($tanggalMulai !== '' && $tanggal < $tanggalMulai) {
        return [];
    }

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
    $st->execute(array_merge($params, ['min_th' => max(1, $minThreshold)]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'nis' => (string) ($row['nis'] ?? ''),
            'nama_santri' => (string) ($row['nama_santri'] ?? '-'),
            'tingkatan' => (string) ($row['tingkatan'] ?? ''),
            'alpa_count' => (int) ($row['alpa_count'] ?? 0),
        ];
    }, $rows);
}

/**
 * Ringkasan kandidat laporan manual per tier.
 *
 * @return array{
 *   tanggal:string,
 *   periode_label:string,
 *   tiers:list<array{tier_id:int,threshold:int,label:string,wa:string,santri_count:int}>,
 *   total_santri:int,
 *   fallback_count:int
 * }
 */
function alpa_wa_preview_manual(PDO $pdo, string $tanggal): array
{
    $today = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || $tanggal > $today) {
        $tanggal = $today;
    }

    $mode = alpa_tier_periode_mode($pdo);
    $tanggalMulai = alpa_tier_tanggal_mulai($pdo);
    $periodeLabel = alpa_tier_periode_pesan_label($mode, $tanggal, $tanggalMulai);
    $tiers = alpa_tier_ensure_kelipatan_defaults($pdo);

    $minTh = $tiers !== []
        ? min(array_map(static fn(array $t): int => (int) $t['threshold'], $tiers))
        : max(1, (int) app_setting($pdo, 'batas_alpa_notif', '5'));

    $santriRows = alpa_wa_query_santri_rows($pdo, $tanggal, $minTh);
    $tierPreview = [];
    $seenIds = [];

    foreach ($tiers as $tier) {
        $th = (int) $tier['threshold'];
        $count = 0;
        foreach ($santriRows as $row) {
            if ((int) $row['alpa_count'] >= $th) {
                $count++;
                $seenIds[(int) $row['id']] = true;
            }
        }
        $tierPreview[] = [
            'tier_id' => (int) $tier['id'],
            'threshold' => $th,
            'label' => (string) $tier['label'],
            'wa' => alpa_tier_resolve_wa($pdo, (string) $tier['wa']),
            'santri_count' => $count,
        ];
    }

    $fallbackCount = 0;
    if ($tiers === []) {
        $fallbackTh = max(1, (int) app_setting($pdo, 'batas_alpa_notif', '5'));
        foreach ($santriRows as $row) {
            if ((int) $row['alpa_count'] >= $fallbackTh) {
                $fallbackCount++;
            }
        }
    }

    return [
        'tanggal' => $tanggal,
        'periode_label' => $periodeLabel,
        'tiers' => $tierPreview,
        'total_santri' => count($santriRows),
        'fallback_count' => $fallbackCount,
    ];
}

/**
 * Kirim laporan alpa manual (rekap penuh per tier) untuk tanggal referensi.
 *
 * @return array{ok:bool,message:string,sent:int,failed:int,tiers:list<array<string,mixed>>}
 */
function alpa_wa_jalankan_laporan_manual(PDO $pdo, bool $paksa = false, ?string $tanggal = null): array
{
    unset($paksa);

    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return ['ok' => false, 'message' => 'Master WA otomatis nonaktif.', 'sent' => 0, 'failed' => 0, 'tiers' => []];
    }
    $gwErr = wa_otomatis_gateway_error($pdo);
    if ($gwErr !== null) {
        return ['ok' => false, 'message' => 'Gateway WA otomatis tidak siap: ' . $gwErr, 'sent' => 0, 'failed' => 0, 'tiers' => []];
    }

    $today = date('Y-m-d');
    $tanggal = $tanggal !== null ? trim($tanggal) : $today;
    if ($tanggal === '') {
        $tanggal = $today;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Format tanggal laporan tidak valid.', 'sent' => 0, 'failed' => 0, 'tiers' => []];
    }
    if ($tanggal > $today) {
        return ['ok' => false, 'message' => 'Tanggal laporan tidak boleh setelah hari ini.', 'sent' => 0, 'failed' => 0, 'tiers' => []];
    }

    $mode = alpa_tier_periode_mode($pdo);
    $tanggalMulai = alpa_tier_tanggal_mulai($pdo);
    if ($tanggalMulai !== '' && $tanggal < $tanggalMulai) {
        return [
            'ok' => false,
            'message' => 'Tanggal sebelum tanggal mulai hitung alpa (' . $tanggalMulai . ').',
            'sent' => 0,
            'failed' => 0,
            'tiers' => [],
        ];
    }

    $periodeKey = alpa_tier_periode_key($mode, $tanggal);
    $periodeLabel = alpa_tier_periode_pesan_label($mode, $tanggal, $tanggalMulai);
    $tiers = alpa_tier_ensure_kelipatan_defaults($pdo);

    if ($tiers === []) {
        return alpa_wa_jalankan_laporan_manual_fallback($pdo, $tanggal, $periodeLabel, $periodeKey);
    }

    $minTh = min(array_map(static fn(array $t): int => (int) $t['threshold'], $tiers));
    $santriRows = alpa_wa_query_santri_rows($pdo, $tanggal, $minTh);
    if ($santriRows === []) {
        return [
            'ok' => false,
            'message' => 'Tidak ada santri alpa pada periode ' . $periodeLabel . '.',
            'sent' => 0,
            'failed' => 0,
            'tiers' => [],
        ];
    }

    $sentTotal = 0;
    $failedTotal = 0;
    $tierResults = [];

    foreach ($tiers as $tier) {
        $tid = (int) $tier['id'];
        $th = (int) $tier['threshold'];
        $entries = [];
        foreach ($santriRows as $row) {
            if ((int) $row['alpa_count'] >= $th) {
                $entries[] = $row;
            }
        }
        if ($entries === []) {
            continue;
        }

        $wa = alpa_tier_resolve_wa($pdo, (string) $tier['wa']);
        if ($wa === '') {
            $tierResults[] = [
                'tier_id' => $tid,
                'threshold' => $th,
                'label' => (string) $tier['label'],
                'santri_count' => count($entries),
                'sent' => 0,
                'skipped' => true,
                'reason' => 'nomor_kosong',
            ];
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

        $waMessages = wa_format_rekap_alpa_per_santri_messages($pdo, $periodeLabel, $th, $rowsFmt, $tanggal);
        $sent = send_wa_bulk_messages($pdo, $wa, $waMessages, [
            'kind' => 'alpa',
            'skip_dedup' => true,
            'dedup_key' => 'alpa:manual:' . $periodeKey . ':tier:' . $tid,
        ]);

        if ($sent <= 0) {
            $failedTotal++;
        }
        $sentTotal += $sent;
        $tierResults[] = [
            'tier_id' => $tid,
            'threshold' => $th,
            'label' => (string) $tier['label'],
            'santri_count' => count($entries),
            'message_parts' => count($waMessages),
            'sent' => $sent,
        ];
    }

    $stats = [
        'tanggal' => $tanggal,
        'periode_key' => $periodeKey,
        'periode_label' => $periodeLabel,
        'sent' => $sentTotal,
        'failed' => $failedTotal,
        'tiers' => $tierResults,
        'at' => date('Y-m-d H:i:s'),
    ];
    save_setting($pdo, 'alpa_wa_manual_last_stats', json_encode($stats, JSON_UNESCAPED_UNICODE));
    save_setting($pdo, 'alpa_wa_manual_last_sent_at', date('Y-m-d H:i:s'));

    if ($sentTotal <= 0) {
        $hasEmptyWa = false;
        foreach ($tierResults as $tr) {
            if (!empty($tr['skipped'])) {
                $hasEmptyWa = true;
                break;
            }
        }
        $msg = $hasEmptyWa
            ? 'Tidak ada nomor penerima tier yang terisi. Isi nomor WA di tier atau tab penerima alpa.'
            : 'Gagal mengirim laporan alpa manual.';

        return ['ok' => false, 'message' => $msg, 'sent' => 0, 'failed' => $failedTotal, 'tiers' => $tierResults];
    }

    return [
        'ok' => true,
        'message' => 'Laporan alpa manual (' . $periodeLabel . ') terkirim: ' . $sentTotal . ' pesan ke '
            . count(array_filter($tierResults, static fn(array $t): bool => (int) ($t['sent'] ?? 0) > 0)) . ' tier.',
        'sent' => $sentTotal,
        'failed' => $failedTotal,
        'tiers' => $tierResults,
    ];
}

/**
 * Mode fallback tanpa tier: kirim rekap ke wa_pengurus jika alpa >= batas_alpa_notif.
 *
 * @return array{ok:bool,message:string,sent:int,failed:int,tiers:list<array<string,mixed>>}
 */
function alpa_wa_jalankan_laporan_manual_fallback(
    PDO $pdo,
    string $tanggal,
    string $periodeLabel,
    string $periodeKey
): array {
    $threshold = max(1, (int) app_setting($pdo, 'batas_alpa_notif', '5'));
    $wa = function_exists('wa_alpa_notif_target') ? trim(wa_alpa_notif_target($pdo)) : trim((string) app_setting($pdo, 'wa_pengurus', ''));
    if ($wa === '') {
        return [
            'ok' => false,
            'message' => 'Nomor penerima alpa belum diatur.',
            'sent' => 0,
            'failed' => 0,
            'tiers' => [],
        ];
    }

    $santriRows = alpa_wa_query_santri_rows($pdo, $tanggal, $threshold);
    if ($santriRows === []) {
        return [
            'ok' => false,
            'message' => 'Tidak ada santri dengan alpa ≥ ' . $threshold . ' pada periode ' . $periodeLabel . '.',
            'sent' => 0,
            'failed' => 0,
            'tiers' => [],
        ];
    }

    $rowsFmt = [];
    foreach ($santriRows as $e) {
        $rowsFmt[] = [
            'nama_santri' => $e['nama_santri'],
            'nis' => $e['nis'],
            'tingkatan' => $e['tingkatan'],
            'nama_kegiatan' => 'Akumulasi periode',
            'total_alpha' => $e['alpa_count'],
        ];
    }

    $waMessages = wa_format_rekap_alpa_per_santri_messages($pdo, $periodeLabel, $threshold, $rowsFmt, $tanggal);
    $sent = send_wa_bulk_messages($pdo, $wa, $waMessages, [
        'kind' => 'alpa',
        'skip_dedup' => true,
        'dedup_key' => 'alpa:manual:' . $periodeKey . ':fallback',
    ]);

    $tierResults = [[
        'tier_id' => 0,
        'threshold' => $threshold,
        'label' => 'Fallback pengurus',
        'santri_count' => count($santriRows),
        'message_parts' => count($waMessages),
        'sent' => $sent,
    ]];

    $stats = [
        'tanggal' => $tanggal,
        'periode_key' => $periodeKey,
        'periode_label' => $periodeLabel,
        'sent' => $sent,
        'failed' => $sent > 0 ? 0 : 1,
        'tiers' => $tierResults,
        'mode' => 'fallback',
        'at' => date('Y-m-d H:i:s'),
    ];
    save_setting($pdo, 'alpa_wa_manual_last_stats', json_encode($stats, JSON_UNESCAPED_UNICODE));
    save_setting($pdo, 'alpa_wa_manual_last_sent_at', date('Y-m-d H:i:s'));

    return [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? 'Laporan alpa manual (' . $periodeLabel . ') terkirim ke penerima fallback (' . $sent . ' pesan).'
            : 'Gagal mengirim laporan alpa manual.',
        'sent' => $sent,
        'failed' => $sent > 0 ? 0 : 1,
        'tiers' => $tierResults,
    ];
}

/**
 * @return array<string, mixed>
 */
function alpa_wa_manual_status(PDO $pdo): array
{
    $lastStats = json_decode((string) app_setting($pdo, 'alpa_wa_manual_last_stats', ''), true);
    if (!is_array($lastStats)) {
        $lastStats = null;
    }

    $tanggalDefault = date('Y-m-d');
    $preview = alpa_wa_preview_manual($pdo, $tanggalDefault);

    return [
        'last_sent_at' => trim((string) app_setting($pdo, 'alpa_wa_manual_last_sent_at', '')),
        'last_stats' => $lastStats,
        'preview' => $preview,
        'tanggal_default' => $tanggalDefault,
        'tanggal_max' => $tanggalDefault,
    ];
}
