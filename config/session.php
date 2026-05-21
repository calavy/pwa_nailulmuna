<?php

require_once __DIR__ . '/../helpers/app_path.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Semua link internal href="/..." otomatis dapat prefix /pwa_nailulmuna di XAMPP. */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    ob_start(static function (string $buffer): string {
        return app_ob_rewrite_html($buffer);
    });
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
