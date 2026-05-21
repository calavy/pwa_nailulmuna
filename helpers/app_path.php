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
        $cached = rtrim($cfg['base_path'], '/');

        return $cached;
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
    if ($url !== '') {
        return rtrim($url, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . app_base_path();
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

    $result = preg_replace(
        '#\b(href|action|src)=(["\'])(?!//)/' . $alreadyPrefixed . '#',
        '$1=$2' . $base . '/',
        $html
    );

    return is_string($result) ? $result : $html;
}
