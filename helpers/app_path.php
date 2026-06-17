<?php

declare(strict_types=1);

/**
 * Path dasar aplikasi di web server.
 * Production: https://pwa.nailulmuna.id/ (base_path kosong).
 */
function app_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = [
        'base_path' => null,
        'public_url' => '',
    ];

    $main = dirname(__DIR__) . '/config/app.php';
    if (is_file($main)) {
        $loaded = require $main;
        if (is_array($loaded)) {
            $cfg = array_merge($cfg, $loaded);
        }
    }

    $local = dirname(__DIR__) . '/config/app.local.php';
    if (is_file($local)) {
        $loaded = require $local;
        if (is_array($loaded)) {
            $cfg = array_merge($cfg, $loaded);
        }
    }

    return $cfg;
}

function app_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $env = getenv('APP_BASE_PATH');
    if (is_string($env)) {
        $cached = rtrim($env, '/');

        return $cached;
    }

    $cfg = app_config();
    if (array_key_exists('base_path', $cfg) && $cfg['base_path'] !== null && is_string($cfg['base_path'])) {
        $configured = rtrim($cfg['base_path'], '/');
        $publicUrl = trim((string) ($cfg['public_url'] ?? ''));
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $hostMatchesPublic = $publicUrl === ''
            || $host === ''
            || str_contains(strtolower($publicUrl), $host);
        // Host cocok (localhost / production) — pakai base_path dari config
        if ($configured !== '' && $hostMatchesPublic) {
            $cached = $configured;

            return $cached;
        }
        // Host beda (ngrok, domain production saat app.local masih localhost) → deteksi folder di bawah
    }

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $appRoot = realpath(dirname(__DIR__)) ?: '';
    if ($docRoot !== '' && $appRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $rel = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $cached = rtrim($rel, '/');

        return $cached;
    }

    $cached = '';

    return $cached;
}

/** URL publik penuh, mis. https://pwa.nailulmuna.id */
function app_public_url(): string
{
    $cfg = app_config();
    $url = trim((string) ($cfg['public_url'] ?? ''));
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($url !== '' && $host !== '' && str_contains(strtolower($url), $host)) {
        return rtrim($url, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ? 'https'
        : 'http';
    $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $requestHost . app_base_path();
}

/** Normalisasi REQUEST_URI untuk ACL/menu (hilangkan base_path di lokal). */
function app_normalize_request_path(string $uri): string
{
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
    $base = app_base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base)) ?: '/';
    }

    return $path === '' ? '/' : $path;
}

/** Path absolut dari root web, mis. app_href('/dashboard.php'). Aman jika sudah ada base_path. */
function app_href(string $url): string
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '' || preg_match('#^https?://#i', $url) || str_starts_with($url, '//')) {
        return $url;
    }
    if (!str_starts_with($url, '/')) {
        return $url;
    }

    $path = $url;
    $suffix = '';
    if (($q = strpos($url, '?')) !== false) {
        $path = substr($url, 0, $q);
        $suffix = substr($url, $q);
    } elseif (($h = strpos($url, '#')) !== false) {
        $path = substr($url, 0, $h);
        $suffix = substr($url, $h);
    }

    $path = '/' . ltrim($path, '/');
    $base = app_base_path();
    if ($base === '') {
        return $path . $suffix;
    }

    $slug = trim($base, '/');
    if (
        str_starts_with($path, $base . '/')
        || $path === $base
        || ($slug !== '' && (str_starts_with($path, '/' . $slug . '/') || $path === '/' . $slug))
    ) {
        return $path . $suffix;
    }

    return $base . $path . $suffix;
}

/** Versi cache-bust untuk file di /assets/ (mtime). */
function app_asset_version(string $assetPath): string
{
    static $cache = [];
    $assetPath = '/' . ltrim(str_replace('\\', '/', $assetPath), '/');
    if (isset($cache[$assetPath])) {
        return $cache[$assetPath];
    }
    $root = dirname(__DIR__);
    $full = $root . $assetPath;
    $cache[$assetPath] = is_file($full) ? (string) filemtime($full) : '1';

    return $cache[$assetPath];
}

/** URL asset dengan ?v=mtime agar HP/PWA tidak memakai CSS/JS lama. */
function app_asset_href(string $assetPath): string
{
    $assetPath = '/' . ltrim(str_replace('\\', '/', $assetPath), '/');
    $url = app_href($assetPath);
    if (!str_starts_with($assetPath, '/assets/')) {
        return $url;
    }
    $sep = str_contains($url, '?') ? '&' : '?';

    return $url . $sep . 'v=' . app_asset_version($assetPath);
}

/** Redirect ke path absolut aplikasi (mis. `/dashboard.php`). */
function app_redirect_path(string $absolutePath): void
{
    header('Location: ' . app_href($absolutePath));
    exit;
}

/** URL path absolut dalam aplikasi, mis. app_url('login.php') → /login.php */
function app_url(string $relativePath = ''): string
{
    $base = app_base_path();
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . $relativePath;
}

/** Redirect HTTP dengan path relatif aplikasi. */
function app_redirect(string $relativePath): void
{
    header('Location: ' . app_url($relativePath));
    exit;
}

/** Lingkungan pengembangan lokal (XAMPP/localhost) — bukan server production yang disebar. */
function app_is_local_dev(): bool
{
    if (strtolower((string) ($GLOBALS['pondok_env'] ?? '')) === 'local') {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (
        $host === 'localhost'
        || $host === '127.0.0.1'
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.ngrok-free.dev')
        || str_ends_with($host, '.ngrok-free.app')
        || str_ends_with($host, '.ngrok.io')
        || str_contains($host, 'ngrok')
    ) {
        return true;
    }
    $bp = strtolower(app_base_path());
    if ($bp !== '' && (str_contains($bp, 'pwa_nailulmuna') || str_contains($bp, 'xampp'))) {
        return true;
    }
    $pub = strtolower(trim((string) (app_config()['public_url'] ?? '')));
    if ($pub !== '' && (str_contains($pub, 'localhost') || str_contains($pub, '127.0.0.1'))) {
        return true;
    }

    return false;
}

/** Ubah path absolut internal `/foo.php` agar menyertakan base_path (lokal XAMPP). */
function app_rewrite_internal_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, '/')) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return $url;
    }
    $base = app_base_path();
    if ($base === '') {
        return $url;
    }
    $slug = trim($base, '/');
    if ($slug !== '' && (str_starts_with($url, $base . '/') || str_starts_with($url, '/' . $slug . '/'))) {
        return $url;
    }

    return $base . $url;
}

/** Perbaiki href/action absolut di HTML agar jalan di subfolder lokal. */
function app_ob_rewrite_html(string $html): string
{
    $base = app_base_path();
    if ($base === '') {
        return $html;
    }

    $slug = trim($base, '/');
    $alreadyPrefixed = $slug !== '' ? '(?!' . preg_quote($slug, '#') . '\/)' : '';

    $prefixAbs = '$1=$2' . $base . '/';
    $patterns = [
        '#\b(href|action|src)=(["\'])(?!//)/' . $alreadyPrefixed . '#',
        '#\bdata-sdm-modal=(["\'])(?!//)/' . $alreadyPrefixed . '#',
    ];
    $result = $html;
    foreach ($patterns as $pattern) {
        $replaced = preg_replace($pattern, $prefixAbs, $result);
        $result = is_string($replaced) ? $replaced : $result;
    }

    return $result;
}
