<?php

declare(strict_types=1);

/**
 * Olah logo pondok: hilangkan latar putih/terang, pertahankan transparansi PNG.
 */

/** @return GdImage|null */
function logo_image_load(string $absolutePath)
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

/** Salin ke gambar truecolor dengan channel alpha. */
function logo_image_to_rgba(GdImage $src): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $w, $h, $transparent);
    imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
    imagesavealpha($out, true);

    return $out;
}

/**
 * Jadikan piksel putih / abu sangat terang transparan (untuk JPG tanpa alpha).
 *
 * @return GdImage gambar baru
 */
function logo_image_remove_light_background(GdImage $src, int $tolerance = 48): GdImage
{
    $rgba = logo_image_to_rgba($src);
    $w = imagesx($rgba);
    $h = imagesy($rgba);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgbaPx = imagecolorat($rgba, $x, $y);
            $a = ($rgbaPx >> 24) & 0x7F;
            if ($a > 100) {
                continue;
            }
            $r = ($rgbaPx >> 16) & 0xFF;
            $g = ($rgbaPx >> 8) & 0xFF;
            $b = $rgbaPx & 0xFF;
            $min = min($r, $g, $b);
            $max = max($r, $g, $b);
            $lum = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
            $sat = $max > 0 ? ($max - $min) / $max : 0.0;
            $isNearWhite = $min >= 255 - $tolerance;
            $isPale = $lum >= 248 - (int) ($tolerance * 0.35) && $sat < 0.12;
            if ($isNearWhite || $isPale) {
                imagesetpixel($rgba, $x, $y, imagecolorallocatealpha($rgba, 255, 255, 255, 127));
            }
        }
    }

    imagesavealpha($rgba, true);

    return $rgba;
}

/**
 * Simpan logo sebagai PNG transparan (hilangkan putih). Mengembalikan path absolut baru atau null.
 */
function logo_image_save_transparent_png(string $sourceAbsolute, ?string $destAbsolute = null): ?string
{
    if (!function_exists('imagepng')) {
        return null;
    }
    $src = logo_image_load($sourceAbsolute);
    if ($src === null) {
        return null;
    }
    $clean = logo_image_remove_light_background($src);
    imagedestroy($src);

    if ($destAbsolute !== null) {
        $dest = $destAbsolute;
    } else {
        $dest = preg_replace('/\.(jpe?g|webp)$/i', '.png', $sourceAbsolute);
        if ($dest === $sourceAbsolute) {
            $dest = $sourceAbsolute;
        }
    }

    imagesavealpha($clean, true);
    imagealphablending($clean, false);
    $ok = imagepng($clean, $dest, 6);
    imagedestroy($clean);

    return $ok ? $dest : null;
}

/**
 * Setelah unggah logo: optimasi PNG transparan & kembalikan path relatif baru (uploads/logos/...).
 */
function logo_image_process_uploaded_logo(string $absolutePath, string $uploadsDirRelative = 'uploads/logos'): ?string
{
    $pngPath = logo_image_save_transparent_png($absolutePath);
    if ($pngPath === null || !is_file($pngPath)) {
        return null;
    }

    if ($pngPath !== $absolutePath && is_file($absolutePath)) {
        @unlink($absolutePath);
    }

    $root = dirname(__DIR__);
    $rel = $uploadsDirRelative . '/' . basename($pngPath);

    return str_replace('\\', '/', $rel);
}
