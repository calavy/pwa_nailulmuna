<?php

declare(strict_types=1);

/**
 * Path dasar aplikasi di web server (mis. /pwa_nailulmuna atau '' jika di root ngrok).
 */
function app_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $env = getenv('APP_BASE_PATH');
    if (is_string($env) && $env !== '') {
        $cached = rtrim($env, '/');

        return $cached;
    }

    $configFile = dirname(__DIR__) . '/config/app.php';
    if (is_file($configFile)) {
        $cfg = require $configFile;
        if (is_array($cfg) && isset($cfg['base_path']) && is_string($cfg['base_path'])) {
            $cached = rtrim($cfg['base_path'], '/');

            return $cached;
        }
    }

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $appRoot = realpath(dirname(__DIR__)) ?: '';
    if ($docRoot !== '' && $appRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $rel = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $cached = rtrim($rel, '/');

        return $cached;
    }

    $cached = '/pwa_nailulmuna';

    return $cached;
}

/** URL path absolut dalam aplikasi, mis. app_url('login.php') → /pwa_nailulmuna/login.php */
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
