<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/datetime_display.php';

function cashless_wa_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'cashless_accounts')) {
        $done = true;

        return;
    }
    $done = true;
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        try {
            $pdo->exec('ALTER TABLE cashless_accounts ADD COLUMN saldo_rendah_wa_flag TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
        }
    }
}

function cashless_wa_saldo_rendah_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'cashless_saldo_rendah_wa_enabled', '1')) === '1';
}

function cashless_wa_saldo_rendah_threshold(PDO $pdo): int
{
    return max(0, (int) app_setting($pdo, 'cashless_saldo_rendah_wa_ambang', '30000'));
}

function cashless_wa_transaksi_sukses_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'cashless_transaksi_wa_enabled', '1')) === '1';
}

function cashless_wa_laporan_harian_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'cashless_laporan_harian_wa_enabled', '0')) === '1';
}

function cashless_wa_laporan_harian_jam(PDO $pdo): string
{
    $jam = trim((string) app_setting($pdo, 'cashless_laporan_harian_wa_jam', '20:00'));
    if (!preg_match('/^\d{1,2}:\d{2}$/', $jam)) {
        return '20:00';
    }

    return $jam;
}

/** @return list<string> */
function cashless_wa_laporan_harian_targets(PDO $pdo): array
{
    $raw = trim((string) app_setting($pdo, 'cashless_laporan_harian_wa_targets', ''));
    if ($raw === '') {
        $raw = trim((string) app_setting($pdo, 'keterangan_pengurus_bidang_keuangan', ''));
    }
    if ($raw === '') {
        $raw = trim((string) app_setting($pdo, 'wa_pengurus', ''));
    }

    return parse_phone_list($raw);
}

/**
 * @return array{limit:int,terpakai:int,sisa:int,balance:int}
 */
function cashless_santri_jatah_harian(PDO $pdo, int $santriId, ?float $balanceOverride = null): array
{
    $limit = max(0, (int) app_setting($pdo, 'cashless_daily_limit', '10000'));
    $terpakai = 0;
    if ($santriId > 0 && table_exists($pdo, 'cashless_transactions')) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(nominal),0) FROM cashless_transactions WHERE santri_id = :sid AND jenis='DEBIT' AND DATE(tanggal)=CURDATE()");
        $st->execute(['sid' => $santriId]);
        $terpakai = (int) ($st->fetchColumn() ?: 0);
    }
    $balance = 0;
    if ($balanceOverride !== null) {
        $balance = (int) round((float) $balanceOverride);
    } elseif ($santriId > 0 && table_exists($pdo, 'cashless_accounts')) {
        $st = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :sid LIMIT 1');
        $st->execute(['sid' => $santriId]);
        $balance = (int) round((float) ($st->fetchColumn() ?: 0));
    }

    return [
        'limit' => $limit,
        'terpakai' => $terpakai,
        'sisa' => max(0, $limit - $terpakai),
        'balance' => $balance,
    ];
}

function cashless_santri_saldo_cukup_debit(PDO $pdo, int $santriId, int $nominal): ?string
{
    if ($nominal <= 0) {
        return 'Nominal tidak valid.';
    }
    if (!table_exists($pdo, 'cashless_accounts')) {
        return 'Akun cashless belum tersedia.';
    }
    $st = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :sid LIMIT 1');
    $st->execute(['sid' => $santriId]);
    $balance = (float) ($st->fetchColumn() ?: 0);
    if ($balance <= 0) {
        return 'Transaksi ditolak: saldo cashless habis.';
    }
    if ($balance < $nominal) {
        return 'Transaksi ditolak: saldo tidak cukup.';
    }
    $jatah = cashless_santri_jatah_harian($pdo, $santriId, $balance);
    if (($jatah['terpakai'] + $nominal) > $jatah['limit']) {
        return 'Transaksi ditolak: batas belanja harian terlampaui. Sisa jatah hari ini Rp '
            . number_format($jatah['sisa'], 0, ',', '.') . '.';
    }

    return null;
}

function cashless_wa_reset_low_flag(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    cashless_wa_ensure_schema($pdo);
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        return;
    }
    $pdo->prepare('UPDATE cashless_accounts SET saldo_rendah_wa_flag = 0 WHERE santri_id = :id')->execute(['id' => $santriId]);
}

function cashless_wa_low_flag_sent(PDO $pdo, int $santriId): bool
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return false;
    }
    cashless_wa_ensure_schema($pdo);
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        return false;
    }
    $st = $pdo->prepare('SELECT saldo_rendah_wa_flag FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);

    return (int) ($st->fetchColumn() ?: 0) === 1;
}

function cashless_wa_set_low_flag(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }
    cashless_wa_ensure_schema($pdo);
    if (!column_exists($pdo, 'cashless_accounts', 'saldo_rendah_wa_flag')) {
        return;
    }
    $pdo->prepare('UPDATE cashless_accounts SET saldo_rendah_wa_flag = 1 WHERE santri_id = :id')->execute(['id' => $santriId]);
}

function cashless_wa_rp(int $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function wa_format_cashless_saldo_rendah_wali(
    PDO $pdo,
    string $namaSantri,
    int $saldoTersisa,
    int $ambang
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }

    return wa_template_render($pdo, 'cashless_saldo_rendah_wali', [
        'nama_santri' => $namaSantri,
        'saldo_tersisa' => cashless_wa_rp($saldoTersisa),
        'ambang' => cashless_wa_rp($ambang),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

function wa_format_cashless_transaksi_sukses_wali(
    PDO $pdo,
    string $namaSantri,
    int $nominal,
    string $namaKoperasi,
    int $saldoKeseluruhan,
    int $sisaJatahHari,
    int $limitHarian,
    int $terpakaiHari
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }

    return wa_template_render($pdo, 'cashless_transaksi_sukses_wali', [
        'nama_santri' => $namaSantri,
        'nominal' => cashless_wa_rp($nominal),
        'nama_koperasi' => $namaKoperasi,
        'saldo_keseluruhan' => cashless_wa_rp($saldoKeseluruhan),
        'sisa_jatah_hari' => cashless_wa_rp($sisaJatahHari),
        'limit_harian' => cashless_wa_rp($limitHarian),
        'terpakai_hari' => cashless_wa_rp($terpakaiHari),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/**
 * @param array<string, mixed> $ringkasan
 */
function wa_format_cashless_laporan_harian_pengurus(PDO $pdo, array $ringkasan): string
{
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }

    return wa_template_render($pdo, 'cashless_laporan_harian_pengurus', [
        'tanggal' => (string) ($ringkasan['tanggal_label'] ?? ''),
        'total_transaksi' => (string) (int) ($ringkasan['total_transaksi'] ?? 0),
        'total_nominal' => cashless_wa_rp((int) ($ringkasan['total_nominal'] ?? 0)),
        'rincian_koperasi' => (string) ($ringkasan['rincian_koperasi'] ?? '-'),
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
    ]);
}

/**
 * Kirim WA ke wali jika saldo cashless turun ke ambang atau di bawahnya (sekali per periode rendah).
 */
function cashless_wa_maybe_notify_saldo_rendah(PDO $pdo, int $santriId, float|int $balanceAfter): void
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return;
    }

    cashless_wa_ensure_schema($pdo);
    $threshold = cashless_wa_saldo_rendah_threshold($pdo);
    $balanceInt = (int) round((float) $balanceAfter);

    if ($balanceInt > $threshold) {
        cashless_wa_reset_low_flag($pdo, $santriId);

        return;
    }

    if (!cashless_wa_saldo_rendah_enabled($pdo)) {
        return;
    }

    if (cashless_wa_low_flag_sent($pdo, $santriId)) {
        return;
    }

    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }

    $waliPhone = wa_otomatis_santri_wali_phone($pdo, $santriId);
    if ($waliPhone === '') {
        return;
    }

    $st = $pdo->prepare('SELECT COALESCE(NULLIF(nama_santri, ""), nama) AS nama_santri FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $namaSantri = trim((string) ($st->fetchColumn() ?: 'Santri'));

    $msg = wa_format_cashless_saldo_rendah_wali($pdo, $namaSantri, $balanceInt, $threshold);
    if (!send_wa_message($pdo, $waliPhone, $msg)) {
        return;
    }

    cashless_wa_set_low_flag($pdo, $santriId);
}

/** WA ke wali setelah transaksi debit berhasil. */
function cashless_wa_notify_transaksi_sukses(
    PDO $pdo,
    int $santriId,
    int $nominal,
    int $koperasiId,
    float|int $saldoSetelah
): void {
    if ($santriId <= 0 || $nominal <= 0 || !cashless_wa_transaksi_sukses_enabled($pdo)) {
        return;
    }

    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }

    $waliPhone = wa_otomatis_santri_wali_phone($pdo, $santriId);
    if ($waliPhone === '') {
        return;
    }

    require_once __DIR__ . '/cashless_koperasi.php';
    $kop = $koperasiId > 0 ? cashless_koperasi_by_id($pdo, $koperasiId) : null;
    $namaKoperasi = is_array($kop) ? (string) ($kop['nama'] ?? ('Koperasi ' . $koperasiId)) : 'Koperasi';

    $st = $pdo->prepare('SELECT COALESCE(NULLIF(nama_santri, ""), nama) AS nama_santri FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $namaSantri = trim((string) ($st->fetchColumn() ?: 'Santri'));

    $jatah = cashless_santri_jatah_harian($pdo, $santriId, (float) $saldoSetelah);
    $msg = wa_format_cashless_transaksi_sukses_wali(
        $pdo,
        $namaSantri,
        $nominal,
        $namaKoperasi,
        (int) $jatah['balance'],
        (int) $jatah['sisa'],
        (int) $jatah['limit'],
        (int) $jatah['terpakai']
    );
    send_wa_message($pdo, $waliPhone, $msg);
}

/**
 * Ringkasan transaksi debit cashless satu hari (semua koperasi).
 *
 * @return array{
 *   tanggal:string,
 *   tanggal_label:string,
 *   total_transaksi:int,
 *   total_nominal:int,
 *   per_koperasi:list<array{id:int,nama:string,jumlah:int,nominal:int}>,
 *   rincian_koperasi:string
 * }
 */
function cashless_wa_ringkasan_harian(PDO $pdo, ?string $tanggal = null): array
{
    require_once __DIR__ . '/cashless_koperasi.php';
    $tanggal = $tanggal ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    $empty = [
        'tanggal' => $tanggal,
        'tanggal_label' => app_format_tanggal_id($tanggal),
        'total_transaksi' => 0,
        'total_nominal' => 0,
        'per_koperasi' => [],
        'rincian_koperasi' => 'Tidak ada transaksi.',
    ];

    if (!table_exists($pdo, 'cashless_transactions')) {
        return $empty;
    }

    $hasKop = column_exists($pdo, 'cashless_transactions', 'koperasi_id');
    $sql = "
        SELECT " . ($hasKop ? 'COALESCE(ct.koperasi_id, 0)' : '0') . " AS koperasi_id,
               COUNT(*) AS jumlah,
               COALESCE(SUM(ct.nominal), 0) AS nominal
        FROM cashless_transactions ct
        WHERE ct.jenis = 'DEBIT' AND DATE(ct.tanggal) = :tgl
        GROUP BY " . ($hasKop ? 'COALESCE(ct.koperasi_id, 0)' : '0') . "
        ORDER BY koperasi_id ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute(['tgl' => $tanggal]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $kopMap = [];
    foreach (cashless_koperasi_list($pdo, false) as $k) {
        $kopMap[(int) ($k['id'] ?? 0)] = (string) ($k['nama'] ?? '');
    }

    $perKoperasi = [];
    $totalTrx = 0;
    $totalNom = 0;
    $lines = [];
    foreach ($rows as $r) {
        $kid = (int) ($r['koperasi_id'] ?? 0);
        $jumlah = (int) ($r['jumlah'] ?? 0);
        $nominal = (int) ($r['nominal'] ?? 0);
        $nama = $kid > 0 ? ($kopMap[$kid] ?? ('Koperasi ' . $kid)) : 'Umum / tanpa koperasi';
        $perKoperasi[] = ['id' => $kid, 'nama' => $nama, 'jumlah' => $jumlah, 'nominal' => $nominal];
        $totalTrx += $jumlah;
        $totalNom += $nominal;
        $lines[] = '• *' . $nama . '*: ' . $jumlah . ' transaksi · ' . cashless_wa_rp($nominal);
    }

    return [
        'tanggal' => $tanggal,
        'tanggal_label' => app_format_tanggal_id($tanggal),
        'total_transaksi' => $totalTrx,
        'total_nominal' => $totalNom,
        'per_koperasi' => $perKoperasi,
        'rincian_koperasi' => $lines !== [] ? implode("\n", $lines) : 'Tidak ada transaksi.',
    ];
}

/**
 * @return array{ok:bool,message:string,sent:int,skipped?:bool}
 */
function cashless_wa_jalankan_laporan_harian(PDO $pdo, bool $paksa = false): array
{
    if (!$paksa && !cashless_wa_laporan_harian_enabled($pdo)) {
        return ['ok' => false, 'message' => 'Laporan harian cashless nonaktif.', 'sent' => 0, 'skipped' => true];
    }

    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return ['ok' => false, 'message' => 'Gateway WA otomatis tidak siap.', 'sent' => 0];
    }

    $today = date('Y-m-d');
    if (!$paksa) {
        $last = trim((string) app_setting($pdo, 'cashless_laporan_harian_last_date', ''));
        if ($last === $today) {
            return ['ok' => true, 'message' => 'Laporan hari ini sudah dikirim.', 'sent' => 0, 'skipped' => true];
        }
        $jam = cashless_wa_laporan_harian_jam($pdo);
        $nowHm = date('H:i');
        if ($nowHm < $jam) {
            return ['ok' => true, 'message' => 'Belum jam kirim (' . $jam . ').', 'sent' => 0, 'skipped' => true];
        }
    }

    $targets = cashless_wa_laporan_harian_targets($pdo);
    if ($targets === []) {
        return ['ok' => false, 'message' => 'Nomor penerima laporan belum diatur.', 'sent' => 0];
    }

    $ringkasan = cashless_wa_ringkasan_harian($pdo, $today);
    $msg = wa_format_cashless_laporan_harian_pengurus($pdo, $ringkasan);

    $sent = 0;
    foreach ($targets as $phone) {
        if (send_wa_message($pdo, $phone, $msg)) {
            $sent++;
        }
    }

    if ($sent > 0) {
        save_setting($pdo, 'cashless_laporan_harian_last_date', $today);
        save_setting($pdo, 'cashless_laporan_harian_last_sent_at', date('Y-m-d H:i:s'));
        save_setting($pdo, 'cashless_laporan_harian_last_stats', json_encode([
            'transaksi' => (int) $ringkasan['total_transaksi'],
            'nominal' => (int) $ringkasan['total_nominal'],
            'sent' => $sent,
        ], JSON_UNESCAPED_UNICODE));
    }

    return [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? 'Laporan cashless terkirim ke ' . $sent . ' nomor (' . (int) $ringkasan['total_transaksi'] . ' transaksi, ' . cashless_wa_rp((int) $ringkasan['total_nominal']) . ').'
            : 'Gagal mengirim laporan cashless.',
        'sent' => $sent,
    ];
}

function cashless_wa_cron_laporan_harian(PDO $pdo): void
{
    cashless_wa_jalankan_laporan_harian($pdo, false);
}

/**
 * @return array<string, mixed>
 */
function cashless_wa_laporan_status_hari_ini(PDO $pdo): array
{
    $ringkasan = cashless_wa_ringkasan_harian($pdo, date('Y-m-d'));
    $lastStats = json_decode((string) app_setting($pdo, 'cashless_laporan_harian_last_stats', ''), true);

    return [
        'ringkasan' => $ringkasan,
        'enabled' => cashless_wa_laporan_harian_enabled($pdo),
        'jam' => cashless_wa_laporan_harian_jam($pdo),
        'last_date' => trim((string) app_setting($pdo, 'cashless_laporan_harian_last_date', '')),
        'last_sent_at' => trim((string) app_setting($pdo, 'cashless_laporan_harian_last_sent_at', '')),
        'last_stats' => is_array($lastStats) ? $lastStats : null,
        'send_time_ok' => date('H:i') >= cashless_wa_laporan_harian_jam($pdo),
    ];
}
