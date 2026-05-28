<?php

declare(strict_types=1);

/**
 * Warna gradien kartu ID — diekstrak dari logo pondok dan disimpan di app_settings.
 */

/** @return array<string, string> */
function kartu_brand_preset_ocean(): array
{
    return kartu_brand_palette_from_hex('#1d4ed8');
}

/** @return array<string, string> */
function kartu_brand_preset_emerald(): array
{
    // Palet hijau gelap khusus tema kartu "Brand Pondok".
    return kartu_brand_palette_from_hex('#0b5d46');
}

function kartu_brand_rgb_to_hex(int $r, int $g, int $b): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

function kartu_brand_hex_to_rgb(string $hex): ?array
{
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (strlen($h) !== 6 || !ctype_xdigit($h)) {
        return null;
    }
    $n = (int) hexdec($h);

    return ['r' => ($n >> 16) & 255, 'g' => ($n >> 8) & 255, 'b' => $n & 255];
}

function kartu_brand_adjust_hex(string $hex, int $amount): string
{
    $rgb = kartu_brand_hex_to_rgb($hex);
    if ($rgb === null) {
        return $hex;
    }

    return kartu_brand_rgb_to_hex($rgb['r'] + $amount, $rgb['g'] + $amount, $rgb['b'] + $amount);
}

/** @return array<string, string> */
function kartu_brand_palette_from_hex(string $baseHex): array
{
    $base = strtolower($baseHex);
    if ($base[0] !== '#') {
        $base = '#' . $base;
    }
    $c1 = kartu_brand_adjust_hex($base, -58);
    $rgb = kartu_brand_hex_to_rgb($c1) ?? ['r' => 30, 'g' => 64, 'b' => 175];

    return [
        'base' => $base,
        'grad1' => $c1,
        'grad2' => kartu_brand_adjust_hex($base, -18),
        'grad3' => kartu_brand_adjust_hex($base, 34),
        'border' => kartu_brand_adjust_hex($base, 72),
        'print_border' => kartu_brand_adjust_hex($base, 56),
        'shadow' => sprintf('rgba(%d,%d,%d,.33)', $rgb['r'], $rgb['g'], $rgb['b']),
    ];
}

/**
 * @return array{r:int,g:int,b:int}|null
 */
function kartu_brand_extract_rgb_from_file(string $absolutePath): ?array
{
    if (!is_file($absolutePath) || !function_exists('imagecreatefromstring')) {
        return null;
    }

    $blob = @file_get_contents($absolutePath);
    if ($blob === false || $blob === '') {
        return null;
    }

    $src = @imagecreatefromstring($blob);
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);

        return null;
    }

    $size = 48;
    $thumb = imagecreatetruecolor($size, $size);
    if ($thumb === false) {
        imagedestroy($src);

        return null;
    }

    imagealphablending($thumb, true);
    imagesavealpha($thumb, true);
    $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
    imagefilledrectangle($thumb, 0, 0, $size, $size, $transparent);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $size, $size, $w, $h);
    imagedestroy($src);

    /** @var list<array{r:int,g:int,b:int,score:float}> $samples */
    $samples = [];

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $rgba = imagecolorat($thumb, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            if ($a > 100) {
                continue;
            }
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $max = max($r, $g, $b);
            $min = min($r, $g, $b);
            $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            if ($lum < 32 || $lum > 232) {
                continue;
            }
            $sat = $max > 0 ? ($max - $min) / $max : 0.0;
            if ($sat < 0.14) {
                continue;
            }
            $score = $sat * (1.0 - abs($lum - 118.0) / 118.0);
            $samples[] = ['r' => $r, 'g' => $g, 'b' => $b, 'score' => $score];
        }
    }

    imagedestroy($thumb);

    if ($samples === []) {
        return null;
    }

    usort($samples, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    $sampleCount = count($samples);
    $take = min($sampleCount, max(3, (int) ceil($sampleCount * 0.22)));
    $sumR = 0;
    $sumG = 0;
    $sumB = 0;
    for ($i = 0; $i < $take; $i++) {
        $sumR += $samples[$i]['r'];
        $sumG += $samples[$i]['g'];
        $sumB += $samples[$i]['b'];
    }

    return [
        'r' => (int) round($sumR / $take),
        'g' => (int) round($sumG / $take),
        'b' => (int) round($sumB / $take),
    ];
}

/** Sinkronkan palet kartu dari file logo di pengaturan pondok. */
function kartu_brand_sync_from_logo(PDO $pdo): bool
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

    $rgb = kartu_brand_extract_rgb_from_file($absolute);
    if ($rgb === null) {
        return false;
    }

    $palette = kartu_brand_palette_from_hex(kartu_brand_rgb_to_hex($rgb['r'], $rgb['g'], $rgb['b']));
    $palette['logo_path'] = $logoPath;
    $palette['updated_at'] = (string) time();

    save_setting($pdo, 'kartu_brand_theme', json_encode($palette, JSON_UNESCAPED_SLASHES));

    return true;
}

/** @return array<string, string>|null */
function kartu_brand_theme_decode(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['grad1'], $decoded['grad2'], $decoded['grad3'])) {
        return null;
    }

    return $decoded;
}

/**
 * Palet gradien kartu untuk tema "Brand Pondok".
 *
 * @return array<string, string>
 */
function kartu_brand_theme_for_cards(PDO $pdo, string $fallbackPreset = 'ocean'): array
{
    // Permintaan UI terbaru: tema "Brand Pondok" selalu dominan hijau gelap.
    return kartu_brand_preset_emerald();
}

/** Atribut style inline agar warna kartu langsung benar sebelum JS. */
function kartu_brand_card_style_attrs(array $theme): string
{
    if (!isset($theme['grad1'], $theme['grad2'], $theme['grad3'])) {
        return '';
    }

    return sprintf(
        ' style="--card-grad-1:%s;--card-grad-2:%s;--card-grad-3:%s;--card-border:%s;--card-print-border:%s;--card-shadow:%s"',
        htmlspecialchars((string) $theme['grad1'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) $theme['grad2'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) $theme['grad3'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) ($theme['border'] ?? '#bfdbfe'), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) ($theme['print_border'] ?? '#93c5fd'), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) ($theme['shadow'] ?? 'rgba(30,64,175,.33)'), ENT_QUOTES, 'UTF-8')
    );
}
