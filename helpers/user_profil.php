<?php

declare(strict_types=1);

/**
 * Foto profil akun users (upload, URL, avatar HTML).
 */

function user_profil_ensure_schema(PDO $pdo): void
{
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_profil_schema_ok'])) {
        return;
    }
    if (!table_exists($pdo, 'users')) {
        return;
    }
    $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS foto_profil VARCHAR(255) NULL DEFAULT NULL');
    if (!column_exists($pdo, 'users', 'jenis_kelamin')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN jenis_kelamin ENUM('Laki-laki','Perempuan') NULL DEFAULT NULL");
    }
    if (!column_exists($pdo, 'users', 'no_wa')) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN no_wa VARCHAR(32) NULL DEFAULT NULL');
        } catch (PDOException $e) {
            /* abaikan */
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['user_profil_schema_ok'] = 1;
    }
}

/** @return 'Laki-laki'|'Perempuan'|null */
function user_profil_normalize_jenis_kelamin(?string $jk): ?string
{
    $jk = trim((string) $jk);
    if ($jk === 'Laki-laki' || strcasecmp($jk, 'laki-laki') === 0 || strcasecmp($jk, 'l') === 0) {
        return 'Laki-laki';
    }
    if ($jk === 'Perempuan' || strcasecmp($jk, 'perempuan') === 0 || strcasecmp($jk, 'p') === 0) {
        return 'Perempuan';
    }

    return null;
}

/** Sapaan hormat: Bapak / Ibu. */
function user_profil_sapaan(?string $jenisKelamin): string
{
    $jk = user_profil_normalize_jenis_kelamin($jenisKelamin);
    if ($jk === 'Perempuan') {
        return 'Ibu';
    }
    if ($jk === 'Laki-laki') {
        return 'Bapak';
    }

    return 'Bapak/Ibu';
}

/** Nama depan untuk tampilan singkat. */
function user_profil_nama_depan(string $nama): string
{
    $nama = trim($nama);
    if ($nama === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $nama) ?: [];

    return (string) ($parts[0] ?? $nama);
}

/**
 * @param array{nama?:string,jenis_kelamin?:string|null} $user
 */
function user_profil_panggilan_display(array $user): string
{
    $sapaan = user_profil_sapaan($user['jenis_kelamin'] ?? null);
    $depan = user_profil_nama_depan((string) ($user['nama'] ?? ''));

    return $depan !== '' ? $sapaan . ' ' . $depan : $sapaan;
}

/** Gambar profil default (belum upload) — menyesuaikan jenis kelamin jika ada. */
function user_profil_default_avatar_href(?string $jenisKelamin): string
{
    $jk = user_profil_normalize_jenis_kelamin($jenisKelamin);
    $file = match ($jk) {
        'Perempuan' => 'avatar-default-perempuan.svg',
        'Laki-laki' => 'avatar-default-laki.svg',
        default => 'avatar-default.svg',
    };

    return app_href('/assets/images/' . $file);
}

function user_profil_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/profiles';
}

/** @return list<string> */
function user_profil_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

/** Kompres & resize foto upload agar lebih ringan di HP (max lebar 800px). */
function user_profil_optimize_uploaded_image(string $targetPath, int $maxWidth = 800, int $jpegQuality = 82): void
{
    if (!is_file($targetPath) || !function_exists('imagecreatetruecolor')) {
        return;
    }
    $info = @getimagesize($targetPath);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return;
    }
    [$width, $height, $type] = $info;
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($targetPath),
        IMAGETYPE_PNG => @imagecreatefrompng($targetPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($targetPath) : false,
        default => false,
    };
    if ($src === false) {
        return;
    }
    $newWidth = $width;
    $newHeight = $height;
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int) max(1, round($height * ($maxWidth / $width)));
    }
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if ($dst === false) {
        imagedestroy($src);

        return;
    }
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($src);
    $saved = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $targetPath, $jpegQuality),
        IMAGETYPE_PNG => imagepng($dst, $targetPath, 6),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $targetPath, $jpegQuality) : false,
        default => false,
    };
    imagedestroy($dst);
}

/**
 * @param array{name?:string,tmp_name?:string,error?:int} $file
 * @return array{ok:bool,path?:string,error?:string}
 */
function user_profil_handle_upload(array $file, ?string $oldRelativePath = null): array
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

    $maxBytes = 2 * 1024 * 1024;
    if (@filesize($tmpFile) > $maxBytes) {
        return ['ok' => false, 'error' => 'Ukuran foto maksimal 2 MB.'];
    }

    $targetDir = user_profil_upload_dir();
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'error' => 'Folder upload tidak dapat dibuat.'];
    }

    $safeName = 'user-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;

    if (!move_uploaded_file($tmpFile, $targetPath)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan foto ke server.'];
    }

    user_profil_optimize_uploaded_image($targetPath);

    user_profil_delete_file($oldRelativePath);

    return ['ok' => true, 'path' => 'uploads/profiles/' . $safeName];
}

function user_profil_delete_file(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/profiles/')) {
        return;
    }
    $full = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

function user_profil_url(?string $relativePath): string
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return '';
    }
    return app_href('/' . ltrim($relativePath, '/'));
}

function user_profil_initials(string $nama): string
{
    $nama = trim($nama);
    if ($nama === '') {
        return '?';
    }
    $parts = preg_split('/\s+/u', $nama) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }

    return mb_strtoupper(mb_substr($nama, 0, 2));
}

/**
 * @param array{nama?:string,foto_profil?:string|null} $user
 */
function user_profil_render_avatar(array $user, string $sizeClass = 'app-user-avatar--md', string $extraClass = ''): string
{
    $nama = trim((string) ($user['nama'] ?? 'User'));
    $foto = trim((string) ($user['foto_profil'] ?? ''));
    $classes = 'app-user-avatar ' . $sizeClass;
    if ($extraClass !== '') {
        $classes .= ' ' . $extraClass;
    }
    $title = htmlspecialchars($nama, ENT_QUOTES, 'UTF-8');

    if ($foto !== '') {
        $src = htmlspecialchars(user_profil_url($foto), ENT_QUOTES, 'UTF-8');
        $fb = htmlspecialchars(user_profil_default_avatar_href($user['jenis_kelamin'] ?? null), ENT_QUOTES, 'UTF-8');

        return '<span class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . ' app-user-avatar--uploaded" title="' . $title . '">'
            . '<img src="' . $src . '" alt="" class="app-user-avatar__img" loading="lazy" decoding="async" data-pondok-cache="1" data-fallback-src="' . $fb . '">'
            . '</span>';
    }

    $srcDefault = htmlspecialchars(
        user_profil_default_avatar_href($user['jenis_kelamin'] ?? null),
        ENT_QUOTES,
        'UTF-8'
    );

    return '<span class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . ' app-user-avatar--default" title="' . $title . '">'
        . '<img src="' . $srcDefault . '" alt="" class="app-user-avatar__img" loading="lazy" decoding="async">'
        . '</span>';
}

function user_profil_sync_session(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !isset($_SESSION['user']) || (int) ($_SESSION['user']['id'] ?? 0) !== $userId) {
        return;
    }
    user_profil_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT foto_profil FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $_SESSION['user']['foto_profil'] = trim((string) ($row['foto_profil'] ?? ''));
    if (column_exists($pdo, 'users', 'jenis_kelamin')) {
        $stJk = $pdo->prepare('SELECT jenis_kelamin FROM users WHERE id = :id LIMIT 1');
        $stJk->execute(['id' => $userId]);
        $rowJk = $stJk->fetch(PDO::FETCH_ASSOC);
        $_SESSION['user']['jenis_kelamin'] = user_profil_normalize_jenis_kelamin($rowJk['jenis_kelamin'] ?? null);
    }
}

function user_profil_fetch_for_user(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }
    user_profil_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT foto_profil FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return trim((string) ($row['foto_profil'] ?? '')) ?: null;
}
