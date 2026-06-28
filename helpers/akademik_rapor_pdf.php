<?php

declare(strict_types=1);

require_once __DIR__ . '/file_stream.php';

function akademik_rapor_pdf_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/rapor';
}

function akademik_rapor_pdf_delete_file(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/rapor/')) {
        return;
    }
    $full = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

function akademik_rapor_pdf_full_path(?string $relativePath): ?string
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/rapor/')) {
        return null;
    }
    $full = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (!is_file($full)) {
        return null;
    }

    return $full;
}

/**
 * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
 * @return array{ok:bool,path?:string,original_name?:string,error?:string}
 */
function akademik_rapor_pdf_handle_upload(array $file, int $raporId, ?string $oldRelativePath = null): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload PDF gagal. Coba lagi.'];
    }
    if ($raporId <= 0) {
        return ['ok' => false, 'error' => 'Simpan rapor terlebih dahulu sebelum mengunggah PDF.'];
    }

    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $originalName = trim((string) ($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return ['ok' => false, 'error' => 'Hanya file PDF yang diperbolehkan.'];
    }
    if (!is_uploaded_file($tmpFile)) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Ukuran PDF maksimal 15 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpFile) ?: '';
    if ($mime !== 'application/pdf' && $mime !== 'application/x-pdf') {
        return ['ok' => false, 'error' => 'File bukan format PDF yang valid.'];
    }

    $targetDir = akademik_rapor_pdf_upload_dir();
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'error' => 'Folder upload tidak dapat dibuat.'];
    }

    $safeName = 'rapor-' . $raporId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.pdf';
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($tmpFile, $targetPath)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan PDF ke server.'];
    }

    akademik_rapor_pdf_delete_file($oldRelativePath);

    return [
        'ok' => true,
        'path' => 'uploads/rapor/' . $safeName,
        'original_name' => $originalName !== '' ? mb_substr($originalName, 0, 200) : 'rapor.pdf',
    ];
}

/** @param array<string, mixed> $raporRow */
function akademik_rapor_pdf_download_name(array $raporRow): string
{
    $custom = trim((string) ($raporRow['pdf_original_name'] ?? ''));
    if ($custom !== '' && str_ends_with(strtolower($custom), '.pdf')) {
        return preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $custom) ?: 'rapor.pdf';
    }
    $judul = trim((string) ($raporRow['judul_periode'] ?? 'rapor'));
    $judul = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $judul) ?: 'rapor';
    $nis = trim((string) ($raporRow['nis'] ?? ''));

    return ($nis !== '' ? $nis . '_' : '') . $judul . '.pdf';
}

/** @param array<string, mixed> $raporRow */
function akademik_rapor_pdf_stream(array $raporRow, bool $download = false): void
{
    $full = akademik_rapor_pdf_full_path((string) ($raporRow['pdf_path'] ?? ''));
    if ($full === null) {
        http_response_code(404);
        exit('File PDF rapor tidak ditemukan.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    app_http_stream_file($full, [
        'mime' => 'application/pdf',
        'filename' => akademik_rapor_pdf_download_name($raporRow),
        'download' => $download,
        'cache_seconds' => 86400,
        'not_found' => 'File PDF rapor tidak ditemukan.',
    ]);
}
