<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

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
    $periodKey = $calendar === 'HIJRIYAH' ? get_hijri_year_month($today) : date('Y-m', strtotime($today));
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

    $sendKey = ($isCustomMasehi ? 'MASEHI_CUSTOM' : $calendar) . ':' . $periodKey;
    $lastKey = trim((string) app_setting($pdo, 'wa_tagihan_last_period_key', ''));

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
        'last_period_key' => $lastKey,
        'last_sent_at' => trim((string) app_setting($pdo, 'wa_tagihan_last_sent_at', '')),
        'period_already_sent' => $lastKey === $sendKey,
    ];
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

    return (int) date('j', strtotime($tanggalMasehi) ?: time);
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

    ensure_santri_identity_columns($pdo);
    if (!column_exists($pdo, 'santri', 'no_wa_wali')) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Kolom no_wa_wali belum tersedia.'];
    }
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
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'message' => 'Periode ini sudah dikirim (' . (string) $ctx['last_sent_at'] . ').',
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
    $stmt = $pdo->query('SELECT id, nis, ' . $nameExpr . ' AS nama_santri, ' . $classExpr . ' AS kategori_kelas, no_wa_wali FROM santri WHERE COALESCE(no_wa_wali, "") <> "" ' . $activeExpr . ' ORDER BY id ASC LIMIT 500');
    $santriRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($santriRows === []) {
        return ['ok' => false, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'eligible' => 0, 'message' => 'Tidak ada santri dengan nomor WA wali.'];
    }

    $tagihanCtx = tagihan_bulanan_page_context($pdo, $bulan, $tahunMulai, $tahunSelesai);
    $paidMap = $tagihanCtx['paid_map'];
    $syCtx = $tagihanCtx['sy_ctx'];

    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $eligible = 0;

    foreach ($santriRows as $row) {
        $santriId = (int) ($row['id'] ?? 0);
        if ($santriId <= 0) {
            continue;
        }
        $kelas = trim((string) ($row['kategori_kelas'] ?? ''));
        $components = keuangan_tagihan_wajib_components($pdo, $kelas);
        if ($components === []) {
            $skipped++;
            continue;
        }
        $st = tagihan_wajib_status_for_month_bulk(
            $pdo,
            $santriId,
            $bulan,
            $tahunMulai,
            $tahunSelesai,
            $kelas,
            $paidMap,
            $syCtx
        );
        $sisa = (int) ($st['sisa_total'] ?? 0);
        if ($sisa <= 0) {
            $skipped++;
            continue;
        }
        $eligible++;
        $nama = trim((string) ($row['nama_santri'] ?? 'Santri'));
        $labelKekurangan = wa_tagihan_label_kekurangan($components, $st['per_pos'] ?? []);
        $message = wa_format_tagihan_otomatis_wali($pdo, $nama, $labelKekurangan, $sisa);
        $phone = normalize_wa_phone((string) ($row['no_wa_wali'] ?? ''));
        if ($phone === '') {
            $failed++;
            continue;
        }
        $result = send_wa_message_with_result($pdo, $phone, $message);
        if ($result['success'] ?? false) {
            $sent++;
        } else {
            $failed++;
        }
    }

    if ($sent > 0 && !$paksaTanpaJadwal) {
        save_setting($pdo, 'wa_tagihan_last_period_key', (string) $ctx['send_key']);
        save_setting($pdo, 'wa_tagihan_last_sent_at', date('Y-m-d H:i:s'));
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
