<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/google_sheets_client.php';

/** @return array<string, string> internal key => default Google Sheet tab title (PNM10) */
function laporan_snapshot_default_tab_titles(): array
{
    return [
        'Neraca_Pondok' => 'Neraca',
        'Rekap_Kas_Bulanan' => 'Rekap Kas Bulanan',
        'Pemasukan_Detail' => 'Pemasukan (Detail)',
        'Pengeluaran_Detail' => 'Pengeluaran (Detail)',
        'Tunggakan_Syahriyah' => 'Tunggakan Syahriyah',
        'Syahriyah_12Bulan' => 'Laporan Syahriyah',
        'Uang_Saku_Titipan' => 'Uang Saku Santri (Titipan)',
        'Payroll_Pembimbing' => 'Payroll Pembimbing',
        'BOS_BKU' => 'BOS - BKU',
        'BOS_LRA' => 'BOS - LRA',
        'Data_Master_Santri' => 'Data Master Santri',
    ];
}

function laporan_snapshot_normalize_spreadsheet_id(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $raw, $m)) {
        return (string) $m[1];
    }

    return $raw;
}

/** @return list<string> */
function laporan_snapshot_tab_keys(): array
{
    return array_keys(laporan_snapshot_default_tab_titles());
}

/** @return list<string> */
function laporan_snapshot_tab_names(): array
{
    return laporan_snapshot_tab_keys();
}

/**
 * @return array<string, string> internal key => sheet tab title
 */
function laporan_snapshot_tab_titles(PDO $pdo): array
{
    $titles = laporan_snapshot_default_tab_titles();
    $raw = trim((string) app_setting($pdo, 'laporan_snapshot_tab_titles', ''));
    if ($raw === '') {
        return $titles;
    }
    $overrides = json_decode($raw, true);
    if (!is_array($overrides)) {
        return $titles;
    }
    foreach ($overrides as $key => $label) {
        $key = (string) $key;
        $label = trim((string) $label);
        if ($label !== '' && array_key_exists($key, $titles)) {
            $titles[$key] = $label;
        }
    }

    return $titles;
}

function laporan_snapshot_sheet_title(PDO $pdo, string $internalKey): string
{
    $titles = laporan_snapshot_tab_titles($pdo);

    return (string) ($titles[$internalKey] ?? $internalKey);
}

/** @return list<list<string|int|null>> */
function laporan_snapshot_uang_saku_to_rows(PDO $pdo): array
{
    require_once __DIR__ . '/cashless_koperasi.php';

    $rekap = cashless_rekap_saldo_santri($pdo);
    $rowsExport = (array) ($rekap['rows'] ?? []);
    $summaryExport = (array) ($rekap['summary'] ?? []);
    $dailyLimitExport = (int) ($rekap['daily_limit'] ?? 10000);
    $jamResetExport = cashless_daily_reset_jam($pdo);

    $xlsxRows = [
        ['nis', 'nama_santri', 'tingkatan', 'saldo', 'total_topup', 'total_belanja', 'keluar_manual', 'terpakai_hari_ini', 'sisa_jatah_hari', 'status_pin', 'batas_harian', 'jam_reset_harian'],
    ];
    foreach ($rowsExport as $sr) {
        $pinOk = (int) ($sr['pin_terpasang'] ?? 0) === 1;
        $xlsxRows[] = [
            (string) ($sr['nis'] ?? ''),
            (string) ($sr['nama_santri'] ?? ''),
            (string) ($sr['tingkatan'] ?? ''),
            (int) ($sr['saldo'] ?? 0),
            (int) ($sr['total_topup'] ?? 0),
            (int) ($sr['total_debit'] ?? 0),
            (int) ($sr['total_pengeluaran'] ?? 0),
            (int) ($sr['debit_hari_ini'] ?? 0),
            (int) ($sr['sisa_jatah_hari'] ?? 0),
            $pinOk ? 'Sudah' : 'Belum',
            $dailyLimitExport,
            $jamResetExport,
        ];
    }
    $xlsxRows[] = [];
    $xlsxRows[] = [
        'RINGKASAN',
        'total_santri=' . (int) ($summaryExport['total_santri'] ?? 0),
        'bersaldo=' . (int) ($summaryExport['jumlah_bersaldo'] ?? 0),
        'total_saldo=' . (int) ($summaryExport['total_saldo'] ?? 0),
        'pin_sudah=' . (int) ($summaryExport['pin_sudah'] ?? 0),
        'pin_belum=' . (int) ($summaryExport['pin_belum'] ?? 0),
    ];

    return $xlsxRows;
}

function laporan_snapshot_enabled(PDO $pdo): bool
{
    return app_setting($pdo, 'laporan_snapshot_enabled', '0') === '1';
}

function laporan_snapshot_jam(PDO $pdo): string
{
    $jam = trim((string) app_setting($pdo, 'laporan_snapshot_jam', '00:00'));
    if (preg_match('/^(\d{1,2}):(\d{2})/', $jam, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    return '00:00';
}

function laporan_snapshot_send_time_ok(PDO $pdo, ?string $nowHm = null): bool
{
    $nowHm = $nowHm ?? date('H:i');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $nowHm, $nm)) {
        return false;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', laporan_snapshot_jam($pdo), $jm)) {
        return false;
    }
    $nowMin = (int) $nm[1] * 60 + (int) $nm[2];
    $targetMin = (int) $jm[1] * 60 + (int) $jm[2];

    return $nowMin >= $targetMin;
}

function laporan_snapshot_sa_json_path(PDO $pdo): string
{
    $configured = trim((string) app_setting($pdo, 'laporan_snapshot_sa_json_path', ''));
    if ($configured === '') {
        $configured = 'config/google_service_account.json';
    }
    $normalized = str_replace('\\', '/', $configured);
    if (preg_match('#^[a-zA-Z]:/#', $normalized) || str_starts_with($normalized, '/')) {
        return $configured;
    }

    return dirname(__DIR__) . '/' . ltrim($normalized, '/');
}

/**
 * @return array{path:string, exists:bool, readable:bool, valid_json:bool, client_email:string, error:string}
 */
function laporan_snapshot_sa_status(PDO $pdo): array
{
    $path = laporan_snapshot_sa_json_path($pdo);
    $status = [
        'path' => $path,
        'exists' => false,
        'readable' => false,
        'valid_json' => false,
        'client_email' => '',
        'error' => '',
    ];

    if (!is_file($path)) {
        $status['error'] = 'File kredensial tidak ditemukan. Simpan path di Pengaturan, lalu letakkan JSON Service Account di lokasi tersebut.';

        return $status;
    }
    $status['exists'] = true;

    if (!is_readable($path)) {
        $status['error'] = 'File kredensial ada tetapi tidak bisa dibaca (cek izin file).';

        return $status;
    }
    $status['readable'] = true;

    try {
        $credentials = google_sa_load_credentials($path);
    } catch (Throwable $e) {
        $status['error'] = $e->getMessage();

        return $status;
    }

    $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
    $privateKey = trim((string) ($credentials['private_key'] ?? ''));
    $privateKeyId = trim((string) ($credentials['private_key_id'] ?? ''));

    if (
        stripos($privateKeyId, 'replace') !== false
        || stripos($privateKey, 'REPLACE_WITH_KEY') !== false
        || stripos($clientEmail, 'your-service-account') !== false
        || stripos($clientEmail, 'example.com') !== false
    ) {
        $status['error'] = 'File masih berisi placeholder contoh. Ganti dengan JSON asli dari Google Cloud Console.';

        return $status;
    }

    $status['valid_json'] = true;
    $status['client_email'] = $clientEmail;
    $status['error'] = '';

    return $status;
}

/**
 * Uji OAuth + akses spreadsheet (tanpa menulis data laporan).
 *
 * @return array{
 *     ok:bool,
 *     steps:array<string, array{ok:bool, message:string}>,
 *     client_email:string,
 *     spreadsheet_id:string,
 *     error?:string
 * }
 */
function laporan_snapshot_test_google_access(PDO $pdo): array
{
    $saStatus = laporan_snapshot_sa_status($pdo);
    $clientEmail = (string) ($saStatus['client_email'] ?? '');
    $steps = [];

    if (!$saStatus['valid_json']) {
        $err = (string) ($saStatus['error'] ?? 'Kredensial tidak valid');
        $steps['kredensial'] = ['ok' => false, 'message' => $err];

        return [
            'ok' => false,
            'steps' => $steps,
            'client_email' => $clientEmail,
            'spreadsheet_id' => '',
            'error' => $err,
        ];
    }

    $steps['kredensial'] = ['ok' => true, 'message' => 'File JSON Service Account valid'];

    try {
        $credentials = google_sa_load_credentials(laporan_snapshot_sa_json_path($pdo));
        $token = google_sa_access_token($credentials);
        $steps['oauth'] = ['ok' => true, 'message' => 'Token OAuth berhasil'];
    } catch (Throwable $e) {
        $steps['oauth'] = ['ok' => false, 'message' => $e->getMessage()];

        return [
            'ok' => false,
            'steps' => $steps,
            'client_email' => $clientEmail,
            'spreadsheet_id' => '',
            'error' => $e->getMessage(),
        ];
    }

    $spreadsheetId = trim((string) app_setting($pdo, 'laporan_snapshot_spreadsheet_id', ''));
    if ($spreadsheetId === '') {
        $steps['spreadsheet'] = [
            'ok' => true,
            'message' => 'Spreadsheet ID kosong — kirim snapshot pertama kali akan membuat sheet baru (Sheets + Drive API harus aktif).',
        ];

        return [
            'ok' => true,
            'steps' => $steps,
            'client_email' => $clientEmail,
            'spreadsheet_id' => '',
        ];
    }

    try {
        google_api_request(
            'GET',
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=spreadsheetId,properties.title',
            $token
        );
        $steps['spreadsheet'] = ['ok' => true, 'message' => 'Akses spreadsheet OK (ID: ' . $spreadsheetId . ')'];
    } catch (Throwable $e) {
        $steps['spreadsheet'] = ['ok' => false, 'message' => $e->getMessage()];

        return [
            'ok' => false,
            'steps' => $steps,
            'client_email' => $clientEmail,
            'spreadsheet_id' => $spreadsheetId,
            'error' => $e->getMessage(),
        ];
    }

    $emailsCsv = trim((string) app_setting($pdo, 'laporan_snapshot_share_emails', ''));
    if ($emailsCsv !== '') {
        $steps['share_penerima'] = [
            'ok' => true,
            'message' => 'Email penerima terisi — invite Viewer diuji saat Kirim snapshot (bukan di tes ini). Jika gagal permission, kosongkan email penerima lalu share manual.',
        ];
    } else {
        $steps['share_penerima'] = ['ok' => true, 'message' => 'Email penerima kosong — langkah invite dilewati'];
    }

    return [
        'ok' => true,
        'steps' => $steps,
        'client_email' => $clientEmail,
        'spreadsheet_id' => $spreadsheetId,
    ];
}

function laporan_snapshot_as_of_date(): string
{
    return date('Y-m-d', strtotime('-1 day') ?: time());
}

function laporan_snapshot_normalize_as_of(?string $raw): string
{
    $fallback = laporan_snapshot_as_of_date();
    $raw = trim((string) $raw);
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $fallback;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if ($dt === false || $dt->format('Y-m-d') !== $raw) {
        return $fallback;
    }

    $today = new DateTimeImmutable('today');
    if ($dt > $today) {
        return $fallback;
    }

    return $raw;
}

/**
 * @return list<list<string|int>>
 */
function laporan_snapshot_meta_rows(string $snapshotAt, string $asOf, string $contextLabel): array
{
    return [
        ['snapshot_at', $snapshotAt],
        ['as_of', $asOf],
        ['konteks', $contextLabel],
        [],
    ];
}

/**
 * @param array<string, mixed> $neraca
 * @return list<list<string|int>>
 */
function laporan_snapshot_neraca_to_rows(array $neraca, string $snapshotAt, string $asOf): array
{
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'Neraca Pondok per ' . (string) ($neraca['as_of_label'] ?? $asOf));
    $rows[] = ['total_aset', (int) ($neraca['aset']['total'] ?? 0)];
    $rows[] = ['total_pasiva', (int) ($neraca['total_pasiva'] ?? 0)];
    $rows[] = ['selisih', (int) ($neraca['selisih'] ?? 0)];
    $rows[] = [];
    $rows[] = ['bagian', 'label', 'nominal'];

    foreach (['aset' => 'Aset', 'liabilitas' => 'Liabilitas', 'aset_neto' => 'Aset Neto'] as $key => $bagianLabel) {
        $block = (array) ($neraca[$key] ?? []);
        foreach ((array) ($block['sections'] ?? []) as $section) {
            $judul = (string) ($section['judul'] ?? '');
            foreach ((array) ($section['baris'] ?? []) as $baris) {
                $rows[] = [
                    $bagianLabel . ($judul !== '' ? ' — ' . $judul : ''),
                    (string) ($baris['label'] ?? ''),
                    (int) ($baris['nominal'] ?? 0),
                ];
            }
            $rows[] = [$bagianLabel, 'Subtotal ' . $judul, (int) ($section['subtotal'] ?? 0)];
        }
        $rows[] = [$bagianLabel, 'Total ' . $bagianLabel, (int) ($block['total'] ?? 0)];
    }

    return $rows;
}

/**
 * @param array<string, mixed> $rekap
 * @return list<list<string|int|float|string>>
 */
function laporan_snapshot_rekap_kas_to_rows(array $rekap, string $snapshotAt, string $asOf): array
{
    $taLabel = (string) ($rekap['ta_label'] ?? '');
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'Rekap Kas Bulanan TA ' . $taLabel);
    $rows[] = ['saldo_awal_ta', (int) ($rekap['saldo_awal_ta'] ?? 0)];
    $rows[] = ['saldo_akhir', (int) ($rekap['saldo_akhir'] ?? 0)];
    $rows[] = [];
    $rows[] = [
        'bulan', 'label', 'tanggal_dari', 'tanggal_sampai', 'saldo_awal',
        'masuk_syahriyah', 'masuk_makan', 'masuk_saku', 'masuk_awal_tahun', 'masuk_donasi', 'masuk_lain', 'masuk_total',
        'keluar_syahriyah', 'keluar_makan', 'keluar_saku', 'keluar_lain', 'keluar', 'saldo_akhir', 'saldo_fisik', 'selisih_saldo',
    ];

    foreach ((array) ($rekap['baris'] ?? []) as $row) {
        $rows[] = [
            (int) ($row['bulan'] ?? 0),
            (string) ($row['label'] ?? ''),
            (string) ($row['tanggal_dari'] ?? ''),
            (string) ($row['tanggal_sampai'] ?? ''),
            (int) ($row['saldo_awal'] ?? 0),
            (int) ($row['masuk_syahriyah'] ?? 0),
            (int) ($row['masuk_makan'] ?? 0),
            (int) ($row['masuk_saku'] ?? 0),
            (int) ($row['masuk_awal_tahun'] ?? 0),
            (int) ($row['masuk_donasi'] ?? 0),
            (int) ($row['masuk_lain'] ?? 0),
            (int) ($row['masuk_total'] ?? 0),
            (int) ($row['keluar_syahriyah'] ?? 0),
            (int) ($row['keluar_makan'] ?? 0),
            (int) ($row['keluar_saku'] ?? 0),
            (int) ($row['keluar_lain'] ?? 0),
            (int) ($row['keluar'] ?? 0),
            (int) ($row['saldo_akhir'] ?? 0),
            (int) ($row['saldo_fisik'] ?? 0),
            (int) ($row['selisih_saldo'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $body
 * @return list<list<string|int>>
 */
function laporan_snapshot_tunggakan_to_rows(array $body, string $snapshotAt, string $asOf, string $bulanLabel, string $taLabel): array
{
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'Tunggakan Syahriyah — ' . $bulanLabel . ' TA ' . $taLabel);
    $rows[] = ['jumlah_santri', count($body)];
    $rows[] = [];
    $rows[] = [
        'nis', 'nama', 'tingkatan', 'kategori', 'tagihan', 'bayar', 'sisa', 'status',
        'sy_expected', 'sy_paid', 'sy_sisa', 'mk_expected', 'mk_paid', 'mk_sisa', 'sk_expected', 'sk_paid', 'sk_sisa',
    ];

    foreach ($body as $r) {
        $rows[] = [
            (string) ($r['nis'] ?? ''),
            (string) ($r['nama'] ?? ''),
            (string) ($r['tingkatan'] ?? ''),
            (string) ($r['kategori'] ?? ''),
            (int) ($r['tagihan'] ?? 0),
            (int) ($r['bayar'] ?? 0),
            (int) ($r['sisa'] ?? 0),
            (string) ($r['status'] ?? ''),
            (int) ($r['sy_expected'] ?? 0),
            (int) ($r['sy_paid'] ?? 0),
            (int) ($r['sy_sisa'] ?? 0),
            (int) ($r['mk_expected'] ?? 0),
            (int) ($r['mk_paid'] ?? 0),
            (int) ($r['mk_sisa'] ?? 0),
            (int) ($r['sk_expected'] ?? 0),
            (int) ($r['sk_paid'] ?? 0),
            (int) ($r['sk_sisa'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $laporanRows
 * @return list<list<string|int|float|string>>
 */
function laporan_snapshot_syahriyah_12_to_rows(array $laporanRows, string $snapshotAt, string $asOf, string $taLabel): array
{
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'Laporan Syahriyah 12 Bulan TA ' . $taLabel);
    $rows[] = ['bulan', 'label', 'rentang_masehi', 'expected', 'paid', 'sisa'];

    $totalExpected = 0;
    $totalPaid = 0;
    $totalSisa = 0;
    foreach ($laporanRows as $r) {
        $expected = (int) ($r['tagihan'] ?? 0);
        $paid = (int) ($r['terbayar'] ?? 0);
        $sisa = (int) ($r['sisa'] ?? 0);
        $totalExpected += $expected;
        $totalPaid += $paid;
        $totalSisa += $sisa;
        $rows[] = [
            (int) ($r['bulan'] ?? 0),
            (string) ($r['label'] ?? ''),
            (string) ($r['rentang_masehi'] ?? ''),
            $expected,
            $paid,
            $sisa,
        ];
    }
    $rows[] = ['', 'TOTAL', '', $totalExpected, $totalPaid, $totalSisa];

    return $rows;
}

/**
 * @param list<array<string, mixed>> $payrollRows
 * @return list<list<string|int|float|string>>
 */
function laporan_snapshot_payroll_to_rows(array $payrollRows, string $snapshotAt, string $asOf, string $periodeLabel): array
{
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'Payroll Pembimbing (sudah dibayar) — ' . $periodeLabel);
    $rows[] = ['jumlah_baris', count($payrollRows)];
    $rows[] = [];
    $rows[] = ['periode_mode', 'periode_label', 'bulan', 'tahun', 'nip', 'nama', 'total_bayar', 'total_hak', 'tanggal_bayar', 'keterangan'];

    foreach ($payrollRows as $r) {
        $rows[] = [
            (string) ($r['periode_mode'] ?? ''),
            (string) ($r['periode_label'] ?? ''),
            (int) ($r['bulan'] ?? 0),
            (int) ($r['tahun'] ?? 0),
            (string) ($r['nip'] ?? ''),
            (string) ($r['nama_pembimbing'] ?? ''),
            (int) round((float) ($r['total_bayar'] ?? 0)),
            (int) round((float) ($r['total_hak'] ?? 0)),
            (string) ($r['tanggal_bayar'] ?? ''),
            (string) ($r['keterangan'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $bkuRows
 * @return list<list<string|int|string>>
 */
function laporan_snapshot_bos_bku_to_rows(array $bkuRows, int $saldoAwal, string $snapshotAt, string $asOf, string $periodeLabel): array
{
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'BOS BKU — ' . $periodeLabel);
    $rows[] = ['saldo_awal_periode', $saldoAwal];
    $rows[] = [];
    $rows[] = ['tanggal', 'kode_akun', 'uraian', 'jenjang', 'sumber_dana', 'debit', 'kredit'];

    foreach ($bkuRows as $r) {
        $rows[] = [
            (string) ($r['tanggal'] ?? ''),
            (string) ($r['kode_akun'] ?? ''),
            (string) ($r['uraian'] ?? ''),
            (string) ($r['jenjang'] ?? ''),
            (string) ($r['sumber_dana'] ?? ''),
            (int) ($r['debit'] ?? 0),
            (int) ($r['kredit'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * @param array<string, mixed> $lra
 * @return list<list<string|int|string>>
 */
function laporan_snapshot_bos_lra_to_rows(array $lra, string $snapshotAt, string $asOf, string $periodeLabel): array
{
    $rows = laporan_snapshot_meta_rows($snapshotAt, $asOf, 'BOS LRA — ' . $periodeLabel);
    $rows[] = ['saldo_awal_periode', (int) ($lra['saldo_awal_periode'] ?? 0)];
    $rows[] = ['total_pendapatan', (int) ($lra['total_pendapatan'] ?? 0)];
    $rows[] = ['total_pengeluaran', (int) ($lra['total_pengeluaran'] ?? 0)];
    $rows[] = ['surplus', (int) ($lra['surplus'] ?? 0)];
    $rows[] = [];
    $rows[] = ['kelompok', 'kode', 'nama', 'nilai'];

    $sectionLabels = [
        'pendapatan' => 'Pendapatan',
        'beban_wustho' => 'Beban Wustho',
        'beban_ulya' => 'Beban Ulya',
        'beban_umum' => 'Beban Umum',
        'beban_lain' => 'Beban Lain',
    ];
    foreach ($sectionLabels as $key => $label) {
        foreach ((array) (($lra['sections'][$key] ?? []) ?: []) as $item) {
            $rows[] = [
                $label,
                (string) ($item['kode'] ?? ''),
                (string) ($item['nama'] ?? ''),
                (int) ($item['nilai'] ?? 0),
            ];
        }
    }

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function laporan_snapshot_fetch_payroll_paid(PDO $pdo): array
{
    require_once __DIR__ . '/payroll_pembimbing.php';
    payroll_pembimbing_ensure_gaji_table($pdo);
    if (!table_exists($pdo, 'keuangan_gaji_pembimbing') || !table_exists($pdo, 'pembimbing')) {
        return [];
    }

    $period = payroll_pembimbing_resolve_period($pdo, []);
    $mode = strtoupper((string) ($period['periode_mode'] ?? 'MASEHI')) === 'HIJRIYAH' ? 'HIJRIYAH' : 'MASEHI';
    $bulan = (int) ($period['month'] ?? (int) date('m'));
    $tahun = (int) ($period['year'] ?? (int) date('Y'));
    $periodeLabel = (string) ($period['period_label'] ?? ($bulan . '/' . $tahun));

    $st = $pdo->prepare('
        SELECT g.periode_mode, g.periode_label, g.bulan, g.tahun, g.total_bayar, g.total_hak,
               g.tanggal_bayar, g.keterangan, p.nip, p.nama_pembimbing
        FROM keuangan_gaji_pembimbing g
        INNER JOIN pembimbing p ON p.id = g.pembimbing_id
        WHERE g.periode_mode = :mode AND g.bulan = :bulan AND g.tahun = :tahun
        ORDER BY p.nama_pembimbing ASC
    ');
    $st->execute(['mode' => $mode, 'bulan' => $bulan, 'tahun' => $tahun]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['periode_label'] = $r['periode_label'] ?: $periodeLabel;
        $rows[] = $r;
    }

    return $rows;
}

/**
 * @return array<string, array{rows:list<list<mixed>>, rows_count:int, error?:string}>
 */
function laporan_snapshot_collect_all(PDO $pdo, string $asOf): array
{
    require_once __DIR__ . '/keuangan_neraca.php';
    require_once __DIR__ . '/keuangan_rekap_kas_bulan.php';
    require_once __DIR__ . '/keuangan_ta_context.php';
    require_once __DIR__ . '/tagihan_bulanan.php';
    require_once __DIR__ . '/keuangan_bos.php';
    require_once __DIR__ . '/santri_list_sort.php';

    if (function_exists('keuangan_ensure_schema_deferred')) {
        keuangan_ensure_schema_deferred($pdo);
    }
    if (function_exists('bos_ensure_schema')) {
        bos_ensure_schema($pdo);
    }

    $snapshotAt = date('Y-m-d H:i:s');
    $out = [];

    try {
        $neraca = keuangan_build_neraca($pdo, $asOf, 'pondok');
        $rows = laporan_snapshot_neraca_to_rows($neraca, $snapshotAt, $asOf);
        $out['Neraca_Pondok'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Neraca_Pondok'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        $berjalan = keuangan_periode_berjalan($pdo);
        $keuanganTa = keuangan_ta_resolve($pdo);
        $tm = (int) $keuanganTa['mulai'];
        $ts = (int) $keuanganTa['selesai'];
        $rekap = keuangan_build_rekap_kas_bulanan($pdo, $tm, $ts, (int) ($berjalan['bulan'] ?? 12));
        $rows = laporan_snapshot_rekap_kas_to_rows($rekap, $snapshotAt, $asOf);
        $out['Rekap_Kas_Bulanan'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Rekap_Kas_Bulanan'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        $berjalan = keuangan_periode_berjalan($pdo);
        $keuanganTa = keuangan_ta_resolve($pdo);
        $tm = (int) $keuanganTa['mulai'];
        $ts = (int) $keuanganTa['selesai'];
        $bulanTagihan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));
        $bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts);
        $slotAktif = pondok_slot_dari_bulan_tagihan($bulanSlots, $bulanTagihan);
        $bulanLabel = is_array($slotAktif) ? pondok_bulan_slot_label_tampilan($pdo, $slotAktif) : ('Bulan ' . $bulanTagihan);
        $taLabel = pondok_tahun_ajaran_label($pdo, ['mulai' => $tm, 'selesai' => $ts]);

        $listPack = tagihan_syahriyah_list_compute($pdo, $bulanTagihan, $tm, $ts, santri_list_sort_mode(null));
        $bodyAll = (array) ($listPack['body'] ?? []);
        $body = array_values(array_filter($bodyAll, static function (array $r): bool {
            $st = (string) ($r['status'] ?? '');

            return in_array($st, ['Belum', 'Sebagian'], true) || (int) ($r['sisa'] ?? 0) > 0;
        }));
        $rows = laporan_snapshot_tunggakan_to_rows($body, $snapshotAt, $asOf, $bulanLabel, $taLabel);
        $out['Tunggakan_Syahriyah'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Tunggakan_Syahriyah'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        $berjalan = keuangan_periode_berjalan($pdo);
        $keuanganTa = keuangan_ta_resolve($pdo);
        $tm = (int) $keuanganTa['mulai'];
        $ts = (int) $keuanganTa['selesai'];
        $taLabel = pondok_tahun_ajaran_label($pdo, ['mulai' => $tm, 'selesai' => $ts]);
        $bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts);
        $bulanList = [];
        foreach ($bulanSlots as $slot) {
            $bSlot = (int) ($slot['bulan_tagihan'] ?? 0);
            if ($bSlot >= 1 && $bSlot <= 12) {
                $bulanList[$bSlot] = true;
            }
        }
        $bulanList = array_map('intval', array_keys($bulanList));
        sort($bulanList);
        $sqlSantri = 'SELECT id, tingkatan, kategori_kelas FROM santri';
        if (column_exists($pdo, 'santri', 'is_aktif')) {
            $sqlSantri .= ' WHERE COALESCE(is_aktif, 1) = 1';
        }
        $santriRows = table_exists($pdo, 'santri') ? ($pdo->query($sqlSantri)->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $laporan12 = tagihan_laporan_12bulan_compute($pdo, $tm, $ts, $bulanSlots, $bulanList, $santriRows);
        $expectedByMonth = (array) ($laporan12['expected_by_month'] ?? []);
        $paidByMonth = (array) ($laporan12['paid_by_month'] ?? []);
        $rowsLaporan = [];
        foreach ($bulanSlots as $slot) {
            $b = (int) ($slot['bulan_tagihan'] ?? 0);
            if ($b < 1 || $b > 12) {
                continue;
            }
            $expected = (int) ($expectedByMonth[$b] ?? 0);
            $paid = (int) ($paidByMonth[$b] ?? 0);
            $rowsLaporan[] = [
                'bulan' => $b,
                'label' => pondok_bulan_slot_label_tampilan($pdo, $slot),
                'rentang_masehi' => ($slot['masehi_awal'] ?? '') !== '' ? ($slot['masehi_awal'] . ' – ' . $slot['masehi_akhir']) : '',
                'tagihan' => $expected,
                'terbayar' => $paid,
                'sisa' => max(0, $expected - $paid),
            ];
        }
        $rows = laporan_snapshot_syahriyah_12_to_rows($rowsLaporan, $snapshotAt, $asOf, $taLabel);
        $out['Syahriyah_12Bulan'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Syahriyah_12Bulan'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        require_once __DIR__ . '/payroll_pembimbing.php';
        $period = payroll_pembimbing_resolve_period($pdo, []);
        $periodeLabel = (string) ($period['period_label'] ?? '');
        $payrollRows = laporan_snapshot_fetch_payroll_paid($pdo);
        $rows = laporan_snapshot_payroll_to_rows($payrollRows, $snapshotAt, $asOf, $periodeLabel);
        $out['Payroll_Pembimbing'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Payroll_Pembimbing'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        $periodeRange = bos_resolve_periode_range(null);
        $tglMulai = (string) $periodeRange['tgl_mulai'];
        $tglSelesai = (string) $periodeRange['tgl_selesai'];
        $periodeLabel = (string) $periodeRange['label'];
        $bkuRows = bos_laporan_bku_rows_range($pdo, $tglMulai, $tglSelesai);
        $saldoAwal = bos_saldo_awal_periode($pdo, $tglMulai);
        $rows = laporan_snapshot_bos_bku_to_rows($bkuRows, $saldoAwal, $snapshotAt, $asOf, $periodeLabel);
        $out['BOS_BKU'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['BOS_BKU'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        $periodeRange = bos_resolve_periode_range(null);
        $tglMulai = (string) $periodeRange['tgl_mulai'];
        $tglSelesai = (string) $periodeRange['tgl_selesai'];
        $periodeLabel = (string) $periodeRange['label'];
        $lra = bos_laporan_lra_range($pdo, $tglMulai, $tglSelesai);
        $rows = laporan_snapshot_bos_lra_to_rows($lra, $snapshotAt, $asOf, $periodeLabel);
        $out['BOS_LRA'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['BOS_LRA'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        require_once __DIR__ . '/keuangan_impor_ekspor.php';
        $rows = keuangan_impor_ekspor_build_masuk_rows($pdo);
        $out['Pemasukan_Detail'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Pemasukan_Detail'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        require_once __DIR__ . '/keuangan_impor_ekspor.php';
        $rows = keuangan_impor_ekspor_build_keluar_rows($pdo);
        $out['Pengeluaran_Detail'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Pengeluaran_Detail'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        $rows = laporan_snapshot_uang_saku_to_rows($pdo);
        $out['Uang_Saku_Titipan'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Uang_Saku_Titipan'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    try {
        require_once __DIR__ . '/santri_export.php';
        $rows = santri_master_export_rows($pdo, true);
        $out['Data_Master_Santri'] = ['rows' => $rows, 'rows_count' => count($rows)];
    } catch (Throwable $e) {
        $out['Data_Master_Santri'] = ['rows' => [], 'rows_count' => 0, 'error' => $e->getMessage()];
    }

    return $out;
}

/**
 * @return array{ok:bool, mode:string, spreadsheet_id?:string, tabs?:array<string, mixed>, error?:string, shared?:list<string>, share_warning?:string}
 */
function laporan_snapshot_push_to_google(PDO $pdo, array $collected, bool $forceShare = false): array
{
    $credentials = google_sa_load_credentials(laporan_snapshot_sa_json_path($pdo));
    $token = google_sa_access_token($credentials);

    $spreadsheetId = trim((string) app_setting($pdo, 'laporan_snapshot_spreadsheet_id', ''));
    $title = 'PWA ' . app_brand_nama_ponpes($pdo) . ' — Snapshot Laporan';
    $spreadsheetId = google_sheets_ensure_spreadsheet($token, $spreadsheetId !== '' ? $spreadsheetId : null, $title);
    if (trim((string) app_setting($pdo, 'laporan_snapshot_spreadsheet_id', '')) !== $spreadsheetId) {
        save_setting($pdo, 'laporan_snapshot_spreadsheet_id', $spreadsheetId);
    }

    $tabKeys = laporan_snapshot_tab_keys();
    $sheetTitles = [];
    foreach ($tabKeys as $key) {
        $sheetTitles[] = laporan_snapshot_sheet_title($pdo, $key);
    }
    google_sheets_ensure_tabs($token, $spreadsheetId, $sheetTitles);

    $tabResults = [];
    foreach ($tabKeys as $key) {
        $sheetTitle = laporan_snapshot_sheet_title($pdo, $key);
        $pack = (array) ($collected[$key] ?? []);
        $rows = (array) ($pack['rows'] ?? []);
        try {
            $written = google_sheets_write_tab($token, $spreadsheetId, $sheetTitle, $rows);
            $tabResults[$sheetTitle] = [
                'rows_count' => $written,
                'error' => (string) ($pack['error'] ?? ''),
                'internal_key' => $key,
            ];
        } catch (Throwable $e) {
            $tabResults[$sheetTitle] = [
                'rows_count' => 0,
                'error' => $e->getMessage(),
                'internal_key' => $key,
            ];
        }
    }

    $shared = [];
    $shareWarning = '';
    $emailsCsv = trim((string) app_setting($pdo, 'laporan_snapshot_share_emails', ''));
    if ($emailsCsv !== '' && ($forceShare || app_setting($pdo, 'laporan_snapshot_last_share_date', '') !== date('Y-m-d'))) {
        try {
            $shared = google_drive_share_emails($token, $spreadsheetId, $emailsCsv, 'reader');
            save_setting($pdo, 'laporan_snapshot_last_share_date', date('Y-m-d'));
        } catch (Throwable $e) {
            $shareWarning = $e->getMessage();
        }
    }

    $hasError = false;
    foreach ($tabResults as $tr) {
        if (trim((string) ($tr['error'] ?? '')) !== '') {
            $hasError = true;
            break;
        }
    }

    return [
        'ok' => !$hasError,
        'mode' => 'ran',
        'spreadsheet_id' => $spreadsheetId,
        'tabs' => $tabResults,
        'shared' => $shared,
        'share_warning' => $shareWarning,
        'error' => $hasError ? 'Satu atau lebih tab gagal' : '',
    ];
}

/**
 * @return array{ran:bool, mode:string, note?:string}
 */
function laporan_snapshot_run(PDO $pdo, bool $force = false): array
{
    $today = date('Y-m-d');
    if (!$force) {
        if (!laporan_snapshot_enabled($pdo)) {
            return ['ran' => false, 'mode' => 'disabled', 'note' => 'disabled'];
        }
        if (!laporan_snapshot_send_time_ok($pdo)) {
            return ['ran' => false, 'mode' => 'waiting', 'note' => 'belum jam'];
        }
        if (trim((string) app_setting($pdo, 'laporan_snapshot_last_date', '')) === $today) {
            return ['ran' => false, 'mode' => 'idle', 'note' => 'sudah jalan hari ini'];
        }
    }

    $lock = 0;
    try {
        $lockSt = $pdo->query("SELECT GET_LOCK('pwa_laporan_snapshot_tick', 0)");
        $lock = (int) ($lockSt ? $lockSt->fetchColumn() : 0);
    } catch (Throwable $e) {
        $lock = 1;
    }
    if ($lock !== 1) {
        return ['ran' => false, 'mode' => 'locked', 'note' => 'proses lain berjalan'];
    }

    save_setting($pdo, 'laporan_snapshot_last_run_at', date('Y-m-d H:i:s'));

    try {
        $asOf = laporan_snapshot_as_of_date();
        $collected = laporan_snapshot_collect_all($pdo, $asOf);
        $push = laporan_snapshot_push_to_google($pdo, $collected, $force);
        $shareWarning = trim((string) ($push['share_warning'] ?? ''));
        save_setting($pdo, 'laporan_snapshot_last_result', json_encode([
            'as_of' => $asOf,
            'spreadsheet_id' => $push['spreadsheet_id'] ?? '',
            'tabs' => $push['tabs'] ?? [],
            'shared' => $push['shared'] ?? [],
            'share_warning' => $shareWarning,
            'ok' => (bool) ($push['ok'] ?? false),
            'error' => (string) ($push['error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));

        if (!($push['ok'] ?? false)) {
            save_setting($pdo, 'laporan_snapshot_last_error', (string) ($push['error'] ?? 'push gagal'));
        } else {
            save_setting($pdo, 'laporan_snapshot_last_error', $shareWarning);
            save_setting($pdo, 'laporan_snapshot_last_date', $today);
        }

        return [
            'ran' => true,
            'mode' => ($push['ok'] ?? false) ? 'ok' : 'error',
            'note' => (string) ($push['spreadsheet_id'] ?? ''),
        ];
    } catch (Throwable $e) {
        save_setting($pdo, 'laporan_snapshot_last_error', $e->getMessage());
        save_setting($pdo, 'laporan_snapshot_last_result', json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));

        return ['ran' => true, 'mode' => 'error', 'note' => $e->getMessage()];
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('pwa_laporan_snapshot_tick')");
        } catch (Throwable $e) {
        }
    }
}

/**
 * @return array{ran:bool, mode:string, note?:string}
 */
function laporan_snapshot_run_tick(PDO $pdo): array
{
    return laporan_snapshot_run($pdo, false);
}

/** Cron snapshot harian dianggap stale jika tidak ada tick push lebih lama dari ini (detik). */
function laporan_snapshot_cron_stale_after_sec(): int
{
    return 36 * 3600;
}

/**
 * Snapshot otomatis sehat: sukses hari ini, atau masih menunggu jam push hari ini dengan sukses kemarin.
 */
function laporan_snapshot_cron_recently_active(PDO $pdo, ?int $maxAgeSec = null): bool
{
    if (!laporan_snapshot_enabled($pdo)) {
        return false;
    }
    $maxAgeSec ??= laporan_snapshot_cron_stale_after_sec();
    $today = date('Y-m-d');
    $lastDate = trim((string) app_setting($pdo, 'laporan_snapshot_last_date', ''));
    if ($lastDate === $today) {
        return true;
    }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($lastDate === $yesterday && !laporan_snapshot_send_time_ok($pdo)) {
        return true;
    }
    $lastRun = trim((string) app_setting($pdo, 'laporan_snapshot_last_run_at', ''));
    if ($lastRun === '') {
        return false;
    }
    $ts = strtotime($lastRun);

    return $ts !== false && (time() - $ts) <= $maxAgeSec;
}

function laporan_snapshot_cron_is_stale(PDO $pdo, ?int $maxAgeSec = null): bool
{
    if (!laporan_snapshot_enabled($pdo)) {
        return false;
    }

    return !laporan_snapshot_cron_recently_active($pdo, $maxAgeSec);
}

/**
 * @return array<string, mixed>
 */
function laporan_snapshot_status(PDO $pdo): array
{
    $lastResult = json_decode((string) app_setting($pdo, 'laporan_snapshot_last_result', ''), true);

    return [
        'enabled' => laporan_snapshot_enabled($pdo),
        'jam' => laporan_snapshot_jam($pdo),
        'send_time_ok' => laporan_snapshot_send_time_ok($pdo),
        'last_date' => trim((string) app_setting($pdo, 'laporan_snapshot_last_date', '')),
        'last_run_at' => trim((string) app_setting($pdo, 'laporan_snapshot_last_run_at', '')),
        'last_error' => trim((string) app_setting($pdo, 'laporan_snapshot_last_error', '')),
        'spreadsheet_id' => trim((string) app_setting($pdo, 'laporan_snapshot_spreadsheet_id', '')),
        'share_emails' => trim((string) app_setting($pdo, 'laporan_snapshot_share_emails', '')),
        'sa_json_path' => laporan_snapshot_sa_json_path($pdo),
        'last_result' => is_array($lastResult) ? $lastResult : null,
        'cron_recently_active' => laporan_snapshot_cron_recently_active($pdo),
        'cron_stale' => laporan_snapshot_cron_is_stale($pdo),
    ];
}
