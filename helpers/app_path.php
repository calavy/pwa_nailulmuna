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
    if (array_key_exists('base_path', $cfg) && is_string($cfg['base_path'])) {
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
