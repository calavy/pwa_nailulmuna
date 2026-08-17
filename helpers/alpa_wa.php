<?php

declare(strict_types=1);

require_once __DIR__ . '/alpa_tier.php';
require_once __DIR__ . '/wa_laporan_alpa.php';

function alpa_wa_row_status_is_alpa(string $status): bool
{
    $st = strtoupper(trim($status));

    return $st === 'ALPA' || $st === 'ISTIRAHAT';
}

/**
 * Agregasi jumlah alpa per santri (slot terjadwal, selaras rekap presensi).
 *
 * @return list<array{id:int,nis:string,nama_santri:string,tingkatan:string,alpa_count:int,total_poin:int}>
 */
function alpa_wa_fetch_santri_alpa_rows(PDO $pdo, string $tanggal): array
{
    static $cache = [];
    if (isset($cache[$tanggal])) {
        return $cache[$tanggal];
    }

    ensure_alpa_tier_tables($pdo);
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return $cache[$tanggal] = [];
    }

    $mode = alpa_tier_periode_mode($pdo);
    $tanggalMulai = alpa_tier_tanggal_mulai($pdo);
    if ($tanggalMulai !== '' && $tanggal < $tanggalMulai) {
        return $cache[$tanggal] = [];
    }

    $range = alpa_tier_window_range($mode, $tanggal, $tanggalMulai);
    $start = (string) $range['start'];
    $end = (string) $range['end'];
    if ($start === '' || $end === '' || $start > $end) {
        return $cache[$tanggal] = [];
    }

    require_once __DIR__ . '/presensi_jadwal.php';
    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    presensi_finalize_date_range($pdo, $start, $end, $auditUserId > 0 ? $auditUserId : 1);

    $rows = presensi_fetch_rows_rekap($pdo, $start, $end, 0, null, false);

    /** @var array<int, array{id:int,nis:string,nama_santri:string,tingkatan:string,alpa_count:int,total_poin:int}> $bySantri */
    $bySantri = [];
    foreach ($rows as $row) {
        if (!alpa_wa_row_status_is_alpa((string) ($row['status_presensi'] ?? ''))) {
            continue;
        }
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySantri[$sid])) {
            $bySantri[$sid] = [
                'id' => $sid,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? '-'),
                'tingkatan' => (string) ($row['tingkatan'] ?? ''),
                'alpa_count' => 0,
                'total_poin' => 0,
            ];
        }
        $bySantri[$sid]['alpa_count']++;
    }

    $pointPerAlpa = max(1, (int) app_setting($pdo, 'point_auto_alpa', '5'));
    $poinMap = alpa_wa_fetch_total_poin_map($pdo, $start, $end);
    foreach ($bySantri as $sid => &$entry) {
        $fromLedger = (int) ($poinMap[$sid] ?? 0);
        $entry['total_poin'] = $fromLedger > 0
            ? $fromLedger
            : ((int) $entry['alpa_count'] * $pointPerAlpa);
    }
    unset($entry);

    $cache[$tanggal] = array_values($bySantri);

    return $cache[$tanggal];
}

/**
 * Total poin ALPA/telat per santri dari point_ledger pada rentang tanggal.
 *
 * @return array<int, int>
 */
function alpa_wa_fetch_total_poin_map(PDO $pdo, string $start, string $end): array
{
    if (!table_exists($pdo, 'point_ledger') || $start === '' || $end === '') {
        return [];
    }

    $st = $pdo->prepare('
        SELECT santri_id, COALESCE(SUM(point_delta), 0) AS total_poin
        FROM point_ledger
        WHERE tanggal BETWEEN :a AND :b
          AND sumber_data IN ("PRESENSI_ALPA_AUTO", "PRESENSI_TELAT_AUTO")
        GROUP BY santri_id
    ');
    $st->execute(['a' => $start, 'b' => $end]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $map[$sid] = (int) ($row['total_poin'] ?? 0);
        }
    }

    return $map;
}

/** Ambil nilai poin untuk perbandingan tier (total_poin jika ada, fallback alpa×setting). */
function alpa_wa_row_poin_value(PDO $pdo, array $row): int
{
    $totalPoin = (int) ($row['total_poin'] ?? 0);
    if ($totalPoin > 0) {
        return $totalPoin;
    }
    $alpaCount = (int) ($row['alpa_count'] ?? 0);
    if ($alpaCount <= 0) {
        return 0;
    }

    return $alpaCount * max(1, (int) app_setting($pdo, 'point_auto_alpa', '5'));
}

/**
 * Peta santri_id → jumlah alpa pada periode aktif.
 *
 * @return array<int, int>
 */
function alpa_wa_santri_alpa_count_map(PDO $pdo, string $tanggal): array
{
    $map = [];
    foreach (alpa_wa_fetch_santri_alpa_rows($pdo, $tanggal) as $row) {
        $map[(int) ($row['id'] ?? 0)] = (int) ($row['alpa_count'] ?? 0);
    }

    return $map;
}

/**
 * Query santri dengan jumlah alpa pada periode aktif (sampai tanggal referensi).
 *
 * @return list<array{id:int,nis:string,nama_santri:string,tingkatan:string,alpa_count:int}>
 */
function alpa_wa_query_santri_rows(PDO $pdo, string $tanggal, int $minThreshold = 1): array
{
    $minThreshold = max(1, $minThreshold);
    $rows = alpa_wa_fetch_santri_alpa_rows($pdo, $tanggal);
    $rows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => alpa_wa_row_poin_value($pdo, $row) >= $minThreshold
    ));

    usort($rows, static function (array $a, array $b) use ($pdo): int {
        $cmp = strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmpPoin = alpa_wa_row_poin_value($pdo, $b) <=> alpa_wa_row_poin_value($pdo, $a);
        if ($cmpPoin !== 0) {
            return $cmpPoin;
        }

        return strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
    });

    if (count($rows) > 500) {
        $rows = array_slice($rows, 0, 500);
    }

    return $rows;
}

/**
 * Filter baris santri alpa per kelompok putra/putri (selaras rekap ALPA).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function alpa_wa_filter_rows_by_kelompok(array $rows, string $kelompok): array
{
    require_once __DIR__ . '/rekap_alpa_santri.php';

    return rekap_alpa_filter_rows($rows, $kelompok);
}

/**
 * Rencana batch kirim WA per tier: satu batch jika tier punya nomor, else pecah putra/putri.
 *
 * @param list<array<string, mixed>> $entries
 * @return list<array{kelompok:?string,entries:list<array<string,mixed>>,wa:string}>
 */
function alpa_wa_build_tier_send_batches(PDO $pdo, string $tierWa, array $entries): array
{
    if ($entries === []) {
        return [];
    }

    $tierWa = trim($tierWa);
    if ($tierWa !== '') {
        $wa = alpa_tier_resolve_wa($pdo, $tierWa);
        if ($wa === '') {
            return [];
        }

        return [['kelompok' => null, 'entries' => $entries, 'wa' => $wa]];
    }

    require_once __DIR__ . '/rekap_alpa_santri.php';
    $batches = [];
    foreach (rekap_alpa_kelompok_valid() as $kelompok) {
        $filtered = rekap_alpa_filter_rows($entries, $kelompok);
        if ($filtered === []) {
            continue;
        }
        $wa = alpa_tier_resolve_wa($pdo, '', $kelompok);
        if ($wa === '') {
            continue;
        }
        $batches[] = ['kelompok' => $kelompok, 'entries' => $filtered, 'wa' => $wa];
    }

    return $batches;
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
    require_once __DIR__ . '/rekap_alpa_santri.php';

    foreach ($tiers as $tier) {
        $th = (int) $tier['threshold'];
        $count = 0;
        $countPutra = 0;
        $countPutri = 0;
        foreach ($santriRows as $row) {
            if (alpa_wa_row_poin_value($pdo, $row) >= $th) {
                $count++;
                $seenIds[(int) $row['id']] = true;
                if (rekap_alpa_row_matches_kelompok($row, 'putra')) {
                    $countPutra++;
                }
                if (rekap_alpa_row_matches_kelompok($row, 'putri')) {
                    $countPutri++;
                }
            }
        }
        $tierWa = trim((string) $tier['wa']);
        $tierPreview[] = [
            'tier_id' => (int) $tier['id'],
            'threshold' => $th,
            'label' => (string) $tier['label'],
            'wa' => $tierWa !== '' ? alpa_tier_resolve_wa($pdo, $tierWa) : '',
            'wa_putra' => $tierWa !== '' ? '' : alpa_tier_resolve_wa($pdo, '', 'putra'),
            'wa_putri' => $tierWa !== '' ? '' : alpa_tier_resolve_wa($pdo, '', 'putri'),
            'santri_count' => $count,
            'santri_count_putra' => $countPutra,
            'santri_count_putri' => $countPutri,
        ];
    }

    $fallbackCount = 0;
    if ($tiers === []) {
        $fallbackTh = max(1, (int) app_setting($pdo, 'batas_alpa_notif', '5'));
        foreach ($santriRows as $row) {
            if (alpa_wa_row_poin_value($pdo, $row) >= $fallbackTh) {
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
            if (alpa_wa_row_poin_value($pdo, $row) >= $th) {
                $entries[] = $row;
            }
        }
        if ($entries === []) {
            continue;
        }

        $batches = alpa_wa_build_tier_send_batches($pdo, (string) $tier['wa'], $entries);
        if ($batches === []) {
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

        $tierSent = 0;
        $tierMessageParts = 0;
        foreach ($batches as $batch) {
            $batchEntries = $batch['entries'];
            $wa = (string) $batch['wa'];
            $kelompok = $batch['kelompok'] ?? null;
            $rowsFmt = [];
            foreach ($batchEntries as $e) {
                $poin = alpa_wa_row_poin_value($pdo, $e);
                $rowsFmt[] = [
                    'nama_santri' => $e['nama_santri'],
                    'nis' => $e['nis'],
                    'tingkatan' => $e['tingkatan'],
                    'nama_kegiatan' => 'Akumulasi periode',
                    'total_alpha' => $poin,
                    'total_poin' => $poin,
                ];
            }

            $waMessages = wa_format_rekap_alpa_per_santri_messages($pdo, $periodeLabel, $th, $rowsFmt, $tanggal);
            $dedupSuffix = $kelompok !== null && $kelompok !== '' ? $kelompok : (string) $tid;
            $sent = send_wa_bulk_messages($pdo, $wa, $waMessages, [
                'kind' => 'alpa',
                'skip_dedup' => true,
                'dedup_key' => 'alpa:manual:' . $periodeKey . ':tier:' . $tid . ':' . $dedupSuffix,
            ]);

            if ($sent <= 0) {
                $failedTotal++;
            }
            $tierSent += $sent;
            $tierMessageParts += count($waMessages);
            $sentTotal += $sent;
        }

        $tierResults[] = [
            'tier_id' => $tid,
            'threshold' => $th,
            'label' => (string) $tier['label'],
            'santri_count' => count($entries),
            'message_parts' => $tierMessageParts,
            'sent' => $tierSent,
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
    require_once __DIR__ . '/rekap_alpa_santri.php';

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

    $sentTotal = 0;
    $failedTotal = 0;
    $tierResults = [];

    foreach (rekap_alpa_kelompok_valid() as $kelompok) {
        $filtered = rekap_alpa_filter_rows($santriRows, $kelompok);
        if ($filtered === []) {
            continue;
        }
        $wa = function_exists('wa_alpa_notif_target_kelompok')
            ? trim(wa_alpa_notif_target_kelompok($pdo, $kelompok))
            : trim((string) app_setting($pdo, 'wa_pengurus', ''));
        if ($wa === '') {
            $tierResults[] = [
                'tier_id' => 0,
                'threshold' => $threshold,
                'label' => 'Fallback ' . rekap_alpa_kelompok_label($kelompok),
                'kelompok' => $kelompok,
                'santri_count' => count($filtered),
                'sent' => 0,
                'skipped' => true,
                'reason' => 'nomor_kosong',
            ];
            continue;
        }

        $rowsFmt = [];
        foreach ($filtered as $e) {
            $poin = alpa_wa_row_poin_value($pdo, $e);
            $rowsFmt[] = [
                'nama_santri' => $e['nama_santri'],
                'nis' => $e['nis'],
                'tingkatan' => $e['tingkatan'],
                'nama_kegiatan' => 'Akumulasi periode',
                'total_alpha' => $poin,
                'total_poin' => $poin,
            ];
        }

        $waMessages = wa_format_rekap_alpa_per_santri_messages($pdo, $periodeLabel, $threshold, $rowsFmt, $tanggal);
        $sent = send_wa_bulk_messages($pdo, $wa, $waMessages, [
            'kind' => 'alpa',
            'skip_dedup' => true,
            'dedup_key' => 'alpa:manual:' . $periodeKey . ':fallback:' . $kelompok,
        ]);

        if ($sent <= 0) {
            $failedTotal++;
        }
        $sentTotal += $sent;
        $tierResults[] = [
            'tier_id' => 0,
            'threshold' => $threshold,
            'label' => 'Fallback ' . rekap_alpa_kelompok_label($kelompok),
            'kelompok' => $kelompok,
            'santri_count' => count($filtered),
            'message_parts' => count($waMessages),
            'sent' => $sent,
        ];
    }

    if ($tierResults === []) {
        return [
            'ok' => false,
            'message' => 'Nomor penerima alpa belum diatur.',
            'sent' => 0,
            'failed' => 0,
            'tiers' => [],
        ];
    }

    $stats = [
        'tanggal' => $tanggal,
        'periode_key' => $periodeKey,
        'periode_label' => $periodeLabel,
        'sent' => $sentTotal,
        'failed' => $failedTotal,
        'tiers' => $tierResults,
        'mode' => 'fallback',
        'at' => date('Y-m-d H:i:s'),
    ];
    save_setting($pdo, 'alpa_wa_manual_last_stats', json_encode($stats, JSON_UNESCAPED_UNICODE));
    save_setting($pdo, 'alpa_wa_manual_last_sent_at', date('Y-m-d H:i:s'));

    return [
        'ok' => $sentTotal > 0,
        'message' => $sentTotal > 0
            ? 'Laporan alpa manual (' . $periodeLabel . ') terkirim (' . $sentTotal . ' pesan).'
            : 'Gagal mengirim laporan alpa manual.',
        'sent' => $sentTotal,
        'failed' => $failedTotal,
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
