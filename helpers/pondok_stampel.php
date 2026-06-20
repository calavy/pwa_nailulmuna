<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/logo_image.php';

function pondok_stampel_default_src(): string
{
    return '/assets/img/stempel-pondok.png';
}

function pondok_stampel_setting_key(string $kind): string
{
    return match ($kind) {
        'surat' => 'stampel_surat_path',
        'kuitansi' => 'stampel_kuitansi_path',
        default => '',
    };
}

function pondok_stampel_relative_path(PDO $pdo, string $kind): string
{
    $key = pondok_stampel_setting_key($kind);
    if ($key === '') {
        return '';
    }
    $rel = trim((string) app_setting($pdo, $key, ''));
    if ($rel === '') {
        return '';
    }
    $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $rel), '/');

    return is_file($full) ? $rel : '';
}

/** URL stempel untuk cetak (upload custom atau fallback default). */
function pondok_stampel_href(PDO $pdo, string $kind, bool $fallbackDefault = true): string
{
    require_once __DIR__ . '/app_path.php';
    $rel = pondok_stampel_relative_path($pdo, $kind);
    if ($rel !== '') {
        return app_href('/' . ltrim($rel, '/'));
    }
    if (!$fallbackDefault) {
        return '';
    }

    return app_href(pondok_stampel_default_src());
}

function pondok_stampel_configured(PDO $pdo, string $kind): bool
{
    return pondok_stampel_relative_path($pdo, $kind) !== '';
}

/**
 * Unggah stempel surat / kuitansi. Mengembalikan pesan error atau null jika sukses.
 *
 * @param array<string, mixed> $file
 */
function pondok_stampel_handle_upload(PDO $pdo, array $file, string $kind): ?string
{
    $key = pondok_stampel_setting_key($kind);
    if ($key === '') {
        return 'Jenis stempel tidak valid.';
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return 'Format stempel tidak didukung. Gunakan JPG, PNG, atau WEBP.';
    }
    if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
        return 'Unggahan stempel gagal.';
    }

    $targetDir = dirname(__DIR__) . '/uploads/stempel';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $prefix = $kind === 'surat' ? 'surat' : 'kuitansi';
    $safeName = 'stampel-' . $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($tmpFile, $targetPath)) {
        return 'Gagal menyimpan file stempel.';
    }

    $rel = 'uploads/stempel/' . $safeName;
    $optimizedRel = logo_image_process_uploaded_logo($targetPath, 'uploads/stempel');
    if ($optimizedRel !== null) {
        $rel = $optimizedRel;
    }

    $oldRel = trim((string) app_setting($pdo, $key, ''));
    save_setting($pdo, $key, $rel);
    pondok_stampel_delete_file($oldRel);

    return null;
}

function pondok_stampel_delete_file(string $relativePath): void
{
    $rel = trim(str_replace('\\', '/', $relativePath));
    if ($rel === '' || str_contains($rel, '..')) {
        return;
    }
    $full = dirname(__DIR__) . '/' . ltrim($rel, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

/** CSS stempel di blok tanda tangan surat. */
function pondok_stampel_surat_css(): string
{
    return <<<'CSS'
        .surat-ttd__ruang {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .surat-ttd__stampel {
            max-width: 100%;
            max-height: 22mm;
            width: auto;
            height: auto;
            object-fit: contain;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
CSS;
}
