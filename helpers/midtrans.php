<?php

declare(strict_types=1);

/**
 * Midtrans Snap — bayar tagihan bulanan dari portal wali.
 * Settlement webhook → keuangan_save_pembayaran (metode MIDTRANS).
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/app_path.php';

/**
 * Override opsional dari config/midtrans.local.php (jangan di-commit).
 *
 * @return array<string, mixed>
 */
function midtrans_local_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [];
    $file = dirname(__DIR__) . '/config/midtrans.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }

    return $cfg;
}

function midtrans_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_midtrans_order (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id VARCHAR(64) NOT NULL,
            santri_id INT NOT NULL,
            wali_santri_id INT NULL,
            jenis_periode ENUM('BULANAN','AWAL_TAHUN') NOT NULL DEFAULT 'BULANAN',
            bulan_tagihan TINYINT NOT NULL DEFAULT 0,
            tahun_ajaran_mulai SMALLINT NOT NULL,
            tahun_ajaran_selesai SMALLINT NOT NULL,
            items_json TEXT NOT NULL,
            gross_amount INT NOT NULL DEFAULT 0,
            snap_token VARCHAR(255) NULL,
            transaction_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            midtrans_payload MEDIUMTEXT NULL,
            pembayaran_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_midtrans_order_id (order_id),
            INDEX idx_midtrans_santri (santri_id),
            INDEX idx_midtrans_status (transaction_status)
        )
    ");

    if (!function_exists('ensure_keuangan_transaksi_tables')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    ensure_keuangan_transaksi_tables($pdo);
    midtrans_ensure_metode_bayar_enum($pdo);
}

function midtrans_ensure_metode_bayar_enum(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar')) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE keuangan_pembayaran MODIFY COLUMN metode_bayar ENUM('KAS','TRANSFER','MIDTRANS') NOT NULL DEFAULT 'KAS'");
    } catch (PDOException $e) {
        // ignore if already applied / engine quirk
    }
}

function midtrans_enabled(PDO $pdo): bool
{
    $local = midtrans_local_config();
    if (array_key_exists('enabled', $local)) {
        $on = !empty($local['enabled']);
    } else {
        $on = trim((string) app_setting($pdo, 'midtrans_enabled', '0')) === '1';
    }

    if (!$on
        || midtrans_server_key($pdo) === ''
        || midtrans_client_key($pdo) === ''
        || midtrans_akun_id($pdo) <= 0
    ) {
        return false;
    }

    return midtrans_key_mode_check($pdo)['ok'];
}

function midtrans_is_production(PDO $pdo): bool
{
    $local = midtrans_local_config();
    if (!empty($local['mode'])) {
        return strtolower(trim((string) $local['mode'])) === 'production';
    }

    return strtolower(trim((string) app_setting($pdo, 'midtrans_mode', 'sandbox'))) === 'production';
}

function midtrans_server_key(PDO $pdo): string
{
    $local = midtrans_local_config();
    if (!empty($local['server_key'])) {
        return trim((string) $local['server_key']);
    }

    return trim((string) app_setting($pdo, 'midtrans_server_key', ''));
}

function midtrans_client_key(PDO $pdo): string
{
    $local = midtrans_local_config();
    if (!empty($local['client_key'])) {
        return trim((string) $local['client_key']);
    }

    return trim((string) app_setting($pdo, 'midtrans_client_key', ''));
}

function midtrans_akun_id(PDO $pdo): int
{
    $local = midtrans_local_config();
    if (!empty($local['akun_id'])) {
        return (int) $local['akun_id'];
    }

    return (int) app_setting($pdo, 'midtrans_akun_id', '0');
}

/**
 * Metode yang diprioritaskan di Snap (QRIS + Virtual Account).
 *
 * @return list<string>
 */
function midtrans_enabled_payments_default(): array
{
    return [
        'other_qris',
        'bca_va',
        'bni_va',
        'bri_va',
        'permata_va',
        'cimb_va',
        'danamon_va',
        'other_va',
        'echannel',
        'gopay',
        'shopeepay',
    ];
}

/**
 * Cek konsistensi mode sandbox/production dengan prefix key.
 *
 * @return array{ok:bool,message:string,key_kind:string}
 */
function midtrans_key_mode_check(PDO $pdo): array
{
    $server = midtrans_server_key($pdo);
    $client = midtrans_client_key($pdo);
    $prodMode = midtrans_is_production($pdo);
    $serverSb = str_starts_with($server, 'SB-');
    $clientSb = str_starts_with($client, 'SB-');

    if ($server === '' || $client === '') {
        return ['ok' => false, 'message' => 'Server Key / Client Key belum diisi.', 'key_kind' => 'empty'];
    }

    if ($serverSb !== $clientSb) {
        return [
            'ok' => false,
            'message' => 'Server Key dan Client Key tidak cocok (satu sandbox, satu production).',
            'key_kind' => 'mixed',
        ];
    }

    $keysAreSandbox = $serverSb;
    if ($prodMode && $keysAreSandbox) {
        return [
            'ok' => false,
            'message' => 'Mode Production tetapi key berawalan SB- (sandbox). Ganti mode ke Sandbox atau pakai key production.',
            'key_kind' => 'sandbox',
        ];
    }
    if (!$prodMode && !$keysAreSandbox) {
        return [
            'ok' => false,
            'message' => 'Mode Sandbox tetapi key tanpa awalan SB- (terlihat production). Untuk uji coba, pakai key dari dashboard.sandbox.midtrans.com atau ubah mode ke Production.',
            'key_kind' => 'production',
        ];
    }

    return [
        'ok' => true,
        'message' => $keysAreSandbox ? 'Key sandbox cocok dengan mode Sandbox.' : 'Key production cocok dengan mode Production.',
        'key_kind' => $keysAreSandbox ? 'sandbox' : 'production',
    ];
}

/**
 * @return array{ready:bool,items:list<array{ok:bool,label:string}>}
 */
function midtrans_readiness_checklist(PDO $pdo): array
{
    $keyCheck = midtrans_key_mode_check($pdo);
    $items = [
        [
            'ok' => trim((string) app_setting($pdo, 'midtrans_enabled', '0')) === '1' || !empty(midtrans_local_config()['enabled']),
            'label' => 'Fitur Midtrans diaktifkan',
        ],
        [
            'ok' => midtrans_server_key($pdo) !== '' && midtrans_client_key($pdo) !== '',
            'label' => 'Server Key & Client Key terisi',
        ],
        [
            'ok' => $keyCheck['ok'],
            'label' => $keyCheck['message'],
        ],
        [
            'ok' => midtrans_akun_id($pdo) > 0,
            'label' => 'Akun kas/bank penerima dipilih',
        ],
        [
            'ok' => midtrans_enabled($pdo) && $keyCheck['ok'],
            'label' => 'Siap tampilkan tombol Bayar online di portal wali',
        ],
    ];
    $ready = true;
    foreach ($items as $it) {
        if (empty($it['ok'])) {
            $ready = false;
            break;
        }
    }

    return ['ready' => $ready, 'items' => $items];
}

function midtrans_api_base(PDO $pdo): string
{
    return midtrans_is_production($pdo)
        ? 'https://api.midtrans.com'
        : 'https://api.sandbox.midtrans.com';
}

/**
 * Ambil status transaksi dari Midtrans (untuk sync tanpa webhook / uji lokal).
 *
 * @return array{ok:bool,message?:string,data?:array<string,mixed>}
 */
function midtrans_http_status(PDO $pdo, string $orderId): array
{
    $serverKey = midtrans_server_key($pdo);
    if ($serverKey === '' || $orderId === '') {
        return ['ok' => false, 'message' => 'Konfigurasi/order kosong'];
    }
    $url = midtrans_api_base($pdo) . '/v2/' . rawurlencode($orderId) . '/status';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'message' => 'cURL tidak tersedia'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($serverKey . ':'),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') {
        return ['ok' => false, 'message' => $err !== '' ? $err : 'Gagal status Midtrans'];
    }
    $data = json_decode($body, true);
    if (!is_array($data) || $http >= 400) {
        return ['ok' => false, 'message' => (string) ($data['status_message'] ?? ('HTTP ' . $http))];
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * Sinkron order pending milik santri (dipakai saat kembali dari Snap di localhost).
 *
 * @return array{synced:int,message:string}
 */
function midtrans_sync_pending_for_santri(PDO $pdo, int $santriId): array
{
    midtrans_ensure_schema($pdo);
    if ($santriId <= 0) {
        return ['synced' => 0, 'message' => 'Santri tidak valid'];
    }
    $st = $pdo->prepare("
        SELECT order_id FROM keuangan_midtrans_order
        WHERE santri_id = :sid
          AND (pembayaran_id IS NULL OR pembayaran_id = 0 OR pembayaran_id = -1)
          AND transaction_status IN ('pending','capture','settlement','authorize')
        ORDER BY id DESC
        LIMIT 8
    ");
    $st->execute(['sid' => $santriId]);
    $orders = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $synced = 0;
    foreach ($orders as $orderId) {
        $orderId = trim((string) $orderId);
        if ($orderId === '') {
            continue;
        }
        $status = midtrans_http_status($pdo, $orderId);
        if (!$status['ok'] || !is_array($status['data'] ?? null)) {
            continue;
        }
        $data = $status['data'];
        // Bangun notifikasi palsu yang lolos verifikasi signature lokal
        $gross = (string) ($data['gross_amount'] ?? '0');
        $statusCode = (string) ($data['status_code'] ?? '200');
        $sig = hash('sha512', $orderId . $statusCode . $gross . midtrans_server_key($pdo));
        $notif = array_merge($data, [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $gross,
            'signature_key' => $sig,
        ]);
        $res = midtrans_handle_notification($pdo, $notif);
        if (!empty($res['ok']) && (int) ($res['pembayaran_id'] ?? 0) > 0) {
            $synced++;
        }
    }

    return [
        'synced' => $synced,
        'message' => $synced > 0
            ? "Berhasil mencatat {$synced} pembayaran Midtrans."
            : 'Belum ada pembayaran Midtrans yang siap dicatat.',
    ];
}

function midtrans_snap_js_url(PDO $pdo): string
{
    return midtrans_is_production($pdo)
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
}

function midtrans_snap_api_base(PDO $pdo): string
{
    return midtrans_is_production($pdo)
        ? 'https://app.midtrans.com/snap/v1'
        : 'https://app.sandbox.midtrans.com/snap/v1';
}

function midtrans_notification_url(): string
{
    return rtrim(app_public_url(), '/') . app_href('/api/midtrans_notification.php');
}

/**
 * @return array{ok:bool,message?:string,token?:string,order_id?:string,redirect_url?:string,client_key?:string,snap_js?:string,gross_amount?:int}
 */
function midtrans_create_snap_for_tagihan(
    PDO $pdo,
    int $santriId,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    ?int $waliSantriId = null,
    ?array $posFilter = null
): array {
    midtrans_ensure_schema($pdo);

    if (!midtrans_enabled($pdo)) {
        return ['ok' => false, 'message' => 'Pembayaran Midtrans belum diaktifkan.'];
    }
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12) {
        return ['ok' => false, 'message' => 'Data tagihan tidak valid.'];
    }

    if (!function_exists('keuangan_biaya_definitions')) {
        require_once __DIR__ . '/keuangan_defs.php';
    }
    if (!function_exists('keuangan_tagihan_breakdown_for_santri')) {
        require_once __DIR__ . '/keuangan_rekap.php';
    }
    if (!function_exists('keuangan_pembayaran_validasi_urutan_bulan')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }

    $urutan = keuangan_pembayaran_validasi_urutan_bulan($pdo, $santriId, $bulanTagihan, $tahunMulai, $tahunSelesai);
    if (!$urutan['ok']) {
        return ['ok' => false, 'message' => (string) ($urutan['message'] ?? 'Urutan bulan tagihan belum valid.')];
    }

    $defs = keuangan_biaya_definitions();
    $breakdown = keuangan_tagihan_breakdown_for_santri(
        $pdo,
        $santriId,
        'BULANAN',
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $defs
    );

    $items = [];
    $gross = 0;
    $namaBySlug = [];
    foreach ($defs as $def) {
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $namaBySlug[$slug] = (string) ($def['nama'] ?? $slug);
    }

    foreach ($breakdown as $slug => $row) {
        $slug = (string) $slug;
        if ($posFilter !== null && $posFilter !== [] && !in_array($slug, $posFilter, true)) {
            continue;
        }
        $sisa = (int) ($row['sisa'] ?? 0);
        if ($sisa <= 0) {
            continue;
        }
        $items[] = [
            'slug' => $slug,
            'nama' => $namaBySlug[$slug] ?? $slug,
            'nominal' => $sisa,
        ];
        $gross += $sisa;
    }

    if ($items === [] || $gross <= 0) {
        return ['ok' => false, 'message' => 'Tidak ada sisa tagihan untuk bulan ini.'];
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $stSantri = $pdo->prepare("SELECT nis, {$nameCol} AS nama FROM santri WHERE id = :id LIMIT 1");
    $stSantri->execute(['id' => $santriId]);
    $santri = $stSantri->fetch(PDO::FETCH_ASSOC) ?: [];
    $namaSantri = trim((string) ($santri['nama'] ?? 'Santri'));
    $nis = trim((string) ($santri['nis'] ?? (string) $santriId));

    $orderId = midtrans_generate_order_id($santriId, $bulanTagihan);
    $itemDetails = [];
    foreach ($items as $it) {
        $itemDetails[] = [
            'id' => $it['slug'],
            'price' => (int) $it['nominal'],
            'quantity' => 1,
            'name' => mb_substr((string) $it['nama'], 0, 50),
        ];
    }

    $bulanNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $bulanNama = $bulanNames[$bulanTagihan] ?? ('Bulan ' . $bulanTagihan);

    $payload = [
        'transaction_details' => [
            'order_id' => $orderId,
            'gross_amount' => $gross,
        ],
        'item_details' => $itemDetails,
        'customer_details' => [
            'first_name' => mb_substr($namaSantri, 0, 50),
            'last_name' => 'NIS ' . mb_substr($nis, 0, 20),
        ],
        'custom_field1' => (string) $santriId,
        'custom_field2' => 'BULANAN:' . $bulanTagihan,
        'custom_field3' => $tahunMulai . '/' . $tahunSelesai,
        'callbacks' => [
            'finish' => rtrim(app_public_url(), '/') . app_href('/wali/keuangan.php?tab=bayar&midtrans=finish'),
        ],
        // Jangan kirim enabled_payments — biarkan Midtrans menampilkan semua channel
        // yang aktif di dashboard (QRIS/VA). Filter ketat justru bisa mengosongkan metode.
    ];

    $snap = midtrans_http_snap_create($pdo, $payload);
    if (!$snap['ok']) {
        return ['ok' => false, 'message' => $snap['message'] ?? 'Gagal membuat transaksi Midtrans.'];
    }

    $token = (string) ($snap['token'] ?? '');
    $redirect = trim((string) ($snap['redirect_url'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'message' => 'Token Snap kosong dari Midtrans.'];
    }
    if ($redirect === '') {
        $redirect = midtrans_is_production($pdo)
            ? ('https://app.midtrans.com/snap/v4/redirection/' . rawurlencode($token))
            : ('https://app.sandbox.midtrans.com/snap/v4/redirection/' . rawurlencode($token));
    }

    $ins = $pdo->prepare('
        INSERT INTO keuangan_midtrans_order (
            order_id, santri_id, wali_santri_id, jenis_periode, bulan_tagihan,
            tahun_ajaran_mulai, tahun_ajaran_selesai, items_json, gross_amount,
            snap_token, transaction_status
        ) VALUES (
            :order_id, :santri_id, :wali_santri_id, :jenis_periode, :bulan_tagihan,
            :tahun_ajaran_mulai, :tahun_ajaran_selesai, :items_json, :gross_amount,
            :snap_token, :transaction_status
        )
    ');
    $ins->execute([
        'order_id' => $orderId,
        'santri_id' => $santriId,
        'wali_santri_id' => $waliSantriId,
        'jenis_periode' => 'BULANAN',
        'bulan_tagihan' => $bulanTagihan,
        'tahun_ajaran_mulai' => $tahunMulai,
        'tahun_ajaran_selesai' => $tahunSelesai,
        'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
        'gross_amount' => $gross,
        'snap_token' => $token,
        'transaction_status' => 'pending',
    ]);

    return [
        'ok' => true,
        'token' => $token,
        'order_id' => $orderId,
        'redirect_url' => $redirect,
        'client_key' => midtrans_client_key($pdo),
        'snap_js' => midtrans_snap_js_url($pdo),
        'gross_amount' => $gross,
        'message' => 'Tagihan ' . $bulanNama . ' — Rp ' . number_format($gross, 0, ',', '.'),
    ];
}

/**
 * Uji koneksi Snap (buat token dummy kecil) — untuk checklist sandbox.
 *
 * @return array{ok:bool,message:string,token?:string,redirect_url?:string}
 */
function midtrans_test_snap_connection(PDO $pdo): array
{
    $keyCheck = midtrans_key_mode_check($pdo);
    if (!$keyCheck['ok']) {
        return ['ok' => false, 'message' => $keyCheck['message']];
    }
    if (midtrans_server_key($pdo) === '' || midtrans_client_key($pdo) === '') {
        return ['ok' => false, 'message' => 'Server Key / Client Key kosong.'];
    }

    $orderId = 'PNM-TEST-' . date('ymdHis') . '-' . bin2hex(random_bytes(2));
    $payload = [
        'transaction_details' => [
            'order_id' => $orderId,
            'gross_amount' => 10000,
        ],
        'item_details' => [
            [
                'id' => 'test',
                'price' => 10000,
                'quantity' => 1,
                'name' => 'Uji koneksi Midtrans',
            ],
        ],
        'customer_details' => [
            'first_name' => 'Uji',
            'last_name' => 'Sandbox',
        ],
    ];

    $snap = midtrans_http_snap_create($pdo, $payload);
    if (!$snap['ok']) {
        return ['ok' => false, 'message' => (string) ($snap['message'] ?? 'Gagal uji Snap')];
    }

    $mode = midtrans_is_production($pdo) ? 'Production' : 'Sandbox';

    return [
        'ok' => true,
        'message' => "Koneksi {$mode} OK. Token Snap berhasil dibuat (uji Rp 10.000).",
        'token' => (string) ($snap['token'] ?? ''),
        'redirect_url' => (string) ($snap['redirect_url'] ?? ''),
    ];
}

function midtrans_generate_order_id(int $santriId, int $bulan): string
{
    return sprintf('PNM%d-B%02d-%s-%s', $santriId, $bulan, date('ymdHis'), bin2hex(random_bytes(2)));
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool,message?:string,token?:string,redirect_url?:string}
 */
function midtrans_http_snap_create(PDO $pdo, array $payload): array
{
    $serverKey = midtrans_server_key($pdo);
    if ($serverKey === '') {
        return ['ok' => false, 'message' => 'Server Key Midtrans kosong.'];
    }

    // Jangan kirim enabled_payments — biarkan Midtrans menampilkan semua channel dashboard
    unset($payload['enabled_payments']);

    $url = midtrans_snap_api_base($pdo) . '/transactions';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'message' => 'Payload Midtrans tidak valid.'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'message' => 'cURL tidak tersedia.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($serverKey . ':'),
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return ['ok' => false, 'message' => 'Gagal menghubungi Midtrans: ' . ($err !== '' ? $err : 'unknown')];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'Respons Midtrans tidak valid (HTTP ' . $httpCode . ').'];
    }

    if ($httpCode >= 400 || empty($decoded['token'])) {
        $msgs = $decoded['error_messages'] ?? null;
        if (is_array($msgs) && $msgs !== []) {
            $msg = implode('; ', array_map('strval', $msgs));
        } else {
            $msg = (string) ($decoded['status_message'] ?? ('HTTP ' . $httpCode));
        }

        return ['ok' => false, 'message' => 'Midtrans: ' . $msg];
    }

    $token = (string) $decoded['token'];
    $redirect = trim((string) ($decoded['redirect_url'] ?? ''));
    if ($redirect === '') {
        // Cadangan halaman Snap penuh (QRIS/VA tetap bisa dipilih)
        $redirect = midtrans_is_production($pdo)
            ? ('https://app.midtrans.com/snap/v4/redirection/' . rawurlencode($token))
            : ('https://app.sandbox.midtrans.com/snap/v4/redirection/' . rawurlencode($token));
    }

    return [
        'ok' => true,
        'token' => $token,
        'redirect_url' => $redirect,
    ];
}

function midtrans_verify_signature(PDO $pdo, string $orderId, string $statusCode, string $grossAmount, string $signatureKey): bool
{
    $serverKey = midtrans_server_key($pdo);
    if ($serverKey === '' || $signatureKey === '') {
        return false;
    }
    $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

    return hash_equals($expected, $signatureKey);
}

/**
 * Proses notifikasi Midtrans (idempotent).
 *
 * @param array<string, mixed> $notif
 * @return array{ok:bool,message:string,pembayaran_id?:int}
 */
function midtrans_handle_notification(PDO $pdo, array $notif): array
{
    midtrans_ensure_schema($pdo);

    $orderId = trim((string) ($notif['order_id'] ?? ''));
    $statusCode = trim((string) ($notif['status_code'] ?? ''));
    $grossAmount = trim((string) ($notif['gross_amount'] ?? ''));
    $signature = trim((string) ($notif['signature_key'] ?? ''));
    $trxStatus = strtolower(trim((string) ($notif['transaction_status'] ?? '')));
    $fraud = strtolower(trim((string) ($notif['fraud_status'] ?? '')));

    if ($orderId === '') {
        return ['ok' => false, 'message' => 'order_id kosong'];
    }
    if (!midtrans_verify_signature($pdo, $orderId, $statusCode, $grossAmount, $signature)) {
        return ['ok' => false, 'message' => 'Signature tidak valid'];
    }

    $st = $pdo->prepare('SELECT id, pembayaran_id, transaction_status FROM keuangan_midtrans_order WHERE order_id = :oid LIMIT 1');
    $st->execute(['oid' => $orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['ok' => false, 'message' => 'Order tidak ditemukan'];
    }

    $pdo->prepare('
        UPDATE keuangan_midtrans_order
        SET transaction_status = :st, midtrans_payload = :payload
        WHERE id = :id
    ')->execute([
        'st' => $trxStatus !== '' ? $trxStatus : (string) $order['transaction_status'],
        'payload' => json_encode($notif, JSON_UNESCAPED_UNICODE),
        'id' => (int) $order['id'],
    ]);

    $alreadyPaid = (int) ($order['pembayaran_id'] ?? 0) > 0;
    $isSuccess = ($trxStatus === 'capture' && ($fraud === '' || $fraud === 'accept'))
        || $trxStatus === 'settlement';

    if (!$isSuccess) {
        return ['ok' => true, 'message' => 'Status dicatat: ' . $trxStatus];
    }

    if ($alreadyPaid) {
        return [
            'ok' => true,
            'message' => 'Sudah tercatat',
            'pembayaran_id' => (int) $order['pembayaran_id'],
        ];
    }

    return midtrans_settle_order_to_pembayaran($pdo, $orderId);
}

/**
 * @return array{ok:bool,message:string,pembayaran_id?:int}
 */
function midtrans_settle_order_to_pembayaran(PDO $pdo, string $orderId): array
{
    midtrans_ensure_schema($pdo);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM keuangan_midtrans_order WHERE order_id = :oid LIMIT 1 FOR UPDATE');
        $st->execute(['oid' => $orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();

            return ['ok' => false, 'message' => 'Order tidak ditemukan'];
        }
        if ((int) ($order['pembayaran_id'] ?? 0) > 0) {
            $pid = (int) $order['pembayaran_id'];
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Sudah tercatat',
                'pembayaran_id' => $pid,
            ];
        }
        if ((int) ($order['pembayaran_id'] ?? 0) === -1) {
            $pdo->commit();

            return ['ok' => true, 'message' => 'Sedang diproses'];
        }

        // Claim slot agar webhook paralel tidak double-insert
        $claim = $pdo->prepare('UPDATE keuangan_midtrans_order SET pembayaran_id = -1 WHERE id = :id AND (pembayaran_id IS NULL OR pembayaran_id = 0)');
        $claim->execute(['id' => (int) $order['id']]);
        if ($claim->rowCount() < 1) {
            $pdo->commit();

            return ['ok' => true, 'message' => 'Sedang diproses'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $st = $pdo->prepare('SELECT * FROM keuangan_midtrans_order WHERE order_id = :oid LIMIT 1');
    $st->execute(['oid' => $orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['ok' => false, 'message' => 'Order tidak ditemukan'];
    }
    if ((int) ($order['pembayaran_id'] ?? 0) > 0 && (int) $order['pembayaran_id'] !== -1) {
        return [
            'ok' => true,
            'message' => 'Sudah tercatat',
            'pembayaran_id' => (int) $order['pembayaran_id'],
        ];
    }

    $items = json_decode((string) ($order['items_json'] ?? '[]'), true);
    if (!is_array($items) || $items === []) {
        $pdo->prepare("UPDATE keuangan_midtrans_order SET pembayaran_id = NULL, transaction_status = 'deny' WHERE order_id = :oid")
            ->execute(['oid' => $orderId]);

        return ['ok' => false, 'message' => 'Item order kosong'];
    }

    if (!function_exists('keuangan_biaya_definitions')) {
        require_once __DIR__ . '/keuangan_defs.php';
    }
    if (!function_exists('keuangan_tagihan_breakdown_for_santri')) {
        require_once __DIR__ . '/keuangan_rekap.php';
    }
    require_once __DIR__ . '/keuangan_transaksi.php';

    $santriId = (int) $order['santri_id'];
    $bulan = (int) $order['bulan_tagihan'];
    $tahunMulai = (int) $order['tahun_ajaran_mulai'];
    $tahunSelesai = (int) $order['tahun_ajaran_selesai'];
    $defs = keuangan_biaya_definitions();
    $breakdown = keuangan_tagihan_breakdown_for_santri(
        $pdo,
        $santriId,
        'BULANAN',
        $bulan,
        $tahunMulai,
        $tahunSelesai,
        $defs
    );

    $bayarPos = [];
    $post = [
        'santri_id' => $santriId,
        'jenis_periode' => 'BULANAN',
        'bulan_tagihan' => $bulan,
        'tahun_ajaran_mulai' => $tahunMulai,
        'tahun_ajaran_selesai' => $tahunSelesai,
        'tanggal_bayar' => date('Y-m-d'),
        'metode_bayar' => 'MIDTRANS',
        'akun_id' => midtrans_akun_id($pdo),
        'no_referensi' => $orderId,
        'keterangan' => 'Pembayaran online Midtrans ' . $orderId,
        'bayar_pos' => [],
    ];

    foreach ($items as $it) {
        $slug = (string) ($it['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $wanted = (int) ($it['nominal'] ?? 0);
        $sisa = (int) (($breakdown[$slug]['sisa'] ?? 0));
        $nominal = min($wanted, $sisa);
        if ($nominal <= 0) {
            continue;
        }
        $bayarPos[] = $slug;
        $post['nominal_' . $slug] = (string) $nominal;
    }
    $post['bayar_pos'] = $bayarPos;

    if ($bayarPos === []) {
        $pdo->prepare("UPDATE keuangan_midtrans_order SET pembayaran_id = NULL, transaction_status = 'expire' WHERE order_id = :oid")
            ->execute(['oid' => $orderId]);

        return ['ok' => true, 'message' => 'Tagihan sudah lunas (tidak ada sisa). Order ditutup.'];
    }

    $save = keuangan_save_pembayaran($pdo, $post, 0);
    if (!$save['ok']) {
        $pdo->prepare('UPDATE keuangan_midtrans_order SET pembayaran_id = NULL WHERE order_id = :oid AND pembayaran_id = -1')
            ->execute(['oid' => $orderId]);

        return ['ok' => false, 'message' => (string) ($save['message'] ?? 'Gagal menyimpan pembayaran')];
    }

    $pembayaranId = (int) ($save['id'] ?? 0);
    $pdo->prepare('
        UPDATE keuangan_midtrans_order
        SET pembayaran_id = :pid, transaction_status = :st
        WHERE order_id = :oid
    ')->execute([
        'pid' => $pembayaranId,
        'st' => 'settlement',
        'oid' => $orderId,
    ]);

    // Hapus cache tagihan portal wali
    if (isset($_SESSION) && is_array($_SESSION)) {
        foreach (array_keys($_SESSION) as $k) {
            if (is_string($k) && str_starts_with($k, 'wali_tagihan_kum_' . $santriId)) {
                unset($_SESSION[$k]);
            }
        }
    }

    return [
        'ok' => true,
        'message' => (string) ($save['message'] ?? 'Pembayaran Midtrans tercatat'),
        'pembayaran_id' => $pembayaranId,
    ];
}

/**
 * @return list<array{bulan:int,tahun_mulai:int,tahun_selesai:int,label:string,sisa:int}>
 */
function midtrans_tunggakan_options_for_santri(PDO $pdo, int $santriId, string $kelasKategori): array
{
    if (!function_exists('wali_portal_tagihan_sampai_bulan_berjalan')) {
        require_once __DIR__ . '/wali_portal.php';
    }
    $tagihan = wali_portal_tagihan_sampai_bulan_berjalan($pdo, $santriId, $kelasKategori);
    $berjalan = (array) ($tagihan['berjalan'] ?? []);
    $tm = (int) ($berjalan['mulai'] ?? 0);
    $ts = (int) ($berjalan['selesai'] ?? 0);
    $out = [];
    foreach ((array) ($tagihan['per_bulan_tunggakan'] ?? []) as $row) {
        $bulan = (int) ($row['bulan'] ?? $row['bulan_tagihan'] ?? 0);
        $sisa = (int) ($row['sisa_total'] ?? 0);
        if ($bulan < 1 || $sisa <= 0) {
            continue;
        }
        $out[] = [
            'bulan' => $bulan,
            'tahun_mulai' => $tm,
            'tahun_selesai' => $ts,
            'label' => (string) ($row['label'] ?? ('Bulan ' . $bulan)),
            'sisa' => $sisa,
        ];
    }

    // Fallback: bulan berjalan jika ada sisa tapi daftar tunggakan kosong
    if ($out === [] && (int) ($tagihan['sisa_total'] ?? 0) > 0) {
        $bulan = (int) ($berjalan['bulan'] ?? $berjalan['bulan_tagihan'] ?? 0);
        if ($bulan >= 1 && $bulan <= 12) {
            $out[] = [
                'bulan' => $bulan,
                'tahun_mulai' => $tm,
                'tahun_selesai' => $ts,
                'label' => (string) ($berjalan['periode_tampilan'] ?? $berjalan['bulan_label'] ?? ('Bulan ' . $bulan)),
                'sisa' => (int) $tagihan['sisa_total'],
            ];
        }
    }

    return $out;
}
