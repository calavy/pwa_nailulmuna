<?php

declare(strict_types=1);

require_once __DIR__ . '/user_profil.php';

function santri_foto_ensure_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'santri')) {
        return;
    }
    $pdo->exec('ALTER TABLE santri ADD COLUMN IF NOT EXISTS foto_profil VARCHAR(255) NULL DEFAULT NULL');
}

function santri_foto_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/santri';
}

/**
 * @param array{name?:string,tmp_name?:string,error?:int} $file
 * @return array{ok:bool,path?:string,error?:string}
 */
function santri_foto_handle_upload(array $file, ?string $oldRelativePath = null, int $santriId = 0): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload foto gagal. Coba lagi.'];
    }

    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, user_profil_allowed_extensions(), true)) {
        return ['ok' => false, 'error' => 'Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.'];
    }

    if (!is_uploaded_file($tmpFile)) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }

    if (@filesize($tmpFile) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Ukuran foto maksimal 2 MB.'];
    }

    $targetDir = santri_foto_upload_dir();
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'error' => 'Folder upload tidak dapat dibuat.'];
    }

    $prefix = $santriId > 0 ? ('santri-' . $santriId . '-') : 'santri-';
    $safeName = $prefix . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;

    if (!move_uploaded_file($tmpFile, $targetPath)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan foto ke server.'];
    }

    user_profil_optimize_uploaded_image($targetPath);

    santri_foto_delete_file($oldRelativePath);

    return ['ok' => true, 'path' => 'uploads/santri/' . $safeName];
}

function santri_foto_delete_file(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/santri/')) {
        return;
    }
    $full = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

function santri_foto_url(?string $relativePath): string
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return '';
    }

    return app_href('/' . ltrim($relativePath, '/'));
}

/**
 * @param array<string, mixed> $santriRow
 */
function santri_foto_render_avatar(array $santriRow, string $sizeClass = 'app-user-avatar--md', string $extraClass = ''): string
{
    $nama = trim((string) ($santriRow['nama_santri'] ?? $santriRow['nama_tampil'] ?? 'Santri'));

    return user_profil_render_avatar([
        'nama' => $nama,
        'foto_profil' => $santriRow['foto_profil'] ?? '',
        'jenis_kelamin' => $santriRow['jenis_kelamin'] ?? null,
    ], $sizeClass, trim('portal-avatar ' . $extraClass));
}

/** @return array{nama:string,nis:string,tingkatan:string,subtitle:string,badges:list<string>} */
function portal_profile_meta_from_santri(PDO $pdo, array $santriRow): array
{
    $nama = trim((string) ($santriRow['nama_santri'] ?? $santriRow['nama_tampil'] ?? ''));
    $nis = trim((string) ($santriRow['nis'] ?? ''));
    $tingkatan = trim((string) ($santriRow['tingkatan'] ?? ''));
    $badges = [];
    if ($tingkatan !== '') {
        $badges[] = $tingkatan;
    }
    $kat = trim((string) ($santriRow['kategori_kelas'] ?? ''));
    if ($kat !== '') {
        $badges[] = kelas_keuangan_label_for_kode($pdo, $kat);
    }

    return [
        'nama' => $nama,
        'nis' => $nis,
        'tingkatan' => $tingkatan,
        'subtitle' => $nis !== '' ? ('NIS ' . $nis) : '',
        'badges' => $badges,
    ];
}
