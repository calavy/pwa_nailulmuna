<?php

declare(strict_types=1);

require_once __DIR__ . '/push_fcm.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/perizinan_jenis.php';
require_once __DIR__ . '/perizinan_approval.php';

function push_event_izin_pengajuan_baru(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $jenisIzin,
    string $tanggalMulai,
    string $tanggalSelesai
): void {
    $title = 'Pengajuan izin baru';
    $body = $namaSantri . ' (' . $nis . ') — ' . $jenisIzin . ' ' . $tanggalMulai . ' s/d ' . $tanggalSelesai;
    push_notify_all_staff($pdo, 'izin_pengajuan', $title, $body, [
        'nis' => $nis,
        'jenis' => $jenisIzin,
    ], '/perizinan/index.php');
}

/** Notifikasi ke pengasuh setelah pengajuan izin (staff sudah diberi tahu terpisah). */
function perizinan_push_setelah_pengajuan(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $jenisKode,
    string $tanggalMulai,
    string $tanggalSelesai,
    array $waDetail = [],
    string $pengajuanSumber = 'admin'
): void {
    $label = jenis_izin_label($jenisKode);
    push_event_izin_pengajuan_baru($pdo, $namaSantri, $nis, $label, $tanggalMulai, $tanggalSelesai);
    $body = $namaSantri . ' (' . $nis . ') — ' . $label . ' ' . $tanggalMulai . ' s/d ' . $tanggalSelesai;
    $alasanSnippet = trim((string) ($waDetail['alasan'] ?? ''));
    if ($alasanSnippet !== '') {
        $body .= '. Alasan: ' . mb_substr($alasanSnippet, 0, 120);
    }
    $sumber = perizinan_pengajuan_sumber_normalize($pengajuanSumber);
    if (perizinan_memerlukan_persetujuan_pengasuh($jenisKode)) {
        if ($sumber === 'wali') {
            push_notify_all_kiai($pdo, 'izin_pengajuan', 'Izin syar\'i menunggu persetujuan', $body, [
                'jenis' => perizinan_jenis_izin_normalize($jenisKode),
            ], '/pengasuh/perizinan.php');
        }
    } else {
        push_notify_all_kiai($pdo, 'izin_pengajuan', 'Pemberitahuan izin baru', $body, [
            'jenis' => perizinan_jenis_izin_normalize($jenisKode),
        ], '/perizinan/index.php');
    }

    $alasanWa = $alasanSnippet !== '' ? $alasanSnippet : '—';
    perizinan_wa_kirim_permohonan_baru(
        $pdo,
        $jenisKode,
        $namaSantri,
        $nis,
        (string) ($waDetail['tingkatan'] ?? ''),
        $tanggalMulai,
        $tanggalSelesai,
        (string) ($waDetail['jam_mulai'] ?? ''),
        (string) ($waDetail['jam_selesai'] ?? ''),
        $alasanWa,
        (string) ($waDetail['tujuan'] ?? ''),
        (int) ($waDetail['izin_id'] ?? 0)
    );
}

/** Notifikasi perpanjangan izin (portal wali / pengurus). */
function perizinan_push_setelah_perpanjangan(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $jenisKode,
    string $tanggalMulai,
    string $tanggalSelesaiLama,
    string $tanggalSelesaiBaru,
    string $alasanPerpanjangan
): void {
    $label = jenis_izin_label($jenisKode);
    $title = 'Perpanjangan izin';
    $body = $namaSantri . ' (' . $nis . ') — ' . $label . ' diperpanjang '
        . $tanggalSelesaiLama . ' → ' . $tanggalSelesaiBaru . '. Alasan: ' . $alasanPerpanjangan;
    push_notify_all_staff($pdo, 'izin_perpanjangan', $title, $body, [
        'nis' => $nis,
        'jenis' => perizinan_jenis_izin_normalize($jenisKode),
    ], '/perizinan/index.php');
    if (perizinan_memerlukan_persetujuan_pengasuh($jenisKode)) {
        push_notify_all_kiai($pdo, 'izin_perpanjangan', $title, $body, [
            'jenis' => perizinan_jenis_izin_normalize($jenisKode),
        ], '/pengasuh/perizinan.php');
    }
}

/**
 * Kirim WA permohonan izin baru langsung saat pengajuan (bukan lewat cron).
 *
 * @return array{sent:int,skipped:bool,reason:string}
 */
function perizinan_wa_kirim_permohonan_baru(
    PDO $pdo,
    string $jenisIzin,
    string $namaSantri,
    string $nis = '',
    string $tingkatan = '',
    string $tanggalMulai = '',
    string $tanggalSelesai = '',
    string $jamMulai = '',
    string $jamSelesai = '',
    string $alasan = '',
    string $tujuan = '',
    int $izinId = 0
): array {
    if (!function_exists('wa_permohonan_izin_should_notify')) {
        require_once __DIR__ . '/app.php';
    }
    if (!wa_permohonan_izin_should_notify($pdo, $jenisIzin)) {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'disabled_or_jenis'];
    }
    if (trim((string) app_setting($pdo, 'wa_otomatis_master_enabled', '1')) !== '1') {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'master_off'];
    }

    require_once __DIR__ . '/wa_otomatis.php';
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'gateway'];
    }

    $target = wa_permohonan_izin_target($pdo);
    if ($target === '') {
        return ['sent' => 0, 'skipped' => true, 'reason' => 'no_target'];
    }

    $msg = wa_format_pengajuan_izin_baru(
        $pdo,
        $namaSantri,
        $nis,
        $tingkatan,
        $jenisIzin,
        $tanggalMulai,
        $tanggalSelesai,
        substr($jamMulai, 0, 5) !== '' ? substr($jamMulai, 0, 5) : date('H:i'),
        substr($jamSelesai, 0, 5) !== '' ? substr($jamSelesai, 0, 5) : date('H:i'),
        $alasan,
        $tujuan
    );

    $waOpts = ['kind' => 'izin'];
    if ($izinId > 0) {
        $waOpts['dedup_key'] = 'izin:' . $izinId . ':submit';
        $waOpts['dedup_key_once'] = true;
    } else {
        $waOpts['dedup_key'] = 'izin:submit:' . md5($nis . '|' . $tanggalMulai . '|' . perizinan_jenis_izin_normalize($jenisIzin));
        $waOpts['dedup_key_once'] = true;
    }

    return [
        'sent' => send_wa_bulk($pdo, $target, $msg, $waOpts),
        'skipped' => false,
        'reason' => '',
    ];
}

function push_event_izin_disetujui_wali(
    PDO $pdo,
    int $santriId,
    string $namaSantri,
    string $jenisRaw,
    string $tanggalSelesai,
    string $jamSelesai
): void {
    if (!function_exists('perizinan_jenis_wa_disetujui_vars')) {
        require_once __DIR__ . '/perizinan_jenis.php';
    }
    $waVars = perizinan_jenis_wa_disetujui_vars($jenisRaw);
    $title = (string) ($waVars['judul_push_wali'] ?? 'Izin anak disetujui');
    $body = $namaSantri . ' — ' . ($waVars['jenis_izin'] ?? 'izin') . ' hingga ' . $tanggalSelesai . ' ' . substr($jamSelesai, 0, 5);
    push_notify_wali_for_santri($pdo, $santriId, 'izin_keluar', $title, $body, [], '/wali/keaktifan.php');
}

function push_event_laporan_sakit_wali(
    PDO $pdo,
    int $santriId,
    string $namaSantri,
    string $gejala,
    string $statusKesehatan
): void {
    $title = 'Laporan kesehatan anak';
    $body = $namaSantri . ' — ' . $statusKesehatan . ($gejala !== '' ? ': ' . mb_substr($gejala, 0, 80) : '');
    push_notify_wali_for_santri($pdo, $santriId, 'laporan_sakit', $title, $body, [], '/wali/index.php');
    push_notify_all_staff($pdo, 'izin_pengajuan', 'Laporan sakit: ' . $namaSantri, $body, [], '/perizinan/index.php');
}

function push_event_tagihan_syahriyah_wali(
    PDO $pdo,
    int $santriId,
    string $namaSantri,
    string $periodeLabel,
    string $sisaFormatted
): void {
    $title = 'Pemberitahuan tagihan';
    $body = trim($namaSantri) . ' — ' . $periodeLabel . '. Sisa: ' . $sisaFormatted;
    push_notify_wali_for_santri($pdo, $santriId, 'syahriyah', $title, $body, [], '/wali/keuangan.php?tab=tagihan');
}

function push_event_pelanggaran_berat_kiai(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    int $totalPoin,
    string $sanctionLabel
): void {
    $title = 'Pelanggaran berat';
    $body = $namaSantri . ' (' . $nis . ') — ' . $totalPoin . ' poin. ' . $sanctionLabel;
    push_notify_all_kiai($pdo, 'pelanggaran_berat', $title, $body, [
        'nis' => $nis,
        'poin' => (string) $totalPoin,
    ], '/poin/rekap.php');
}

function push_event_keuangan_harian_kiai(PDO $pdo, string $summaryBody): void
{
    $title = 'Ringkasan keuangan harian';
    push_notify_all_kiai($pdo, 'keuangan_harian', $title, mb_substr($summaryBody, 0, 400), [], '/keuangan/index.php');
}

function push_event_rapat_staff(PDO $pdo, string $title, string $body, ?string $url = null): void
{
    push_notify_all_staff($pdo, 'rapat', $title, $body, [], $url ?? '/dashboard.php');
}

function push_event_tugas_keamanan(PDO $pdo, string $title, string $body): void
{
    push_notify_all_staff($pdo, 'tugas_keamanan', $title, $body, [], '/dashboard.php');
}

function push_event_presensi_santri_scan(
    PDO $pdo,
    string $namaSantri,
    string $body,
    string $nis = '',
    ?int $pembimbingUserId = null
): void {
    $title = 'Scan santri: ' . $namaSantri;
    if ($pembimbingUserId !== null && $pembimbingUserId > 0) {
        push_notify($pdo, 'staff', 'presensi_scan', $title, $body, ['nis' => $nis], '/pembimbing/dashboard.php', null, $pembimbingUserId);
    } else {
        push_notify_all_staff($pdo, 'presensi_scan', $title, $body, ['nis' => $nis], '/rekap/keaktifan_hari.php');
    }
}

function push_event_presensi_pembimbing_scan(PDO $pdo, string $namaPembimbing, string $body): void
{
    $title = 'Scan pembimbing: ' . $namaPembimbing;
    push_notify_all_staff($pdo, 'presensi_scan', $title, $body, [], '/pembimbing/dashboard.php');
}

function push_event_pembimbing_izin_baru(
    PDO $pdo,
    string $namaPembimbing,
    string $jenisIzin,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $alasan
): void {
    $title = 'Izin pembimbing: ' . $namaPembimbing;
    $body = $jenisIzin . ' ' . $tanggalMulai . ' s/d ' . $tanggalSelesai;
    if ($alasan !== '') {
        $body .= ' — ' . mb_substr($alasan, 0, 120);
    }
    push_notify_all_staff($pdo, 'izin_pengajuan', $title, $body, [], '/pembimbing/perizinan.php');
}

function trigger_push_daily_kiai(PDO $pdo): void
{
    if (!push_should_send_fcm($pdo)) {
        return;
    }
    if (trim((string) app_setting($pdo, 'fcm_daily_kiai_enabled', '1')) !== '1') {
        return;
    }
    $sendTime = trim((string) app_setting($pdo, 'fcm_daily_kiai_time', '20:00'));
    if ($sendTime !== '' && preg_match('/^\d{2}:\d{2}$/', $sendTime) && date('H:i') < $sendTime) {
        return;
    }
    $today = date('Y-m-d');
    if (trim((string) app_setting($pdo, 'fcm_daily_kiai_last_date', '')) === $today) {
        return;
    }

    $parts = [];
    $namaPonpes = app_brand_nama_ponpes($pdo);
    $parts[] = $namaPonpes . ' — ' . date('d/m/Y');

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(total_nominal),0) FROM keuangan_pembayaran WHERE tanggal_bayar = :t');
        $st->execute(['t' => $today]);
        $masuk = (int) $st->fetchColumn();
        $parts[] = 'Pemasukan hari ini: Rp ' . number_format($masuk, 0, ',', '.');
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(nominal),0) FROM keuangan_pengeluaran WHERE tanggal = :t');
        $st->execute(['t' => $today]);
        $keluar = (int) $st->fetchColumn();
        $parts[] = 'Pengeluaran hari ini: Rp ' . number_format($keluar, 0, ',', '.');
    }

    if (table_exists($pdo, 'point_ledger')) {
        $threshold = 40;
        if (table_exists($pdo, 'point_sanctions')) {
            $th = $pdo->query('SELECT MAX(ambang_poin) FROM point_sanctions')->fetchColumn();
            if ($th !== false && (int) $th > 0) {
                $threshold = (int) $th;
            }
        }
        $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $st = $pdo->prepare("
            SELECT s.nis, s.{$nameCol} AS nama, COALESCE(SUM(pl.poin),0) AS total
            FROM point_ledger pl
            INNER JOIN santri s ON s.id = pl.santri_id
            WHERE pl.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY s.id, s.nis, s.{$nameCol}
            HAVING total >= :th
            ORDER BY total DESC
            LIMIT 5
        ");
        $st->execute(['th' => $threshold]);
        $heavy = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($heavy !== []) {
            $parts[] = 'Pelanggaran berat (≥' . $threshold . ' poin): ' . count($heavy) . ' santri.';
        }
    }

    require_once __DIR__ . '/pengasuh_laporan_hari.php';
    $keaktifanRingkas = pengasuh_laporan_hari_push_ringkasan($pdo, $today);
    if ($keaktifanRingkas !== null && (int) ($keaktifanRingkas['total'] ?? 0) > 0) {
        $parts[] = sprintf(
            'Keaktifan: %.1f%% hadir · %d alpa · %d kegiatan',
            (float) ($keaktifanRingkas['persen'] ?? 0),
            (int) ($keaktifanRingkas['alpa'] ?? 0),
            (int) ($keaktifanRingkas['kegiatan'] ?? 0)
        );
    }

    $body = implode(' · ', $parts);
    $laporanUrl = '/pengasuh/laporan_hari.php?tanggal=' . urlencode($today);
    if (push_notify_all_kiai($pdo, 'keuangan_harian', 'Ringkasan harian pondok', $body, [], $laporanUrl) > 0) {
        save_setting($pdo, 'fcm_daily_kiai_last_date', $today);
    }
}

function trigger_push_tagihan_wali_from_cron(PDO $pdo): void
{
    if (!push_should_send_fcm($pdo) || !table_exists($pdo, 'santri')) {
        return;
    }
    if (trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) !== '1') {
        return;
    }
    require_once __DIR__ . '/wa_tagihan.php';
    require_once __DIR__ . '/pondok_kalender.php';
    $ctx = wa_tagihan_jadwal_context($pdo);
    if (!$ctx['is_send_day'] || !$ctx['send_time_ok'] || $ctx['period_already_sent']) {
        return;
    }

    $today = date('Y-m-d');
    $periodKey = !empty($ctx['recurring']) ? ('push-day:' . $today) : ('push:' . (string) ($ctx['period_key'] ?? date('Y-m')));
    $lastPush = trim((string) app_setting($pdo, 'fcm_tagihan_last_period_key', ''));
    if ($lastPush === $periodKey) {
        return;
    }

    ensure_santri_identity_columns($pdo);
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $katCol = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : null);
    $cols = 'id, nis, ' . $nameExpr . ' AS nama_santri';
    if ($katCol !== null) {
        $cols .= ', ' . $katCol . ' AS kelas_kategori';
    }
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(is_aktif,1) = 1 ' : '';
    $stmt = $pdo->query('SELECT ' . $cols . ' FROM santri WHERE 1=1 ' . $activeExpr . ' ORDER BY id ASC LIMIT 300');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $periodeBerjalan = pondok_periode_berjalan($pdo);
    $bulan = max(1, min(12, (int) ($periodeBerjalan['bulan'] ?? 1)));
    $periode = keuangan_tahun_ajaran_aktif($pdo);
    $tm = (int) $periode['mulai'];
    $ts = (int) $periode['selesai'];

    $sent = 0;
    foreach ($rows as $row) {
        $sid = (int) ($row['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kat = trim((string) ($row['kelas_kategori'] ?? ''));
        $st = wa_tagihan_santri_status($pdo, $sid, $kat, $bulan, $tm, $ts);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        if ($sisa <= 0) {
            continue;
        }
        $nama = (string) ($row['nama_santri'] ?? 'Santri');
        $components = keuangan_tagihan_wajib_components($pdo, $kat);
        $labelKekurangan = wa_tagihan_label_kekurangan($components, $st['per_pos'] ?? []);
        $body = push_format_tagihan_otomatis_body($nama, $labelKekurangan, $sisa);
        if (push_notify_wali_for_santri(
            $pdo,
            $sid,
            'syahriyah',
            'Pemberitahuan tagihan',
            $body,
            ['sisa' => (string) $sisa],
            '/wali/keuangan.php?tab=tagihan'
        ) > 0) {
            $sent++;
        }
    }
    if ($sent > 0) {
        save_setting($pdo, 'fcm_tagihan_last_period_key', $periodKey);
    }
}

function push_maybe_pelanggaran_berat_after_point(PDO $pdo, int $santriId): void
{
    if (!push_should_send_fcm($pdo) || $santriId <= 0 || !table_exists($pdo, 'point_ledger')) {
        return;
    }

    $threshold = 40;
    if (table_exists($pdo, 'point_sanctions')) {
        $th = $pdo->query('SELECT MAX(ambang_poin) FROM point_sanctions WHERE is_active = 1')->fetchColumn();
        if ($th !== false && (int) $th > 0) {
            $threshold = (int) $th;
        }
    }

    if (!function_exists('rekap_poin_presensi_eligible_sql')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    $eligiblePoinSql = rekap_poin_presensi_eligible_sql($pdo, 'pl');
    $st = $pdo->prepare('
        SELECT COALESCE(SUM(pl.point_delta), 0)
        FROM point_ledger pl
        WHERE pl.santri_id = :sid
        ' . $eligiblePoinSql . '
    ');
    $st->execute(['sid' => $santriId]);
    $total = (int) $st->fetchColumn();
    if ($total < $threshold) {
        return;
    }

    $debounceKey = 'fcm_pelanggaran_push_' . $santriId . '_' . date('Y-m');
    if (trim((string) app_setting($pdo, $debounceKey, '')) === '1') {
        return;
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $stS = $pdo->prepare('SELECT nis, ' . $nameCol . ' AS nama FROM santri WHERE id = :id LIMIT 1');
    $stS->execute(['id' => $santriId]);
    $santri = $stS->fetch(PDO::FETCH_ASSOC);
    if (!$santri) {
        return;
    }

    $sanctionLabel = 'Perlu tindakan kedisiplinan';
    if (table_exists($pdo, 'point_sanctions')) {
        $sanRows = $pdo->query('SELECT ambang_poin, tindakan FROM point_sanctions WHERE is_active = 1 ORDER BY ambang_poin ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($sanRows as $sr) {
            if ($total >= (int) ($sr['ambang_poin'] ?? 0)) {
                $sanctionLabel = (string) ($sr['tindakan'] ?? $sanctionLabel);
            }
        }
    }

    $n = push_event_pelanggaran_berat_kiai(
        $pdo,
        (string) ($santri['nama'] ?? 'Santri'),
        (string) ($santri['nis'] ?? '-'),
        $total,
        $sanctionLabel
    );
    if ($n > 0) {
        save_setting($pdo, $debounceKey, '1');
    }
}
