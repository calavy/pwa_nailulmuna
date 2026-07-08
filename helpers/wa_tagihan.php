<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_otomatis.php';
require_once __DIR__ . '/santri_wa.php';

/**
 * Konteks jadwal kirim WA tagihan otomatis hari ini.
 *
 * @return array{
 *   enabled:bool,
 *   calendar:string,
 *   due_day:int,
 *   send_time:string,
 *   send_time_ok:bool,
 *   today:string,
 *   today_day:int,
 *   period_key:string,
 *   send_key:string,
 *   is_send_day:bool,
 *   is_custom_masehi:bool,
 *   last_period_key:string,
 *   last_sent_at:string,
 *   period_already_sent:bool
 * }
 */
function wa_tagihan_jadwal_context(PDO $pdo, ?string $tanggal = null): array
{
    $today = $tanggal ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
        $today = date('Y-m-d');
    }
    $enabled = trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) === '1';
    $calendar = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
    if (!in_array($calendar, ['MASEHI', 'HIJRIYAH'], true)) {
        $calendar = 'HIJRIYAH';
    }
    $dueDay = max(1, min(30, (int) app_setting($pdo, 'wa_tagihan_day', '5')));
    $sendTime = trim((string) app_setting($pdo, 'wa_tagihan_send_time', '08:00'));
    $sendTimeOk = true;
    if ($sendTime !== '' && preg_match('/^\d{2}:\d{2}$/', $sendTime)) {
        $sendTimeOk = date('H:i') >= $sendTime;
    }
    $customRaw = trim((string) app_setting($pdo, 'wa_tagihan_custom_masehi_dates', ''));

    $todayDay = wa_tagihan_tanggal_hari($pdo, $today, $calendar);
    $periodKey = $calendar === 'HIJRIYAH' ? wa_tagihan_hijri_period_key($pdo, $today) : date('Y-m', strtotime($today));
    $isCustomMasehi = false;
    $isSendDay = false;

    if ($calendar === 'MASEHI') {
        $customDates = wa_tagihan_parse_custom_masehi_dates($customRaw);
        if ($customDates !== []) {
            $isCustomMasehi = in_array($today, $customDates, true);
            $isSendDay = $isCustomMasehi;
            if ($isSendDay) {
                $periodKey = $today;
            }
        } else {
            $isSendDay = $todayDay === $dueDay;
        }
    } else {
        $isSendDay = $todayDay === $dueDay;
    }

    $hasCustomMasehiDates = $calendar === 'MASEHI' && wa_tagihan_parse_custom_masehi_dates($customRaw) !== [];
    $recurring = wa_tagihan_recurring_enabled($pdo) && !$hasCustomMasehiDates;
    if ($recurring) {
        $isSendDay = $todayDay >= 1;
    }

    $sendKey = ($isCustomMasehi ? 'MASEHI_CUSTOM' : $calendar) . ':' . $periodKey;
    if ($recurring) {
        $sendKey .= ':' . $today;
    }
    $lastKey = trim((string) app_setting($pdo, 'wa_tagihan_last_period_key', ''));
    $lastSentDate = trim((string) app_setting($pdo, 'wa_tagihan_last_sent_date', ''));
    $periodAlreadySent = $recurring
        ? $lastSentDate === $today
        : $lastKey === $sendKey;

    return [
        'enabled' => $enabled,
        'calendar' => $calendar,
        'due_day' => $dueDay,
        'send_time' => $sendTime,
        'send_time_ok' => $sendTimeOk,
        'today' => $today,
        'today_day' => $todayDay,
        'period_key' => $periodKey,
        'send_key' => $sendKey,
        'is_send_day' => $isSendDay,
        'is_custom_masehi' => $isCustomMasehi,
        'recurring' => $recurring,
        'kumulatif' => wa_tagihan_kumulatif_enabled($pdo),
        'last_period_key' => $lastKey,
        'last_sent_at' => trim((string) app_setting($pdo, 'wa_tagihan_last_sent_at', '')),
        'last_sent_date' => $lastSentDate,
        'period_already_sent' => $periodAlreadySent,
    ];
}

function wa_tagihan_kumulatif_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_tagihan_kumulatif', '1')) === '1';
}

function wa_tagihan_recurring_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_tagihan_recurring', '0')) === '1';
}

/** Kunci periode hijriyah YYYY-MM dari pemetaan pondok (selaras perhitungan hari ke-N). */
function wa_tagihan_hijri_period_key(PDO $pdo, string $tanggalMasehi): string
{
    require_once __DIR__ . '/akademik.php';

    return akademik_hijri_ym_untuk_masehi($pdo, $tanggalMasehi);
}

/**
 * Simpan pengaturan WA tagihan (toggle & mode kirim). Jadwal kalender di Kalender Pondok.
 *
 * @return array{ok:bool,message:string}
 */
function wa_tagihan_jadwal_simpan(PDO $pdo, array $post): array
{
    if (array_key_exists('wa_tagihan_auto_enabled', $post)) {
        save_setting($pdo, 'wa_tagihan_auto_enabled', (string) ((int) ($post['wa_tagihan_auto_enabled'] ?? 0) === 1 ? 1 : 0));
    }
    save_setting($pdo, 'wa_tagihan_kumulatif', isset($post['wa_tagihan_kumulatif']) ? '1' : '0');
    save_setting($pdo, 'wa_tagihan_recurring', isset($post['wa_tagihan_recurring']) ? '1' : '0');

    return ['ok' => true, 'message' => 'Pengaturan WA tagihan disimpan.'];
}

/**
 * Status tagihan santri untuk kirim WA (bulan tunggal atau kumulatif TA).
 *
 * @param array<int, array<string, int>>|null $paidMap
 * @return array<string, mixed>
 */
function wa_tagihan_santri_status(
    PDO $pdo,
    int $santriId,
    string $kelas,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    ?array $paidMap = null,
    ?array $syCtx = null
): array {
    if (!function_exists('tagihan_wajib_status_kumulatif_ta')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    if (wa_tagihan_kumulatif_enabled($pdo)) {
        return tagihan_wajib_status_kumulatif_ta($pdo, $santriId, $bulanTagihan, $tahunMulai, $tahunSelesai, $kelas);
    }
    if ($paidMap === null || $syCtx === null) {
        $ctx = tagihan_bulanan_page_context($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
        $paidMap = $ctx['paid_map'];
        $syCtx = $ctx['sy_ctx'];
    }

    return tagihan_wajib_status_for_month_bulk(
        $pdo,
        $santriId,
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $kelas,
        $paidMap,
        $syCtx
    );
}

/** Label periode tagihan untuk teks WA. */
function wa_tagihan_periode_label_dari_status(PDO $pdo, array $status): string
{
    $perBulan = $status['per_bulan'] ?? null;
    if (is_array($perBulan) && $perBulan !== []) {
        if (count($perBulan) === 1) {
            return (string) ($perBulan[0]['label'] ?? '');
        }
        $first = (string) ($perBulan[0]['label'] ?? '');
        $last = (string) ($perBulan[count($perBulan) - 1]['label'] ?? '');

        return $first !== '' && $last !== '' ? $first . ' s.d. ' . $last : $first;
    }
    $bulanAkhir = (int) ($status['bulan_akhir'] ?? 0);
    $tm = (int) ($status['tahun_mulai'] ?? 0);
    $ts = (int) ($status['tahun_selesai'] ?? 0);
    if ($bulanAkhir > 0 && $tm > 0 && $ts > 0) {
        return pondok_bulan_label($pdo, $bulanAkhir, $tm, $ts);
    }

    return '';
}

/**
 * @param list<array{slug:string,nama:string,nominal:int}> $components
 * @param array<string, mixed> $status
 */
function wa_tagihan_format_pesan_santri(PDO $pdo, string $namaSantri, array $components, array $status): string
{
    $labelKekurangan = wa_tagihan_label_kekurangan($components, (array) ($status['per_pos'] ?? []));
    $periode = wa_tagihan_periode_label_dari_status($pdo, $status);

    return wa_format_tagihan_otomatis_wali(
        $pdo,
        $namaSantri,
        $labelKekurangan,
        (int) ($status['sisa_total'] ?? 0),
        $periode
    );
}

/** Hari dalam bulan (1–30), hijriyah dari kalender pondok bila perlu. */
function wa_tagihan_tanggal_hari(PDO $pdo, string $tanggalMasehi, string $calendarMode): int
{
    if ($calendarMode === 'HIJRIYAH') {
        require_once __DIR__ . '/akademik.php';
        $hijri = akademik_hijri_tanggal_penuh($pdo, $tanggalMasehi);
        if (preg_match('/^\d{4}-\d{2}-(\d{2})$/', $hijri, $m)) {
            return max(1, min(30, (int) $m[1]));
        }
    }

    return (int) date('j', strtotime($tanggalMasehi) ?: time());
}

/** @return list<int> */
function wa_tagihan_sent_ids_load(PDO $pdo, string $sendKey): array
{
    $raw = trim((string) app_setting($pdo, 'wa_tagihan_sent_ids:' . md5($sendKey), ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_values(array_filter(array_map('intval', $decoded))) : [];
}

/** @param list<int> $ids */
function wa_tagihan_sent_ids_save(PDO $pdo, string $sendKey, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    save_setting($pdo, 'wa_tagihan_sent_ids:' . md5($sendKey), json_encode($ids, JSON_UNESCAPED_UNICODE));
}

function wa_tagihan_sent_ids_clear(PDO $pdo, string $sendKey): void
{
    save_setting($pdo, 'wa_tagihan_sent_ids:' . md5($sendKey), '');
    save_setting($pdo, 'wa_tagihan_batch_offset:' . md5($sendKey), '0');
}

/**
 * Jalankan kirim WA tagihan (otomatis atau manual paksa).
 *
 * @return array{ok:bool,sent:int,failed:int,skipped:int,eligible:int,message:string,blocked_reason?:string}
 */
function wa_tagihan_jalankan_kirim(PDO $pdo, bool $paksaTanpaJadwal = false, ?int $bulanTagihan = null): array
{
    if (!function_exists('keuangan_tahun_ajaran_aktif')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    if (!function_exists('tagihan_bulanan_page_context')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    if (!function_exists('pondok_periode_berjalan')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }

    $gwErr = wa_otomatis_gateway_error($pdo);
    if ($gwErr !== null) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => $gwErr, 'blocked_reason' => 'gateway'];
    }

    ensure_santri_identity_columns($pdo);
    if (!table_exists($pdo, 'keuangan_pembayaran')) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Tabel keuangan belum tersedia.'];
    }

    $ctx = wa_tagihan_jadwal_context($pdo);
    if (!$paksaTanpaJadwal) {
        if (!$ctx['enabled']) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'WA tagihan otomatis nonaktif.', 'blocked_reason' => 'disabled'];
        }
        if (!$ctx['is_send_day']) {
            return [
                'ok' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'message' => 'Bukan hari jadwal kirim (hari ke-' . (int) $ctx['due_day'] . ', hari ini ke-' . (int) $ctx['today_day'] . ').',
                'blocked_reason' => 'not_send_day',
            ];
        }
        if (!$paksaTanpaJadwal && !$ctx['send_time_ok']) {
            return [
                'ok' => false,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'message' => 'Belum jam kirim (jadwal: ' . (string) $ctx['send_time'] . ').',
                'blocked_reason' => 'before_send_time',
            ];
        }
        if ($ctx['period_already_sent']) {
            $sudahMsg = !empty($ctx['recurring'])
                ? 'Hari ini sudah dikirim (' . (string) ($ctx['last_sent_at'] ?: $ctx['last_sent_date']) . ').'
                : 'Periode ini sudah dikirim (' . (string) $ctx['last_sent_at'] . ').';

            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'message' => $sudahMsg,
                'blocked_reason' => 'already_sent',
            ];
        }
    }

    $periodeBerjalan = pondok_periode_berjalan($pdo);
    $bulan = $bulanTagihan ?? max(1, min(12, (int) ($periodeBerjalan['bulan'] ?? 1)));
    $periodeTa = keuangan_tahun_ajaran_aktif($pdo);
    $tahunMulai = (int) ($periodeTa['mulai'] ?? 0);
    $tahunSelesai = (int) ($periodeTa['selesai'] ?? 0);

    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $classExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : "''");
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(is_aktif, 1) = 1 ' : '';
    $waCols = 'id, nis, ' . $nameExpr . ' AS nama_santri, ' . $classExpr . ' AS kategori_kelas';
    if (column_exists($pdo, 'santri', 'no_wa_wali')) {
        $waCols .= ', no_wa_wali';
    }
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $waCols .= ', wali_santri_id';
    }
    foreach (['nama_ayah', 'no_kontak_ayah', 'nama_ibu', 'no_kontak_ibu'] as $col) {
        if (column_exists($pdo, 'santri', $col)) {
            $waCols .= ', ' . $col;
        }
    }
    $sendKey = (string) ($ctx['send_key'] ?? '');
    $sentIds = (!$paksaTanpaJadwal && $sendKey !== '') ? wa_tagihan_sent_ids_load($pdo, $sendKey) : [];
    $sentIdMap = array_fill_keys($sentIds, true);

    $batchSize = 500;
    $offsetKey = 'wa_tagihan_batch_offset:' . md5($sendKey);
    $offset = $paksaTanpaJadwal ? 0 : max(0, (int) app_setting($pdo, $offsetKey, '0'));

    $stmt = $pdo->prepare('SELECT ' . $waCols . ' FROM santri WHERE 1=1 ' . $activeExpr . ' ORDER BY id ASC LIMIT ' . $batchSize . ' OFFSET ' . $offset);
    $stmt->execute();
    $santriRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($santriRows === [] && $offset === 0) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Tidak ada santri aktif.'];
    }
    if ($santriRows === [] && $offset > 0) {
        if (!$paksaTanpaJadwal && $sendKey !== '') {
            save_setting($pdo, $offsetKey, '0');
            save_setting($pdo, 'wa_tagihan_last_period_key', $sendKey);
            wa_tagihan_sent_ids_clear($pdo, $sendKey);
        }

        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Batch selesai — semua santri sudah diproses.'];
    }

    $kumulatif = wa_tagihan_kumulatif_enabled($pdo);
    $tagihanCtx = $kumulatif ? null : tagihan_bulanan_page_context($pdo, $bulan, $tahunMulai, $tahunSelesai);
    $paidMap = $tagihanCtx['paid_map'] ?? null;
    $syCtx = $tagihanCtx['sy_ctx'] ?? null;
    $tingkatanMap = $tagihanCtx['tingkatan_map'] ?? null;
    if (!is_array($tingkatanMap) && function_exists('santri_tingkatan_map_for_ta')) {
        require_once __DIR__ . '/santri_ta.php';
        $tingkatanMap = santri_tingkatan_map_for_ta($pdo, $tahunMulai, $tahunSelesai);
    }

    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $eligible = 0;

    foreach ($santriRows as $row) {
        $santriId = (int) ($row['id'] ?? 0);
        if ($santriId <= 0) {
            continue;
        }
        if (isset($sentIdMap[$santriId])) {
            $skipped++;
            continue;
        }
        if (function_exists('keuangan_santri_kelas_tagihan')) {
            require_once __DIR__ . '/santri_ta.php';
        }
        $kelas = function_exists('keuangan_santri_kelas_tagihan')
            ? keuangan_santri_kelas_tagihan($pdo, $santriId, $tahunMulai, $tahunSelesai, $row, is_array($tingkatanMap) ? $tingkatanMap : null)
            : trim((string) ($row['kategori_kelas'] ?? ''));
        $components = keuangan_tagihan_wajib_components($pdo, $kelas);
        if ($components === []) {
            $skipped++;
            continue;
        }
        $st = wa_tagihan_santri_status($pdo, $santriId, $kelas, $bulan, $tahunMulai, $tahunSelesai, $paidMap, $syCtx);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        if ($sisa <= 0) {
            $skipped++;
            continue;
        }
        $eligible++;
        $nama = trim((string) ($row['nama_santri'] ?? 'Santri'));
        $message = wa_tagihan_format_pesan_santri($pdo, $nama, $components, $st);
        $phone = wa_otomatis_santri_wali_phone($pdo, $row);
        if ($phone === '') {
            $failed++;
            continue;
        }
        $result = send_wa_message_with_result($pdo, $phone, $message);
        if ($result['success'] ?? false) {
            $sent++;
            if (!$paksaTanpaJadwal && $sendKey !== '') {
                $sentIds[] = $santriId;
                $sentIdMap[$santriId] = true;
            }
        } else {
            $failed++;
        }
        usleep(350000);
    }

    if (!$paksaTanpaJadwal && $sendKey !== '') {
        wa_tagihan_sent_ids_save($pdo, $sendKey, $sentIds);
        if (count($santriRows) >= $batchSize) {
            save_setting($pdo, $offsetKey, (string) ($offset + $batchSize));
        } else {
            save_setting($pdo, $offsetKey, '0');
        }
    }

    if (!$paksaTanpaJadwal && $sent > 0) {
        save_setting($pdo, 'wa_tagihan_last_sent_at', date('Y-m-d H:i:s'));
        if (!empty($ctx['recurring'])) {
            save_setting($pdo, 'wa_tagihan_last_sent_date', date('Y-m-d'));
        }
    }
    if (!$paksaTanpaJadwal && $eligible > 0 && $failed === 0 && empty($ctx['recurring']) && count($santriRows) < $batchSize) {
        save_setting($pdo, 'wa_tagihan_last_period_key', (string) $ctx['send_key']);
        save_setting($pdo, 'wa_tagihan_last_sent_at', date('Y-m-d H:i:s'));
        if ($sendKey !== '') {
            wa_tagihan_sent_ids_clear($pdo, $sendKey);
        }
    } elseif (!$paksaTanpaJadwal && $eligible > 0 && $failed === 0 && empty($ctx['recurring']) && count($santriRows) >= $batchSize && (int) app_setting($pdo, $offsetKey, '0') === 0) {
        save_setting($pdo, 'wa_tagihan_last_period_key', (string) $ctx['send_key']);
        save_setting($pdo, 'wa_tagihan_last_sent_at', date('Y-m-d H:i:s'));
        if ($sendKey !== '') {
            wa_tagihan_sent_ids_clear($pdo, $sendKey);
        }
    } elseif (!$paksaTanpaJadwal && $eligible > 0 && $failed > 0) {
        save_setting($pdo, 'wa_tagihan_last_partial_fail_at', date('Y-m-d H:i:s'));
        save_setting($pdo, 'wa_tagihan_last_partial_fail_stats', json_encode([
            'sent' => $sent,
            'failed' => $failed,
            'eligible' => $eligible,
        ], JSON_UNESCAPED_UNICODE));
    }
    save_setting($pdo, 'wa_tagihan_last_run_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_tagihan_last_run_stats', json_encode([
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'eligible' => $eligible,
        'paksa' => $paksaTanpaJadwal,
    ], JSON_UNESCAPED_UNICODE));

    $ok = $sent > 0 || ($eligible === 0 && $skipped > 0);
    $msg = $sent > 0
        ? $sent . ' WA tagihan terkirim.'
        : ($eligible > 0
            ? 'Tidak ada WA terkirim (' . $failed . ' gagal). Periksa token gateway di Pengaturan → WA.'
            : 'Tidak ada santri dengan tagihan wajib belum lunas.');

    if ($failed > 0 && $sent > 0) {
        $msg .= ' Gagal: ' . $failed . '.';
    }
    if ($skipped > 0) {
        $msg .= ' Dilewati: ' . $skipped . ' (lunas/tanpa tarif).';
    }

    return [
        'ok' => $ok,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'eligible' => $eligible,
        'message' => $msg,
    ];
}
