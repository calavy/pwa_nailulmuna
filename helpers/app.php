<?php

/** @return array<string, string> */
function app_settings_cache(PDO $pdo, bool $forceReload = false): array
{
    static $cache = null;
    static $cachePdoId = null;
    $pdoId = spl_object_id($pdo);
    if ($forceReload) {
        $cache = null;
        $cachePdoId = null;
    }
    if (is_array($cache) && $cachePdoId === $pdoId) {
        return $cache;
    }
    $cachePdoId = $pdoId;
    $cache = [];
    if (!table_exists($pdo, 'app_settings')) {
        return $cache;
    }
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM app_settings') as $row) {
            $k = (string) ($row['setting_key'] ?? '');
            if ($k !== '') {
                $cache[$k] = (string) ($row['setting_value'] ?? '');
            }
        }
    } catch (PDOException $e) {
        $cache = [];
    }

    return $cache;
}

function app_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $cache = app_settings_cache($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    return $default;
}

/**
 * Tahun Masehi default untuk filter laporan (bila URL tidak menyertakan tahun).
 * BERJALAN: mengikuti tahun dari tanggal server. TETAP: nilai app_tahun_masehi_tetap (1900–2100).
 */
function app_tahun_masehi_default(PDO $pdo): int
{
    $yNow = (int) date('Y');
    $mode = strtoupper(trim((string) app_setting($pdo, 'app_tahun_masehi_mode', 'BERJALAN')));
    if ($mode !== 'TETAP') {
        return $yNow;
    }
    $fixed = (int) app_setting($pdo, 'app_tahun_masehi_tetap', (string) $yNow);
    if ($fixed < 1900 || $fixed > 2100) {
        return $yNow;
    }

    return $fixed;
}

/**
 * Sinkronisasi berat (presensi, poin, WA) — dibatasi agar navigasi menu tidak lambat.
 * Cron memakai wa_auto_run_scheduled_wa() langsung (tanpa throttle 10 menit).
 */
function app_request_path_is_lightweight(string $requestPath): bool
{
    if ($requestPath === '') {
        return false;
    }
    if (str_contains($requestPath, '/menu/menu_hub.php')) {
        return true;
    }
    if (str_contains($requestPath, '/api/')) {
        return true;
    }
    if (preg_match('#\.(css|js|map|woff2?|png|jpe?g|gif|webp|ico)$#i', $requestPath)) {
        return true;
    }
    if (preg_match('#^/(keuangan|pembayaran)/#', $requestPath)) {
        return true;
    }
    if (preg_match('#^/(santri|settings|presensi|pembimbing|akademik|rekap|perizinan|poin|jadwal|data|admin|yayasan|menu|santri_portal|wali|pengasuh|kegiatan|login|logout|cron)/#', $requestPath)) {
        return true;
    }
    if (preg_match('#/(login|logout)\.php$#i', $requestPath)) {
        return true;
    }
    if ($requestPath === '/dashboard.php' || str_ends_with($requestPath, '/dashboard.php')) {
        return true;
    }

    return false;
}

/** Lewati cron/fallback WA untuk AJAX, JSON API, atau CLI. */
function app_request_is_background_job_skip(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $xhr = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($xhr === 'xmlhttprequest') {
        return true;
    }
    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));
    if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
        return true;
    }

    return false;
}

/** Halaman scan/kiosk — layout minimal, hindari beban header/footer penuh. */
function app_request_path_is_scan_kiosk(string $requestPath): bool
{
    $p = strtolower(str_replace('\\', '/', $requestPath));

    return str_contains($p, '/presensi/scan')
        || str_contains($p, '/cashless_scan')
        || str_contains($p, '/presensi/kiosk');
}

/** Muat CSS dashboard hanya di halaman beranda/dashboard. */
function app_should_load_dashboard_css(string $requestPath): bool
{
    $p = strtolower(str_replace('\\', '/', $requestPath));

    return str_contains($p, 'dashboard.php')
        || str_contains($p, '/pembimbing/dashboard')
        || str_contains($p, '/pengasuh/dashboard');
}

/** Modal SDM hanya di modul data santri/pembimbing/pengguna. */
function app_should_load_sdm_modals(string $requestPath): bool
{
    $p = strtolower(str_replace('\\', '/', $requestPath));

    return (bool) preg_match('#^/(santri|pembimbing|data|users|wali/data)/#', $p);
}

/** Offline sync JS — skip halaman pengaturan murni. */
function app_should_load_offline_sync_js(string $requestPath): bool
{
    $p = strtolower(str_replace('\\', '/', $requestPath));
    if (preg_match('#^/settings/(?!presensi)#', $p)) {
        return false;
    }

    return true;
}

/** JS shell tambahan — tidak perlu di halaman scan agar kamera cepat siap. */
function app_should_load_app_shell_js(string $requestPath): bool
{
    return !app_request_path_is_scan_kiosk($requestPath);
}

/** Hapus cache branding header (mis. setelah ubah logo/nama pondok). */
function app_header_brand_invalidate(): void
{
    unset($_SESSION['app_header_brand_v1']);
}

/**
 * Branding header — teks di-cache per sesi; logo selalu diambil ulang dari pengaturan.
 *
 * @return array{title:string,tagline:string,logo:string,logo_href:string,initials:string,alamat:string}
 */
function app_header_brand_context(PDO $pdo, string $fallbackTitle = 'A.P.I Nailul Muna'): array
{
    $sessionKey = 'app_header_brand_v1';
    if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
        $title = app_brand_nama_ponpes($pdo, $fallbackTitle);
        $_SESSION[$sessionKey] = [
            'title' => app_brand_title_display($title),
            'tagline' => trim((string) app_setting($pdo, 'jenis_pendidikan', '')),
            'initials' => app_pondok_logo_initials($pdo, $fallbackTitle),
            'alamat' => trim((string) app_setting($pdo, 'alamat_ponpes', '')),
        ];
    }
    $_SESSION[$sessionKey]['logo'] = app_pondok_logo_src($pdo);
    $_SESSION[$sessionKey]['logo_href'] = app_pondok_logo_href($pdo, false);
    $_SESSION[$sessionKey]['title'] = app_brand_title_display((string) ($_SESSION[$sessionKey]['title'] ?? $fallbackTitle));

    return $_SESSION[$sessionKey];
}

/** Migrasi skema ringan — sekali per sesi login, bukan tiap request. */
function app_ensure_schema_deferred(PDO $pdo): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    if (!empty($_SESSION['app_schema_ready_v1'])) {
        return;
    }

    // Lokal (XAMPP): DB dari impor SQL sudah lengkap — lewati puluhan ALTER TABLE per login.
    if (
        function_exists('app_is_local_dev') && app_is_local_dev()
        && table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'status_santri')
        && table_exists($pdo, 'user_access_permissions')
    ) {
        $_SESSION['app_schema_ready_v1'] = 1;

        return;
    }

    try {
        if (table_exists($pdo, 'users')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (table_exists($pdo, 'users') && !table_exists($pdo, 'user_access_permissions')) {
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS user_access_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    permission_key VARCHAR(80) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_user_permission (user_id, permission_key),
                    CONSTRAINT fk_user_access_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ');
        }
        if (table_exists($pdo, 'jadwal_kegiatan')) {
            ensure_jadwal_kegiatan_tempat($pdo);
        }

        if (function_exists('ensure_santri_compat_schema')) {
            ensure_santri_compat_schema($pdo);
        }
        ensure_santri_identity_columns($pdo);
        if (function_exists('ensure_kelas_keuangan_table')) {
            ensure_kelas_keuangan_table($pdo);
        }
        if (!function_exists('ensure_wali_santri_table')) {
            require_once __DIR__ . '/wali.php';
        }
        if (function_exists('ensure_wali_santri_table')) {
            ensure_wali_santri_table($pdo);
        }
        if (!function_exists('ensure_surat_nomor_schema')) {
            require_once __DIR__ . '/surat_nomor.php';
        }
        if (function_exists('ensure_surat_nomor_schema')) {
            ensure_surat_nomor_schema($pdo);
        }
        if (!function_exists('ensure_alpa_tier_tables')) {
            require_once __DIR__ . '/alpa_tier.php';
        }
        if (function_exists('ensure_alpa_tier_tables')) {
            ensure_alpa_tier_tables($pdo);
        }

        if (!function_exists('payroll_pembimbing_ensure_schema')) {
            require_once __DIR__ . '/payroll_pembimbing.php';
        }
        if (function_exists('payroll_pembimbing_ensure_schema')) {
            payroll_pembimbing_ensure_schema($pdo);
        }

        if (!function_exists('login_pembimbing_ensure_password_plain_column')) {
            require_once __DIR__ . '/login_pembimbing.php';
        }
        if (function_exists('login_pembimbing_ensure_password_plain_column')) {
            login_pembimbing_ensure_password_plain_column($pdo);
        }

        if (!function_exists('logo_ensure_white_bg_pwa_icons') && file_exists(__DIR__ . '/pwa_brand.php')) {
            require_once __DIR__ . '/pwa_brand.php';
        }
        if (function_exists('logo_ensure_white_bg_pwa_icons')) {
            logo_ensure_white_bg_pwa_icons($pdo);
        }
    } catch (Throwable $e) {
        error_log('[app_ensure_schema_deferred] ' . $e->getMessage());
    }

    $_SESSION['app_schema_ready_v1'] = 1;
}

function app_run_deferred_maintenance(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    static $ranThisRequest = false;
    if ($ranThisRequest) {
        return;
    }
    $ranThisRequest = true;

    $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if (app_request_path_is_lightweight($requestPath)) {
        return;
    }

    $intervalSec = 600;
    $now = time();
    $lastAt = (int) app_setting($pdo, 'app_maintenance_last_at', '0');
    if ($lastAt > 0 && ($now - $lastAt) < $intervalSec) {
        return;
    }

    if (table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
        $today = date('Y-m-d');
        $jamNow = date('H:i:s');
        sync_presence_for_active_schedules($pdo, $today, $jamNow, $userId);
        sync_presence_for_ended_schedules($pdo, $today, $jamNow, $userId);
    }
    ensure_point_tables($pdo);
    sync_points_from_presensi($pdo, $userId);
    trigger_auto_wa_notifications($pdo);
    trigger_auto_wa_tagihan_wali($pdo);
    require_once __DIR__ . '/wa_kegiatan_kosong.php';
    trigger_wa_kelas_kosong_bertahap($pdo);

    save_setting($pdo, 'app_maintenance_last_at', (string) $now);
}

/** Hapus kunci debounce WA lama di app_settings agar DB tidak membengkak. */
function wa_cleanup_old_debounce_keys(PDO $pdo, int $keepDays = 30): int
{
    if (!table_exists($pdo, 'app_settings')) {
        return 0;
    }
    $cutoffTs = strtotime('-' . max(7, $keepDays) . ' days');
    if ($cutoffTs === false) {
        return 0;
    }
    $cutoffDate = date('Y-m-d', $cutoffTs);
    $patterns = ['wa_pb_scan_sent_%', 'wa_mw_scan_sent_%', 'wa_mudabir_missing_%', 'wa_kelas_kosong_counter_%', 'wa_kelas_kosong_ok_%'];
    $deleted = 0;
    foreach ($patterns as $pattern) {
        $st = $pdo->prepare('SELECT setting_key FROM app_settings WHERE setting_key LIKE :p');
        $st->execute(['p' => $pattern]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $key) {
            if (!is_string($key) || !preg_match('/(\d{4}-\d{2}-\d{2})/', $key, $m)) {
                continue;
            }
            if ($m[1] < $cutoffDate) {
                $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1')->execute(['k' => $key]);
                $deleted++;
            }
        }
    }

    return $deleted;
}

function save_setting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
}

/** Mode tampilan pondok: light atau dark (dipaksa untuk semua pengguna). */
function pondok_ui_theme_mode(?PDO $pdo = null): string
{
    if (!($pdo instanceof PDO)) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    if (!($pdo instanceof PDO)) {
        return 'light';
    }
    $mode = strtolower(trim((string) app_setting($pdo, 'ui_theme_mode', 'light')));

    return $mode === 'dark' ? 'dark' : 'light';
}

/** Script anti-FOUC di &lt;head&gt; + window.PONDOK_THEME_MODE. */
function pondok_ui_theme_head_html(?PDO $pdo = null): string
{
    $mode = pondok_ui_theme_mode($pdo);
    $bg = $mode === 'dark' ? '#e2e8f0' : '#eef5ff';

    return '<script>window.PONDOK_THEME_MODE=' . json_encode($mode, JSON_UNESCAPED_UNICODE)
        . ';(function(){try{var m=window.PONDOK_THEME_MODE===\'dark\'?\'dark\':\'light\';var d=document.documentElement;'
        . 'd.setAttribute(\'data-theme\',m);d.style.colorScheme=\'light\';d.style.backgroundColor='
        . json_encode($bg, JSON_UNESCAPED_UNICODE)
        . ';try{localStorage.setItem(\'theme-mode\',m);}catch(e2){}}catch(e){document.documentElement.setAttribute(\'data-theme\',\'light\');}})();</script>';
}

/** Reset cache pengaturan setelah simpan. */
function app_settings_cache_reset(PDO $pdo): void
{
    if (!function_exists('app_performance_cache_clear')) {
        require_once __DIR__ . '/app_cache.php';
    }
    app_performance_cache_clear($pdo, ['schema_flags' => false, 'opcache' => false, 'all_users_acl' => false]);
}

/** Nama pondok/pesantren untuk branding aplikasi dan surat. */
function app_brand_nama_ponpes(PDO $pdo, string $fallback = 'A.P.I Nailul Muna'): string
{
    $nama = trim((string) app_setting($pdo, 'nama_ponpes', ''));
    if ($nama === '' || $nama === 'Nama Pondok Pesantren') {
        return app_brand_title_display($fallback);
    }

    return app_brand_title_display($nama);
}

/** Seragamkan penulisan singkatan lembaga (mis. A.P.I tanpa spasi antar huruf). */
function app_brand_title_display(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '') {
        return $title;
    }
    $title = preg_replace('/\bA\s*\.\s*P\s*\.\s*I\.?\s*/iu', 'A.P.I ', $title);

    return trim(preg_replace('/\s{2,}/u', ' ', $title));
}

/** Path/URL logo pesantren untuk tampilan UI (kosong jika belum diatur / file hilang). */
function app_pondok_logo_src(PDO $pdo): string
{
    $logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
    if ($logoPath !== '') {
        $rel = '/' . ltrim(str_replace('\\', '/', $logoPath), '/');
        $full = dirname(__DIR__) . $rel;
        if (is_file($full)) {
            return $rel;
        }
    }

    return trim((string) app_setting($pdo, 'logo_url', ''));
}

/** URL absolut aplikasi untuk logo pondok (path relatif + base path XAMPP/PWA). */
function app_pondok_logo_href(PDO $pdo, bool $fallbackDefault = true): string
{
    require_once __DIR__ . '/app_path.php';
    $src = app_pondok_logo_src($pdo);
    if ($src === '') {
        return $fallbackDefault ? app_href(app_pwa_default_icon_src()) : '';
    }
    if (preg_match('#^https?://#i', $src) || str_starts_with($src, '//')) {
        return $src;
    }

    return app_href('/' . ltrim($src, '/'));
}

function app_pwa_resolve_pdo(?PDO $pdo = null): ?PDO
{
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    return null;
}

function app_pwa_default_icon_src(): string
{
    return '/assets/img/stempel-pondok.png';
}

/** Path relatif ikon PWA (ikon install 192px, logo pondok, atau fallback). */
function app_pwa_icon_src(?PDO $pdo = null): string
{
    $pdo = app_pwa_resolve_pdo($pdo);
    if ($pdo !== null) {
        if (function_exists('pwa_brand_icon_relative_path')) {
            $pwa192 = pwa_brand_icon_relative_path($pdo, 192);
            if ($pwa192 !== '') {
                return '/' . ltrim($pwa192, '/');
            }
        }
        $src = app_pondok_logo_src($pdo);
        if ($src !== '') {
            return '/' . ltrim($src, '/');
        }
    }

    return app_pwa_default_icon_src();
}

function app_pwa_icon_href(?PDO $pdo = null): string
{
    require_once __DIR__ . '/app_path.php';

    return app_href(app_pwa_icon_src($pdo));
}

function app_pwa_icon_mime(?PDO $pdo = null): string
{
    $ext = strtolower(pathinfo(app_pwa_icon_src($pdo), PATHINFO_EXTENSION));

    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'image/png',
    };
}

/** URL absolut ikon untuk manifest.json (512px). */
function app_pwa_manifest_icon_url(?PDO $pdo = null): string
{
    require_once __DIR__ . '/app_path.php';
    $pdo = app_pwa_resolve_pdo($pdo);
    if ($pdo !== null && function_exists('pwa_brand_icon_absolute_url')) {
        return pwa_brand_icon_absolute_url($pdo, 512);
    }

    return app_public_url() . app_url(ltrim(app_pwa_icon_src($pdo), '/'));
}

function app_pwa_app_name(?PDO $pdo = null): string
{
    $pdo = app_pwa_resolve_pdo($pdo);
    if ($pdo !== null) {
        $nama = app_brand_nama_ponpes($pdo, '');
        if ($nama !== '') {
            return $nama . ' App';
        }
    }

    return 'Nailul Muna App';
}

function app_pwa_short_name(?PDO $pdo = null): string
{
    $pdo = app_pwa_resolve_pdo($pdo);
    if ($pdo !== null) {
        $nama = app_brand_nama_ponpes($pdo, '');
        if ($nama !== '') {
            return mb_strlen($nama) > 14 ? 'Nailul Muna' : $nama;
        }
    }

    return 'Nailul Muna';
}

/** URL absolut latar splash PWA (gradien tema). */
function app_pwa_splash_bg_url(?PDO $pdo = null): string
{
    require_once __DIR__ . '/app_path.php';

    return app_public_url() . app_url('assets/img/pwa-splash-bg.svg');
}

/** Tag <link> ikon install PWA (favicon + Apple touch). */
function app_pwa_icon_link_tags(?PDO $pdo = null): string
{
    $href = htmlspecialchars(app_pwa_icon_href($pdo), ENT_QUOTES, 'UTF-8');
    $mime = htmlspecialchars(app_pwa_icon_mime($pdo), ENT_QUOTES, 'UTF-8');

    return '<link rel="icon" type="' . $mime . '" sizes="192x192" href="' . $href . '">' . "\n"
        . '    <link rel="icon" type="' . $mime . '" sizes="512x512" href="' . $href . '">' . "\n"
        . '    <link rel="apple-touch-icon" sizes="180x180" href="' . $href . '">' . "\n"
        . '    <link rel="shortcut icon" href="' . $href . '">';
}

/** Inisial 2 huruf dari nama pondok (fallback logo). */
function app_pondok_logo_initials(PDO $pdo, string $fallbackTitle = 'A.P.I Nailul Muna'): string
{
    $nama = app_brand_nama_ponpes($pdo, $fallbackTitle);
    $lettersOnly = preg_replace('/[^A-Za-z]/u', '', $nama);

    return strtoupper(substr(($lettersOnly !== '' ? $lettersOnly : 'AP'), 0, 2));
}

/**
 * Nilai awal pengaturan pesantren bila belum pernah disimpan.
 *
 * @return array<string, string>
 */
function pondok_settings_defaults(): array
{
    return [
        'nama_ponpes' => 'Pondok Pesantren Nailul Muna',
        'jenis_pendidikan' => 'Pondok Pesantren / Pesantren Putra Putri',
        'alamat_ponpes' => '',
        'telp_ponpes' => '',
        'website_ponpes' => '',
        'kota_ponpes' => 'Muntilan',
        'kop_accent_color' => '#065f46',
        'kop_jenis_fallback' => 'Lembaga Pondok Pesantren',
        'nama_pengasuh' => '',
        'nama_ketua_yayasan' => '',
        'logo_path' => '',
        'pwa_theme_color' => '',
        'pwa_background_color' => '',
        'ui_theme_mode' => 'light',
        'pwa_icon_192' => '',
        'pwa_icon_512' => '',
        'pwa_icon_maskable_512' => '',
        'wa_gateway_url' => '',
        'wa_gateway_token' => '',
        'wa_sender' => '',
        'wa_gateway_provider' => 'fonte',
        'wa_meta_phone_number_id' => '',
        'wa_meta_access_token' => '',
        'wa_meta_graph_version' => 'v21.0',
        'wa_meta_template_lang' => 'id',
        'wa_meta_template_name' => '',
        'wa_fonnte_queue_offline' => '0',
        'wa_fonnte_api_delay' => '8-12',
        'wa_dispatch_strict_mode' => '1',
        'wa_auto_web_fallback_enabled' => '0',
        'wa_delay_tagihan' => '12-20',
        'wa_fonte_bulk_limit' => '15',
        'wa_fonte_warmup_hours' => '3',
        'wa_fonte_warmup_until' => '',
        'wa_fonte_warmup_pending' => '0',
        'wa_fonte_disconnected_at' => '',
        'wa_delay_cashless' => '',
        'wa_delay_presensi' => '',
        'wa_delay_alpa' => '',
        'wa_delay_poin' => '',
        'wa_delay_izin' => '',
        'wa_delay_rapor' => '',
        'wa_pengurus' => '',
        'wa_permohonan_izin' => '',
        'wa_permohonan_izin_enabled' => '1',
        'wa_permohonan_izin_jenis' => 'SYARI',
        'wa_petugas_pendidikan' => '',
        'wa_notif_mudabir_enabled' => '1',
        'mudabir_batas_menit' => '30',
        'wa_kelas_kosong_enabled' => '1',
        'wa_kelas_kosong_batas_menit' => '20',
        'wa_kelas_kosong_batas_kali' => '3',
        'wa_kelas_kosong_target_1' => '',
        'wa_kelas_kosong_target_3' => '',
        'wa_presensi_grup_fonte' => '',
        'wa_presensi_grup_fonte_enabled' => '1',
        'wa_presensi_kirim_pembimbing_enabled' => '1',
        'jam_kirim_wa_auto' => '',
        'wa_tagihan_auto_enabled' => '0',
        'wa_tagihan_calendar' => 'HIJRIYAH',
        'jadwal_tampilan_grup' => 'kegiatan',
        'wa_tagihan_day' => '5',
        'wa_tagihan_send_time' => '08:00',
        'wa_tagihan_custom_masehi_dates' => '',
        'keterangan_pengurus_bidang_keuangan' => 'Bertanggung jawab atas administrasi keuangan dan tagihan santri.',
        'batas_alpa_notif' => '3',
        'batas_telat_menit' => '15',
        'kategori_baik_max' => '1',
        'kategori_sedang_max' => '3',
        'keaktifan_tanggal_mulai_scan' => '',
        'izin_perpanjangan_max_hari' => '7',
        'izin_perpanjangan_jenis' => 'SAKIT,KELUAR',
        'izin_alpa_batas_enabled' => '1',
        'izin_alpa_keluar_max' => '3',
        'izin_alpa_keluar_hari' => '4',
        'izin_alpa_pulang_max' => '3',
        'izin_alpa_pulang_hari' => '4',
        'izin_alpa_bypass_user_ids' => '',
        'wa_izin_pembimbing_enabled' => '1',
        'wa_izin_pembimbing_grup' => '',
        'wa_izin_pembimbing_kirim_grup' => '0',
        'wa_izin_grup_fonte' => '',
        'wa_izin_grup_fonte_enabled' => '1',
        'wa_izin_pengurus' => '',
        'wa_izin_pengurus_putra' => '',
        'wa_izin_pengurus_putri' => '',
        'wa_alpa_pengurus_putra' => '',
        'wa_alpa_pengurus_putri' => '',
        'wa_izin_pengurus_enabled' => '1',
        'wa_izin_selesai_enabled' => '1',
        'wa_izin_wali_enabled' => '1',
        'wa_pembayaran_wali_enabled' => '1',
        'stampel_surat_path' => '',
        'stampel_kuitansi_path' => '',
        'cashless_saldo_rendah_wa_enabled' => '1',
        'poin_wa_notif_enabled' => '1',
        'cashless_saldo_rendah_wa_ambang' => '30000',
        'cashless_transaksi_wa_enabled' => '1',
        'cashless_laporan_harian_wa_enabled' => '0',
        'cashless_laporan_harian_wa_jam' => '20:00',
        'cashless_laporan_harian_wa_targets' => '',
        'wa_awal_tahun_auto_enabled' => '0',
        'wa_awal_tahun_send_time' => '09:00',
        'keuangan_pkpps_alokasi_komponen' => '',
        'keuangan_pos_nama_makan' => 'Makan',
        'app_tahun_masehi_mode' => 'BERJALAN',
        'app_tahun_masehi_tetap' => (string) (int) date('Y'),
        'pondok_ta_bulan_awal_hijri' => '1',
        'pondok_ta_bulan_awal_masehi' => '7',
        'akademik_libur_presensi_mode' => 'TAALIM_ONLY',
        'akademik_libur_taalim_only' => '1',
    ];
}

function akademik_libur_presensi_mode(PDO $pdo): string
{
    $mode = strtoupper(trim((string) app_setting($pdo, 'akademik_libur_presensi_mode', '')));
    if ($mode === '') {
        // kompatibilitas pengaturan lama
        $legacyTaalimOnly = app_setting($pdo, 'akademik_libur_taalim_only', '1') !== '0';
        return $legacyTaalimOnly ? 'TAALIM_ONLY' : 'ALL_BLOCKED';
    }
    if (!in_array($mode, ['ALL_BLOCKED', 'TAALIM_ONLY', 'JAMAAH_ONLY'], true)) {
        return 'TAALIM_ONLY';
    }
    return $mode;
}

function akademik_libur_presensi_diizinkan(PDO $pdo, string $kategoriKegiatan): bool
{
    $mode = akademik_libur_presensi_mode($pdo);
    $kat = strtoupper(trim($kategoriKegiatan));
    $isJamaah = $kat === 'JAMAAH';
    return match ($mode) {
        'ALL_BLOCKED' => false,
        'TAALIM_ONLY' => $isJamaah,      // saat libur: hanya jalur jama'ah yang jalan
        'JAMAAH_ONLY' => !$isJamaah,     // saat libur: hanya jalur ta'lim/ta'alum yang jalan
        default => $isJamaah,
    };
}

function akademik_libur_presensi_mode_label(PDO $pdo): string
{
    return match (akademik_libur_presensi_mode($pdo)) {
        'ALL_BLOCKED' => 'Semua presensi libur',
        'TAALIM_ONLY' => 'Ta\'lim/Ta\'alum libur, Jama\'ah aktif',
        'JAMAAH_ONLY' => 'Jama\'ah libur, Ta\'lim/Ta\'alum aktif',
        default => 'Ta\'lim/Ta\'alum libur, Jama\'ah aktif',
    };
}

function akademik_libur_presensi_mode_aktif_di_tanggal(PDO $pdo, string $tanggal): ?string
{
    if (!function_exists('akademik_libur_info')) {
        require_once __DIR__ . '/akademik.php';
    }
    if (!function_exists('akademik_blokir_presensi_libur')) {
        require_once __DIR__ . '/akademik.php';
    }
    $libur = akademik_libur_info($pdo, $tanggal, 'presensi');
    if ($libur === null || !akademik_blokir_presensi_libur($pdo)) {
        return null;
    }
    return akademik_libur_presensi_mode($pdo);
}

function akademik_libur_presensi_filter_sql_by_mode(string $mode, string $kategoriExpr = 'COALESCE(k.kategori_kegiatan, "TAALIM")'): string
{
    $m = strtoupper(trim($mode));
    if ($m === 'TAALIM_ONLY') {
        return ' AND ' . $kategoriExpr . ' = "JAMAAH" ';
    }
    if ($m === 'JAMAAH_ONLY') {
        return ' AND ' . $kategoriExpr . ' <> "JAMAAH" ';
    }
    if ($m === 'ALL_BLOCKED') {
        return ' AND 1 = 0 ';
    }
    return '';
}

/** Nomor penerima notifikasi alpa otomatis per kelompok putra/putri. */
function wa_alpa_notif_target_kelompok(PDO $pdo, string $kelompok): string
{
    require_once __DIR__ . '/wa_nomor.php';
    $kelompok = strtolower(trim($kelompok));
    if ($kelompok === 'putri') {
        $fromTable = wa_nomor_targets($pdo, 'alpa_putri');
        if ($fromTable !== '') {
            return $fromTable;
        }
        $nomor = trim((string) app_setting($pdo, 'wa_alpa_pengurus_putri', ''));
        if ($nomor !== '') {
            return $nomor;
        }
    } elseif ($kelompok === 'putra') {
        $fromTable = wa_nomor_targets($pdo, 'alpa_putra');
        if ($fromTable !== '') {
            return $fromTable;
        }
        $nomor = trim((string) app_setting($pdo, 'wa_alpa_pengurus_putra', ''));
        if ($nomor !== '') {
            return $nomor;
        }
        $fromPengurus = wa_nomor_targets($pdo, 'pengurus');
        if ($fromPengurus !== '') {
            return $fromPengurus;
        }

        return trim((string) app_setting($pdo, 'wa_pengurus', ''));
    }

    return wa_alpa_notif_target($pdo);
}

/** Nomor penerima notifikasi alpa otomatis (mode lama / fallback putra tanpa tier). */
function wa_alpa_notif_target(PDO $pdo): string
{
    return wa_alpa_notif_target_kelompok($pdo, 'putra');
}

/** Nomor penerima permohonan izin baru (PENDING). Fallback ke wa_pengurus jika belum diisi. */
function wa_permohonan_izin_target(PDO $pdo): string
{
    require_once __DIR__ . '/wa_nomor.php';
    $fromTable = wa_nomor_targets($pdo, 'izin_baru');
    if ($fromTable !== '') {
        return $fromTable;
    }

    $izin = trim((string) app_setting($pdo, 'wa_permohonan_izin', ''));
    if ($izin !== '') {
        return $izin;
    }

    return wa_alpa_notif_target($pdo);
}

function wa_permohonan_izin_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_permohonan_izin_enabled', '1')) === '1';
}

/**
 * Jenis izin yang memicu WA permohonan baru (kode: SAKIT, KELUAR, TUGAS, SYARI).
 *
 * @return list<string>
 */
function wa_permohonan_izin_jenis_allowed_list(PDO $pdo): array
{
    require_once __DIR__ . '/perizinan_jenis.php';
    $raw = trim((string) app_setting($pdo, 'wa_permohonan_izin_jenis', 'SYARI'));
    if ($raw === '') {
        return [];
    }
    $allowed = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $kode = perizinan_jenis_izin_normalize((string) $part);
        if (in_array($kode, perizinan_jenis_izin_kodes(), true)) {
            $allowed[$kode] = $kode;
        }
    }

    return array_values($allowed);
}

function wa_permohonan_izin_jenis_allowed(PDO $pdo, string $jenisIzin): bool
{
    require_once __DIR__ . '/perizinan_jenis.php';
    $kode = perizinan_jenis_izin_normalize($jenisIzin);
    $allowed = wa_permohonan_izin_jenis_allowed_list($pdo);

    return $allowed !== [] && in_array($kode, $allowed, true);
}

/** Apakah WA permohonan izin baru harus dikirim untuk jenis ini. */
function wa_permohonan_izin_should_notify(PDO $pdo, string $jenisIzin): bool
{
    return wa_permohonan_izin_enabled($pdo) && wa_permohonan_izin_jenis_allowed($pdo, $jenisIzin);
}

/** Nomor penerima laporan izin disetujui & izin selesai untuk pengurus/petugas surat. */
function wa_izin_pengurus_target(PDO $pdo): string
{
    require_once __DIR__ . '/wa_nomor.php';
    $fromTable = wa_nomor_targets($pdo, 'izin_pengurus');
    if ($fromTable !== '') {
        return $fromTable;
    }

    $nomor = trim((string) app_setting($pdo, 'wa_izin_pengurus', ''));
    if ($nomor !== '') {
        return $nomor;
    }

    return wa_permohonan_izin_target($pdo);
}

/** Nomor pengurus izin per kelompok putra/putri; fallback ke wa_izin_pengurus jika kosong. */
function wa_izin_pengurus_target_kelompok(PDO $pdo, string $kelompok): string
{
    require_once __DIR__ . '/wa_nomor.php';
    $kelompok = strtolower(trim($kelompok));
    if ($kelompok === 'putra') {
        $fromTable = wa_nomor_targets($pdo, 'izin_putra');
        if ($fromTable !== '') {
            return $fromTable;
        }
        $nomor = trim((string) app_setting($pdo, 'wa_izin_pengurus_putra', ''));
        if ($nomor !== '') {
            return $nomor;
        }
    } elseif ($kelompok === 'putri') {
        $fromTable = wa_nomor_targets($pdo, 'izin_putri');
        if ($fromTable !== '') {
            return $fromTable;
        }
        $nomor = trim((string) app_setting($pdo, 'wa_izin_pengurus_putri', ''));
        if ($nomor !== '') {
            return $nomor;
        }
    }

    return wa_izin_pengurus_target($pdo);
}

function wa_izin_pengurus_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_izin_pengurus_enabled', '1')) === '1';
}

function wa_izin_selesai_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_izin_selesai_enabled', '1')) === '1';
}

function wa_izin_wali_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_izin_wali_enabled', '1')) === '1';
}

/**
 * WA petugas pendidikan: munawib belum hadir, kelas kosong, dll.
 */
function wa_petugas_pendidikan_target(PDO $pdo): string
{
    require_once __DIR__ . '/wa_nomor.php';
    $fromTable = wa_nomor_targets($pdo, 'petugas_pendidikan');
    if ($fromTable !== '') {
        return $fromTable;
    }

    $petugas = trim((string) app_setting($pdo, 'wa_petugas_pendidikan', ''));
    if ($petugas !== '') {
        return $petugas;
    }

    return wa_alpa_notif_target($pdo);
}

/** Isi kunci pengaturan pondok yang belum ada di app_settings (tanpa menimpa nilai lama). */
function ensure_pondok_settings_defaults(PDO $pdo): void
{
    if (!table_exists($pdo, 'app_settings')) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['pondok_settings_defaults_ok'])) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT IGNORE INTO app_settings (setting_key, setting_value)
        VALUES (:k, :v)
    ');
    foreach (pondok_settings_defaults() as $key => $value) {
        $ins->execute(['k' => $key, 'v' => $value]);
    }
    if (function_exists('logo_ensure_white_bg_pwa_icons')) {
        logo_ensure_white_bg_pwa_icons($pdo);
    } elseif (file_exists(__DIR__ . '/pwa_brand.php')) {
        require_once __DIR__ . '/pwa_brand.php';
        logo_ensure_white_bg_pwa_icons($pdo);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pondok_settings_defaults_ok'] = 1;
    }
}

/** Normalisasi isi QR bayar cashless (sama di scan & pengaturan peta kode). */
function cashless_normalize_money_qr_payload(string $raw): string
{
    $t = trim($raw);
    if (stripos($t, 'CASHLESSPAY:') === 0) {
        $t = trim(substr($t, strlen('CASHLESSPAY:')));
    }
    $out = preg_replace('/[^A-Za-z0-9]/', '', $t) ?? '';

    return strtoupper($out);
}

function ensure_cashless_nominal_qr_map_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cashless_nominal_qr_map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_qr VARCHAR(120) NOT NULL,
            nominal INT NOT NULL DEFAULT 0,
            keterangan VARCHAR(160) NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cashless_nominal_qr_kode (kode_qr)
        )
    ');
}

function ensure_cashless_nominal_tokens_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cashless_nominal_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_code VARCHAR(80) NOT NULL UNIQUE,
            nominal INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            is_used TINYINT(1) NOT NULL DEFAULT 0,
            used_at DATETIME NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

function islamic_calendar_locale(): string
{
    return 'en_US@calendar=islamic-umalqura';
}

function get_hijri_year_month(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        $timestamp = time();
    }
    if (!class_exists('IntlDateFormatter')) {
        return date('Y-m', $timestamp);
    }

    $formatter = new IntlDateFormatter(
        islamic_calendar_locale(),
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::TRADITIONAL,
        'yyyy-MM'
    );
    $formatted = $formatter->format($timestamp);
    if (is_string($formatted) && preg_match('/^\d{4}-\d{2}$/', $formatted)) {
        return $formatted;
    }

    // Fallback ke varian kalender islamic default bila umalqura tidak tersedia.
    $fallbackFormatter = new IntlDateFormatter(
        'id_ID@calendar=islamic',
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::TRADITIONAL,
        'yyyy-MM'
    );
    $fallback = $fallbackFormatter->format($timestamp);
    return is_string($fallback) && preg_match('/^\d{4}-\d{2}$/', $fallback) ? $fallback : date('Y-m', $timestamp);
}

/**
 * Tanggal hijriyah yyyy-MM-dd dari tanggal masehi Y-m-d (Intl: Um al-Qura, lalu islamic).
 * Rentang bulan H. ke Masehi (get_gregorian_range_from_hijri_month) diselaraskan dengan fungsi ini.
 */
function get_hijri_full_date(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        $timestamp = time();
    }
    if (!class_exists('IntlDateFormatter')) {
        return date('Y-m-d', $timestamp);
    }
    foreach ([islamic_calendar_locale(), 'id_ID@calendar=islamic'] as $loc) {
        $formatter = new IntlDateFormatter(
            $loc,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            date_default_timezone_get(),
            IntlDateFormatter::TRADITIONAL,
            'yyyy-MM-dd'
        );
        $formatted = $formatter->format($timestamp);
        if (is_string($formatted) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $formatted)) {
            return $formatted;
        }
    }

    return date('Y-m-d', $timestamp);
}

function get_hijri_ym_from_gregorian_month(int $year, int $month): string
{
    $date = sprintf('%04d-%02d-01', $year, $month);
    return get_hijri_year_month($date);
}

/**
 * Memecah string hijriyah yyyy-mm-dd menjadi komponen bilangan.
 *
 * @return array{y:int,m:int,d:int}|null
 */
function hijri_parse_ymd_parts(string $hijriYmd): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $hijriYmd, $m)) {
        return null;
    }

    return ['y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3]];
}

/**
 * Perkiraan rentang tanggal Masehi (Y-m-d) untuk satu bulan H. bila IntlCalendar tidak ada.
 * Penting: angka tahun H. (mis. 1447) tidak boleh dipakai sebagai tahun pada kalender Masehi.
 * Metode: memetakan 12 bulan H. ke potongan merata rentang Masehi perkiraan untuk satu tahun H. penuh
 * (bukan hisab resmi; aktifkan extension=intl untuk Um al-Qura).
 *
 * @return array{0: string, 1: string}
 */
function hijri_month_gregorian_bounds_tabular_gregorian_year_split(int $hijriYear, int $hijriMonth): array
{
    $hy = max(1300, min(1600, $hijriYear));
    $hm = max(1, min(12, $hijriMonth));
    $gyLo = (int) max(1, floor(622.5446 + 0.970224 * ($hy - 1)));
    $gyHi = (int) min(2100, ceil(622.5446 + 0.970224 * $hy));
    $tStart = strtotime(sprintf('%04d-01-01', $gyLo));
    $tEnd = strtotime(sprintf('%04d-12-31', $gyHi));
    if ($tStart === false || $tEnd === false || $tEnd < $tStart) {
        return [sprintf('%04d-01-01', $gyLo), sprintf('%04d-12-31', max($gyLo, $gyHi))];
    }
    $span = $tEnd - $tStart;
    $a = (int) ($tStart + (int) floor($span * ($hm - 1) / 12));
    $b = (int) ($tStart + (int) floor($span * $hm / 12) - 86400);
    if ($b < $a) {
        $b = min($tEnd, $a + 28 * 86400);
    }
    $b = min($b, $tEnd);

    return [date('Y-m-d', $a), date('Y-m-d', $b)];
}

/**
 * Perkiraan rentang Masehi (Y-m-d) untuk satu bulan H. dari IntlCalendar.
 * Urutan locale sama dengan get_hijri_full_date (Um al-Qura lalu islamic).
 *
 * @return array{0: string, 1: string}
 */
function hijri_month_gregorian_bounds_intl_approx(int $hijriYear, int $hijriMonth): array
{
    if (!class_exists('IntlCalendar')) {
        return hijri_month_gregorian_bounds_tabular_gregorian_year_split($hijriYear, $hijriMonth);
    }

    $timezone = new DateTimeZone(date_default_timezone_get());
    foreach ([islamic_calendar_locale(), 'id_ID@calendar=islamic'] as $loc) {
        $calendar = IntlCalendar::createInstance($timezone, $loc);
        if (!$calendar) {
            continue;
        }

        $calendar->set(IntlCalendar::FIELD_YEAR, $hijriYear);
        $calendar->set(IntlCalendar::FIELD_MONTH, $hijriMonth - 1);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->set(IntlCalendar::FIELD_HOUR_OF_DAY, 0);
        $calendar->set(IntlCalendar::FIELD_MINUTE, 0);
        $calendar->set(IntlCalendar::FIELD_SECOND, 0);
        $startMs = $calendar->getTime();
        if ($startMs === false) {
            continue;
        }

        $nextCalendar = clone $calendar;
        $nextCalendar->add(IntlCalendar::FIELD_MONTH, 1);
        $nextStartMs = $nextCalendar->getTime();
        if ($nextStartMs === false) {
            continue;
        }

        $start = date('Y-m-d', (int) floor(((float) $startMs) / 1000));
        $end = date('Y-m-d', (int) floor((((float) $nextStartMs) - 86400000) / 1000));

        return [$start, $end];
    }

    return hijri_month_gregorian_bounds_tabular_gregorian_year_split($hijriYear, $hijriMonth);
}

/**
 * Daftar tanggal Masehi (Y-m-d) yang menurut get_hijri_full_date() jatuh pada bulan H. (tahun & bulan).
 * Menyelaraskan arah Hijri→Masehi dengan Masehi→Hijri (sumber konversi yang dipakai aplikasi).
 *
 * @return list<string>
 */
function get_masehi_days_in_hijri_month(int $hijriYear, int $hijriMonth): array
{
    [$approxStart, $approxEnd] = hijri_month_gregorian_bounds_intl_approx($hijriYear, $hijriMonth);
    if (!class_exists('IntlDateFormatter')) {
        return masehi_linear_days_between($approxStart, $approxEnd);
    }

    $padDays = 70;
    $cur = strtotime($approxStart . ' -' . $padDays . ' days');
    $lim = strtotime($approxEnd . ' +' . $padDays . ' days');
    if ($cur === false || $lim === false) {
        return masehi_linear_days_between($approxStart, $approxEnd);
    }

    $hits = [];
    while ($cur <= $lim) {
        $ymd = date('Y-m-d', $cur);
        $parts = hijri_parse_ymd_parts(get_hijri_full_date($ymd));
        if ($parts && $parts['y'] === $hijriYear && $parts['m'] === $hijriMonth) {
            $hits[] = $ymd;
        }
        $cur = strtotime('+1 day', $cur);
    }

    if ($hits === []) {
        return masehi_linear_days_between($approxStart, $approxEnd);
    }

    sort($hits);

    return $hits;
}

/**
 * @return list<string>
 */
function masehi_linear_days_between(string $gStart, string $gEnd): array
{
    $out = [];
    $cur = strtotime($gStart);
    $endTs = strtotime($gEnd);
    if ($cur === false || $endTs === false) {
        return [];
    }
    while ($cur <= $endTs) {
        $out[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }

    return $out;
}

/**
 * Rentang Masehi (inklusif) setara satu bulan hijriyah menurut get_hijri_full_date().
 *
 * @return array{0: string, 1: string}
 */
function get_gregorian_range_from_hijri_month(int $hijriYear, int $hijriMonth): array
{
    $days = get_masehi_days_in_hijri_month($hijriYear, $hijriMonth);
    if ($days !== []) {
        return [$days[0], $days[count($days) - 1]];
    }

    return hijri_month_gregorian_bounds_intl_approx($hijriYear, $hijriMonth);
}

function ensure_jadwal_kegiatan_tempat(PDO $pdo): void
{
    if (!table_exists($pdo, 'jadwal_kegiatan')) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS tempat VARCHAR(255) NULL');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN tempat VARCHAR(255) NULL');
        } catch (PDOException $e2) {
            $m2 = $e2->getMessage();
            if (stripos($m2, 'Duplicate column') !== false || strpos($m2, '1060') !== false) {
                return;
            }
            throw $e2;
        }
    }
}

function ensure_kegiatan_kategori_column(PDO $pdo): void
{
    if (!table_exists($pdo, 'kegiatan')) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE kegiatan ADD COLUMN IF NOT EXISTS kategori_kegiatan VARCHAR(20) NOT NULL DEFAULT "TAALIM"');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE kegiatan ADD COLUMN kategori_kegiatan VARCHAR(20) NOT NULL DEFAULT "TAALIM"');
        } catch (PDOException $e2) {
            $m2 = $e2->getMessage();
            if (stripos($m2, 'Duplicate column') === false && strpos($m2, '1060') === false) {
                throw $e2;
            }
        }
    }
    $pdo->exec('UPDATE kegiatan SET kategori_kegiatan = "TAALIM" WHERE COALESCE(kategori_kegiatan, "") = ""');
}

/** Kategori beban kerja payroll per kegiatan/mata pelajaran (Berat/Sedang/Ringan/Khusus). */
function ensure_kegiatan_payroll_kriteria_column(PDO $pdo): void
{
    if (!table_exists($pdo, 'kegiatan')) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE kegiatan ADD COLUMN IF NOT EXISTS payroll_kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL DEFAULT 'RINGAN'");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE kegiatan ADD COLUMN payroll_kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL DEFAULT 'RINGAN'");
        } catch (PDOException $e2) {
            $m2 = $e2->getMessage();
            if (stripos($m2, 'Duplicate column') === false && strpos($m2, '1060') === false) {
                throw $e2;
            }
        }
    }
    $pdo->exec("UPDATE kegiatan SET payroll_kriteria = 'RINGAN' WHERE COALESCE(payroll_kriteria, '') = ''");
}

function activity_for_tingkatan(PDO $pdo, string $tingkatan, string $date, string $time): ?array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return null;
    }
    ensure_jadwal_kegiatan_tempat($pdo);
    ensure_kegiatan_kategori_column($pdo);
    $modeLibur = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $date);
    $kategoriFilter = $modeLibur !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLibur, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';

    $day = date('N', strtotime($date));
    $statement = $pdo->prepare('
        SELECT k.id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan, j.id AS jadwal_kegiatan_id, j.jam_mulai, j.jam_selesai, j.tempat
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.tingkatan = :tingkatan OR j.tingkatan = "Semua Tingkatan")
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
          AND k.is_active = 1
          ' . $kategoriFilter . '
        LIMIT 1
    ');
    $statement->execute([
        'tingkatan' => $tingkatan,
        'hari_ke' => $day,
        'jam_now' => $time,
    ]);

    $result = $statement->fetch();
    return $result ?: null;
}

function resolve_wa_endpoint(string $endpoint, string $token): string
{
    $endpoint = trim($endpoint);
    if ($endpoint !== '') {
        $normalized = $endpoint;
        if (!preg_match('#^https?://#i', $normalized)) {
            $normalized = 'https://' . ltrim($normalized, '/');
        }

        $parts = parse_url($normalized);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        // Banyak kasus URL panel/login Fonnte (mis. md.fonnte.com/new/login.php).
        // Paksa ke endpoint API resmi agar token-only tetap jalan.
        if (strpos($host, 'fonnte.com') !== false || strpos($host, 'fonte') !== false) {
            if ($host !== 'api.fonnte.com' || stripos($path, '/send') === false) {
                return 'https://api.fonnte.com/send';
            }
        }

        return $normalized;
    }

    // Jika hanya token yang diisi, gunakan endpoint default Fonnte.
    if (trim($token) !== '') {
        return 'https://api.fonnte.com/send';
    }

    return '';
}

function send_wa_message_with_result(PDO $pdo, string $phone, string $message, array $override = []): array
{
    require_once __DIR__ . '/wa_otomatis.php';

    return wa_otomatis_send($pdo, $phone, $message, $override);
}

function send_wa_message(PDO $pdo, string $phone, string $message, array $opts = []): bool
{
    $result = send_wa_message_with_result($pdo, $phone, $message, $opts);
    return (bool) ($result['success'] ?? false);
}

function parse_phone_list(string $raw): array
{
    require_once __DIR__ . '/wa_otomatis.php';

    return wa_otomatis_parse_targets($raw);
}

function normalize_wa_phone(string $phone): string
{
    require_once __DIR__ . '/wa_otomatis.php';

    return wa_otomatis_normalize_target($phone);
}

/** Tautan https://wa.me/… untuk membuka chat dengan teks awal (tanpa gateway). */
function wa_me_chat_url(string $phoneRaw, string $text = ''): ?string
{
    $digits = normalize_wa_phone($phoneRaw);
    if ($digits === '' || strlen($digits) < 10) {
        return null;
    }
    $base = 'https://wa.me/' . $digits;
    $t = trim($text);
    if ($t === '') {
        return $base;
    }

    return $base . '?text=' . rawurlencode($t);
}

function send_wa_bulk(PDO $pdo, string $phonesRaw, string $message, array $opts = []): int
{
    $result = send_wa_bulk_with_result($pdo, $phonesRaw, $message, $opts);

    return (int) ($result['sent'] ?? 0);
}

/**
 * @param array<string, mixed> $opts
 * @return array{sent:int,failed:int,total:int,details:list<array<string,mixed>>}
 */
function send_wa_bulk_with_result(PDO $pdo, string $phonesRaw, string $message, array $opts = []): array
{
    require_once __DIR__ . '/wa_otomatis.php';

    return wa_otomatis_send_bulk($pdo, $phonesRaw, $message, $opts);
}

function trigger_auto_wa_notifications(PDO $pdo): void
{
    require_once __DIR__ . '/wa_otomatis.php';
    require_once __DIR__ . '/alpa_tier.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }
    if (!table_exists($pdo, 'app_settings') || !table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return;
    }

    $pengurusWa = wa_alpa_notif_target($pdo);
    // Boleh kosong di sini jika tiap tier punya nomor sendiri; cron helper pakai fallback per tier.
    require_once __DIR__ . '/datetime_display.php';
    $jamAutoWa = trim((string) app_setting($pdo, 'jam_kirim_wa_auto', ''));
    if ($jamAutoWa !== '' && !app_jam_sudah_lewat($jamAutoWa)) {
        return;
    }

    // Silang ambang: hanya yang belum pernah dilapor untuk ambang tsb (bukan dump harian semua ≥ N).
    require_once __DIR__ . '/wa_laporan_alpa.php';
    $result = alpa_tier_cron_flush_crossings($pdo, date('Y-m-d'));
    $sentPutra = 0;
    $sentPutri = 0;
    foreach ($result['tiers'] ?? [] as $tierRow) {
        if (!is_array($tierRow)) {
            continue;
        }
        $sentPutra += (int) ($tierRow['sent_putra'] ?? 0);
        $sentPutri += (int) ($tierRow['sent_putri'] ?? 0);
    }
    save_setting($pdo, 'wa_auto_alpa_last_result', json_encode([
        'sent' => (int) ($result['sent'] ?? 0),
        'sent_putra' => $sentPutra,
        'sent_putri' => $sentPutri,
        'tiers' => $result['tiers'] ?? [],
        'pending_santri' => (int) ($result['pending_santri'] ?? 0),
        'note' => (string) ($result['note'] ?? ''),
        'mode' => 'crossing',
        'at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE));

    if ((int) ($result['sent'] ?? 0) > 0) {
        save_setting($pdo, 'wa_auto_last_sent_at', date('Y-m-d H:i:s'));
        // Tetap catat tanggal terakhir ada kiriman (bukan kunci “sekali sehari dump”).
        save_setting($pdo, 'wa_auto_last_sent_date', date('Y-m-d'));
    } elseif ($pengurusWa === '' && ($result['note'] ?? '') === 'tidak_ada_kiriman_baru') {
        // tidak ada yang perlu dikirim
    }
}

/**
 * Notifikasi ketika pembimbing izin namun munawib pengganti belum scan
 * setelah batas menit dari jam mulai jadwal.
 */
function trigger_wa_mudabir_belum_hadir(PDO $pdo): void
{
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'general')) {
        return;
    }
    if (!table_exists($pdo, 'perizinan_pembimbing')
        || !table_exists($pdo, 'jadwal_kegiatan')
        || !table_exists($pdo, 'kegiatan')
        || !table_exists($pdo, 'pembimbing')
        || !table_exists($pdo, 'munawib_penugasan')
        || !table_exists($pdo, 'presensi_munawib')) {
        return;
    }

    $enabled = trim((string) app_setting($pdo, 'wa_notif_mudabir_enabled', '1')) === '1';
    if (!$enabled) {
        return;
    }
    if (wa_otomatis_gateway_error($pdo) !== null) {
        return;
    }
    $waTujuan = wa_petugas_pendidikan_target($pdo);
    if ($waTujuan === '') {
        return;
    }

    $batasMenit = max(5, (int) app_setting($pdo, 'mudabir_batas_menit', '30'));
    $tanggal = date('Y-m-d');
    $jamSekarang = date('H:i:s');
    $hariKe = (int) date('N', strtotime($tanggal));
    $debounceDate = date('Y-m-d');

    $sql = '
        SELECT
            j.id AS jadwal_id,
            j.kegiatan_id,
            j.jam_mulai,
            j.jam_selesai,
            b.id AS pembimbing_id,
            b.nama_pembimbing,
            b.nip,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS nama_kegiatan,
            COALESCE(j.tingkatan, "") AS tingkatan
        FROM perizinan_pembimbing i
        INNER JOIN pembimbing b ON b.id = i.pembimbing_id
        INNER JOIN jadwal_kegiatan j ON j.pembimbing_id = i.pembimbing_id
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE i.status_izin = "IZIN"
          AND :tgl BETWEEN i.tanggal_mulai AND i.tanggal_selesai
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND k.is_active = 1
          AND :jam_now BETWEEN ADDTIME(j.jam_mulai, SEC_TO_TIME(:batas_sec)) AND j.jam_selesai
        ORDER BY j.jam_mulai ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        'tgl' => $tanggal,
        'hari_ke' => $hariKe,
        'jam_now' => $jamSekarang,
        'batas_sec' => $batasMenit * 60,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return;
    }

    $missing = [];
    foreach ($rows as $r) {
        $jadwalId = (int) ($r['jadwal_id'] ?? 0);
        $kegiatanId = (int) ($r['kegiatan_id'] ?? 0);
        $pembimbingId = (int) ($r['pembimbing_id'] ?? 0);
        if ($jadwalId <= 0 || $kegiatanId <= 0 || $pembimbingId <= 0) {
            continue;
        }

        $debounceKey = 'wa_mudabir_missing_' . $debounceDate . '_' . $jadwalId;
        if (trim((string) app_setting($pdo, $debounceKey, '')) === '1') {
            continue;
        }

        $cekPenugasanSql = '
            SELECT mp.id
            FROM munawib_penugasan mp
            WHERE mp.pembimbing_id = :pid
              AND mp.status = "AKTIF"
              AND :tgl BETWEEN mp.tanggal_mulai AND mp.tanggal_selesai
              AND (mp.kegiatan_id IS NULL OR mp.kegiatan_id = :kid)
            ORDER BY mp.id DESC
            LIMIT 1
        ';
        $stP = $pdo->prepare($cekPenugasanSql);
        $stP->execute(['pid' => $pembimbingId, 'tgl' => $tanggal, 'kid' => $kegiatanId]);
        $penugasanId = (int) ($stP->fetchColumn() ?: 0);
        if ($penugasanId <= 0) {
            $missing[] = $r + ['reason' => 'Belum ada penugasan munawib'];
            continue;
        }

        $cekScanSql = '
            SELECT pm.id
            FROM presensi_munawib pm
            WHERE pm.penugasan_id = :pen
              AND pm.tanggal = :tgl
              AND (pm.kegiatan_id IS NULL OR pm.kegiatan_id = :kid)
            LIMIT 1
        ';
        $stS = $pdo->prepare($cekScanSql);
        $stS->execute(['pen' => $penugasanId, 'tgl' => $tanggal, 'kid' => $kegiatanId]);
        $scanId = (int) ($stS->fetchColumn() ?: 0);
        if ($scanId <= 0) {
            $missing[] = $r + ['reason' => 'Penugasan ada, tetapi munawib belum scan'];
        }
    }

    if ($missing === []) {
        return;
    }

    $lines = [];
    $lines[] = '⚠️ Laporan munawib belum hadir';
    $lines[] = 'Tanggal: ' . date('d/m/Y');
    $lines[] = 'Batas: ' . $batasMenit . ' menit dari jam mulai';
    $lines[] = '';
    foreach ($missing as $i => $m) {
        $lines[] = ($i + 1) . '. ' . (string) ($m['nama_kegiatan'] ?? 'Kegiatan');
        $lines[] = '   Pembimbing izin: ' . (string) ($m['nama_pembimbing'] ?? '-');
        $lines[] = '   Tingkatan: ' . (string) ($m['tingkatan'] ?? '-');
        $lines[] = '   Jam: ' . substr((string) ($m['jam_mulai'] ?? '00:00:00'), 0, 5)
            . ' - ' . substr((string) ($m['jam_selesai'] ?? '00:00:00'), 0, 5);
        $lines[] = '   Status: ' . (string) ($m['reason'] ?? '-');
    }
    $message = implode("\n", $lines);

    if (!function_exists('presensi_wa_kirim')) {
        require_once __DIR__ . '/wa_presensi.php';
    }
    $bulk = presensi_wa_kirim($pdo, $waTujuan, $message, [
        'kind' => 'presensi',
        'dedup_key' => 'mudabir_missing:' . $debounceDate . ':summary',
        'dedup_key_once' => true,
    ]);
    $sent = (int) ($bulk['sent'] ?? 0);
    if ($sent > 0 || (int) ($bulk['skipped'] ?? 0) > 0) {
        foreach ($missing as $m) {
            $jadwalId = (int) ($m['jadwal_id'] ?? 0);
            if ($jadwalId > 0) {
                save_setting($pdo, 'wa_mudabir_missing_' . $debounceDate . '_' . $jadwalId, '1');
            }
        }
        save_setting($pdo, 'wa_mudabir_last_sent_at', date('Y-m-d H:i:s'));

        $pbMessages = [];
        foreach ($missing as $m) {
            $pbId = (int) ($m['pembimbing_id'] ?? 0);
            if ($pbId <= 0 || isset($pbMessages[$pbId])) {
                continue;
            }
            $pbMessages[$pbId] = '⚠️ Munawib pengganti belum hadir' . "\n"
                . 'Tanggal: ' . date('d/m/Y') . "\n"
                . 'Kegiatan: ' . (string) ($m['nama_kegiatan'] ?? 'Kegiatan') . "\n"
                . 'Tingkatan: ' . (string) ($m['tingkatan'] ?? '-') . "\n"
                . 'Jam: ' . substr((string) ($m['jam_mulai'] ?? '00:00:00'), 0, 5)
                . ' - ' . substr((string) ($m['jam_selesai'] ?? '00:00:00'), 0, 5) . "\n"
                . 'Status: ' . (string) ($m['reason'] ?? '-') . "\n"
                . 'Silakan koordinasi munawib pengganti.';
        }
        presensi_wa_kirim_ke_pembimbing($pdo, $pbMessages, [
            'kind' => 'presensi',
            'dedup_key' => 'mudabir_missing:' . $debounceDate . ':summary',
            'context' => 'munawib_belum_hadir',
        ]);
    }
}

function ensure_point_tables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_rule VARCHAR(40) NOT NULL UNIQUE,
            kategori VARCHAR(80) NOT NULL,
            nama_rule VARCHAR(150) NOT NULL,
            bobot_poin INT NOT NULL DEFAULT 0,
            contoh_pelanggaran TEXT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_sanctions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ambang_poin INT NOT NULL,
            tindakan TEXT NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tanggal DATE NOT NULL,
            jenis_perubahan ENUM("PLUS","MINUS") NOT NULL DEFAULT "PLUS",
            point_delta INT NOT NULL,
            rule_id INT NULL,
            sumber_data VARCHAR(40) NOT NULL DEFAULT "MANUAL",
            reference_presensi_id INT NULL,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_point_source_ref (sumber_data, reference_presensi_id),
            INDEX idx_point_santri_tanggal (santri_id, tanggal),
            CONSTRAINT fk_point_ledger_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE,
            CONSTRAINT fk_point_ledger_rule FOREIGN KEY (rule_id) REFERENCES point_rules(id) ON DELETE SET NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_followups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            periode_bulan TINYINT NOT NULL,
            periode_tahun SMALLINT NOT NULL,
            total_poin INT NOT NULL DEFAULT 0,
            tindakan VARCHAR(120) NOT NULL,
            durasi_keterangan VARCHAR(120) NULL,
            keterangan TEXT NULL,
            status_tindak ENUM("BELUM","PROSES","SELESAI") NOT NULL DEFAULT "BELUM",
            bukti_tindak TEXT NULL,
            handled_by_user_id INT NULL,
            handled_by_nama VARCHAR(120) NOT NULL,
            tanggal_tindak DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_followup_periode (periode_tahun, periode_bulan),
            INDEX idx_followup_santri (santri_id),
            CONSTRAINT fk_point_followups_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        )
    ');
    $pdo->exec('ALTER TABLE point_followups ADD COLUMN IF NOT EXISTS status_tindak ENUM("BELUM","PROSES","SELESAI") NOT NULL DEFAULT "BELUM"');
    $pdo->exec('ALTER TABLE point_followups ADD COLUMN IF NOT EXISTS bukti_tindak TEXT NULL');
    if (function_exists('column_exists') && !column_exists($pdo, 'point_rules', 'jenis_rule')) {
        try {
            $pdo->exec('ALTER TABLE point_rules ADD COLUMN jenis_rule ENUM("PLUS","MINUS") NOT NULL DEFAULT "PLUS" AFTER bobot_poin');
        } catch (PDOException $e) {
        }
    }

    $rulesCount = (int) $pdo->query('SELECT COUNT(*) FROM point_rules')->fetchColumn();
    if ($rulesCount === 0) {
        $defaults = [
            ['A_SANGAT_BERAT', 'A. Sangat Berat', 'Pelanggaran sangat berat', 25, 'Percintaan, Pencurian, Perkelahian, Perjudian, Narkoba/Miras, Asusila.', 10],
            ['B_BERAT_15', 'B. Berat', 'Pelanggaran berat', 15, 'Membawa HP/Elektronik tanpa izin, kendaraan tanpa izin, ghosob, masuk asrama lawan jenis.', 20],
            ['B_BERAT_10', 'B. Berat', 'Pelanggaran berat level 2', 10, 'Bolos ngaji/belajar/mujahadah, merusak fasilitas, kata kasar, tidur saat kegiatan sama.', 30],
            ['C_SEDANG_5', 'C. Sedang', 'Pelanggaran sedang', 5, 'Keluar tanpa izin, ngiras/ngendong, bermain catur/kartu, meminjam dipan.', 40],
            ['C_SEDANG_3', 'C. Sedang', 'Pelanggaran sedang level 2', 3, 'Tidak piket, gaduh, tidur saat kegiatan.', 50],
            ['D_RINGAN_1', 'D. Ringan', 'Pelanggaran ringan', 1, 'Peci non-hitam, lengan pendek saat sholat, rambut/model tidak lazim, geland/kalung, sampah.', 60],
        ];
        $insertRule = $pdo->prepare('
            INSERT INTO point_rules (kode_rule, kategori, nama_rule, bobot_poin, contoh_pelanggaran, urutan)
            VALUES (:kode_rule, :kategori, :nama_rule, :bobot_poin, :contoh_pelanggaran, :urutan)
        ');
        foreach ($defaults as $row) {
            $insertRule->execute([
                'kode_rule' => $row[0],
                'kategori' => $row[1],
                'nama_rule' => $row[2],
                'bobot_poin' => $row[3],
                'contoh_pelanggaran' => $row[4],
                'urutan' => $row[5],
            ]);
        }
    }

    $sanctionCount = (int) $pdo->query('SELECT COUNT(*) FROM point_sanctions')->fetchColumn();
    if ($sanctionCount === 0) {
        $sanctions = [
            [10, 'Pilihan: Membaca Al-Quran 2 juz, Mujahadah 1 jam, atau 1 jam bersih-bersih.', 10],
            [25, 'Wajib gundul (putra)/kerudung disiplin (putri). Pilihan: berdiri 2 jam, baca Yasin 2 jam, Mujahadah 2 jam, atau 2 jam bersih-bersih.', 20],
            [50, 'Surat Peringatan 1 (SP1). Wajib gundul/kerudung disiplin. Pilihan: baca Yasin 3 jam, Al-Quran 5 juz, Mujahadah 3 jam, atau 3 jam bersih-bersih.', 30],
            [75, 'Surat Peringatan 2 (SP2) dan pemanggilan orang tua. Wajib gundul/kerudung disiplin. Pilihan: baca Yasin 4 jam, Al-Quran 7 juz, Mujahadah 4 jam, atau 4 jam bersih-bersih.', 40],
            [100, 'Sanksi final: dikeluarkan dari pesantren. Wajib gundul/kerudung disiplin hingga dijemput. Pilihan: baca Yasin 5 jam, Al-Quran 9 juz, Mujahadah 5 jam, atau 5 jam bersih-bersih.', 50],
        ];
        $insertSanction = $pdo->prepare('
            INSERT INTO point_sanctions (ambang_poin, tindakan, urutan)
            VALUES (:ambang_poin, :tindakan, :urutan)
        ');
        foreach ($sanctions as $item) {
            $insertSanction->execute([
                'ambang_poin' => $item[0],
                'tindakan' => $item[1],
                'urutan' => $item[2],
            ]);
        }
    }
}

function poin_ambang_sanksi_minimum(PDO $pdo): int
{
    if (!table_exists($pdo, 'point_sanctions')) {
        return 10;
    }
    $v = $pdo->query('SELECT MIN(ambang_poin) FROM point_sanctions WHERE is_active = 1')->fetchColumn();
    if ($v === false || $v === null) {
        return 10;
    }
    $m = (int) $v;

    return $m > 0 ? $m : 10;
}

/**
 * Status tindak lanjut terbaru per santri untuk periode bulan/tahun (berdasarkan id terbesar).
 *
 * @return array<int, string> santri_id => BELUM|PROSES|SELESAI
 */
function poin_latest_followup_status_map(PDO $pdo, int $month, int $year): array
{
    if (!table_exists($pdo, 'point_followups')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT pf.santri_id, pf.status_tindak
        FROM point_followups pf
        INNER JOIN (
            SELECT santri_id, MAX(id) AS mid
            FROM point_followups
            WHERE periode_bulan = :m AND periode_tahun = :y
            GROUP BY santri_id
        ) t ON t.mid = pf.id
    ');
    $st->execute(['m' => $month, 'y' => $year]);
    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $r['santri_id']] = strtoupper((string) $r['status_tindak']);
    }

    return $map;
}

/**
 * Santri dengan total poin periode >= ambang sanksi aktif terendah,
 * yang belum ditangani: tidak ada tindak lanjut atau status terakhir bukan SELESAI.
 *
 * @return list<array{santri_id:int,nis:string,nama_santri:string,tingkatan:?string,total_poin:int|string}>
 */
function poin_santri_perlu_tindakan(PDO $pdo, int $month, int $year, ?string $tingkatanFilter = null): array
{
    if (!table_exists($pdo, 'point_ledger')) {
        return [];
    }
    if (!function_exists('rekap_poin_presensi_eligible_sql')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    $ambangMin = poin_ambang_sanksi_minimum($pdo);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $statusMap = poin_latest_followup_status_map($pdo, $month, $year);
    $eligiblePoinSql = rekap_poin_presensi_eligible_sql($pdo, 'pl');

    $stmt = $pdo->prepare('
        SELECT s.id AS santri_id, s.nis, s.nama_santri, s.tingkatan,
            COALESCE(SUM(pl.point_delta), 0) AS total_poin
        FROM santri s
        LEFT JOIN point_ledger pl ON pl.santri_id = s.id AND pl.tanggal BETWEEN :a AND :b
            ' . $eligiblePoinSql . '
        GROUP BY s.id, s.nis, s.nama_santri, s.tingkatan
        HAVING total_poin >= :ambang
        ORDER BY total_poin DESC, s.nama_santri ASC
    ');
    $stmt->execute(['a' => $start, 'b' => $end, 'ambang' => $ambangMin]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($tingkatanFilter !== null && $tingkatanFilter !== '') {
            if (strcasecmp((string) ($row['tingkatan'] ?? ''), $tingkatanFilter) !== 0) {
                continue;
            }
        }
        $sid = (int) $row['santri_id'];
        if (($statusMap[$sid] ?? '') === 'SELESAI') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

function sync_points_from_presensi(PDO $pdo, int $createdBy): int
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'point_ledger')) {
        return 0;
    }

    if (!function_exists('rekap_keaktifan_tanggal_mulai_scan')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    $mulaiScan = rekap_keaktifan_tanggal_mulai_scan($pdo);
    $mulaiSql = $mulaiScan !== '' ? ' AND p.tanggal_presensi >= ' . $pdo->quote($mulaiScan) : '';

    $pointAlpa = (int) app_setting($pdo, 'point_auto_alpa', '5');
    $pointTelat = (int) app_setting($pdo, 'point_auto_telat', '1');
    $insert = $pdo->prepare('
        INSERT INTO point_ledger (santri_id, tanggal, jenis_perubahan, point_delta, sumber_data, reference_presensi_id, keterangan, created_by)
        VALUES (:santri_id, :tanggal, "PLUS", :point_delta, :sumber_data, :reference_presensi_id, :keterangan, :created_by)
    ');

    $added = 0;
    $affectedSantri = [];
    if ($pointAlpa > 0) {
        require_once __DIR__ . '/presensi_jadwal.php';
        $alpaRows = $pdo->query('
            SELECT p.id, p.santri_id, p.tanggal_presensi, p.kegiatan_id, s.tingkatan
            FROM presensi p
            INNER JOIN santri s ON s.id = p.santri_id
            LEFT JOIN point_ledger pl ON pl.sumber_data = "PRESENSI_ALPA_AUTO" AND pl.reference_presensi_id = p.id
            WHERE p.status_presensi = "ALPA"
              AND pl.id IS NULL
              ' . $mulaiSql . '
        ')->fetchAll();
        foreach ($alpaRows as $row) {
            if (!presensi_row_eligible_for_hitung($pdo, $row)) {
                continue;
            }
            $insert->execute([
                'santri_id' => (int) $row['santri_id'],
                'tanggal' => $row['tanggal_presensi'],
                'point_delta' => $pointAlpa,
                'sumber_data' => 'PRESENSI_ALPA_AUTO',
                'reference_presensi_id' => (int) $row['id'],
                'keterangan' => 'Auto poin dari presensi ALPA.',
                'created_by' => $createdBy,
            ]);
            $added++;
            $affectedSantri[(int) $row['santri_id']] = true;
        }
    }

    if ($pointTelat > 0) {
        $telatRows = $pdo->query('
            SELECT p.id, p.santri_id, p.tanggal_presensi, p.catatan
            FROM presensi p
            LEFT JOIN point_ledger pl ON pl.sumber_data = "PRESENSI_TELAT_AUTO" AND pl.reference_presensi_id = p.id
            WHERE p.catatan LIKE "%Terlambat%"
              AND pl.id IS NULL
              ' . $mulaiSql . '
        ')->fetchAll();
        foreach ($telatRows as $row) {
            $insert->execute([
                'santri_id' => (int) $row['santri_id'],
                'tanggal' => $row['tanggal_presensi'],
                'point_delta' => $pointTelat,
                'sumber_data' => 'PRESENSI_TELAT_AUTO',
                'reference_presensi_id' => (int) $row['id'],
                'keterangan' => 'Auto poin dari presensi telat. ' . (string) ($row['catatan'] ?? ''),
                'created_by' => $createdBy,
            ]);
            $added++;
            $affectedSantri[(int) $row['santri_id']] = true;
        }
    }

    if ($affectedSantri !== []) {
        require_once __DIR__ . '/poin_wa.php';
        foreach (array_keys($affectedSantri) as $sid) {
            poin_wa_maybe_notify_santri($pdo, (int) $sid);
        }
    }

    return $added;
}

function santri_category(int $alphaCount, int $goodMax, int $mediumMax): string
{
    if ($alphaCount === 0) {
        return 'Bagus';
    }

    if ($alphaCount <= $goodMax) {
        return 'Baik';
    }

    if ($alphaCount <= $mediumMax) {
        return 'Sedang';
    }

    return 'Buruk';
}

function sanitize_db_column_name(string $name): string
{
    $column = strtolower(trim($name));
    $column = str_replace(['-', '/', '.'], '_', $column);
    $column = preg_replace('/\s+/', '_', $column) ?? $column;
    $column = preg_replace('/[^a-z0-9_]/', '', $column) ?? $column;
    return trim($column, '_');
}

function ensure_santri_identity_columns(PDO $pdo): void
{
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['pondok_santri_identity_v1'])) {
        return;
    }
    static $doneCli = false;
    if (session_status() !== PHP_SESSION_ACTIVE && $doneCli) {
        return;
    }
    if (!table_exists($pdo, 'santri')) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['pondok_santri_identity_v1'] = 1;
        } else {
            $doneCli = true;
        }

        return;
    }

    $definitions = [
        'nik' => 'VARCHAR(40) NULL',
        'jenis_kelamin' => 'VARCHAR(20) NULL',
        'tempat_lahir_kab' => 'VARCHAR(120) NULL',
        'tanggal_lahir' => 'VARCHAR(20) NULL',
        'bulan_lahir' => 'VARCHAR(20) NULL',
        'tahun_lahir' => 'VARCHAR(10) NULL',
        'jumlah_saudara' => 'VARCHAR(10) NULL',
        'anak_ke' => 'VARCHAR(10) NULL',
        'hobi' => 'VARCHAR(120) NULL',
        'cita_cita' => 'VARCHAR(120) NULL',
        'dusun' => 'VARCHAR(120) NULL',
        'rt_rw' => 'VARCHAR(30) NULL',
        'desa_kelurahan' => 'VARCHAR(120) NULL',
        'kecamatan' => 'VARCHAR(120) NULL',
        'kabupaten' => 'VARCHAR(120) NULL',
        'propinsi' => 'VARCHAR(120) NULL',
        'nama_ayah' => 'VARCHAR(120) NULL',
        'pekerjaan_ayah' => 'VARCHAR(120) NULL',
        'no_kontak_ayah' => 'VARCHAR(30) NULL',
        'nama_ibu' => 'VARCHAR(120) NULL',
        'pekerjaan_ibu' => 'VARCHAR(120) NULL',
        'no_kontak_ibu' => 'VARCHAR(30) NULL',
        'nama_kafil' => 'VARCHAR(120) NULL',
        'status_kafil' => 'VARCHAR(80) NULL',
        'pekerjaan_kafil' => 'VARCHAR(120) NULL',
        'no_kontak_kafil' => 'VARCHAR(30) NULL',
        'pendidikan_diniyyah_terakhir' => 'TEXT NULL',
        'pendidikan_formal_terakhir' => 'TEXT NULL',
        'kitab_yang_pernah_dikaji' => 'TEXT NULL',
        'keluhan_sakit' => 'TEXT NULL',
        'pengobatan' => 'TEXT NULL',
        'tanggal_masuk' => 'DATE NULL',
        'alasan_mondok' => 'TEXT NULL',
        'atas_keinginan' => 'TEXT NULL',
        'mengapa_nailul' => 'TEXT NULL',
        'kategori_kelas' => 'VARCHAR(80) NULL',
        'no_wa_wali' => 'VARCHAR(40) NULL',
        'wali_portal_pin_hash' => 'VARCHAR(255) NULL',
        'santri_portal_pin_hash' => 'VARCHAR(255) NULL',
        'wali_santri_id' => 'INT NULL',
        'status_santri' => 'VARCHAR(30) NOT NULL DEFAULT \'AKTIF\'',
        'alasan_keluar' => 'TEXT NULL',
        'tanggal_keluar' => 'DATE NULL',
        'nama_kamar' => 'VARCHAR(120) NULL',
        'no_ranjang' => 'VARCHAR(80) NULL',
        'asrama_ranjang_id' => 'INT NULL',
        'kelas_ruangan_id' => 'INT NULL',
        'foto_profil' => 'VARCHAR(255) NULL',
    ];

    foreach ($definitions as $column => $typeSql) {
        $pdo->exec('ALTER TABLE santri ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $typeSql);
    }

    if (column_exists($pdo, 'santri', 'no_ranjang')) {
        try {
            $pdo->exec('ALTER TABLE santri MODIFY COLUMN no_ranjang VARCHAR(80) NULL');
        } catch (Throwable $e) {
            // abaikan
        }
    }

    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        try {
            $pdo->exec('CREATE INDEX idx_santri_wali_santri ON santri (wali_santri_id)');
        } catch (PDOException $e) {
            $m = strtolower($e->getMessage());
            if (!str_contains($m, 'duplicate') && !str_contains($m, '1061') && !str_contains($m, 'exists')) {
                throw $e;
            }
        }
    }

    if (function_exists('santri_status_migrate_legacy')) {
        require_once __DIR__ . '/santri_status.php';
        santri_status_migrate_legacy($pdo);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pondok_santri_identity_v1'] = 1;
    } else {
        $doneCli = true;
    }
}

function kelas_keuangan_kode_preference_rank(string $kode): int
{
    $k = strtoupper(trim($kode));
    if ($k === '') {
        return 0;
    }
    if (preg_match('/^\d+$/', $k)) {
        return 1;
    }
    if (strlen($k) <= 2) {
        return 2;
    }

    return 10;
}

/** Kode sub-level kelas keuangan (WUSTO1, ULYA-2, …) — tidak digabung saat cleanup. */
function kelas_keuangan_is_sublevel_kode(string $kode): bool
{
    $k = strtoupper(trim($kode));
    if ($k === '') {
        return false;
    }
    $k = str_replace([' ', '_'], '-', $k);

    return (bool) preg_match('/^(MUAD|WUSTO|ULYA)-?[123]$/', $k);
}

/**
 * Normalisasi input "Wustho 1" / "WUSTO 2" ke kode master (WUSTO1, ULYA2, …).
 */
function kelas_keuangan_resolve_sublevel_pattern(string $raw): ?string
{
    $t = strtoupper(trim($raw));
    if ($t === '') {
        return null;
    }
    $compact = preg_replace('/[\s_-]+/', '', $t) ?? $t;
    if (preg_match('/^(MUAD|WUSTO|WUSTHO|WUST|ULYA|ULY)([123])$/', $compact, $m)) {
        $fam = $m[1];
        if (str_starts_with($fam, 'WUST')) {
            return 'WUSTO' . $m[2];
        }
        if (str_starts_with($fam, 'ULY')) {
            return 'ULYA' . $m[2];
        }
        if ($fam === 'MUAD') {
            return 'MUAD' . $m[2];
        }
    }
    $spaced = preg_replace('/\s+/', ' ', $t) ?? $t;
    if (preg_match('/^(MUADALAH|MUAD)\s+([123])$/', $spaced, $m)) {
        return 'MUAD' . $m[2];
    }
    if (preg_match('/^(WUSTHO|WUSTO|WUST)\s+([123])$/', $spaced, $m)) {
        return 'WUSTO' . $m[2];
    }
    if (preg_match('/^(ULYA|ULY)\s+([123])$/', $spaced, $m)) {
        return 'ULYA' . $m[2];
    }

    return null;
}

/** Pastikan Wustho 1–3 dan Ulya 1–3 ada di master kelas keuangan. */
function kelas_keuangan_ensure_sublevel_rows(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'kelas_keuangan')) {
        return;
    }
    $done = true;

    $subs = [
        ['WUSTO1', 'Wustho 1', 'wustho', 21],
        ['WUSTO2', 'Wustho 2', 'wustho', 22],
        ['WUSTO3', 'Wustho 3', 'wustho', 23],
        ['ULYA1', 'Ulya 1', 'ulya', 31],
        ['ULYA2', 'Ulya 2', 'ulya', 32],
        ['ULYA3', 'Ulya 3', 'ulya', 33],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES (:k, :n, :t, :u, 1)');
    foreach ($subs as $s) {
        $ins->execute(['k' => $s[0], 'n' => $s[1], 't' => $s[2], 'u' => $s[3]]);
    }
}

/** Gabungkan entri lama (kode 1/2/3) ke MUAD/WUSTO/ULYA dan hapus duplikat per tarif. */
function kelas_keuangan_cleanup_duplicate_rows(PDO $pdo): void
{
    static $cleaned = false;
    if ($cleaned) {
        return;
    }
    $cleaned = true;

    $canonicalSeed = [
        ['MUAD', 'Muadalah', 'muadalah', 1],
        ['WUSTO', 'Wustho', 'wustho', 2],
        ['ULYA', 'Ulya', 'ulya', 3],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES (:k, :n, :t, :u, 1)');
    foreach ($canonicalSeed as $s) {
        $ins->execute(['k' => $s[0], 'n' => $s[1], 't' => $s[2], 'u' => $s[3]]);
    }

    $legacyToCanonical = ['1' => 'MUAD', '2' => 'WUSTO', '3' => 'ULYA'];
    $hasSantriKat = column_exists($pdo, 'santri', 'kategori_kelas');
    foreach ($legacyToCanonical as $oldKode => $newKode) {
        $canon = $pdo->prepare('SELECT id FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k LIMIT 1');
        $canon->execute(['k' => $newKode]);
        $canonId = (int) ($canon->fetchColumn() ?: 0);
        if ($canonId <= 0) {
            continue;
        }
        if ($hasSantriKat) {
            $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')
                ->execute(['baru' => $newKode, 'lama' => strtoupper($oldKode)]);
        }
        $pdo->prepare('DELETE FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :lama AND id <> :id')
            ->execute(['lama' => strtoupper($oldKode), 'id' => $canonId]);
    }

    $rows = $pdo->query('SELECT id, kode, tarif_keuangan_tier FROM kelas_keuangan WHERE is_aktif = 1 ORDER BY urutan ASC, id ASC')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    /** @var array<string, array{id:int,kode:string,tarif_keuangan_tier:string}> $bestByTier */
    $bestByTier = [];
    foreach ($rows as $row) {
        $tier = strtolower(trim((string) ($row['tarif_keuangan_tier'] ?? 'wustho')));
        if (!in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
            $tier = 'wustho';
        }
        $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
        if (kelas_keuangan_is_sublevel_kode($kode)) {
            continue;
        }
        if (!isset($bestByTier[$tier])) {
            $bestByTier[$tier] = ['id' => (int) $row['id'], 'kode' => $kode, 'tarif_keuangan_tier' => $tier];
            continue;
        }
        $keep = $bestByTier[$tier];
        $keepRank = kelas_keuangan_kode_preference_rank($keep['kode']);
        $rowRank = kelas_keuangan_kode_preference_rank($kode);
        if ($rowRank > $keepRank) {
            if ($hasSantriKat && $keep['kode'] !== $kode) {
                $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')
                    ->execute(['baru' => $kode, 'lama' => $keep['kode']]);
            }
            $pdo->prepare('DELETE FROM kelas_keuangan WHERE id = :id')->execute(['id' => $keep['id']]);
            $bestByTier[$tier] = ['id' => (int) $row['id'], 'kode' => $kode, 'tarif_keuangan_tier' => $tier];
        } else {
            if ($hasSantriKat && $keep['kode'] !== $kode) {
                $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')
                    ->execute(['baru' => $keep['kode'], 'lama' => $kode]);
            }
            $pdo->prepare('DELETE FROM kelas_keuangan WHERE id = :id')->execute(['id' => (int) $row['id']]);
        }
    }
}

function ensure_kelas_keuangan_table(PDO $pdo): void
{
    static $doneCli = false;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['pondok_kelas_keuangan_v1'])) {
        kelas_keuangan_ensure_sublevel_rows($pdo);

        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE && $doneCli) {
        kelas_keuangan_ensure_sublevel_rows($pdo);

        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kelas_keuangan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(40) NOT NULL,
            nama_tampilan VARCHAR(120) NOT NULL,
            tarif_keuangan_tier VARCHAR(20) NOT NULL DEFAULT \'wustho\',
            urutan INT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_kelas_keuangan_kode (kode)
        )
    ');
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM kelas_keuangan')->fetchColumn();
    if ($cnt === 0) {
        $seed = [
            ['MUAD', 'Muadalah', 'muadalah', 1],
            ['WUSTO', 'Wustho', 'wustho', 2],
            ['ULYA', 'Ulya', 'ulya', 3],
        ];
        $ins = $pdo->prepare('INSERT INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES (:k, :n, :t, :u, 1)');
        foreach ($seed as $s) {
            $ins->execute(['k' => $s[0], 'n' => $s[1], 't' => $s[2], 'u' => $s[3]]);
        }
    }
    kelas_keuangan_ensure_sublevel_rows($pdo);
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['kelas_keuangan_cleanup_v2'])) {
        kelas_keuangan_cleanup_duplicate_rows($pdo);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['kelas_keuangan_cleanup_v2'] = 1;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pondok_kelas_keuangan_v1'] = 1;
    } else {
        $doneCli = true;
    }
}

/** @return list<array{id:int,kode:string,nama_tampilan:string,tarif_keuangan_tier:string,urutan:int,is_aktif:int}> */
function kelas_keuangan_all_rows(PDO $pdo): array
{
    ensure_kelas_keuangan_table($pdo);

    return $pdo->query('SELECT id, kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif FROM kelas_keuangan ORDER BY urutan ASC, nama_tampilan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array{id:int,kode:string,nama_tampilan:string,tarif_keuangan_tier:string,urutan:int}> */
function kelas_keuangan_list_active(PDO $pdo): array
{
    ensure_kelas_keuangan_table($pdo);

    return $pdo->query('SELECT id, kode, nama_tampilan, tarif_keuangan_tier, urutan FROM kelas_keuangan WHERE is_aktif = 1 ORDER BY urutan ASC, nama_tampilan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Peta kode upper => label — sekali per request (hindari N+1 di daftar santri). @return array<string, string> */
function kelas_keuangan_label_map(PDO $pdo): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    if (!table_exists($pdo, 'kelas_keuangan')) {
        return $map;
    }
    ensure_kelas_keuangan_table($pdo);
    foreach ($pdo->query('SELECT kode, nama_tampilan FROM kelas_keuangan') as $row) {
        $k = strtoupper(trim((string) ($row['kode'] ?? '')));
        if ($k === '') {
            continue;
        }
        $label = trim((string) ($row['nama_tampilan'] ?? ''));
        $map[$k] = $label !== '' ? $label : $k;
    }

    return $map;
}

/** Samakan input (kode atau nama tampilan persis) ke kode master, tanpa cek is_aktif. */
function kelas_keuangan_resolve_kode(PDO $pdo, string $raw): ?string
{
    ensure_kelas_keuangan_table($pdo);
    $t = trim($raw);
    if ($t === '') {
        return null;
    }
    $subKode = kelas_keuangan_resolve_sublevel_pattern($t);
    if ($subKode !== null) {
        $stSub = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :u LIMIT 1');
        $stSub->execute(['u' => $subKode]);
        $rowSub = $stSub->fetch(PDO::FETCH_ASSOC);
        if (is_array($rowSub) && isset($rowSub['kode'])) {
            return strtoupper(trim((string) $rowSub['kode']));
        }
    }
    $u = strtoupper($t);
    $st = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :u LIMIT 1');
    $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && isset($row['kode'])) {
        return strtoupper(trim((string) $row['kode']));
    }
    $st2 = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(nama_tampilan)) = :u LIMIT 1');
    $st2->execute(['u' => $u]);
    $row2 = $st2->fetch(PDO::FETCH_ASSOC);
    if (is_array($row2) && isset($row2['kode'])) {
        return strtoupper(trim((string) $row2['kode']));
    }

    return null;
}

function santri_normalize_kategori_kelas(PDO $pdo, string $raw): string
{
    ensure_kelas_keuangan_table($pdo);
    $resolved = kelas_keuangan_resolve_kode($pdo, $raw);
    if ($resolved === null) {
        return '';
    }
    $st = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k AND is_aktif = 1 LIMIT 1');
    $st->execute(['k' => $resolved]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && isset($row['kode'])) {
        return strtoupper(trim((string) $row['kode']));
    }

    return '';
}

function kelas_keuangan_label_for_kode(PDO $pdo, string $kode): string
{
    $k = strtoupper(trim($kode));
    if ($k === '') {
        return '';
    }
    $map = kelas_keuangan_label_map($pdo);
    if (isset($map[$k])) {
        return $map[$k];
    }

    return $k;
}

function keuangan_tier_key_from_kelas_heuristic(string $kelasKategori): string
{
    $t = strtolower(trim($kelasKategori));
    if ($t === '') {
        return 'wustho';
    }
    if (str_contains($t, 'muadalah') || str_contains($t, 'muad') || str_contains($t, 'ula') || str_contains($t, 'mts') || str_contains($t, 'smp') || $t === 'm') {
        return 'muadalah';
    }
    if (str_contains($t, 'wustho') || str_contains($t, 'wusto') || $t === 'w') {
        return 'wustho';
    }
    if (str_contains($t, 'ulya') || str_contains($t, 'uly') || str_contains($t, 'aliyah') || str_contains($t, 'ma') || str_contains($t, 'sma') || str_contains($t, 'smk') || $t === 'u') {
        return 'ulya';
    }
    return 'wustho';
}

function keuangan_tier_key_from_kelas(string $kelasKategori, ?PDO $pdo = null): string
{
    $rawIn = trim($kelasKategori);
    if ($pdo !== null) {
        $resolved = kelas_keuangan_resolve_kode($pdo, $rawIn);
        if ($resolved !== null) {
            $rawIn = $resolved;
        }
    }
    $normKey = strtoupper($rawIn);
    if ($normKey === '') {
        return keuangan_tier_key_from_kelas_heuristic('');
    }
    if ($pdo !== null) {
        ensure_kelas_keuangan_table($pdo);
        static $cache = [];
        if (!array_key_exists($normKey, $cache)) {
            $st = $pdo->prepare('SELECT tarif_keuangan_tier FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k LIMIT 1');
            $st->execute(['k' => $normKey]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && isset($row['tarif_keuangan_tier'])) {
                $t = strtolower(trim((string) $row['tarif_keuangan_tier']));
                if (in_array($t, ['muadalah', 'wustho', 'ulya'], true)) {
                    $cache[$normKey] = $t;
                }
            }
            if (!array_key_exists($normKey, $cache)) {
                $cache[$normKey] = keuangan_tier_key_from_kelas_heuristic($kelasKategori);
            }
        }
        return $cache[$normKey];
    }
    return keuangan_tier_key_from_kelas_heuristic($kelasKategori);
}

/** Kolom grid biaya di halaman keuangan: 2=muadalah, 3=wustho, 4=ulya */
function keuangan_fee_grid_column_for_tier(string $tierKey): int
{
    return match ($tierKey) {
        'muadalah' => 2,
        'ulya' => 4,
        default => 3,
    };
}

function keuangan_monthly_bill_components(PDO $pdo, string $kelasKategori): array
{
    $defs = [
        ['slug' => 'syahriyah', 'nama' => 'Syahriyah', 'default' => ['muadalah' => 200000, 'wustho' => 210000, 'ulya' => 215000]],
        ['slug' => 'makan', 'nama' => 'Makan', 'default' => ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000]],
        ['slug' => 'saku', 'nama' => 'Saku', 'default' => ['muadalah' => 300000, 'wustho' => 300000, 'ulya' => 300000]],
    ];
    $tier = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
    $out = [];
    foreach ($defs as $def) {
        $fallback = (int) ($def['default'][$tier] ?? 0);
        $nominal = (int) app_setting($pdo, 'keuangan_fee_' . $def['slug'] . '_' . $tier, (string) $fallback);
        $out[] = [
            'slug' => $def['slug'],
            'nama' => $def['nama'],
            'nominal' => max(0, $nominal),
        ];
    }
    return $out;
}

/** Nominal Syahriyah bulanan untuk santri (sesuai tier tarif di pengaturan keuangan). */
function syahriyah_expected_nominal_for_santri(PDO $pdo, string $kelasKategori): int
{
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        if (($c['slug'] ?? '') === 'syahriyah') {
            return max(0, (int) ($c['nominal'] ?? 0));
        }
    }

    return 0;
}

/** Jumlah pos Syahriyah yang sudah tercatat di pembayaran bulanan untuk bulan & tahun ajaran tertentu. */
function syahriyah_paid_nominal_for_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): int
{
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12) {
        return 0;
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }

    return tagihan_paid_nominal_for_pos_month($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, 'syahriyah');
}

/** Pos bulanan wajib (status lunas/belum dihitung dari ini saja). */
function keuangan_tagihan_wajib_slugs(): array
{
    return ['syahriyah'];
}

/** Pos bulanan opsional (makan & saku — tidak mempengaruhi status wajib). */
function keuangan_tagihan_opsional_bulanan_slugs(): array
{
    return ['makan', 'saku'];
}

/** Semua pos tagihan bulanan untuk tampilan / pembayaran. */
function keuangan_tagihan_bulanan_slugs(): array
{
    return array_merge(keuangan_tagihan_wajib_slugs(), keuangan_tagihan_opsional_bulanan_slugs());
}

/** Pos yang masuk pengingat WA (syahriyah + makan; saku tidak ikut). */
function keuangan_tagihan_wa_slugs(): array
{
    return ['syahriyah', 'makan'];
}

/**
 * Komponen nama pos untuk label WA (syahriyah + makan).
 *
 * @return list<array{slug:string,nama:string,nominal:int}>
 */
function keuangan_tagihan_wa_components(PDO $pdo, string $kelasKategori): array
{
    $slugs = keuangan_tagihan_wa_slugs();
    $out = [];
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        $slug = (string) ($c['slug'] ?? '');
        if (!in_array($slug, $slugs, true)) {
            continue;
        }
        if ($slug === 'makan') {
            if (!function_exists('keuangan_makan_pos_nama')) {
                require_once __DIR__ . '/keuangan_kelas_makan.php';
            }
            $namaMakan = keuangan_makan_pos_nama($pdo);
            if ($namaMakan !== '') {
                $c['nama'] = $namaMakan;
            }
        }
        $out[] = $c;
    }

    return $out;
}

/**
 * @return list<array{slug:string,nama:string,nominal:int}>
 */
function keuangan_tagihan_wajib_components(PDO $pdo, string $kelasKategori): array
{
    $slugs = keuangan_tagihan_wajib_slugs();
    $out = [];
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        if (in_array((string) ($c['slug'] ?? ''), $slugs, true)) {
            $out[] = $c;
        }
    }

    return $out;
}

function tagihan_expected_nominal_for_pos(PDO $pdo, string $kelasKategori, string $posSlug): int
{
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        if (($c['slug'] ?? '') === $posSlug) {
            return max(0, (int) ($c['nominal'] ?? 0));
        }
    }

    return 0;
}

function tagihan_paid_nominal_for_pos_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai, string $posSlug): int
{
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12 || $posSlug === '') {
        return 0;
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }
    if (!function_exists('pondok_sql_match_bulan_tagihan')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }
    $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanTagihan, 'p');

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid
          AND p.jenis_periode = \'BULANAN\'
          AND ' . $bulanMatch['sql'] . '
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND d.pos_slug = :pos_slug
    ');
    $stmt->execute(array_merge([
        'sid' => $santriId,
        'tm' => $tahunAjaranMulai,
        'ts' => $tahunAjaranSelesai,
        'pos_slug' => $posSlug,
    ], $bulanMatch['params']));

    return (int) ((float) ($stmt->fetchColumn() ?: 0));
}

function tagihan_wajib_total_expected(PDO $pdo, string $kelasKategori): int
{
    $total = 0;
    foreach (keuangan_tagihan_wajib_components($pdo, $kelasKategori) as $c) {
        $total += (int) ($c['nominal'] ?? 0);
    }

    return $total;
}

function tagihan_wajib_paid_for_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): int
{
    $paid = 0;
    foreach (keuangan_tagihan_wajib_slugs() as $slug) {
        $paid += tagihan_paid_nominal_for_pos_month($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $slug);
    }

    return $paid;
}

/**
 * @return array{
 *   expected_total:int,
 *   paid_total:int,
 *   sisa_total:int,
 *   status:string,
 *   statusClass:string,
 *   per_pos:array<string,array{expected:int,paid:int,sisa:int,status:string,statusClass:string}>
 * }
 */
function tagihan_wajib_status_for_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai, string $kelasKategori): array
{
    if (!function_exists('tagihan_bulan_dibebankan')) {
        require_once __DIR__ . '/tagihan_santri_masuk.php';
    }
    if (!tagihan_bulan_dibebankan($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai)) {
        return tagihan_wajib_status_kosong();
    }

    $perPos = [];
    $expectedTotal = 0;
    $paidTotal = 0;
    $sisaTotal = 0;
    $allLunas = true;
    $anyPaid = false;
    $anyExpected = false;

    if (!function_exists('keuangan_syahriyah_expected_dengan_potongan')) {
        require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
    }

    foreach (keuangan_tagihan_wajib_components($pdo, $kelasKategori) as $c) {
        $slug = (string) ($c['slug'] ?? '');
        $expected = max(0, (int) ($c['nominal'] ?? 0));
        $expectedDasar = $expected;
        $persenPotongan = 0.0;
        $keteranganPotongan = '';
        $potonganNominal = 0;
        $potonganDijeda = false;
        $pkppsTambahan = 0;
        $expectedSetelahPotongan = $expected;
        $tierKey = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
        if ($slug === 'syahriyah' && $santriId > 0) {
            $syPot = keuangan_syahriyah_expected_dengan_potongan(
                $pdo,
                $santriId,
                $kelasKategori,
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai
            );
            $expectedDasar = (int) ($syPot['expected_dasar'] ?? $expected);
            $expected = (int) ($syPot['expected'] ?? $expected);
            $persenPotongan = (float) ($syPot['persen'] ?? 0);
            $keteranganPotongan = (string) ($syPot['keterangan'] ?? '');
            $potonganNominal = (int) ($syPot['potongan_nominal'] ?? 0);
            $potonganDijeda = !empty($syPot['potongan_dijeda']);
            $pkppsTambahan = (int) ($syPot['pkpps_tambahan'] ?? 0);
            $expectedSetelahPotongan = max(0, $expected - $pkppsTambahan);
        }
        $paid = $santriId > 0
            ? tagihan_paid_nominal_for_pos_month($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $slug)
            : 0;
        $sisa = max(0, $expected - $paid);
        if ($expected > 0) {
            $anyExpected = true;
        }
        if ($paid > 0) {
            $anyPaid = true;
        }
        if ($expected > 0 && $paid < $expected) {
            $allLunas = false;
        }
        if ($expected <= 0) {
            $st = '—';
            $stClass = 'secondary';
        } elseif ($paid >= $expected) {
            $st = 'Lunas';
            $stClass = 'success';
        } elseif ($paid <= 0) {
            $st = 'Belum';
            $stClass = 'danger';
        } else {
            $st = 'Sebagian';
            $stClass = 'warning';
        }
        $posRow = [
            'expected' => $expected,
            'expected_dasar' => $expectedDasar,
            'persen_potongan' => $persenPotongan,
            'keterangan_potongan' => $keteranganPotongan,
            'potongan_nominal' => $potonganNominal,
            'potongan_dijeda' => $potonganDijeda,
            'paid' => $paid,
            'sisa' => $sisa,
            'status' => $st,
            'statusClass' => $stClass,
        ];
        if ($slug === 'syahriyah') {
            $posRow['expected_setelah_potongan'] = $expectedSetelahPotongan;
            $posRow['pkpps_tambahan'] = $pkppsTambahan;
            $posRow['kelas_syahriyah_tambahan'] = 0;
            $posRow['tier_key'] = $tierKey;
        }
        $perPos[$slug] = $posRow;
        $expectedTotal += $expected;
        $paidTotal += $paid;
        $sisaTotal += $sisa;
    }

    if (!$anyExpected) {
        $status = '—';
        $statusClass = 'secondary';
    } elseif ($allLunas && $expectedTotal > 0) {
        $status = 'Lunas';
        $statusClass = 'success';
    } elseif (!$anyPaid) {
        $status = 'Belum';
        $statusClass = 'danger';
    } else {
        $status = 'Sebagian';
        $statusClass = 'warning';
    }

    return [
        'expected_total' => $expectedTotal,
        'paid_total' => $paidTotal,
        'sisa_total' => $sisaTotal,
        'status' => $status,
        'statusClass' => $statusClass,
        'per_pos' => $perPos,
    ];
}

/** Kirim WA tagihan manual (tanpa cek jadwal otomatis). */
function wa_tagihan_kirim_manual(PDO $pdo, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai, ?array $santriIdsFilter = null): array
{
    if (!function_exists('tagihan_bulanan_page_context')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    if (!function_exists('keuangan_tahun_ajaran_aktif')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    ensure_santri_identity_columns($pdo);
    ensure_wali_santri_table($pdo);
    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }
    if (!function_exists('wa_otomatis_santri_wali_phone')) {
        require_once __DIR__ . '/wa_otomatis.php';
    }

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
    $sql = 'SELECT ' . $waCols . ' FROM santri WHERE 1=1 ' . $activeExpr;
    $params = [];
    if (is_array($santriIdsFilter) && $santriIdsFilter !== []) {
        $ids = array_values(array_filter(array_map('intval', $santriIdsFilter)));
        if ($ids !== []) {
            $sql .= ' AND id IN (' . implode(',', $ids) . ')';
        }
    }
    $sql .= ' ORDER BY id ASC LIMIT 500';
    $santriRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($santriRows === []) {
        return ['ok' => false, 'sent' => 0, 'skipped' => 0, 'message' => 'Tidak ada santri dengan nomor WA wali.'];
    }

    if (!function_exists('wa_tagihan_santri_status')) {
        require_once __DIR__ . '/wa_tagihan.php';
    }
    $kumulatif = wa_tagihan_kumulatif_enabled($pdo);
    $tagihanCtx = $kumulatif ? null : tagihan_bulanan_page_context($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $paidMap = $tagihanCtx['paid_map'] ?? null;
    $syCtx = $tagihanCtx['sy_ctx'] ?? null;
    $sent = 0;
    $skipped = 0;
    foreach ($santriRows as $row) {
        $santriId = (int) ($row['id'] ?? 0);
        if ($santriId <= 0) {
            continue;
        }
        $kelas = trim((string) ($row['kategori_kelas'] ?? ''));
        $components = keuangan_tagihan_wa_components($pdo, $kelas);
        if ($components === []) {
            $skipped++;
            continue;
        }
        $st = wa_tagihan_santri_status($pdo, $santriId, $kelas, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $paidMap, $syCtx);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        if ($sisa <= 0) {
            $skipped++;
            continue;
        }
        $nama = trim((string) ($row['nama_santri'] ?? 'Santri'));
        $message = wa_tagihan_format_pesan_santri($pdo, $nama, $components, $st);
        $phone = wa_otomatis_santri_wali_phone($pdo, $row);
        if ($phone === '') {
            $skipped++;
            continue;
        }
        if (send_wa_message($pdo, $phone, $message, [
            'kind' => 'tagihan',
            'dedup_key' => 'tagihan:manual:' . $bulanTagihan . ':' . $tahunAjaranMulai . '-' . $tahunAjaranSelesai . ':santri:' . $santriId,
        ])) {
            $sent++;
        }
    }

    return [
        'ok' => $sent > 0,
        'sent' => $sent,
        'skipped' => $skipped,
        'message' => $sent > 0
            ? $sent . ' WA tagihan terkirim (' . $skipped . ' dilewati: lunas/tanpa tagihan).'
            : 'Tidak ada WA terkirim. Periksa tagihan belum lunas dan nomor wali.',
    ];
}

/**
 * Preview pesan tagihan satu santri (untuk tautan WA per baris).
 *
 * @return array{ok:bool,message:string,phone:string,wa_url:?string,nama:string,sisa:int}|null
 */
function wa_tagihan_preview_santri(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): ?array
{
    if ($santriId <= 0) {
        return null;
    }
    if (!function_exists('tagihan_bulanan_page_context')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    ensure_santri_identity_columns($pdo);
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $classExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : "''");
    $waCols = [];
    if (column_exists($pdo, 'santri', 'no_wa_wali')) {
        $waCols[] = 'no_wa_wali';
    }
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $waCols[] = 'wali_santri_id';
    }
    if (column_exists($pdo, 'santri', 'no_kontak_ayah')) {
        $waCols[] = 'no_kontak_ayah';
    }
    if (column_exists($pdo, 'santri', 'no_kontak_ibu')) {
        $waCols[] = 'no_kontak_ibu';
    }
    $waSelect = $waCols !== [] ? ', ' . implode(', ', $waCols) : '';
    $st = $pdo->prepare("SELECT id, nis, {$nameExpr} AS nama_santri, {$classExpr} AS kategori_kelas{$waSelect} FROM santri WHERE id = :id LIMIT 1");
    $st->execute(['id' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.', 'error' => 'Santri tidak ditemukan.', 'phone' => '', 'wa_url' => null, 'nama' => '', 'sisa' => 0];
    }
    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }
    $phone = santri_resolve_no_wa_wali($pdo, $row);
    if ($phone === '') {
        return ['ok' => false, 'message' => 'Nomor WA wali kosong.', 'error' => 'Nomor WA wali kosong.', 'phone' => '', 'wa_url' => null, 'nama' => (string) ($row['nama_santri'] ?? ''), 'sisa' => 0];
    }
    if (!function_exists('wa_tagihan_santri_status')) {
        require_once __DIR__ . '/wa_tagihan.php';
    }
    $kumulatif = wa_tagihan_kumulatif_enabled($pdo);
    $tagihanCtx = $kumulatif ? null : tagihan_bulanan_page_context($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $tingkatanMap = $tagihanCtx['tingkatan_map'] ?? null;
    if (!is_array($tingkatanMap) && function_exists('santri_tingkatan_map_for_ta')) {
        require_once __DIR__ . '/santri_ta.php';
        $tingkatanMap = santri_tingkatan_map_for_ta($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    }
    if (!function_exists('keuangan_santri_kelas_tagihan')) {
        require_once __DIR__ . '/santri_ta.php';
    }
    $kelas = keuangan_santri_kelas_tagihan(
        $pdo,
        $santriId,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $row,
        is_array($tingkatanMap) ? $tingkatanMap : null
    );
    $stTag = wa_tagihan_santri_status(
        $pdo,
        $santriId,
        $kelas,
        $bulanTagihan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $tagihanCtx['paid_map'] ?? null,
        $tagihanCtx['sy_ctx'] ?? null
    );
    $sisa = (int) ($stTag['sisa_total'] ?? 0);
    if ($sisa <= 0) {
        return ['ok' => false, 'message' => 'Tagihan syahriyah dan makan sudah lunas.', 'error' => 'Tagihan syahriyah dan makan sudah lunas.', 'phone' => $phone, 'wa_url' => null, 'nama' => (string) ($row['nama_santri'] ?? ''), 'sisa' => 0];
    }
    $components = keuangan_tagihan_wa_components($pdo, $kelas);
    $nama = trim((string) ($row['nama_santri'] ?? 'Santri'));
    $pesan = wa_tagihan_format_pesan_santri($pdo, $nama, $components, $stTag);

    return [
        'ok' => true,
        'message' => $pesan,
        'pesan' => $pesan,
        'phone' => $phone,
        'wa_url' => wa_me_chat_url($phone, $pesan),
        'nama' => $nama,
        'sisa' => $sisa,
        'error' => '',
    ];
}

function trigger_auto_wa_tagihan_wali(PDO $pdo): void
{
    require_once __DIR__ . '/wa_otomatis.php';
    if (!wa_otomatis_should_run($pdo, 'tagihan')) {
        return;
    }
    require_once __DIR__ . '/wa_tagihan.php';
    if (function_exists('wa_tagihan_jalankan_kirim')) {
        wa_tagihan_jalankan_kirim($pdo, false);
    }
}

/** @return list<string> */
function wa_tagihan_parse_custom_masehi_dates(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $out = [];
    foreach ($parts as $item) {
        $d = trim((string) $item);
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $out[$d] = $d;
    }
    return array_values($out);
}

function sync_daily_presence_for_tingkatan(PDO $pdo, string $tanggal, string $tingkatan, ?int $kegiatanId, int $createdBy, bool $tandaiAlpa = true): void
{
    static $retryDepth = 0;
    try {
        sync_daily_presence_for_tingkatan_impl($pdo, $tanggal, $tingkatan, $kegiatanId, $createdBy, $tandaiAlpa);
    } catch (PDOException $e) {
        if ($retryDepth > 0 || !pondok_pdo_is_gone_away($e)) {
            throw $e;
        }
        $retryDepth++;
        try {
            sync_daily_presence_for_tingkatan_impl(pondok_pdo_reconnect(), $tanggal, $tingkatan, $kegiatanId, $createdBy, $tandaiAlpa);
        } finally {
            $retryDepth--;
        }
    }
}

function sync_daily_presence_for_tingkatan_impl(PDO $pdo, string $tanggal, string $tingkatan, ?int $kegiatanId, int $createdBy, bool $tandaiAlpa = true): void
{
    if ($tingkatan === '' || !table_exists($pdo, 'presensi') || !table_exists($pdo, 'perizinan')) {
        return;
    }

    require_once __DIR__ . '/presensi_jadwal.php';
    $kegiatanIdInt = $kegiatanId !== null ? (int) $kegiatanId : 0;
    if ($kegiatanIdInt <= 0) {
        return;
    }
    if (!presensi_tingkatan_terjadwal($pdo, $tingkatan, $kegiatanIdInt, $tanggal)) {
        return;
    }

    require_once __DIR__ . '/santri_operasional.php';
    $santriStmt = $pdo->prepare('SELECT id FROM santri WHERE tingkatan = :tingkatan AND ' . santri_sql_aktif_only('santri'));
    $santriStmt->execute(['tingkatan' => $tingkatan]);
    $santriIds = array_map('intval', $santriStmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$santriIds) {
        return;
    }

    require_once __DIR__ . '/akademik.php';
    $hijri = akademik_hijri_ym_untuk_masehi($pdo, $tanggal);
    $jam = date('H:i:s');
    require_once __DIR__ . '/perizinan_aktif.php';
    $izinMap = perizinan_map_izin_berlaku_tanggal($pdo, $tanggal);

    if (!function_exists('santri_izin_tetap_berlaku')) {
        require_once __DIR__ . '/santri_izin_tetap.php';
    }
    $jamMulaiKeg = null;
    $jamSelesaiKeg = null;
    $jadwalKegiatanId = null;
    if ($kegiatanIdInt > 0 && table_exists($pdo, 'jadwal_kegiatan')) {
        $hariKe = (int) date('N', strtotime($tanggal));
        $jadwalStmt = $pdo->prepare('
            SELECT id, jam_mulai, jam_selesai FROM jadwal_kegiatan
            WHERE kegiatan_id = :kid
              AND (hari_ke = 0 OR hari_ke = :hari)
              AND (tingkatan = :tingkatan OR tingkatan = "Semua Tingkatan")
            ORDER BY jam_mulai ASC LIMIT 1
        ');
        $jadwalStmt->execute(['kid' => $kegiatanIdInt, 'hari' => $hariKe, 'tingkatan' => $tingkatan]);
        $jadwalRow = $jadwalStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($jadwalRow)) {
            $jadwalKegiatanId = (int) ($jadwalRow['id'] ?? 0) ?: null;
            $jamMulaiKeg = (string) ($jadwalRow['jam_mulai'] ?? null);
            $jamSelesaiKeg = (string) ($jadwalRow['jam_selesai'] ?? null);
        }
    }
    if ($jadwalKegiatanId === null) {
        return;
    }
    require_once __DIR__ . '/presensi_admin.php';
    ensure_presensi_jadwal_column($pdo);

    $existingMap = [];
    if ($santriIds !== []) {
        $placeholders = implode(',', array_fill(0, count($santriIds), '?'));
        $bulkExisting = $pdo->prepare('
            SELECT id, santri_id, status_presensi
            FROM presensi
            WHERE tanggal_presensi = ?
              AND kegiatan_id = ?
              AND santri_id IN (' . $placeholders . ')
            ORDER BY id DESC
        ');
        $bulkExisting->execute(array_merge([$tanggal, $kegiatanIdInt], $santriIds));
        foreach ($bulkExisting->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $sid = (int) ($row['santri_id'] ?? 0);
            if ($sid > 0 && !isset($existingMap[$sid])) {
                $existingMap[$sid] = $row;
            }
        }
    }

    $izinTetapMap = santri_izin_tetap_map_for_santri_ids($pdo, $santriIds, $tanggal, $jamMulaiKeg, $jamSelesaiKeg, $kegiatanIdInt);

    $insertStmt = null;
    $updateStmt = null;
    $writePresence = static function (PDO $activePdo, array $ids) use (
        &$insertStmt,
        &$updateStmt,
        $kegiatanIdInt,
        $jadwalKegiatanId,
        $tanggal,
        $jam,
        $hijri,
        $createdBy,
        $tandaiAlpa,
        $izinMap,
        $izinTetapMap,
        $existingMap
    ): void {
        $insertStmt = $activePdo->prepare('
            INSERT INTO presensi (santri_id, kegiatan_id, jadwal_kegiatan_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by)
            VALUES (:santri_id, :kegiatan_id, :jadwal_kegiatan_id, :tanggal_presensi, :jam_presensi, :status_presensi, :kalender_hijriyah, :created_by)
        ');
        $updateStmt = $activePdo->prepare('
            UPDATE presensi
            SET status_presensi = :status_presensi, jam_presensi = :jam_presensi, kalender_hijriyah = :kalender_hijriyah, created_by = :created_by
            WHERE id = :id
        ');

        $n = 0;
        foreach ($ids as $santriId) {
            $n++;
            if ($n % 40 === 0) {
                $activePdo = pondok_pdo_ping($activePdo);
            }

            $desiredStatus = 'ALPA';
            if (isset($izinMap[$santriId])) {
                $desiredStatus = strtoupper((string) $izinMap[$santriId]) === 'SAKIT' ? 'SAKIT' : 'IZIN';
            } elseif (isset($izinTetapMap[$santriId])) {
                $desiredStatus = 'IZIN';
            }
            if (!$tandaiAlpa && $desiredStatus === 'ALPA') {
                continue;
            }

            if ($desiredStatus === 'ALPA') {
                if (!function_exists('presensi_alpa_bebas_is_set')) {
                    require_once __DIR__ . '/presensi_tanpa_scan_koreksi.php';
                }
                if (presensi_alpa_bebas_is_set($activePdo, $santriId, $kegiatanIdInt, $tanggal)) {
                    continue;
                }
            }

            $existing = $existingMap[$santriId] ?? null;
            if ($existing && strtoupper((string) $existing['status_presensi']) === 'HADIR') {
                continue;
            }

            try {
                if (!$existing) {
                    $insertStmt->execute([
                        'santri_id' => $santriId,
                        'kegiatan_id' => $kegiatanIdInt,
                        'jadwal_kegiatan_id' => $jadwalKegiatanId,
                        'tanggal_presensi' => $tanggal,
                        'jam_presensi' => $jam,
                        'status_presensi' => $desiredStatus,
                        'kalender_hijriyah' => $hijri,
                        'created_by' => $createdBy,
                    ]);
                    continue;
                }

                if (strtoupper((string) $existing['status_presensi']) !== $desiredStatus) {
                    $updateStmt->execute([
                        'id' => (int) $existing['id'],
                        'status_presensi' => $desiredStatus,
                        'jam_presensi' => $jam,
                        'kalender_hijriyah' => $hijri,
                        'created_by' => $createdBy,
                    ]);
                }
            } catch (PDOException $e) {
                if (!pondok_pdo_is_gone_away($e)) {
                    throw $e;
                }
                $activePdo = pondok_pdo_reconnect();
                $insertStmt = $activePdo->prepare('
                    INSERT INTO presensi (santri_id, kegiatan_id, jadwal_kegiatan_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by)
                    VALUES (:santri_id, :kegiatan_id, :jadwal_kegiatan_id, :tanggal_presensi, :jam_presensi, :status_presensi, :kalender_hijriyah, :created_by)
                ');
                $updateStmt = $activePdo->prepare('
                    UPDATE presensi
                    SET status_presensi = :status_presensi, jam_presensi = :jam_presensi, kalender_hijriyah = :kalender_hijriyah, created_by = :created_by
                    WHERE id = :id
                ');
                if (!$existing) {
                    $insertStmt->execute([
                        'santri_id' => $santriId,
                        'kegiatan_id' => $kegiatanIdInt,
                        'jadwal_kegiatan_id' => $jadwalKegiatanId,
                        'tanggal_presensi' => $tanggal,
                        'jam_presensi' => $jam,
                        'status_presensi' => $desiredStatus,
                        'kalender_hijriyah' => $hijri,
                        'created_by' => $createdBy,
                    ]);
                } elseif (strtoupper((string) $existing['status_presensi']) !== $desiredStatus) {
                    $updateStmt->execute([
                        'id' => (int) $existing['id'],
                        'status_presensi' => $desiredStatus,
                        'jam_presensi' => $jam,
                        'kalender_hijriyah' => $hijri,
                        'created_by' => $createdBy,
                    ]);
                }
            }
        }
    };

    pondok_pdo_run_with_retry(static function (PDO $activePdo) use ($writePresence, $santriIds): void {
        $writePresence($activePdo, $santriIds);
    }, pondok_pdo_ping($pdo));
}

function sync_presence_for_active_schedules(PDO $pdo, string $tanggal, string $jam, int $createdBy): int
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return 0;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $stmt = $pdo->prepare('
        SELECT DISTINCT j.kegiatan_id, j.tingkatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
          AND k.is_active = 1
          AND UPPER(COALESCE(k.kategori_kegiatan, "TAALIM")) <> "EXTRA"
    ');
    $stmt->execute([
        'hari_ke' => $hariKe,
        'jam_now' => $jam,
    ]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $synced = 0;
    foreach ($rows as $row) {
        $tingkatan = trim((string) ($row['tingkatan'] ?? ''));
        $kegiatanId = isset($row['kegiatan_id']) ? (int) $row['kegiatan_id'] : null;
        if ($tingkatan === '' || strtolower($tingkatan) === 'semua tingkatan') {
            if (!table_exists($pdo, 'tingkatan')) {
                continue;
            }
            $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tingkatanList as $tg) {
                sync_daily_presence_for_tingkatan($pdo, $tanggal, (string) $tg, $kegiatanId, $createdBy, false);
                $synced++;
            }
            continue;
        }

        sync_daily_presence_for_tingkatan($pdo, $tanggal, $tingkatan, $kegiatanId, $createdBy, false);
        $synced++;
    }

    return $synced;
}

/**
 * Setelah jam selesai kegiatan: santri belum scan → status ALPA (untuk rekap & notifikasi).
 */
function sync_presence_for_ended_schedules(PDO $pdo, string $tanggal, string $jam, int $createdBy): int
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return 0;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $stmt = $pdo->prepare('
        SELECT DISTINCT j.kegiatan_id, j.tingkatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now > j.jam_selesai
          AND k.is_active = 1
          AND UPPER(COALESCE(k.kategori_kegiatan, "TAALIM")) <> "EXTRA"
    ');
    $stmt->execute([
        'hari_ke' => $hariKe,
        'jam_now' => $jam,
    ]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $synced = 0;
    foreach ($rows as $row) {
        $tingkatan = trim((string) ($row['tingkatan'] ?? ''));
        $kegiatanId = isset($row['kegiatan_id']) ? (int) $row['kegiatan_id'] : null;
        if ($tingkatan === '' || strtolower($tingkatan) === 'semua tingkatan') {
            if (!table_exists($pdo, 'tingkatan')) {
                continue;
            }
            $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tingkatanList as $tg) {
                sync_daily_presence_for_tingkatan($pdo, $tanggal, (string) $tg, $kegiatanId, $createdBy, true);
                $synced++;
            }
            continue;
        }

        sync_daily_presence_for_tingkatan($pdo, $tanggal, $tingkatan, $kegiatanId, $createdBy, true);
        $synced++;
    }

    return $synced;
}

/**
 * Label jenis izin untuk tampilan (sama dengan opsi dropdown di perizinan).
 */
function jenis_izin_label(string $jenis): string
{
    return match (strtoupper(trim($jenis))) {
        'SAKIT' => 'Sakit',
        'KELUAR' => 'Keluar',
        'TUGAS' => 'Tugas',
        'PULANG' => 'Tugas',
        'SYARI' => 'Izin',
        default => $jenis !== '' ? $jenis : 'Keluar',
    };
}

function wa_salam_pembuka(): string
{
    return "Assalamu'alaikum warahmatullahi wabarakatuh.";
}

/**
 * Daftar pos tagihan yang masih kurang (untuk teks WA/notifikasi).
 *
 * @param list<array{slug:string,nama:string,nominal:int}> $components
 * @param array<string, array{sisa?:int}> $perPos
 */
function wa_tagihan_label_kekurangan(array $components, array $perPos): string
{
    $parts = [];
    foreach ($components as $c) {
        $slug = (string) ($c['slug'] ?? '');
        $sisa = (int) (($perPos[$slug]['sisa'] ?? 0));
        if ($sisa <= 0) {
            continue;
        }
        $nama = trim((string) ($c['nama'] ?? $slug));
        if ($nama === '') {
            $nama = $slug;
        }
        $parts[] = $nama . ' (*Rp ' . number_format($sisa, 0, ',', '.') . '*)';
    }
    if ($parts === []) {
        return 'tagihan bulanan';
    }
    if (count($parts) === 1) {
        return $parts[0];
    }
    $last = array_pop($parts);

    return implode(', ', $parts) . ' dan ' . $last;
}

/** Teks WA otomatis tagihan kekurangan ke wali (bahasa Jawa sopan). */
function wa_format_tagihan_otomatis_wali(
    PDO $pdo,
    string $namaSantri,
    string $labelKekurangan,
    int $totalSisa,
    string $periodeTagihan = '',
    string $rincianPerBulan = ''
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    $namaPonpes = app_brand_nama_ponpes($pdo, 'Pon-Pes A.P.I Nailul Muna');
    $nama = trim($namaSantri) !== '' ? trim($namaSantri) : 'santri';
    $totalFmt = 'Rp ' . number_format(max(0, $totalSisa), 0, ',', '.');
    $ketKeuangan = trim((string) app_setting(
        $pdo,
        'keterangan_pengurus_bidang_keuangan',
        'Bertanggung jawab atas administrasi keuangan dan tagihan santri.'
    ));
    $ketKeuanganLine = $ketKeuangan !== '' ? "\n_" . $ketKeuangan . "_\n" : "\n";
    $periode = trim($periodeTagihan);
    $periodeSnippet = $periode !== '' ? ' untuk periode *' . $periode . '*' : '';
    $rincianBlock = trim($rincianPerBulan);
    $rincianVar = $rincianBlock !== '' ? "\n" . $rincianBlock . "\n" : '';
    $tpl = wa_template_get($pdo, 'tagihan_wali');
    $hasRincianPlaceholder = str_contains($tpl, '{rincian_per_bulan}');

    $msg = wa_template_render($pdo, 'tagihan_wali', [
        'nama_santri' => $nama,
        'nama_ponpes' => $namaPonpes,
        'label_kekurangan' => $labelKekurangan,
        'total_sisa' => '*' . $totalFmt . '*',
        'keterangan_keuangan' => $ketKeuanganLine,
        'periode_tagihan' => $periodeSnippet,
        'rincian_per_bulan' => $hasRincianPlaceholder ? $rincianVar : '',
    ]);
    if ($rincianBlock !== '' && !$hasRincianPlaceholder) {
        $anchor = 'jumlah total *' . $totalFmt . '*.';
        if (str_contains($msg, $anchor)) {
            $msg = str_replace($anchor, $anchor . "\n\n" . $rincianBlock, $msg);
        } else {
            $msg .= "\n\n" . $rincianBlock;
        }
    }

    return $msg;
}

/** Ringkasan tagihan untuk notifikasi push (tanpa markup tebal). */
function push_format_tagihan_otomatis_body(string $namaSantri, string $labelKekuranganPlain, int $totalSisa): string
{
    $nama = trim($namaSantri) !== '' ? trim($namaSantri) : 'santri';
    $plain = preg_replace('/\*([^*]+)\*/', '$1', $labelKekuranganPlain) ?? $labelKekuranganPlain;
    $totalFmt = 'Rp ' . number_format(max(0, $totalSisa), 0, ',', '.');

    return "Nyuwun pangapunten. Putra/Ibu {$nama} masih kekurangan {$plain}. Total: {$totalFmt}. Terima kasih 🙏";
}

function wa_kop_instansi(PDO $pdo): string
{
    $nama = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
    return '*' . $nama . '*' . "\n" . '_Manajemen Santri — notifikasi otomatis_';
}

/**
 * Hasil generate ALPA massal (satu tanggal / satu tingkatan / satu konteks kegiatan).
 *
 * @param array<int, array{nama_santri: string, nis: string, total_alpha: int}> $santriList
 */
function wa_format_laporan_alpa_generate(PDO $pdo, string $tanggalIdn, string $tingkatan, string $namaKegiatan, int $ambang, array $santriList): string
{
    require_once __DIR__ . '/wa_laporan_alpa.php';
    $messages = wa_format_laporan_alpa_generate_messages($pdo, $tanggalIdn, $tingkatan, $namaKegiatan, $ambang, $santriList);
    if ($messages === []) {
        return '';
    }

    return implode("\n\n---\n\n", $messages);
}

function wa_format_pengajuan_izin_baru(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $tingkatan,
    string $jenisKode,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $jamMulai,
    string $jamSelesai,
    string $alasan,
    string $tujuan = ''
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    if (!function_exists('perizinan_jenis_wa_label')) {
        require_once __DIR__ . '/perizinan_jenis.php';
    }

    $jenis = perizinan_jenis_wa_label($jenisKode);
    $labelAlasan = perizinan_jenis_wa_label_alasan($jenisKode);
    $nisT = trim($nis);
    $tgT = trim($tingkatan);
    $tujuanT = trim($tujuan);
    $namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));

    return wa_template_render($pdo, 'pengajuan_izin_baru', [
        'salam' => '',
        'kop' => '',
        'nama_santri' => $namaSantri,
        'nis' => $nisT,
        'nis_baris' => $nisT !== '' ? '• NIS: *' . $nisT . "*\n" : '',
        'tingkatan' => $tgT,
        'tingkatan_baris' => $tgT !== '' ? '• Tingkatan: *' . $tgT . "*\n" : '',
        'jenis_izin' => $jenis,
        'label_alasan' => $labelAlasan,
        'tanggal_mulai' => $tanggalMulai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai,
        'jam_selesai' => $jamSelesai,
        'alasan' => trim($alasan) !== '' ? trim($alasan) : '—',
        'tujuan' => $tujuanT,
        'tujuan_baris' => $tujuanT !== '' ? '• Tujuan: *' . $tujuanT . "*\n" : '',
        'nama_ponpes' => $namaPonpes !== '' ? $namaPonpes : 'Sistem Informasi',
    ]);
}

function wa_format_izin_disetujui_untuk_wali(
    PDO $pdo,
    string $namaSantri,
    string $jenisRaw,
    string $tanggalSelesai,
    string $jamSelesai,
    string $alasan,
    string $tanggalMulai = '',
    string $jamMulai = '',
    int $approvedByUserId = 0
): string {
    if (!function_exists('wa_template_render')) {
        require_once __DIR__ . '/wa_templates.php';
    }
    if (!function_exists('perizinan_jenis_wa_disetujui_vars')) {
        require_once __DIR__ . '/perizinan_jenis.php';
    }
    if (!function_exists('perizinan_wa_vars_disetujui')) {
        require_once __DIR__ . '/perizinan_approval.php';
    }
    $namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
    $periode = $tanggalMulai !== ''
        ? $tanggalMulai . ' s/d ' . $tanggalSelesai
        : $tanggalSelesai;
    $waktu = ($jamMulai !== '' || $jamSelesai !== '')
        ? trim($jamMulai . ' – ' . $jamSelesai, ' –')
        : '';

    $vars = perizinan_wa_vars_disetujui($jenisRaw, [
        'nama_santri' => $namaSantri,
        'tanggal_mulai' => $tanggalMulai !== '' ? $tanggalMulai : $tanggalSelesai,
        'tanggal_selesai' => $tanggalSelesai,
        'jam_mulai' => $jamMulai !== '' ? $jamMulai : '-',
        'jam_selesai' => $jamSelesai !== '' ? $jamSelesai : '-',
        'periode' => $periode,
        'waktu' => $waktu !== '' ? $waktu : ($jamSelesai !== '' ? $jamSelesai : '-'),
        'alasan' => $alasan,
        'nama_ponpes' => $namaPonpes,
    ], $pdo, $approvedByUserId);

    $slug = wa_template_slug_izin_disetujui('izin_disetujui_wali', $jenisRaw);
    $pesan = wa_template_render_izin_disetujui($pdo, 'izin_disetujui_wali', $jenisRaw, $vars);

    return perizinan_wa_sisipkan_ttd_penyetuju($pdo, $slug, $pesan, $approvedByUserId);
}

function user_has_acl_permission_matrix(PDO $pdo): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }
    // Akun virtual (mis. petugas presensi id=0) tidak punya baris ACL di users.
    if ((int) ($_SESSION['user']['id'] ?? 0) <= 0) {
        return false;
    }

    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (in_array($role, ['kiai'], true)) {
        return false;
    }

    return (int) ($_SESSION['user']['is_super_admin'] ?? 0) !== 1
        && table_exists($pdo, 'user_access_permissions');
}

/**
 * @return array<string, int>|null null bila tidak ada filter ACL (semua item menu boleh)
 */
function get_allowed_permission_key_map(PDO $pdo): ?array
{
    if (!user_has_acl_permission_matrix($pdo)) {
        return null;
    }
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        if (function_exists('munawib_is_portal_session') && munawib_is_portal_session()) {
            require_once __DIR__ . '/munawib_portal.php';
            if (!function_exists('login_pembimbing_default_acl_keys')) {
                require_once __DIR__ . '/login_pembimbing.php';
            }
            $keys = array_intersect(
                login_pembimbing_default_acl_keys(),
                ['akademik_setoran', 'pembimbing_dashboard']
            );
            if ($keys === []) {
                $keys = ['akademik_setoran'];
            }

            return app_acl_normalize_allowed_map(array_flip($keys));
        }

        return [];
    }

    $cacheKey = 'acl_map_v2_' . $userId;
    $revKey = 'acl_map_rev_' . $userId;
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (!function_exists('user_acl_is_explicitly_configured')) {
        require_once __DIR__ . '/user_permissions.php';
    }
    if (!function_exists('user_acl_ensure_legacy_configured')) {
        require_once __DIR__ . '/user_permissions.php';
    }
    user_acl_ensure_legacy_configured($pdo, $userId);
    $aclExplicit = user_acl_is_explicitly_configured($pdo, $userId);
    $aclRevision = user_acl_revision($pdo, $userId);

    if ($role === 'admin' && !$aclExplicit) {
        return null;
    }

    if (
        isset($_SESSION[$cacheKey], $_SESSION[$revKey])
        && is_array($_SESSION[$cacheKey])
        && (string) $_SESSION[$revKey] === $aclRevision
    ) {
        return app_acl_normalize_allowed_map($_SESSION[$cacheKey]);
    }

    if (!$aclExplicit && in_array($role, ['admin', 'pengurus', 'petugas_absensi', 'pembimbing'], true)) {
        if (!function_exists('user_permission_ensure_role_defaults')) {
            require_once __DIR__ . '/user_permissions.php';
        }
        user_permission_ensure_role_defaults($pdo, $userId, $role);
    }

    $allowedPermissions = $pdo->prepare('SELECT permission_key FROM user_access_permissions WHERE user_id = :user_id');
    $allowedPermissions->execute(['user_id' => $userId]);
    $allowedKeys = array_map('strval', $allowedPermissions->fetchAll(PDO::FETCH_COLUMN));

    if ($allowedKeys === [] && !$aclExplicit) {
        if (in_array($role, ['pengurus', 'petugas_absensi'], true)) {
            if (!function_exists('user_permission_ensure_role_defaults')) {
                require_once __DIR__ . '/user_permissions.php';
            }
            user_permission_ensure_role_defaults($pdo, $userId, $role);
            $allowedPermissions->execute(['user_id' => $userId]);
            $allowedKeys = array_map('strval', $allowedPermissions->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    if ($allowedKeys === [] && $role === 'admin' && !$aclExplicit) {
        return null;
    }

    $map = app_acl_normalize_allowed_map(array_flip($allowedKeys));
    $_SESSION[$cacheKey] = $map;
    $_SESSION[$revKey] = $aclRevision;
    unset($_SESSION['menu_items_acl_' . $userId], $_SESSION['menu_items_acl_sig_' . $userId]);

    return $map;
}

/**
 * @param array<string, int> $map
 * @return array<string, int>
 */
function app_acl_normalize_allowed_map(array $map): array
{
    if ($map !== []) {
        if (!function_exists('user_permission_expand_allowed_map')) {
            require_once __DIR__ . '/user_permissions.php';
        }
        $map = user_permission_expand_allowed_map($map);
    }
    $map['dashboard'] = 1;

    return $map;
}

/** Rute yang selalu boleh diakses user login (profil, keluar, dll.). */
function app_acl_is_public_route(string $requestPath): bool
{
    static $paths = [
        '/settings/profil.php',
        '/settings/akses_saya.php',
        '/logout.php',
        '/dashboard.php',
        '/menu/menu_hub.php',
    ];
    foreach ($paths as $path) {
        if ($path !== '' && str_contains($requestPath, $path)) {
            return true;
        }
    }

    return false;
}

/**
 * Path yang hanya redirect ke landing hub — jangan pakai sebagai fallback ACL.
 */
function app_acl_is_hub_redirect_stub(string $path): bool
{
    if (!function_exists('app_normalize_request_path')) {
        require_once __DIR__ . '/app_path.php';
    }
    $path = app_normalize_request_path($path);

    return in_array($path, [
        '/keuangan/cashless.php',
        '/keuangan/transaksi.php',
        '/keuangan/kas.php',
        '/perizinan/hub.php',
        '/akademik/setoran.php',
        '/akademik/ikhtibar.php',
        '/pkpps/hub.php',
        '/rekap/presensi.php',
        '/settings/kalender_ta.php',
    ], true);
}

/**
 * Halaman pertama yang boleh diakses (hindari redirect ke dashboard tanpa izin).
 */
function app_acl_first_allowed_path(array $permissionPathMap, array $allowedMap, ?string $skipPath = null): ?string
{
    if ($allowedMap === []) {
        return null;
    }

    $candidates = [];
    if (isset($allowedMap['dashboard'])) {
        $candidates[] = '/dashboard.php';
    }
    foreach ($permissionPathMap as $path => $permissionKey) {
        if ($path !== '' && isset($allowedMap[$permissionKey])) {
            if (app_acl_is_hub_redirect_stub($path)) {
                continue;
            }
            $candidates[] = $path;
        }
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'kiai' && !in_array('/pengasuh/dashboard.php', $candidates, true)) {
        array_unshift($candidates, '/pengasuh/dashboard.php');
    }
    if ($role === 'kiai' && !in_array('/pengasuh/laporan_hari.php', $candidates, true)) {
        $candidates[] = '/pengasuh/laporan_hari.php';
    }
    if ($role === 'kiai' && !in_array('/pengasuh/perizinan.php', $candidates, true)) {
        $candidates[] = '/pengasuh/perizinan.php';
    }
    if ($role === 'kiai' && !in_array('/pengasuh/nilai_keaktifan.php', $candidates, true)) {
        $candidates[] = '/pengasuh/nilai_keaktifan.php';
    }

    foreach ($candidates as $path) {
        if ($skipPath !== null && app_acl_request_paths_equal($skipPath, $path)) {
            continue;
        }

        return $path;
    }

    return null;
}

function app_acl_request_paths_equal(string $requestPath, string $targetPath): bool
{
    $normalize = static function (string $path): string {
        $path = app_normalize_request_path($path);
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        return rtrim($path, '/') ?: '/';
    };

    $a = $normalize($requestPath);
    $b = $normalize($targetPath);

    return $a === $b;
}

/** Setelah login pengurus/petugas: halaman berikutnya mengirim antrian offline otomatis. */
function app_mark_offline_queue_flush(): void
{
    $_SESSION['pondok_flush_offline'] = 1;
}

/** Salin flag flush ke sessionStorage (dibaca assets/js/offline-sync.js). */
function app_offline_queue_flush_script(): void
{
    if (empty($_SESSION['pondok_flush_offline'])) {
        return;
    }
    unset($_SESSION['pondok_flush_offline']);
    echo '<script>try{sessionStorage.setItem("pondok_flush_offline","1");}catch(e){}</script>' . "\n";
}

/** Redirect aman setelah login / dari halaman login (hindari loop). */
function app_post_login_redirect(PDO $pdo): void
{
    require_once __DIR__ . '/app_path.php';
    unset($_SESSION['_acl_redirect_guard']);

    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    if ($role === 'petugas_koperasi') {
        require_once __DIR__ . '/cashless_koperasi.php';
        $kid = (int) ($_SESSION['user']['koperasi_id'] ?? 0);
        if ($kid >= 1 && $kid <= 3) {
            cashless_koperasi_login_from_user($pdo, $kid);
        }
        app_redirect('koperasi/scan.php');
    }
    if (function_exists('is_super_admin') && is_super_admin()) {
        app_redirect('dashboard.php');
    }
    if ($role === 'kiai') {
        app_redirect('pengasuh/dashboard.php');
    }
    if ($role === 'pembimbing') {
        app_redirect('pembimbing/dashboard.php');
    }

    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        app_redirect('dashboard.php');
    }
    if ($allowedMap === []) {
        unset($_SESSION['user']);
        set_flash('error', 'Akun belum memiliki hak akses. Hubungi admin super.');
        app_redirect('login.php');
    }

    if (!function_exists('user_permission_path_map')) {
        require_once __DIR__ . '/user_permissions.php';
    }
    $fallback = app_acl_first_allowed_path(user_permission_path_map(), $allowedMap);
    if ($fallback !== null) {
        app_redirect_path($fallback);
    }

    app_redirect('dashboard.php');
}

/**
 * Redirect ACL ke halaman lain; hentikan loop bila target sama atau sudah pernah dicoba.
 */
function app_acl_safe_redirect(string $fallbackPath, string $requestPath): bool
{
    require_once __DIR__ . '/app_path.php';
    if ($fallbackPath === '' || app_acl_request_paths_equal($requestPath, $fallbackPath)) {
        return false;
    }

    $guard = (string) ($_SESSION['_acl_redirect_guard'] ?? '');
    if ($guard !== '' && app_acl_request_paths_equal($guard, $fallbackPath)) {
        return false;
    }

    $_SESSION['_acl_redirect_guard'] = $fallbackPath;
    app_redirect_path($fallbackPath);

    return true;
}

/** Panggil setelah ubah izin user di pengaturan admin. */
function app_acl_session_cache_clear(int $userId = 0): void
{
    if ($userId > 0) {
        unset(
            $_SESSION['acl_map_' . $userId],
            $_SESSION['acl_map_v2_' . $userId],
            $_SESSION['acl_map_rev_' . $userId],
            $_SESSION['menu_items_acl_' . $userId],
            $_SESSION['menu_items_acl_sig_' . $userId]
        );
        app_menu_pack_invalidate();
        return;
    }
    foreach (array_keys($_SESSION) as $sk) {
        if (!is_string($sk)) {
            continue;
        }
        if (
            str_starts_with($sk, 'acl_map_')
            || str_starts_with($sk, 'acl_map_v2_')
            || str_starts_with($sk, 'acl_map_rev_')
            || str_starts_with($sk, 'menu_items_acl_')
        ) {
            unset($_SESSION[$sk]);
        }
    }
    app_menu_pack_invalidate();
}

/** Menu publik yang tetap tampil walau ACL aktif. */
function app_acl_public_menu_paths(): array
{
    return [
        '/dashboard.php',
        '/settings/profil.php',
        '/settings/akses_saya.php',
    ];
}

/** Apakah path menu boleh ditampilkan menurut ACL. */
function app_acl_menu_path_allowed(string $path, array $permissionPathMap, array $allowedMap): bool
{
    if (in_array($path, app_acl_public_menu_paths(), true)) {
        return true;
    }
    $permPath = app_menu_acl_lookup_path($path, $permissionPathMap);
    if (!isset($permissionPathMap[$permPath])) {
        return false;
    }

    $primaryKey = $permissionPathMap[$permPath];
    if (!function_exists('user_permission_allowed_for_path')) {
        require_once __DIR__ . '/user_permissions.php';
    }

    return user_permission_allowed_for_path($path, $primaryKey, $allowedMap);
}

/**
 * Menu + ACL — satu kali parse menu_data.php per request.
 *
 * @return array{menuItems: array, menuStructure: array, permissionPathMap: array}
 */
/** @var array{menuItems: array, menuStructure: array, permissionPathMap: array}|null */
$GLOBALS['__app_menu_pack_v1'] = $GLOBALS['__app_menu_pack_v1'] ?? null;

function app_menu_pack_invalidate(): void
{
    $GLOBALS['__app_menu_pack_v1'] = null;
}

function app_menu_pack(PDO $pdo): array
{
    $pack = $GLOBALS['__app_menu_pack_v1'] ?? null;
    if (is_array($pack)) {
        return $pack;
    }
    $raw = require __DIR__ . '/../includes/menu_data.php';
    $menuItems = filter_menu_items_by_acl($pdo, $raw['menuItems'], $raw['permissionPathMap']);
    $pack = [
        'menuItems' => $menuItems,
        'menuStructure' => $raw['menuStructure'],
        'permissionPathMap' => $raw['permissionPathMap'],
    ];
    $GLOBALS['__app_menu_pack_v1'] = $pack;

    return $pack;
}

/**
 * Path dasar menu tanpa fragment (#) dan query (?).
 * Tidak memakai strtok — state internal strtok bisa merusak pemanggilan berikutnya.
 */
function app_menu_acl_normalize_path_base(string $path): string
{
    $withoutFragment = explode('#', $path, 2)[0];
    $base = $withoutFragment !== '' ? $withoutFragment : $path;
    $withoutQuery = explode('?', $base, 2)[0];

    return $withoutQuery !== '' ? $withoutQuery : $base;
}

/**
 * Cocokkan path menu ke entri permissionPathMap (coba utuh, tanpa #, tanpa ?).
 */
function app_menu_acl_lookup_path(string $path, array $permissionPathMap): string
{
    if (isset($permissionPathMap[$path])) {
        return $path;
    }
    $withoutFragment = explode('#', $path, 2)[0];
    if ($withoutFragment !== '' && isset($permissionPathMap[$withoutFragment])) {
        return $withoutFragment;
    }
    $base = app_menu_acl_normalize_path_base($path);
    if ($base !== $path && isset($permissionPathMap[$base])) {
        return $base;
    }

    return $path;
}

/** Pengasuh (kiai): sembunyikan menu pengajuan izin saja; modul lain tetap. */
function filter_menu_items_hide_kiai_permohonan_izin(array $menuItems): array
{
    if (strtolower((string) ($_SESSION['user']['role'] ?? '')) !== 'kiai') {
        return $menuItems;
    }
    unset($menuItems['/perizinan/permohonan.php']);

    return $menuItems;
}

function filter_menu_items_by_acl(PDO $pdo, array $menuItems, array $permissionPathMap): array
{
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    $menuSig = md5(implode('|', array_keys($menuItems)));
    $cacheKey = 'menu_items_acl_' . $userId;
    $sigKey = 'menu_items_acl_sig_' . $userId;
    if (
        $userId > 0
        && isset($_SESSION[$cacheKey], $_SESSION[$sigKey])
        && is_array($_SESSION[$cacheKey])
        && (string) $_SESSION[$sigKey] === $menuSig
    ) {
        return filter_menu_items_hide_kiai_permohonan_izin($_SESSION[$cacheKey]);
    }

    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        return filter_menu_items_hide_kiai_permohonan_izin($menuItems);
    }

    $filtered = array_filter(
        $menuItems,
        static function (string $label, string $path) use ($permissionPathMap, $allowedMap): bool {
            if ($path === '/pengasuh/nilai_keaktifan.php') {
                require_once __DIR__ . '/../includes/auth.php';

                return app_acl_menu_path_allowed($path, $permissionPathMap, $allowedMap)
                    && user_can_edit_keaktifan_nilai();
            }

            return app_acl_menu_path_allowed($path, $permissionPathMap, $allowedMap);
        },
        ARRAY_FILTER_USE_BOTH
    );
    $filtered = filter_menu_items_hide_kiai_permohonan_izin($filtered);
    if ($userId > 0) {
        $_SESSION[$cacheKey] = $filtered;
        $_SESSION[$sigKey] = $menuSig;
    }

    return $filtered;
}

function enforce_route_acl_or_redirect(PDO $pdo, string $requestPath, array $permissionPathMap): void
{
    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        return;
    }
    if (app_acl_is_public_route($requestPath)) {
        return;
    }

    if (
        str_contains($requestPath, '/pembimbing/setoran')
        || str_contains($requestPath, '/api/setoran/')
        || str_contains($requestPath, '/akademik/setoran_rekap')
    ) {
        require_once __DIR__ . '/../includes/auth.php';
        require_once __DIR__ . '/munawib_portal.php';
        if (is_super_admin()) {
            return;
        }
        if (munawib_is_portal_session()) {
            return;
        }
    }

    $matchedKey = null;
    $matchedLen = 0;
    foreach ($permissionPathMap as $path => $permissionKey) {
        if ($path === '' || !str_contains($requestPath, $path)) {
            continue;
        }
        $len = strlen($path);
        if ($len >= $matchedLen) {
            $matchedLen = $len;
            $matchedKey = $permissionKey;
        }
    }
    if ($matchedKey === null || isset($allowedMap[$matchedKey])) {
        return;
    }
    if (!function_exists('user_permission_allowed_for_path')) {
        require_once __DIR__ . '/user_permissions.php';
    }
    if (user_permission_allowed_for_path($requestPath, $matchedKey, $allowedMap)) {
        return;
    }

    set_flash('error', 'Anda tidak memiliki akses ke fitur ini. Hubungi admin super.');
    require_once __DIR__ . '/app_path.php';
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    if ($role === 'petugas_koperasi') {
        app_redirect('koperasi/scan.php');
    }
    if ($allowedMap === []) {
        unset($_SESSION['user']);
        set_flash('error', 'Akun belum memiliki hak akses. Hubungi admin super.');
        app_redirect('login.php');
    }
    $fallbackPath = app_acl_first_allowed_path($permissionPathMap, $allowedMap, $requestPath);
    if ($fallbackPath !== null && app_acl_safe_redirect($fallbackPath, $requestPath)) {
        return;
    }
    unset($_SESSION['_acl_redirect_guard'], $_SESSION['user']);
    app_redirect('login.php');
}

function settings_pengaturan_hub_url(): string
{
    return '/menu/menu_hub.php?id=menu-grp-pengaturan';
}

/** Alias ID grup menu lama → ID mega-kategori baru (backward compatibility breadcrumb). */
function menu_hub_id_aliases(): array
{
    return [
        'menu-grp-sdm' => 'menu-grp-santri',
        'menu-grp-saku' => 'menu-grp-keuangan',
        'menu-grp-keuangan-bos' => 'menu-grp-keuangan',
        'menu-grp-pkpps' => 'menu-grp-akademik',
        'menu-grp-kajian' => 'menu-grp-ketertiban',
        'menu-grp-perizinan' => 'menu-grp-ketertiban',
    ];
}

function menu_hub_resolve_id(string $hubId): string
{
    $aliases = menu_hub_id_aliases();

    return $aliases[$hubId] ?? $hubId;
}

/**
 * @return list<array{path:string,label:string,icon:string}>
 */
function settings_pengaturan_nav_items(?PDO $pdo = null): array
{
    static $definitions = null;
    if (!is_array($definitions)) {
        $pack = require __DIR__ . '/../includes/menu_data.php';
        $definitions = is_array($pack['pengaturanNav'] ?? null) ? $pack['pengaturanNav'] : [];
    }

    if (!($pdo instanceof PDO)) {
        return $definitions;
    }

    $pack = app_menu_pack($pdo);
    $menuItems = $pack['menuItems'];
    $out = [];
    foreach ($definitions as $item) {
        $path = (string) ($item['path'] ?? '');
        if ($path !== '' && isset($menuItems[$path])) {
            $out[] = [
                'path' => $path,
                'label' => (string) ($menuItems[$path] ?? ($item['label'] ?? '')),
                'icon' => (string) ($item['icon'] ?? 'fa-solid fa-circle'),
                'group' => (string) ($item['group'] ?? ''),
            ];
        }
    }
    return $out;
}

function menu_tile_icon_for_path(string $path): string
{
    $exactIcons = [
        '/settings/wa_otomatis.php' => 'fa-solid fa-comments',
        '/settings/wa_akun.php' => 'fa-solid fa-address-book',
        '/settings/wa_gateway.php' => 'fa-solid fa-gears',
        '/settings/pesantren.php' => 'fa-solid fa-mosque',
        '/settings/surat_cetak.php' => 'fa-solid fa-file-lines',
        '/settings/peraturan.php' => 'fa-solid fa-scale-balanced',
        '/settings/kalender.php' => 'fa-solid fa-calendar-days',
        '/settings/tingkatan.php' => 'fa-solid fa-layer-group',
        '/settings/kamar_ranjang.php' => 'fa-solid fa-bed',
        '/settings/kelas_ruangan.php' => 'fa-solid fa-door-open',
        '/settings/kelas_keuangan.php' => 'fa-solid fa-coins',
        '/settings/opsional_santri.php' => 'fa-solid fa-utensils',
        '/settings/admin.php' => 'fa-solid fa-user-shield',
        '/settings/presensi_data.php' => 'fa-solid fa-database',
        '/settings/push.php' => 'fa-solid fa-bell',
        '/settings/midtrans.php' => 'fa-solid fa-credit-card',
        '/pembayaran/rekap_pos.php' => 'fa-solid fa-chart-pie',
        '/settings/hijri_mappings.php' => 'fa-solid fa-moon',
        '/yayasan/pengurus.php' => 'fa-solid fa-user-tie',
        '/yayasan/rapat.php' => 'fa-solid fa-people-group',
        '/yayasan/notulen.php' => 'fa-solid fa-file-pen',
        '/yayasan/executive.php' => 'fa-solid fa-chart-line',
    ];
    if (isset($exactIcons[$path])) {
        return $exactIcons[$path];
    }
    if (str_contains($path, 'dashboard')) {
        return 'fa-solid fa-house';
    }
    if (str_contains($path, 'santri')) {
        return 'fa-solid fa-user-group';
    }
    if (str_contains($path, 'wali')) {
        return 'fa-solid fa-people-roof';
    }
    if (str_contains($path, 'pembimbing')) {
        return 'fa-solid fa-chalkboard-user';
    }
    if (str_contains($path, 'presensi')) {
        return 'fa-solid fa-qrcode';
    }
    if (str_contains($path, 'jadwal')) {
        return 'fa-solid fa-calendar-days';
    }
    if (str_contains($path, 'akademik')) {
        return 'fa-solid fa-book';
    }
    if (str_contains($path, 'perizinan')) {
        return 'fa-solid fa-person-walking-arrow-right';
    }
    if (str_contains($path, 'admin')) {
        return 'fa-solid fa-file-lines';
    }
    if (str_contains($path, 'poin')) {
        return 'fa-solid fa-star';
    }
    if (str_contains($path, 'keuangan') || str_contains($path, 'pembayaran')) {
        return 'fa-solid fa-wallet';
    }
    if (str_contains($path, 'rekap')) {
        return 'fa-solid fa-chart-column';
    }
    if (str_contains($path, 'settings')) {
        return 'fa-solid fa-gear';
    }
    if (str_contains($path, 'yayasan')) {
        return 'fa-solid fa-building-columns';
    }
    return 'fa-solid fa-arrow-right';
}

/**
 * @param array<string, mixed> $node
 * @return list<string>
 */
function menu_group_collect_paths(array $node): array
{
    $sections = $node['sections'] ?? null;
    $out = [];
    if (is_array($sections) && $sections !== []) {
        foreach ($sections as $sec) {
            foreach ((array) ($sec['paths'] ?? []) as $p) {
                if (is_string($p) && $p !== '') {
                    $out[] = $p;
                }
            }
        }
    } else {
        foreach ((array) ($node['paths'] ?? []) as $p) {
            if (is_string($p) && $p !== '') {
                $out[] = $p;
            }
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $node
 */
function menu_group_visible_paths(array $node, array $menuItems): array
{
    return array_values(array_filter(
        menu_group_collect_paths($node),
        static fn(string $p): bool => array_key_exists($p, $menuItems)
    ));
}

/**
 * Section grup menu yang lolos ACL (untuk accordion mobile).
 *
 * @param array<string, mixed> $node
 * @return list<array{title:string,paths:list<string>}>
 */
function menu_group_visible_sections(array $node, array $menuItems): array
{
    $sections = $node['sections'] ?? null;
    if (!is_array($sections) || $sections === []) {
        $paths = menu_group_visible_paths($node, $menuItems);
        if ($paths === []) {
            return [];
        }

        return [['title' => '', 'paths' => $paths]];
    }
    $out = [];
    foreach ($sections as $sec) {
        if (!is_array($sec)) {
            continue;
        }
        $paths = array_values(array_filter(
            (array) ($sec['paths'] ?? []),
            static fn($p): bool => is_string($p) && array_key_exists($p, $menuItems)
        ));
        if ($paths === []) {
            continue;
        }
        $out[] = [
            'title' => trim((string) ($sec['title'] ?? '')),
            'paths' => $paths,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $node
 */
function menu_sidebar_group_is_active(array $node, string $requestPath, array $menuItems): bool
{
    $hubId = (string) ($node['id'] ?? '');
    if ($hubId !== '' && str_contains($requestPath, '/menu/menu_hub.php')) {
        $qid = menu_hub_resolve_id(isset($_GET['id']) ? (string) $_GET['id'] : '');
        if ($qid === $hubId) {
            return true;
        }
    }
    foreach (menu_group_visible_paths($node, $menuItems) as $cp) {
        $pathBase = app_menu_acl_normalize_path_base($cp);
        if (str_contains($requestPath, $pathBase)) {
            return true;
        }
    }
    if ($hubId === 'menu-grp-akademik' && (
        str_contains($requestPath, '/pkpps/')
        || str_contains($requestPath, '/rekap/pkpps_')
        || str_contains($requestPath, '/pembimbing/pkpps_')
        || str_contains($requestPath, '/pembayaran/laporan_pkpps_syahriyah.php')
        || str_contains($requestPath, '/akademik/')
        || str_contains($requestPath, '/pembimbing/tugas/')
    )) {
        return true;
    }
    if ($hubId === 'menu-grp-ketertiban' && preg_match('#^/rekap/#', $requestPath) && !str_contains($requestPath, '/rekap/pkpps_')) {
        return true;
    }
    if ($hubId === 'menu-grp-ketertiban' && (
        str_contains($requestPath, '/pkpps/')
        || str_contains($requestPath, '/akademik/')
        || str_contains($requestPath, '/pembimbing/tugas/')
    )) {
        return false;
    }
    if ($hubId === 'menu-grp-yayasan' && str_contains($requestPath, '/yayasan/')) {
        return true;
    }
    if ($hubId === 'menu-grp-pengaturan' && (
        (
            str_starts_with($requestPath, '/settings/')
            && !str_contains($requestPath, '/settings/profil.php')
            && !str_contains($requestPath, '/settings/akses_saya.php')
        )
        || str_contains($requestPath, '/admin/cek_update.php')
    )) {
        return true;
    }
    if ($hubId === 'menu-grp-yayasan' && str_starts_with($requestPath, '/settings/')) {
        return false;
    }

    return false;
}

require_once __DIR__ . '/pwa_brand.php';
