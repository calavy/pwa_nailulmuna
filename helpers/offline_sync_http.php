<?php

declare(strict_types=1);

/**
 * Respons JSON untuk sinkronisasi antrian offline PWA.
 */
function offline_sync_wants_json(): bool
{
    return trim((string) ($_SERVER['HTTP_X_PWA_OFFLINE_SYNC'] ?? '')) === '1';
}

/**
 * @param array<string, mixed> $extra
 */
function offline_sync_json_response(string $type, string $message, array $extra = []): void
{
    $normalized = strtolower(trim($type));
    if (!in_array($normalized, ['success', 'warning', 'error', 'info', 'duplicate', 'danger'], true)) {
        $normalized = 'info';
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode(array_merge([
        'ok' => $normalized === 'success',
        'type' => $normalized,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ambil pesan flash terakhir lalu kosongkan (untuk POST yang memakai set_flash).
 *
 * @return array{type:string,message:string}
 */
function offline_sync_take_flash(): array
{
    $success = $_SESSION['flash']['success'] ?? null;
    $error = $_SESSION['flash']['error'] ?? null;
    unset($_SESSION['flash']['success'], $_SESSION['flash']['error']);

    if (is_string($success) && $success !== '') {
        return ['type' => 'success', 'message' => $success];
    }
    if (is_string($error) && $error !== '') {
        return ['type' => 'error', 'message' => $error];
    }

    return ['type' => 'success', 'message' => 'Berhasil disimpan.'];
}
