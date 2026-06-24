<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** Navigasi cepat yayasan — render isi halaman tanpa layout penuh. */
function yayasan_fragment_enabled(): bool
{
    return !empty($GLOBALS['YAYASAN_FRAGMENT_ONLY']);
}

/**
 * @return array<string, string>
 */
function yayasan_fragment_route_map(): array
{
    return [
        '/yayasan' => 'operasional.php',
        '/yayasan/' => 'operasional.php',
        '/yayasan/dashboard.php' => 'operasional.php',
        '/yayasan/operasional.php' => 'operasional.php',
        '/yayasan/keaktifan.php' => 'keaktifan.php',
        '/yayasan/keaktifan_ranking.php' => 'keaktifan_ranking.php',
        '/yayasan/kesehatan.php' => 'kesehatan.php',
        '/yayasan/pengawasan.php' => 'pengawasan.php',
        '/yayasan/ringkasan.php' => 'ringkasan.php',
        '/yayasan/ketertiban.php' => 'ketertiban.php',
        '/yayasan/timeline.php' => 'timeline.php',
        '/yayasan/sdm_hari.php' => 'sdm_hari.php',
        '/yayasan/rapat.php' => 'rapat.php',
        '/yayasan/keaktifan_kelas.php' => 'keaktifan_kelas.php',
    ];
}

function yayasan_fragment_normalize_path(string $path): string
{
    $path = app_normalize_request_path($path);
    if ($path === '/yayasan') {
        return '/yayasan/operasional.php';
    }

    return $path;
}

/**
 * @param array<string, scalar|null> $query
 * @return array{ok:bool,html?:string,title?:string,stylesheets?:list<string>,scripts?:list<string>,body_class?:string,message?:string}|null
 */
function yayasan_fragment_render(PDO $pdo, string $path, array $query = []): ?array
{
    $path = yayasan_fragment_normalize_path($path);
    $map = yayasan_fragment_route_map();
    $file = $map[$path] ?? null;
    if ($file === null) {
        return null;
    }

    $fullPath = dirname(__DIR__) . '/yayasan/' . $file;
    if (!is_file($fullPath)) {
        return null;
    }

    $savedGet = $_GET;
    $_GET = array_merge($savedGet, array_filter(
        $query,
        static fn ($v) => $v !== null && $v !== ''
    ));

    $pageTitle = 'Yayasan';
    $pageStylesheets = [];
    $pageScripts = [];
    $bodyClass = '';

    $GLOBALS['YAYASAN_FRAGMENT_ONLY'] = true;
    ob_start();
    try {
        require $fullPath;
    } catch (Throwable $e) {
        ob_end_clean();
        $_GET = $savedGet;
        unset($GLOBALS['YAYASAN_FRAGMENT_ONLY']);
        throw $e;
    }
    $html = (string) ob_get_clean();
    $_GET = $savedGet;
    unset($GLOBALS['YAYASAN_FRAGMENT_ONLY']);

    return [
        'ok' => true,
        'html' => $html,
        'title' => (string) ($pageTitle ?? 'Yayasan'),
        'stylesheets' => is_array($pageStylesheets ?? null) ? $pageStylesheets : [],
        'scripts' => is_array($pageScripts ?? null) ? $pageScripts : [],
        'body_class' => (string) ($bodyClass ?? ''),
    ];
}

function yayasan_fragment_api_href(): string
{
    return app_href('/api/yayasan/fragment.php');
}
