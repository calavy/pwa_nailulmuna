<?php

declare(strict_types=1);

/**
 * Lapisan terpusat pengiriman WA otomatis (personal & grup Fonte).
 * Dipakai oleh send_wa_message_with_result / send_wa_bulk di helpers/app.php.
 */

/** Normalisasi target: nomor personal Indonesia atau ID grup WhatsApp / Fonte. */
function wa_otomatis_normalize_target(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $compact = preg_replace('/\s+/', '', $raw) ?? $raw;
    if (preg_match('/^[\d-]+@g\.us$/i', $compact)) {
        return strtolower($compact);
    }

    $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }

    if (wa_otomatis_is_group_digits($digits)) {
        return $digits;
    }

    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }
    if (strpos($digits, '8') === 0 && strlen($digits) <= 13) {
        return '62' . $digits;
    }

    return $digits;
}

/** Grup WA / Fonte biasanya ≥15 digit dan bukan format nomor HP 62… pendek. */
function wa_otomatis_is_group_digits(string $digits): bool
{
    $len = strlen($digits);
    if ($len < 15) {
        return false;
    }
    if (strpos($digits, '62') === 0 && $len <= 14) {
        return false;
    }

    return true;
}

function wa_otomatis_is_group_target(string $normalized): bool
{
    if (str_ends_with(strtolower($normalized), '@g.us')) {
        return true;
    }

    return wa_otomatis_is_group_digits($normalized);
}

/**
 * Fonnte connectOnly: true = tolak jika perangkat WA putus; false = antrekan sampai online.
 *
 * @param array<string, mixed> $override
 */
function wa_otomatis_fonnte_connect_only(PDO $pdo, array $override = []): bool
{
    if (array_key_exists('connect_only', $override)) {
        return (bool) $override['connect_only'];
    }

    return trim((string) app_setting($pdo, 'wa_fonnte_queue_offline', '0')) !== '1';
}

/**
 * Kategori delay pesan WA otomatis (sesuai tab pengaturan).
 *
 * @return array<string, array{key:string,label:string,tab:string}>
 */
function wa_otomatis_delay_kinds(): array
{
    return [
        'tagihan' => ['key' => 'wa_delay_tagihan', 'label' => 'Tagihan & pembayaran', 'tab' => 'tagihan'],
        'cashless' => ['key' => 'wa_delay_cashless', 'label' => 'Cashless', 'tab' => 'cashless'],
        'presensi' => ['key' => 'wa_delay_presensi', 'label' => 'Presensi', 'tab' => 'presensi'],
        'alpa' => ['key' => 'wa_delay_alpa', 'label' => 'Alpa', 'tab' => 'alpa'],
        'poin' => ['key' => 'wa_delay_poin', 'label' => 'Poin', 'tab' => 'poin'],
        'izin' => ['key' => 'wa_delay_izin', 'label' => 'Izin', 'tab' => 'izin'],
        'rapor' => ['key' => 'wa_delay_rapor', 'label' => 'Rapor', 'tab' => 'rapor'],
    ];
}

/** Validasi format delay Fonnte: "5" atau "5-10". Kosong/0 = tidak aktif. */
function wa_otomatis_validate_delay_string(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0') {
        return '';
    }

    if (!preg_match('/^(\d+)(?:-(\d+))?$/', $raw, $matches)) {
        return '';
    }

    $min = (int) $matches[1];
    $max = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $min;
    if ($min < 1 || $min > 300 || $max < $min || $max > 300) {
        return '';
    }

    return $max === $min ? (string) $min : ($min . '-' . $max);
}

/** Ambil nilai minimum delay (detik) dari string delay, atau 0 jika kosong. */
function wa_otomatis_delay_min_seconds(string $delay): int
{
    $delay = wa_otomatis_validate_delay_string($delay);
    if ($delay === '') {
        return 0;
    }
    if (preg_match('/^(\d+)/', $delay, $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

/** Ambil nilai maksimum delay (detik) dari string delay, atau 0 jika kosong. */
function wa_otomatis_delay_max_seconds(string $delay): int
{
    $delay = wa_otomatis_validate_delay_string($delay);
    if ($delay === '') {
        return 0;
    }
    if (preg_match('/^(\d+)(?:-(\d+))?$/', $delay, $matches)) {
        $min = (int) $matches[1];
        $max = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $min;

        return max($min, $max);
    }

    return 0;
}

/**
 * Jeda PHP (mikrodetik) dari string delay Fonnte, acak jika rentang.
 * $floorSec menaikkan batas bawah (tagihan massal).
 */
function wa_otomatis_delay_sleep_us(string $delay, int $floorSec = 0): int
{
    $min = wa_otomatis_delay_min_seconds($delay);
    $max = wa_otomatis_delay_max_seconds($delay);
    $floorSec = max(0, min(120, $floorSec));
    if ($min <= 0) {
        $min = $floorSec > 0 ? $floorSec : 12;
        $max = $min === 12 && $floorSec <= 12 ? 20 : $min;
    }
    if ($floorSec > 0 && $min < $floorSec) {
        $min = $floorSec;
        if ($max < $min) {
            $max = min(120, $floorSec + 8);
        }
    }
    $sec = $max > $min ? random_int($min, $max) : $min;

    return max(1, $sec) * 1000000;
}

function wa_fonte_bulk_limit(PDO $pdo): int
{
    return max(1, min(50, (int) app_setting($pdo, 'wa_fonte_bulk_limit', '15')));
}

function wa_fonte_warmup_hours(PDO $pdo): int
{
    return max(1, min(48, (int) app_setting($pdo, 'wa_fonte_warmup_hours', '3')));
}

function wa_fonte_warmup_until_ts(PDO $pdo): int
{
    $raw = trim((string) app_setting($pdo, 'wa_fonte_warmup_until', ''));
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);

    return is_int($ts) ? $ts : 0;
}

function wa_fonte_warmup_active(PDO $pdo): bool
{
    return wa_fonte_warmup_until_ts($pdo) > time();
}

function wa_fonte_start_warmup(PDO $pdo, string $reason = 'scan'): void
{
    $hours = wa_fonte_warmup_hours($pdo);
    $until = date('Y-m-d H:i:s', time() + ($hours * 3600));
    save_setting($pdo, 'wa_fonte_warmup_until', $until);
    save_setting($pdo, 'wa_fonte_warmup_started_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_fonte_warmup_reason', $reason);
    save_setting($pdo, 'wa_fonte_warmup_pending', '0');
    save_setting($pdo, 'wa_fonte_disconnected_at', '');
}

function wa_fonte_mark_disconnected(PDO $pdo): void
{
    if (trim((string) app_setting($pdo, 'wa_fonte_disconnected_at', '')) === '') {
        save_setting($pdo, 'wa_fonte_disconnected_at', date('Y-m-d H:i:s'));
    }
    save_setting($pdo, 'wa_fonte_warmup_pending', '1');
}

function wa_fonte_mark_connected_maybe_warmup(PDO $pdo): void
{
    $pending = trim((string) app_setting($pdo, 'wa_fonte_warmup_pending', '0')) === '1';
    if (!$pending) {
        return;
    }
    if (wa_fonte_warmup_active($pdo)) {
        save_setting($pdo, 'wa_fonte_warmup_pending', '0');
        save_setting($pdo, 'wa_fonte_disconnected_at', '');

        return;
    }
    wa_fonte_start_warmup($pdo, 'reconnect');
}

function wa_fonte_bulk_blocked_reason(PDO $pdo): ?string
{
    if (!wa_fonte_warmup_active($pdo)) {
        return null;
    }
    $until = trim((string) app_setting($pdo, 'wa_fonte_warmup_until', ''));
    $hours = wa_fonte_warmup_hours($pdo);

    return 'Blast Fonte ditahan ' . $hours . ' jam setelah perangkat baru di-scan / baru nyambung. Kirim 1 nomor masih boleh. Blast lagi setelah ' . $until . '.';
}

/** Delay API tagihan massal: kosong atau di bawah 12 detik → 12-20. */
function wa_fonte_safe_tagihan_delay(PDO $pdo): string
{
    $raw = wa_otomatis_fonnte_api_delay($pdo, ['kind' => 'tagihan']);
    if ($raw === '' || wa_otomatis_delay_min_seconds($raw) < 12) {
        return '12-20';
    }

    return $raw;
}

/**
 * Simpan delay kategori setelah validasi.
 *
 * @return array{value:string,invalid:bool}
 */
function wa_otomatis_save_delay_kind(PDO $pdo, string $kind, string $raw): array
{
    $kinds = wa_otomatis_delay_kinds();
    if (!isset($kinds[$kind])) {
        return ['value' => '', 'invalid' => true];
    }

    $raw = trim($raw);
    if ($raw === '' || $raw === '0') {
        save_setting($pdo, $kinds[$kind]['key'], '');

        return ['value' => '', 'invalid' => false];
    }

    $validated = wa_otomatis_validate_delay_string($raw);
    save_setting($pdo, $kinds[$kind]['key'], $validated);

    return ['value' => $validated, 'invalid' => $validated === ''];
}

/** Simpan delay kategori dari field POST jika ada. */
function wa_otomatis_save_delay_from_post(PDO $pdo, string $kind): bool
{
    $kinds = wa_otomatis_delay_kinds();
    if (!isset($kinds[$kind])) {
        return false;
    }
    $key = $kinds[$kind]['key'];
    if (!array_key_exists($key, $_POST)) {
        return false;
    }
    $res = wa_otomatis_save_delay_kind($pdo, $kind, trim((string) $_POST[$key]));

    return $res['invalid'];
}

/** @return array{kind:string} */
function wa_otomatis_send_opts_for_kind(string $kind): array
{
    return ['kind' => $kind];
}

/**
 * Delay antar pengiriman Fonnte (detik, wajib string di API).
 * Urutan: explicit override → wa_delay_{kind} → wa_fonnte_api_delay global.
 *
 * @param array<string, mixed> $override fonnte_delay|delay|kind
 */
function wa_otomatis_fonnte_api_delay(PDO $pdo, array $override = []): string
{
    if (array_key_exists('fonnte_delay', $override)) {
        $raw = trim((string) $override['fonnte_delay']);
    } elseif (array_key_exists('delay', $override)) {
        $raw = trim((string) $override['delay']);
    } else {
        $raw = '';
        $kind = trim((string) ($override['kind'] ?? ''));
        $kinds = wa_otomatis_delay_kinds();
        if ($kind !== '' && isset($kinds[$kind])) {
            $raw = trim((string) app_setting($pdo, $kinds[$kind]['key'], ''));
        }
        if ($raw === '') {
            $raw = trim((string) app_setting($pdo, 'wa_fonnte_api_delay', '3'));
        }
    }

    return wa_otomatis_validate_delay_string($raw);
}

function wa_otomatis_enrich_api_error(string $error, string $target): string
{
    if ($error === '') {
        return '';
    }
    $low = strtolower($error);
    if (str_contains($low, 'disconnected device') || str_contains($low, 'device disconnected')) {
        return $error
            . ' — perangkat WA di Fonnte tidak terhubung. Buka dashboard Fonnte → Device → scan QR WhatsApp, '
            . 'atau aktifkan opsi "Antrekan saat perangkat offline" di tab Gateway.';
    }
    if (str_contains($low, 'input invalid') && wa_otomatis_is_group_target($target)) {
        return $error
            . ' — untuk grup: salin ID dari Fonnte (format …@g.us), update daftar grup, pastikan nomor WA perangkat masih anggota grup.';
    }

    return $error;
}

/** Format target untuk payload gateway (Fonnte grup wajib …@g.us). */
function wa_otomatis_format_target_for_payload(string $normalized, bool $isFonte): string
{
    if ($normalized === '') {
        return '';
    }
    if (str_ends_with(strtolower($normalized), '@g.us')) {
        return $normalized;
    }
    if ($isFonte && wa_otomatis_is_group_digits($normalized)) {
        return $normalized . '@g.us';
    }

    return $normalized;
}

/** @return list<string> */
function wa_otomatis_parse_targets(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $phones = [];
    foreach ($parts as $part) {
        $phone = wa_otomatis_normalize_target((string) $part);
        if ($phone !== '') {
            $phones[] = $phone;
        }
    }

    return array_values(array_unique($phones));
}

/**
 * @param array<string, mixed> $override
 */
function wa_otomatis_gateway_provider(PDO $pdo, array $override = []): string
{
    $raw = isset($override['provider'])
        ? strtolower(trim((string) $override['provider']))
        : strtolower(trim((string) app_setting($pdo, 'wa_gateway_provider', 'fonte')));

    return $raw === 'meta' ? 'meta' : 'fonte';
}

/**
 * @param array<string, mixed> $override
 * @return array{
 *   provider:string,
 *   endpoint:string,
 *   token:string,
 *   sender:string,
 *   meta_phone_number_id:string,
 *   meta_access_token:string,
 *   meta_graph_version:string,
 *   meta_template_name:string,
 *   meta_template_lang:string
 * }
 */
function wa_otomatis_gateway_config(PDO $pdo, array $override = []): array
{
    $endpoint = isset($override['endpoint'])
        ? trim((string) $override['endpoint'])
        : trim((string) app_setting($pdo, 'wa_gateway_url', ''));
    $token = isset($override['token'])
        ? trim((string) $override['token'])
        : trim((string) app_setting($pdo, 'wa_gateway_token', ''));
    $sender = isset($override['sender'])
        ? trim((string) $override['sender'])
        : trim((string) app_setting($pdo, 'wa_sender', ''));
    $metaPhone = isset($override['meta_phone_number_id'])
        ? trim((string) $override['meta_phone_number_id'])
        : trim((string) app_setting($pdo, 'wa_meta_phone_number_id', ''));
    $metaToken = isset($override['meta_access_token'])
        ? trim((string) $override['meta_access_token'])
        : trim((string) app_setting($pdo, 'wa_meta_access_token', ''));
    $metaVer = isset($override['meta_graph_version'])
        ? trim((string) $override['meta_graph_version'])
        : trim((string) app_setting($pdo, 'wa_meta_graph_version', 'v21.0'));
    if ($metaVer === '') {
        $metaVer = 'v21.0';
    }
    if ($metaVer[0] !== 'v') {
        $metaVer = 'v' . $metaVer;
    }
    $metaTpl = isset($override['meta_template_name'])
        ? trim((string) $override['meta_template_name'])
        : trim((string) app_setting($pdo, 'wa_meta_template_name', ''));
    $metaLang = isset($override['meta_template_lang'])
        ? trim((string) $override['meta_template_lang'])
        : trim((string) app_setting($pdo, 'wa_meta_template_lang', 'id'));
    if ($metaLang === '') {
        $metaLang = 'id';
    }

    return [
        'provider' => wa_otomatis_gateway_provider($pdo, $override),
        'endpoint' => resolve_wa_endpoint($endpoint, $token),
        'token' => $token,
        'sender' => $sender,
        'meta_phone_number_id' => $metaPhone,
        'meta_access_token' => $metaToken,
        'meta_graph_version' => $metaVer,
        'meta_template_name' => $metaTpl,
        'meta_template_lang' => $metaLang,
    ];
}

/**
 * @param array<string, mixed> $override
 */
function wa_otomatis_gateway_error(PDO $pdo, array $override = []): ?string
{
    $cfg = wa_otomatis_gateway_config($pdo, $override);
    $targetRaw = isset($override['check_target']) ? trim((string) $override['check_target']) : '';
    $isGroup = $targetRaw !== '' && wa_otomatis_is_group_target(wa_otomatis_normalize_target($targetRaw));
    if ($isGroup || $cfg['provider'] !== 'meta') {
        if ($cfg['token'] === '') {
            return $isGroup && $cfg['provider'] === 'meta'
                ? 'Kiriman grup memakai Fonte. Token Fonte belum diisi (Pengaturan → Gateway WA).'
                : 'Token gateway WA belum diisi (Pengaturan → Gateway WA).';
        }
        if ($cfg['endpoint'] === '') {
            return 'URL gateway WA tidak valid.';
        }

        return null;
    }
    if ($cfg['meta_phone_number_id'] === '' || $cfg['meta_access_token'] === '') {
        return 'Phone Number ID dan Access Token Meta belum diisi (Pengaturan → Gateway WA).';
    }

    return null;
}

/**
 * Apakah job otomatis boleh mengirim WA (master toggle + pengaturan per jenis).
 *
 * Mode push/wa (fcm_notify_mode) hanya membatasi notifikasi izin yang punya alternatif push.
 * Tagihan, presensi, alpa, kelas kosong, dan cashless memakai toggle masing-masing.
 *
 * @param string $kind general|tagihan|izin|cashless
 */
function wa_otomatis_should_run(PDO $pdo, string $kind = 'general'): bool
{
    if (trim((string) app_setting($pdo, 'wa_otomatis_master_enabled', '1')) !== '1') {
        return false;
    }

    if ($kind === 'tagihan') {
        return trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) === '1';
    }

    if ($kind === 'izin') {
        if (!function_exists('push_should_send_wa')) {
            require_once __DIR__ . '/push_fcm.php';
        }

        return push_should_send_wa($pdo);
    }

    // general, cashless, presensi — WA native; tidak diblokir mode push-only
    return true;
}

function wa_otomatis_extract_api_error(string $response, string $curlError, int $httpCode): string
{
    if ($curlError !== '') {
        return $curlError;
    }
    if ($httpCode === 0) {
        return 'Tidak ada respons dari gateway WA.';
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            foreach (['error_user_msg', 'message', 'error_user_title'] as $ek) {
                if (!empty($decoded['error'][$ek]) && is_string($decoded['error'][$ek])) {
                    return trim((string) $decoded['error'][$ek]);
                }
            }
        }
        foreach (['reason', 'message', 'msg', 'detail', 'error', 'pesan'] as $key) {
            if (!isset($decoded[$key])) {
                continue;
            }
            $val = $decoded[$key];
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            if (is_array($val) && isset($val[0]) && is_string($val[0])) {
                return trim($val[0]);
            }
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['reason', 'message', 'error'] as $key) {
                if (!empty($decoded['data'][$key]) && is_string($decoded['data'][$key])) {
                    return trim((string) $decoded['data'][$key]);
                }
            }
        }
    }

    if ($httpCode >= 400) {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($response)) ?? '');
        if ($snippet !== '' && strlen($snippet) <= 200) {
            return 'HTTP ' . $httpCode . ': ' . $snippet;
        }

        return 'HTTP ' . $httpCode;
    }

    return '';
}

function wa_otomatis_parse_success(string $response, int $httpCode, string $curlError): bool
{
    if ($curlError !== '' || $httpCode === 0) {
        return false;
    }
    if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    if ($response === '') {
        return true;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return true;
    }

    $isSuccess = true;
    if (array_key_exists('status', $decoded)) {
        $statusValue = $decoded['status'];
        if ($statusValue === false || $statusValue === 0 || $statusValue === 'false' || $statusValue === 'failed' || $statusValue === 'error') {
            $isSuccess = false;
        }
        if (is_string($statusValue) && in_array(strtolower($statusValue), ['success', 'sent', 'ok', 'true'], true)) {
            $isSuccess = true;
        }
    }
    if (isset($decoded['success']) && ($decoded['success'] === false || $decoded['success'] === 0 || $decoded['success'] === 'false')) {
        $isSuccess = false;
    }
    if (isset($decoded['error']) && $decoded['error'] !== '' && $decoded['error'] !== null && $decoded['error'] !== false) {
        $isSuccess = false;
    }

    return $isSuccess;
}

function wa_otomatis_is_retryable(int $httpCode, string $curlError): bool
{
    if ($curlError !== '') {
        return true;
    }

    return $httpCode === 0 || $httpCode >= 500;
}

/** Idempotensi WA otomatis aktif (default: ya). */
function wa_dispatch_strict_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_dispatch_strict_mode', '1')) === '1';
}

/** Pastikan tabel ledger idempotensi WA ada. */
function wa_dispatch_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('table_exists')) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS wa_dispatch_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                dedup_key VARCHAR(191) NOT NULL,
                kind VARCHAR(40) NOT NULL DEFAULT "general",
                target_phone VARCHAR(40) NOT NULL DEFAULT "",
                message_hash CHAR(64) NOT NULL DEFAULT "",
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                http_ok TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uk_wa_dispatch (dedup_key),
                INDEX idx_wa_dispatch_sent (sent_at),
                INDEX idx_wa_dispatch_kind (kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } catch (Throwable $e) {
        error_log('[wa_dispatch] ensure_schema: ' . $e->getMessage());
    }
    $done = true;
}

function wa_dispatch_normalize_key(string $dedupKey): string
{
    $dedupKey = trim($dedupKey);
    if ($dedupKey === '') {
        return '';
    }
    if (strlen($dedupKey) <= 191) {
        return $dedupKey;
    }

    return substr($dedupKey, 0, 120) . ':' . sha1($dedupKey);
}

/**
 * Klaim slot kirim — return false jika kunci sudah pernah sukses dikirim.
 */
function wa_dispatch_claim(PDO $pdo, string $dedupKey, string $kind, string $target, string $message = ''): bool
{
    wa_dispatch_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_dispatch_log')) {
        return true;
    }
    $dedupKey = wa_dispatch_normalize_key($dedupKey);
    if ($dedupKey === '') {
        return true;
    }
    $kind = substr(trim($kind), 0, 40);
    if ($kind === '') {
        $kind = 'general';
    }
    $target = substr(wa_otomatis_normalize_target($target), 0, 40);
    $hash = hash('sha256', $message);
    try {
        $st = $pdo->prepare('
            INSERT IGNORE INTO wa_dispatch_log (dedup_key, kind, target_phone, message_hash, http_ok)
            VALUES (:dedup_key, :kind, :target, :hash, 0)
        ');
        $st->execute([
            'dedup_key' => $dedupKey,
            'kind' => $kind,
            'target' => $target,
            'hash' => $hash,
        ]);

        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[wa_dispatch] claim: ' . $e->getMessage());

        return true;
    }
}

function wa_dispatch_confirm(PDO $pdo, string $dedupKey): void
{
    wa_dispatch_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_dispatch_log')) {
        return;
    }
    $dedupKey = wa_dispatch_normalize_key($dedupKey);
    if ($dedupKey === '') {
        return;
    }
    try {
        $pdo->prepare('UPDATE wa_dispatch_log SET http_ok = 1 WHERE dedup_key = :k')->execute(['k' => $dedupKey]);
    } catch (Throwable $e) {
        error_log('[wa_dispatch] confirm: ' . $e->getMessage());
    }
}

function wa_dispatch_release(PDO $pdo, string $dedupKey): void
{
    wa_dispatch_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_dispatch_log')) {
        return;
    }
    $dedupKey = wa_dispatch_normalize_key($dedupKey);
    if ($dedupKey === '') {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM wa_dispatch_log WHERE dedup_key = :k AND http_ok = 0')->execute(['k' => $dedupKey]);
    } catch (Throwable $e) {
        error_log('[wa_dispatch] release: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function wa_dispatch_recent_rows(PDO $pdo, int $limit = 30): array
{
    wa_dispatch_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_dispatch_log')) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $st = $pdo->query('
            SELECT dedup_key, kind, target_phone, http_ok, sent_at
            FROM wa_dispatch_log
            ORDER BY id DESC
            LIMIT ' . $limit . '
        ');

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Satu kali kirim ke gateway (tanpa retry).
 *
 * @param array<string, mixed> $override
 * @return array{success:bool,http_code:int,error:string,response:string,target:string}
 */
function wa_otomatis_send_once(PDO $pdo, string $targetRaw, string $message, array $override = []): array
{
    $target = wa_otomatis_normalize_target($targetRaw);
    if ($target === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Nomor / ID grup WA tidak valid.',
            'response' => 'Nomor / ID grup WA tidak valid.',
            'target' => '',
        ];
    }

    $gwErr = wa_otomatis_gateway_error($pdo, array_merge($override, ['check_target' => $target]));
    if ($gwErr !== null) {
        return wa_otomatis_finish_send($pdo, $target, $message, false, 0, $gwErr, $gwErr, $target);
    }

    $cfg = wa_otomatis_gateway_config($pdo, $override);
    $useMeta = $cfg['provider'] === 'meta' && !wa_otomatis_is_group_target($target);
    if ($useMeta) {
        return wa_otomatis_send_meta_once($pdo, $cfg, $target, $message);
    }

    return wa_otomatis_send_fonte_once($pdo, $cfg, $target, $message, $override);
}

/**
 * @param array<string, mixed> $cfg
 * @param array<string, mixed> $override
 * @return array{success:bool,http_code:int,error:string,response:string,target:string}
 */
function wa_otomatis_send_fonte_once(PDO $pdo, array $cfg, string $target, string $message, array $override): array
{
    $endpoint = (string) ($cfg['endpoint'] ?? '');
    $token = (string) ($cfg['token'] ?? '');
    $isFonte = (bool) preg_match('/fonte|fonnte/i', $endpoint);
    $apiTarget = wa_otomatis_format_target_for_payload($target, $isFonte);

    $payload = [
        'token' => $token,
        'sender' => (string) ($cfg['sender'] ?? ''),
        'target' => $apiTarget,
        'message' => $message,
    ];
    $headers = [];
    if ($isFonte && $token !== '') {
        $headers[] = 'Authorization: ' . $token;
    }
    if ($isFonte) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $payload = [
            'token' => $token,
            'target' => $apiTarget,
            'message' => $message,
        ];
        if (!wa_otomatis_is_group_target($target)) {
            $payload['countryCode'] = '62';
        }
        if (!wa_otomatis_fonnte_connect_only($pdo, $override)) {
            $payload['connectOnly'] = 'false';
        }
        $fonnteDelay = wa_otomatis_fonnte_api_delay($pdo, $override);
        if ($fonnteDelay !== '') {
            $payload['delay'] = $fonnteDelay;
        }
    }

    $exec = wa_otomatis_curl_post($endpoint, http_build_query($payload), $headers);
    $response = $exec['body'];
    $statusCode = $exec['http_code'];
    $curlError = $exec['curl_error'];
    $isSuccess = wa_otomatis_parse_success($response, $statusCode, $curlError);
    $apiError = wa_otomatis_extract_api_error($response, $curlError, $statusCode);
    if ($exec['location'] !== '') {
        $apiError = ($apiError !== '' ? $apiError . "\n" : '') . '[redirect] ' . $exec['location'];
        $isSuccess = false;
    }

    return wa_otomatis_finish_send(
        $pdo,
        $target,
        $message,
        $isSuccess,
        $statusCode,
        $apiError !== '' ? $apiError : $curlError,
        $apiError !== '' ? $apiError : ($curlError !== '' ? $curlError : $response),
        $apiTarget !== '' ? $apiTarget : $target
    );
}

/**
 * @param array<string, mixed> $cfg
 * @return array{success:bool,http_code:int,error:string,response:string,target:string}
 */
function wa_otomatis_send_meta_once(PDO $pdo, array $cfg, string $target, string $message): array
{
    $phoneId = preg_replace('/\D+/', '', (string) ($cfg['meta_phone_number_id'] ?? '')) ?? '';
    $token = (string) ($cfg['meta_access_token'] ?? '');
    $version = (string) ($cfg['meta_graph_version'] ?? 'v21.0');
    $to = preg_replace('/\D+/', '', $target) ?? '';
    $endpoint = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneId) . '/messages';
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];

    $textPayload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $message,
        ],
    ];
    $exec = wa_otomatis_curl_post($endpoint, json_encode($textPayload, JSON_UNESCAPED_UNICODE), $headers);
    $response = $exec['body'];
    $statusCode = $exec['http_code'];
    $curlError = $exec['curl_error'];
    $isSuccess = wa_otomatis_parse_success($response, $statusCode, $curlError)
        || wa_otomatis_meta_message_id($response) !== '';
    $apiError = wa_otomatis_extract_api_error($response, $curlError, $statusCode);

    $tplName = trim((string) ($cfg['meta_template_name'] ?? ''));
    if (!$isSuccess && $tplName !== '' && wa_otomatis_meta_is_24h_error($response, $statusCode)) {
        $tplLang = trim((string) ($cfg['meta_template_lang'] ?? 'id'));
        $bodyText = mb_substr($message, 0, 1024);
        $tplPayload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $tplName,
                'language' => ['code' => $tplLang !== '' ? $tplLang : 'id'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [[
                        'type' => 'text',
                        'text' => $bodyText !== '' ? $bodyText : '-',
                    ]],
                ]],
            ],
        ];
        $exec = wa_otomatis_curl_post($endpoint, json_encode($tplPayload, JSON_UNESCAPED_UNICODE), $headers);
        $response = $exec['body'];
        $statusCode = $exec['http_code'];
        $curlError = $exec['curl_error'];
        $isSuccess = wa_otomatis_parse_success($response, $statusCode, $curlError)
            || wa_otomatis_meta_message_id($response) !== '';
        $apiError = wa_otomatis_extract_api_error($response, $curlError, $statusCode);
        if (!$isSuccess && $apiError !== '') {
            $apiError = 'Jendela 24 jam Meta tertutup; kirim template gagal: ' . $apiError;
        }
    } elseif (!$isSuccess && wa_otomatis_meta_is_24h_error($response, $statusCode) && $tplName === '') {
        $apiError = ($apiError !== '' ? $apiError . ' ' : '')
            . 'Isi nama template Meta yang sudah disetujui untuk kiriman di luar jendela 24 jam.';
    }

    return wa_otomatis_finish_send(
        $pdo,
        $target,
        $message,
        $isSuccess,
        $statusCode,
        $apiError !== '' ? $apiError : $curlError,
        $apiError !== '' ? $apiError : ($curlError !== '' ? $curlError : $response),
        $to
    );
}

function wa_otomatis_meta_message_id(string $response): string
{
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['messages'][0]['id'])) {
        return '';
    }

    return trim((string) $decoded['messages'][0]['id']);
}

function wa_otomatis_meta_is_24h_error(string $response, int $httpCode): bool
{
    if ($httpCode > 0 && $httpCode < 400) {
        return false;
    }
    $decoded = json_decode($response, true);
    $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
    $code = (int) ($err['code'] ?? 0);
    if (in_array($code, [131047, 131051], true)) {
        return true;
    }
    $blob = strtolower(
        (string) ($err['message'] ?? '') . ' '
        . (string) ($err['error_user_msg'] ?? '') . ' '
        . json_encode($err['error_data'] ?? [], JSON_UNESCAPED_UNICODE)
    );

    return str_contains($blob, '24 hour')
        || str_contains($blob, '24-hour')
        || str_contains($blob, 're-engagement')
        || str_contains($blob, 'outside the allowed window');
}

/**
 * @param list<string> $headers
 * @return array{body:string,http_code:int,curl_error:string,location:string}
 */
function wa_otomatis_curl_post(string $endpoint, string $body, array $headers): array
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
    ]);
    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $responseHeaders = '';
    $response = '';
    if (is_string($rawResponse)) {
        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $response = substr($rawResponse, $headerSize);
    }
    $location = '';
    if ($responseHeaders !== '' && preg_match('/^Location:\s*(.+)$/mi', $responseHeaders, $matches)) {
        $location = trim((string) ($matches[1] ?? ''));
    }

    return [
        'body' => $response,
        'http_code' => $statusCode,
        'curl_error' => $curlError,
        'location' => $location,
    ];
}

/**
 * @return array{success:bool,http_code:int,error:string,response:string,target:string}
 */
function wa_otomatis_finish_send(
    PDO $pdo,
    string $target,
    string $message,
    bool $isSuccess,
    int $statusCode,
    string $errorRaw,
    string $responseText,
    string $displayTarget
): array {
    if (table_exists($pdo, 'wa_logs')) {
        $log = $pdo->prepare('
            INSERT INTO wa_logs (target_phone, message, response_text, is_success)
            VALUES (:target_phone, :message, :response_text, :is_success)
        ');
        $log->execute([
            'target_phone' => $target,
            'message' => $message,
            'response_text' => $responseText,
            'is_success' => $isSuccess ? 1 : 0,
        ]);
    }

    $errorOut = $isSuccess ? '' : wa_otomatis_enrich_api_error($errorRaw, $target);
    if (!$isSuccess && $errorOut !== '' && function_exists('save_setting')) {
        save_setting($pdo, 'wa_auto_last_gateway_error', $errorOut);
        $errLow = strtolower($errorOut . ' ' . $errorRaw);
        if (str_contains($errLow, 'disconnected device') || str_contains($errLow, 'device disconnected')) {
            wa_fonte_mark_disconnected($pdo);
        }
    }
    if ($isSuccess) {
        wa_fonte_mark_connected_maybe_warmup($pdo);
    }

    return [
        'success' => $isSuccess,
        'http_code' => $statusCode,
        'error' => $errorOut,
        'response' => $responseText,
        'target' => $displayTarget,
    ];
}

/**
 * Batas pecah pesan ke gateway (hanya dipakai laporan ALPA).
 */
function wa_otomatis_gateway_chunk_max(PDO $pdo): int
{
    return max(40, (int) app_setting($pdo, 'wa_otomatis_chunk_max', '100'));
}

/**
 * Pecah pesan panjang menjadi beberapa bagian (batas karakter gateway WA).
 *
 * @return list<string>
 */
function wa_otomatis_chunk_message(string $message, int $maxLen = 100): array
{
    $message = trim($message);
    if ($message === '') {
        return [''];
    }
    $maxLen = max(40, min(2000, $maxLen));
    if (mb_strlen($message) <= $maxLen) {
        return [$message];
    }

    $chunks = [];
    $paragraphs = preg_split("/\r\n|\n|\r/", $message) ?: [$message];
    $buffer = '';

    $flush = static function () use (&$buffer, &$chunks): void {
        $buf = trim($buffer);
        if ($buf !== '') {
            $chunks[] = $buf;
        }
        $buffer = '';
    };

    foreach ($paragraphs as $para) {
        $para = trim((string) $para);
        if ($para === '') {
            continue;
        }
        if (mb_strlen($para) > $maxLen) {
            $flush();
            $words = preg_split('/\s+/u', $para) ?: [$para];
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : ($line . ' ' . $word);
                if (mb_strlen($candidate) <= $maxLen) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $chunks[] = $line;
                }
                while (mb_strlen($word) > $maxLen) {
                    $chunks[] = mb_substr($word, 0, $maxLen);
                    $word = mb_substr($word, $maxLen);
                }
                $line = $word;
            }
            if ($line !== '') {
                $buffer = $line;
            }
            continue;
        }
        $candidate = $buffer === '' ? $para : ($buffer . "\n" . $para);
        if (mb_strlen($candidate) <= $maxLen) {
            $buffer = $candidate;
            continue;
        }
        $flush();
        $buffer = $para;
    }
    $flush();

    return $chunks !== [] ? $chunks : [mb_substr($message, 0, $maxLen)];
}

/**
 * Kirim dengan retry singkat pada gangguan jaringan / server.
 *
 * @param array<string, mixed> $opts endpoint, token, sender, max_retries, delay_ms
 * @return array{success:bool,http_code:int,error:string,response:string,target:string,attempts:int}
 */
function wa_otomatis_send(PDO $pdo, string $targetRaw, string $message, array $opts = []): array
{
    $maxRetries = max(0, min(3, (int) ($opts['max_retries'] ?? 2)));
    $delayMs = max(100, min(2000, (int) ($opts['delay_ms'] ?? 400)));
    // Default: satu kiriman penuh. Hanya laporan ALPA yang mengisi chunk_max (lihat send_wa_bulk_messages).
    $chunkMax = max(0, (int) ($opts['chunk_max'] ?? 0));
    $chunkDelayMs = max(100, min(3000, (int) ($opts['chunk_delay_ms'] ?? 450)));
    $dedupKey = trim((string) ($opts['dedup_key'] ?? ''));
    $kind = trim((string) ($opts['kind'] ?? 'general'));
    $skipDedup = !empty($opts['skip_dedup']);
    $override = $opts;
    unset(
        $override['max_retries'],
        $override['delay_ms'],
        $override['chunk_max'],
        $override['chunk_delay_ms'],
        $override['delay_between_ms'],
        $override['message_delay_ms'],
        $override['dedup_key'],
        $override['dedup_key_once'],
        $override['dedup_key_per_target'],
        $override['skip_dedup']
    );

    $targetNorm = wa_otomatis_normalize_target($targetRaw);
    $claimedDedup = false;
    if ($dedupKey !== '' && wa_dispatch_strict_enabled($pdo) && !$skipDedup) {
        if (!wa_dispatch_claim($pdo, $dedupKey, $kind, $targetNorm, $message)) {
            return [
                'success' => true,
                'skipped' => true,
                'skipped_reason' => 'duplicate',
                'http_code' => 0,
                'error' => '',
                'response' => 'skipped_duplicate',
                'target' => $targetNorm,
                'attempts' => 0,
            ];
        }
        $claimedDedup = true;
    }

    $chunks = ($chunkMax > 0) ? wa_otomatis_chunk_message($message, $chunkMax) : [trim($message)];
    if ($chunks === []) {
        $chunks = [''];
    }

    $last = ['success' => false, 'http_code' => 0, 'error' => 'Pesan kosong', 'response' => '', 'target' => '', 'attempts' => 0];
    $totalAttempts = 0;
    foreach ($chunks as $idx => $chunk) {
        if ($idx > 0) {
            usleep($chunkDelayMs * 1000);
        }
        for ($i = 0; $i <= $maxRetries; $i++) {
            $totalAttempts++;
            $last = wa_otomatis_send_once($pdo, $targetRaw, $chunk, $override);
            if ($last['success'] ?? false) {
                break;
            }
            if ($i < $maxRetries && wa_otomatis_is_retryable((int) ($last['http_code'] ?? 0), (string) ($last['error'] ?? ''))) {
                usleep($delayMs * 1000);
                continue;
            }
            break;
        }
        if (!($last['success'] ?? false)) {
            break;
        }
    }

    $last['attempts'] = $totalAttempts;
    if (count($chunks) > 1) {
        $last['chunks'] = count($chunks);
    }

    if ($dedupKey !== '' && $claimedDedup) {
        if ($last['success'] ?? false) {
            wa_dispatch_confirm($pdo, $dedupKey);
        } else {
            wa_dispatch_release($pdo, $dedupKey);
        }
    }

    return $last;
}

/**
 * Kirim ke banyak target (personal atau grup).
 *
 * @param array<string, mixed> $opts delay_between_ms, max_retries
 * @return array{sent:int,failed:int,total:int,details:list<array<string,mixed>>}
 */
function wa_otomatis_send_bulk(PDO $pdo, string $targetsRaw, string $message, array $opts = []): array
{
    $targets = wa_otomatis_parse_targets($targetsRaw);
    $kind = trim((string) ($opts['kind'] ?? 'general'));
    if (count($targets) > 1) {
        $blocked = wa_fonte_bulk_blocked_reason($pdo);
        if ($blocked !== null) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => count($targets),
                'total' => count($targets),
                'blocked' => true,
                'blocked_reason' => 'warmup',
                'error' => $blocked,
                'details' => [[
                    'success' => false,
                    'skipped' => true,
                    'skipped_reason' => 'warmup',
                    'error' => $blocked,
                    'target' => 'bulk',
                ]],
            ];
        }
        $limit = wa_fonte_bulk_limit($pdo);
        if (count($targets) > $limit) {
            $targets = array_slice($targets, 0, $limit);
        }
    }
    if (!array_key_exists('delay_between_ms', $opts)) {
        $fonnteDelay = $kind === 'tagihan'
            ? wa_fonte_safe_tagihan_delay($pdo)
            : wa_otomatis_fonnte_api_delay($pdo, $opts);
        $minSec = wa_otomatis_delay_min_seconds($fonnteDelay);
        $floor = $kind === 'tagihan' ? 12 : 8;
        if ($minSec < $floor) {
            $minSec = $floor;
        }
        if ($minSec > 0) {
            $opts['delay_between_ms'] = $minSec * 1000;
        }
    }
    $delayMs = max(0, min(300000, (int) ($opts['delay_between_ms'] ?? 350)));
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $details = [];
    $dedupKeyBase = trim((string) ($opts['dedup_key'] ?? ''));
    $dedupOnce = !empty($opts['dedup_key_once']);
    $dedupPerTarget = array_key_exists('dedup_key_per_target', $opts)
        ? (bool) $opts['dedup_key_per_target']
        : ($dedupKeyBase !== '' && !$dedupOnce);
    $claimedBulk = false;

    if ($dedupKeyBase !== '' && $dedupOnce && wa_dispatch_strict_enabled($pdo) && empty($opts['skip_dedup'])) {
        if (!wa_dispatch_claim($pdo, $dedupKeyBase, $kind, 'bulk', $message)) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => count($targets),
                'total' => count($targets),
                'details' => [[
                    'success' => true,
                    'skipped' => true,
                    'skipped_reason' => 'duplicate',
                    'target' => 'bulk',
                ]],
            ];
        }
        $claimedBulk = true;
    }

    foreach ($targets as $idx => $target) {
        if ($idx > 0 && $delayMs > 0) {
            if ($kind === 'tagihan') {
                usleep(wa_otomatis_delay_sleep_us(wa_fonte_safe_tagihan_delay($pdo), 12));
            } else {
                usleep($delayMs * 1000);
            }
        }
        $targetOpts = $opts;
        if ($dedupKeyBase !== '' && !$dedupOnce) {
            if ($dedupPerTarget) {
                $targetOpts['dedup_key'] = $dedupKeyBase . ':t:' . wa_otomatis_normalize_target($target);
            } else {
                $targetOpts['dedup_key'] = $dedupKeyBase;
            }
        } elseif ($dedupOnce) {
            unset($targetOpts['dedup_key']);
            $targetOpts['skip_dedup'] = true;
        }
        $result = wa_otomatis_send($pdo, $target, $message, $targetOpts);
        if (!empty($result['skipped'])) {
            $skipped++;
        } elseif ($result['success'] ?? false) {
            $sent++;
        } else {
            $failed++;
        }
        $details[] = $result;
    }

    if ($claimedBulk) {
        if ($sent > 0) {
            wa_dispatch_confirm($pdo, $dedupKeyBase);
        } else {
            wa_dispatch_release($pdo, $dedupKeyBase);
        }
    }

    return [
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'total' => count($targets),
        'details' => $details,
    ];
}

/** Nomor WA wali santri (resolver lengkap + normalisasi). */
function wa_otomatis_santri_wali_phone(PDO $pdo, int|array $santriRow): string
{
    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }
    $raw = santri_resolve_no_wa_wali($pdo, $santriRow);

    return wa_otomatis_normalize_target($raw);
}

/**
 * Job WA terjadwal untuk cron (tanpa throttle navigasi web).
 * Dipanggil dari light tick (~60s) di wa_auto_run_tick().
 */
function wa_auto_run_scheduled_wa(PDO $pdo): void
{
    if (!function_exists('trigger_auto_wa_notifications')) {
        require_once __DIR__ . '/app.php';
    }
    if (!function_exists('cashless_wa_cron_laporan_harian')) {
        require_once __DIR__ . '/cashless_wa.php';
    }

    $started = microtime(true);
    $results = [
        'alpa' => ['ran' => false, 'note' => ''],
        'tagihan' => ['ran' => false, 'note' => ''],
        'kelas_kosong' => ['ran' => false, 'note' => ''],
        'cashless_laporan' => ['ran' => false, 'note' => ''],
        'poin_ambang' => ['ran' => false, 'note' => ''],
    ];

    if (wa_otomatis_gateway_error($pdo) !== null) {
        save_setting($pdo, 'wa_auto_scheduled_last_at', date('Y-m-d H:i:s'));
        save_setting($pdo, 'wa_auto_scheduled_last_result', json_encode([
            'skipped' => true,
            'reason' => 'gateway',
            'gateway_error' => wa_otomatis_gateway_error($pdo),
        ], JSON_UNESCAPED_UNICODE));

        return;
    }

    trigger_auto_wa_notifications($pdo);
    $results['alpa']['ran'] = true;

    trigger_auto_wa_tagihan_wali($pdo);
    $results['tagihan']['ran'] = true;

    if (!function_exists('wa_awal_tahun_cron')) {
        require_once __DIR__ . '/wa_awal_tahun.php';
    }
    wa_awal_tahun_cron($pdo);

    require_once __DIR__ . '/wa_kegiatan_kosong.php';
    trigger_wa_kelas_kosong_bertahap($pdo);
    $results['kelas_kosong']['ran'] = true;

    cashless_wa_cron_laporan_harian($pdo);
    $results['cashless_laporan']['ran'] = true;
    $cashlessRes = json_decode((string) app_setting($pdo, 'cashless_laporan_harian_last_stats', ''), true);
    $results['cashless_laporan']['note'] = trim((string) app_setting($pdo, 'cashless_laporan_harian_last_error', ''));
    if ($results['cashless_laporan']['note'] === '' && is_array($cashlessRes) && (int) ($cashlessRes['sent'] ?? 0) > 0) {
        $results['cashless_laporan']['note'] = 'sent=' . (int) $cashlessRes['sent'];
    }

    if (!function_exists('poin_wa_cron_flush')) {
        require_once __DIR__ . '/poin_wa.php';
    }
    $poinCron = poin_wa_cron_flush($pdo);
    $results['poin_ambang'] = [
        'ran' => true,
        'note' => 'sent=' . (int) ($poinCron['sent'] ?? 0) . ' pending=' . (int) ($poinCron['pending'] ?? 0),
    ];

    save_setting($pdo, 'wa_auto_scheduled_last_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_auto_scheduled_last_result', json_encode([
        'skipped' => false,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'jobs' => $results,
        'alpa_stats' => json_decode((string) app_setting($pdo, 'wa_auto_alpa_last_result', ''), true),
        'tagihan_stats' => json_decode((string) app_setting($pdo, 'wa_tagihan_last_run_stats', ''), true),
    ], JSON_UNESCAPED_UNICODE));
}

/** Nama lock MySQL agar cron hosting + fallback web tidak jalan bersamaan. */
const WA_AUTO_TICK_LOCK_NAME = 'pwa_wa_auto_run_tick';

/** Cron dianggap aktif bila tick terakhir tidak lebih tua dari batas ini (detik). */
function wa_auto_cron_stale_after_sec(): int
{
    return 600;
}

/** Cron hosting / CLI sudah jalan baru-baru ini (untuk skip fallback ganda). */
function wa_auto_cron_recently_active(PDO $pdo, ?int $maxAgeSec = null): bool
{
    $maxAgeSec ??= wa_auto_cron_stale_after_sec();
    $last = trim((string) app_setting($pdo, 'wa_auto_last_run_at', ''));
    if ($last === '') {
        return false;
    }
    $ts = strtotime($last);

    return $ts !== false && (time() - $ts) <= $maxAgeSec;
}

/** Cron stale = tidak update lebih lama dari batas stale. */
function wa_auto_cron_is_stale(PDO $pdo, ?int $maxAgeSec = null): bool
{
    return !wa_auto_cron_recently_active($pdo, $maxAgeSec);
}

/**
 * Klaim slot interval secara atomik — cegah dua proses concurrent menjalankan job yang sama.
 */
function wa_auto_try_claim_interval(PDO $pdo, string $settingKey, int $now, int $intervalSec): bool
{
    $threshold = max(0, $now - $intervalSec);
    try {
        $pdo->prepare('
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (:k, "0")
            ON DUPLICATE KEY UPDATE setting_key = setting_key
        ')->execute(['k' => $settingKey]);

        $st = $pdo->prepare('
            UPDATE app_settings
            SET setting_value = :now
            WHERE setting_key = :k
            AND CAST(setting_value AS UNSIGNED) <= :threshold
        ');
        $st->execute([
            'now' => (string) $now,
            'k' => $settingKey,
            'threshold' => (string) $threshold,
        ]);
        if ($st->rowCount() > 0) {
            if (function_exists('app_settings_cache_reset')) {
                app_settings_cache_reset($pdo);
            }

            return true;
        }

        return false;
    } catch (Throwable $e) {
        error_log('[wa_auto_try_claim_interval] ' . $e->getMessage());
        $last = (int) app_setting($pdo, $settingKey, '0');
        if ($last > 0 && ($now - $last) < $intervalSec) {
            return false;
        }
        save_setting($pdo, $settingKey, (string) $now);

        return true;
    }
}

function wa_auto_acquire_tick_lock(PDO $pdo, int $timeoutSec = 0): bool
{
    try {
        $st = $pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
        $st->execute(['name' => WA_AUTO_TICK_LOCK_NAME, 'timeout' => max(0, $timeoutSec)]);

        return (int) $st->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('[wa_auto_acquire_tick_lock] ' . $e->getMessage());

        return true;
    }
}

function wa_auto_release_tick_lock(PDO $pdo): void
{
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => WA_AUTO_TICK_LOCK_NAME]);
    } catch (Throwable $e) {
        error_log('[wa_auto_release_tick_lock] ' . $e->getMessage());
    }
}

/**
 * Nonaktifkan fallback web (cegah kirim dobel saat cron hosting sudah jalan).
 */
function wa_auto_disable_web_fallback(PDO $pdo, string $reason = 'manual'): bool
{
    if (trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '0')) !== '1') {
        return false;
    }
    save_setting($pdo, 'wa_auto_web_fallback_enabled', '0');
    save_setting($pdo, 'wa_auto_fallback_auto_disabled_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_auto_fallback_auto_disabled_reason', $reason);

    return true;
}

/** @deprecated Use wa_auto_disable_web_fallback() */
function wa_auto_disable_web_fallback_if_cron_http(PDO $pdo): bool
{
    return wa_auto_disable_web_fallback($pdo, 'hosting_cron_http');
}

/**
 * @return list<array<string, mixed>>
 */
function wa_logs_recent_duplicates(PDO $pdo, int $hours = 24, int $limit = 20): array
{
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_logs')) {
        return [];
    }
    $hours = max(1, min(168, $hours));
    $limit = max(1, min(50, $limit));
    try {
        $st = $pdo->query('
            SELECT target_phone, kind,
                   DATE_FORMAT(sent_at, "%Y-%m-%d %H:%i") AS minute_bucket,
                   COUNT(*) AS cnt
            FROM wa_logs
            WHERE sent_at >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)
            GROUP BY target_phone, kind, minute_bucket
            HAVING cnt > 1
            ORDER BY cnt DESC, minute_bucket DESC
            LIMIT ' . $limit . '
        ');

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('[wa_logs_recent_duplicates] ' . $e->getMessage());

        return [];
    }
}

/**
 * Satu tick WA otomatis (ringan + berat). Dipakai cron dan fallback navigasi web.
 *
 * @return array{light:bool,heavy:bool,gateway_ok:bool,skipped_lock?:bool}
 */
function wa_auto_run_tick(PDO $pdo): array
{
    $now = time();
    save_setting($pdo, 'wa_auto_last_run_at', date('Y-m-d H:i:s'));

    if (!wa_auto_acquire_tick_lock($pdo)) {
        return [
            'light' => false,
            'heavy' => false,
            'gateway_ok' => wa_otomatis_gateway_error($pdo) === null,
            'skipped_lock' => true,
        ];
    }

    $runLight = false;
    $runHeavy = false;
    $gwErr = null;

    try {
        $gwErr = wa_otomatis_gateway_error($pdo);
        save_setting($pdo, 'wa_auto_last_gateway_ok', $gwErr === null ? '1' : '0');
        if ($gwErr !== null) {
            save_setting($pdo, 'wa_auto_last_gateway_error', $gwErr);
        }

        if ($gwErr === null) {
            if (!function_exists('trigger_wa_pembimbing_belum_scan')) {
                require_once __DIR__ . '/wa_pembimbing_scan.php';
            }

            $lightInterval = max(45, (int) app_setting($pdo, 'wa_auto_light_interval_sec', '60'));
            $runLight = wa_auto_try_claim_interval($pdo, 'wa_auto_light_last_at', $now, $lightInterval);
            if ($runLight) {
                trigger_wa_pembimbing_belum_scan($pdo);
                trigger_wa_mudabir_belum_hadir($pdo);
                if (!function_exists('trigger_wa_yayasan_tugas_belum_progres')) {
                    require_once __DIR__ . '/wa_yayasan_tugas.php';
                }
                trigger_wa_yayasan_tugas_belum_progres($pdo);
                wa_auto_run_scheduled_wa($pdo);
            }

            $heavyInterval = max(300, (int) app_setting($pdo, 'wa_auto_heavy_interval_sec', '300'));
            $runHeavy = wa_auto_try_claim_interval($pdo, 'wa_auto_heavy_last_at', $now, $heavyInterval);
            if ($runHeavy) {
                if (!function_exists('trigger_push_tagihan_wali_from_cron')) {
                    require_once __DIR__ . '/push_events.php';
                }
                trigger_push_tagihan_wali_from_cron($pdo);
                trigger_push_daily_kiai($pdo);

                $cleanupLast = trim((string) app_setting($pdo, 'wa_debounce_cleanup_last_date', ''));
                if ($cleanupLast !== date('Y-m-d')) {
                    $removed = wa_cleanup_old_debounce_keys($pdo, 30);
                    save_setting($pdo, 'wa_debounce_cleanup_last_date', date('Y-m-d'));
                    if ($removed > 0) {
                        save_setting($pdo, 'wa_debounce_cleanup_last_count', (string) $removed);
                    }
                }

                save_setting($pdo, 'wa_auto_last_heavy_at', date('Y-m-d H:i:s'));
            }
        }
    } finally {
        wa_auto_release_tick_lock($pdo);
    }

    return [
        'light' => $runLight,
        'heavy' => $runHeavy,
        'gateway_ok' => $gwErr === null,
    ];
}

/**
 * Fallback bila cron tidak jalan: tick WA saat staf buka aplikasi (throttle sama dengan cron).
 * Job dijadwalkan setelah response terkirim agar navigasi tidak terasa hang.
 */
function wa_auto_web_fallback_tick(PDO $pdo): void
{
    static $ranThisRequest = false;
    if ($ranThisRequest) {
        return;
    }
    $ranThisRequest = true;

    if (trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '0')) !== '1') {
        return;
    }

    if (wa_auto_cron_recently_active($pdo)) {
        return;
    }

    if (function_exists('app_request_is_background_job_skip') && app_request_is_background_job_skip()) {
        return;
    }

    $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($requestPath !== '' && (
        preg_match('#\.(css|js|map|woff2?|png|jpe?g|gif|webp|ico)$#i', $requestPath)
        || str_contains($requestPath, '/cron/wa_auto.php')
    )) {
        return;
    }

    wa_auto_web_fallback_schedule_tick($pdo);
}

function wa_auto_web_fallback_schedule_tick(PDO $pdo): void
{
    static $scheduled = false;
    if ($scheduled) {
        return;
    }
    $scheduled = true;

    register_shutdown_function(static function () use ($pdo): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            wa_auto_run_tick($pdo);
        } catch (Throwable $e) {
            error_log('[wa_auto_web_fallback_tick] ' . $e->getMessage());
        }
    });
}
