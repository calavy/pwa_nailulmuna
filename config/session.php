<?php

require_once __DIR__ . '/../helpers/app_path.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Semua link internal href="/..." otomatis dapat prefix subfolder di XAMPP (hanya HTML, bukan API/asset). */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $skipOb = str_contains($reqPath, '/api/')
        || preg_match('#\.(css|js|map|json|woff2?|png|jpe?g|gif|webp|ico|csv)$#i', $reqPath);
    if (!$skipOb && function_exists('app_base_path') && app_base_path() !== '') {
        ob_start(static function (string $buffer): string {
            if ($buffer === '' || ($buffer[0] ?? '') === '{' || str_starts_with($buffer, '<?xml')) {
                return $buffer;
            }

            return app_ob_rewrite_html($buffer);
        });
    }
}

function set_flash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}
