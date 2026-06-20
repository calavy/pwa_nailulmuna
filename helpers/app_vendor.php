<?php

declare(strict_types=1);

require_once __DIR__ . '/app_path.php';

/**
 * Aset UI inti (Bootstrap, Font Awesome) — lokal agar tampilan offline tetap rapi.
 */
function app_vendor_root(): string
{
    return dirname(__DIR__) . '/assets/vendor';
}

function app_vendor_file_exists(string $relative): bool
{
    $path = app_vendor_root() . '/' . ltrim($relative, '/');

    return is_file($path);
}

function app_vendor_href(string $relative): string
{
    return app_asset_href('/assets/vendor/' . ltrim($relative, '/'));
}

/** URL webfont / vendor tanpa query ?v= (untuk preload & @font-face). */
function app_vendor_static_href(string $relative): string
{
    return app_href('/assets/vendor/' . ltrim($relative, '/'));
}

function app_vendor_bootstrap_css_href(): string
{
    if (app_vendor_file_exists('bootstrap/5.3.3/bootstrap.min.css')) {
        return app_vendor_href('bootstrap/5.3.3/bootstrap.min.css');
    }

    return 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
}

function app_vendor_bootstrap_js_href(): string
{
    if (app_vendor_file_exists('bootstrap/5.3.3/bootstrap.bundle.min.js')) {
        return app_vendor_href('bootstrap/5.3.3/bootstrap.bundle.min.js');
    }

    return 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';
}

function app_vendor_fontawesome_css_href(): string
{
    if (app_vendor_file_exists('fontawesome/6.5.2/all.min.css')) {
        return app_href('/api/vendor/fontawesome.css.php');
    }

    return 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
}

function app_vendor_html5_qrcode_js_href(): string
{
    if (app_vendor_file_exists('html5-qrcode/2.3.8/html5-qrcode.min.js')) {
        return app_vendor_href('html5-qrcode/2.3.8/html5-qrcode.min.js');
    }

    return 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
}

/** @return list<string> path relatif untuk precache PWA */
function app_vendor_precache_relative_paths(): array
{
    $list = [];
    foreach ([
        'bootstrap/5.3.3/bootstrap.min.css',
        'bootstrap/5.3.3/bootstrap.bundle.min.js',
        'fontawesome/6.5.2/all.min.css',
        'fontawesome/6.5.2/webfonts/fa-solid-900.woff2',
        'fontawesome/6.5.2/webfonts/fa-regular-400.woff2',
        'fontawesome/6.5.2/webfonts/fa-brands-400.woff2',
    ] as $rel) {
        if (app_vendor_file_exists($rel)) {
            $list[] = '/assets/vendor/' . $rel;
        }
    }

    return $list;
}

/** @return list<string> path relatif scan QR — precache on-demand */
function app_vendor_scan_precache_relative_paths(): array
{
    $list = [];
    foreach (['html5-qrcode/2.3.8/html5-qrcode.min.js'] as $rel) {
        if (app_vendor_file_exists($rel)) {
            $list[] = '/assets/vendor/' . $rel;
        }
    }

    return $list;
}
