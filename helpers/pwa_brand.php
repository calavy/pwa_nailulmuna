<?php

declare(strict_types=1);

require_once __DIR__ . '/kartu_brand_colors.php';
require_once __DIR__ . '/logo_image.php';

/** Validasi warna hex #RRGGBB. */
function pwa_brand_normalize_hex(string $hex, string $fallback): string
{
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (strlen($h) === 6 && ctype_xdigit($h)) {
        return '#' . strtolower($h);
    }

    return $fallback;
}

/**
 * @return array{theme_color:string,background_color:string,accent_mid:string,accent_light:string}
 */
function app_pwa_theme(?PDO $pdo = null): array
{
    $defaults = [
        'theme_color' => '#0f766e',
        'background_color' => '#0d9488',
        'accent_mid' => '#0891b2',
        'accent_light' => '#eef5ff',
    ];

    $pdo = app_pwa_resolve_pdo($pdo);
    if ($pdo === null) {
        return $defaults;
    }

    $tc = trim((string) app_setting($pdo, 'pwa_theme_color', ''));
    $bc = trim((string) app_setting($pdo, 'pwa_background_color', ''));
    if ($tc !== '') {
        $defaults['theme_color'] = pwa_brand_normalize_hex($tc, $defaults['theme_color']);
    }
    if ($bc !== '') {
        $defaults['background_color'] = pwa_brand_normalize_hex($bc, $defaults['background_color']);
        $defaults['accent_mid'] = pwa_brand_normalize_hex($bc, $defaults['accent_mid']);
    }

    if ($tc === '' || $bc === '') {
        $brand = kartu_brand_theme_decode((string) app_setting($pdo, 'kartu_brand_theme', ''));
        if (is_array($brand)) {
            if ($tc === '' && isset($brand['grad1'])) {
                $defaults['theme_color'] = pwa_brand_normalize_hex((string) $brand['grad1'], $defaults['theme_color']);
            }
            if ($bc === '' && isset($brand['grad2'])) {
                $defaults['background_color'] = pwa_brand_normalize_hex((string) $brand['grad2'], $defaults['background_color']);
            }
            if (isset($brand['grad3'])) {
                $defaults['accent_mid'] = pwa_brand_normalize_hex((string) $brand['grad3'], $defaults['accent_mid']);
            }
        }
    }

    return $defaults;
}

/** Path relatif ikon PWA (ukuran tertentu), kosong jika belum dibuat. */
function pwa_brand_icon_setting_key(int $size, bool $maskable = false): string
{
    if ($maskable && $size >= 512) {
        return 'pwa_icon_maskable_512';
    }

    return 'pwa_icon_' . $size;
}

function pwa_brand_icon_relative_path(PDO $pdo, int $size, bool $maskable = false): string
{
    $key = pwa_brand_icon_setting_key($size, $maskable);
    $stored = trim((string) app_setting($pdo, $key, ''));
    if ($stored !== '' && is_file(dirname(__DIR__) . '/' . ltrim($stored, '/'))) {
        return $stored;
    }

    return '';
}

/** URL absolut ikon untuk manifest / apple-touch. */
function pwa_brand_icon_absolute_url(PDO $pdo, int $size, bool $maskable = false): string
{
    $rel = pwa_brand_icon_relative_path($pdo, $size, $maskable);
    if ($rel !== '') {
        return app_public_url() . app_url(ltrim($rel, '/'));
    }

    $q = http_build_query([
        'size' => $size,
        'maskable' => $maskable ? '1' : '0',
    ]);

    return app_public_url() . app_url('api/pwa/icon.php?' . $q);
}

/**
 * @return list<array{src:string,sizes:string,type:string,purpose?:string}>
 */
function pwa_brand_manifest_icons(PDO $pdo): array
{
    $mime = 'image/png';
    $entries = [
        [
            'src' => pwa_brand_icon_absolute_url($pdo, 192),
            'sizes' => '192x192',
            'type' => $mime,
            'purpose' => 'any',
        ],
        [
            'src' => pwa_brand_icon_absolute_url($pdo, 512),
            'sizes' => '512x512',
            'type' => $mime,
            'purpose' => 'any',
        ],
        [
            'src' => pwa_brand_icon_absolute_url($pdo, 512, true),
            'sizes' => '512x512',
            'type' => $mime,
            'purpose' => 'maskable',
        ],
    ];

    return $entries;
}

/** @return GdImage|null */
function pwa_brand_load_image(string $absolutePath)
{
    if (!is_file($absolutePath) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $blob = @file_get_contents($absolutePath);
    if ($blob === false || $blob === '') {
        return null;
    }
    $img = @imagecreatefromstring($blob);

    return $img instanceof GdImage ? $img : null;
}

/**
 * Render logo ke kanvas persegi PNG.
 *
 * @param bool $opaqueBackground false = transparan (ikon any); true = warna tema (maskable)
 */
function pwa_brand_render_square_png(
    string $sourceAbsolute,
    int $size,
    string $bgHex,
    float $logoScale = 0.82,
    bool $opaqueBackground = false
): ?string {
    $src = pwa_brand_load_image($sourceAbsolute);
    if ($src === null) {
        return null;
    }
    $src = logo_image_remove_light_background($src);

    $canvas = imagecreatetruecolor($size, $size);
    if ($canvas === false) {
        imagedestroy($src);

        return null;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $clear);

    if ($opaqueBackground) {
        imagealphablending($canvas, true);
        $rgb = kartu_brand_hex_to_rgb(pwa_brand_normalize_hex($bgHex, '#0d9488')) ?? ['r' => 13, 'g' => 148, 'b' => 136];
        $bg = imagecolorallocate($canvas, $rgb['r'], $rgb['g'], $rgb['b']);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    $maxSide = (int) round($size * $logoScale);
    $scale = min($maxSide / max(1, $sw), $maxSide / max(1, $sh));
    $dw = max(1, (int) round($sw * $scale));
    $dh = max(1, (int) round($sh * $scale));
    $dx = (int) round(($size - $dw) / 2);
    $dy = (int) round(($size - $dh) / 2);

    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);
    imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    ob_start();
    imagepng($canvas, null, 6);
    $png = ob_get_clean();
    imagedestroy($canvas);

    return is_string($png) && $png !== '' ? $png : null;
}

/** Buat ikon PWA dari logo pondok + simpan ke uploads & app_settings. */
function pwa_brand_sync_from_logo(PDO $pdo): bool
{
    $logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
    if ($logoPath === '') {
        return false;
    }

    $root = dirname(__DIR__);
    $absolute = $root . '/' . ltrim(str_replace(['\\', '..'], ['/', ''], $logoPath), '/');
    if (!is_file($absolute)) {
        return false;
    }

    if (!function_exists('imagepng')) {
        return false;
    }

    $optimizedRel = logo_image_process_uploaded_logo($absolute);
    if ($optimizedRel !== null) {
        save_setting($pdo, 'logo_path', $optimizedRel);
        $absolute = $root . '/' . ltrim($optimizedRel, '/');
    }

    kartu_brand_sync_from_logo($pdo);
    $theme = app_pwa_theme($pdo);
    $bg = (string) ($theme['background_color'] ?? '#0d9488');
    $tc = (string) ($theme['theme_color'] ?? '#0f766e');
    if (trim((string) app_setting($pdo, 'pwa_theme_color', '')) === '') {
        save_setting($pdo, 'pwa_theme_color', $tc);
    }
    if (trim((string) app_setting($pdo, 'pwa_background_color', '')) === '') {
        save_setting($pdo, 'pwa_background_color', $bg);
    }

    $targetDir = $root . '/uploads/logos';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $stamp = date('YmdHis');
    $map = [
        'pwa_icon_192' => ['size' => 192, 'maskable' => false, 'scale' => 0.88],
        'pwa_icon_512' => ['size' => 512, 'maskable' => false, 'scale' => 0.88],
        'pwa_icon_maskable_512' => ['size' => 512, 'maskable' => true, 'scale' => 0.72],
    ];

    foreach ($map as $settingKey => $cfg) {
        $png = pwa_brand_render_square_png(
            $absolute,
            (int) $cfg['size'],
            $bg,
            (float) $cfg['scale'],
            (bool) $cfg['maskable']
        );
        if ($png === null) {
            continue;
        }
        $suffix = $cfg['maskable'] ? 'maskable' : (string) $cfg['size'];
        $fileName = 'pwa-icon-' . $stamp . '-' . $suffix . '.png';
        $full = $targetDir . '/' . $fileName;
        if (file_put_contents($full, $png) !== false) {
            save_setting($pdo, $settingKey, 'uploads/logos/' . $fileName);
        }
    }

    return true;
}

/** Sinkron tema PWA dari palet kartu (tanpa regenerasi ikon). */
function pwa_brand_sync_theme_from_brand(PDO $pdo): void
{
    $brand = kartu_brand_theme_decode((string) app_setting($pdo, 'kartu_brand_theme', ''));
    if (!is_array($brand)) {
        return;
    }
    if (trim((string) app_setting($pdo, 'pwa_theme_color', '')) === '' && isset($brand['grad1'])) {
        save_setting($pdo, 'pwa_theme_color', pwa_brand_normalize_hex((string) $brand['grad1'], '#0f766e'));
    }
    if (trim((string) app_setting($pdo, 'pwa_background_color', '')) === '' && isset($brand['grad2'])) {
        save_setting($pdo, 'pwa_background_color', pwa_brand_normalize_hex((string) $brand['grad2'], '#0d9488'));
    }
}
