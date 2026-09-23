<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/app_hub.php';

function keuangan_fragment_enabled(): bool
{
    return !empty($GLOBALS['APP_FRAGMENT_ONLY']) || !empty($GLOBALS['YAYASAN_FRAGMENT_ONLY']);
}

/** @return list<string> */
function keuangan_fragment_hub_ids(): array
{
    return ['keuangan_transaksi', 'keuangan_kas', 'keuangan_cashless'];
}

/**
 * Whitelist path hub → file relatif root proyek.
 *
 * @return array<string, string>
 */
function keuangan_fragment_route_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    $registry = app_hub_registry();
    foreach (keuangan_fragment_hub_ids() as $hubId) {
        if (!isset($registry[$hubId]['tabs'])) {
            continue;
        }
        foreach ($registry[$hubId]['tabs'] as $tab) {
            $path = app_hub_normalize_path((string) ($tab['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $map[$path] = ltrim($path, '/');
        }
    }

    return $map;
}

function keuangan_fragment_normalize_path(string $path): string
{
    return app_hub_normalize_path($path);
}

function keuangan_fragment_path_in_whitelist(string $path): bool
{
    $path = keuangan_fragment_normalize_path($path);
    $map = keuangan_fragment_route_map();

    return isset($map[$path]);
}

/**
 * @param array<string, string> $permissionPathMap
 */
function keuangan_fragment_path_allowed(PDO $pdo, string $path, array $permissionPathMap): bool
{
    if (!keuangan_fragment_path_in_whitelist($path)) {
        return false;
    }

    return app_hub_tab_allowed($pdo, keuangan_fragment_normalize_path($path), $permissionPathMap);
}

/**
 * @param array<string, scalar|null> $query
 * @return array{ok:bool,html?:string,title?:string,stylesheets?:list<string>,scripts?:list<string>,body_class?:string,message?:string}|null
 */
function keuangan_fragment_render(PDO $pdo, string $path, array $query = []): ?array
{
    $path = keuangan_fragment_normalize_path($path);
    $map = keuangan_fragment_route_map();
    $relative = $map[$path] ?? null;
    if ($relative === null) {
        return null;
    }

    $fullPath = dirname(__DIR__) . '/' . $relative;
    if (!is_file($fullPath)) {
        return null;
    }

    $savedGet = $_GET;
    $_GET = array_merge($savedGet, array_filter(
        $query,
        static fn ($v) => $v !== null && $v !== ''
    ));

    $pageTitle = 'Keuangan';
    $pageStylesheets = [];
    $pageScripts = [];
    $bodyClass = '';

    $GLOBALS['APP_FRAGMENT_ONLY'] = true;
    $GLOBALS['YAYASAN_FRAGMENT_ONLY'] = true;
    ob_start();
    try {
        require $fullPath;
    } catch (Throwable $e) {
        ob_end_clean();
        $_GET = $savedGet;
        unset($GLOBALS['APP_FRAGMENT_ONLY'], $GLOBALS['YAYASAN_FRAGMENT_ONLY']);
        throw $e;
    }
    $html = (string) ob_get_clean();
    $_GET = $savedGet;
    unset($GLOBALS['APP_FRAGMENT_ONLY'], $GLOBALS['YAYASAN_FRAGMENT_ONLY']);

    return [
        'ok' => true,
        'html' => $html,
        'title' => (string) ($pageTitle ?? 'Keuangan'),
        'stylesheets' => is_array($pageStylesheets ?? null) ? $pageStylesheets : [],
        'scripts' => is_array($pageScripts ?? null) ? $pageScripts : [],
        'body_class' => (string) ($bodyClass ?? ''),
    ];
}

function keuangan_fragment_api_href(): string
{
    return app_href('/api/keuangan/fragment.php');
}
