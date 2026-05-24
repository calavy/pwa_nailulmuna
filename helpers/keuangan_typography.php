<?php

declare(strict_types=1);

function keuangan_format_rupiah(int $nominal): string
{
    $prefix = $nominal < 0 ? '-Rp ' : 'Rp ';

    return $prefix . number_format(abs($nominal), 0, ',', '.');
}

/** Font stack modul keuangan — sama dengan --font-sans di app.css */
function keuangan_font_family(): string
{
    return '"Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif';
}

/** Tag link Google Fonts untuk halaman cetak mandiri (tanpa header). */
function keuangan_typography_font_links(): string
{
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">';
}

/** CSS dasar untuk halaman cetak/PDF laporan keuangan. */
function keuangan_typography_print_css(): string
{
    $font = keuangan_font_family();

    return '
        body {
            font-family: ' . $font . ';
            font-size: 10.5pt;
            line-height: 1.5;
            color: #0f172a;
            margin: 12px 16px;
            -webkit-font-smoothing: antialiased;
        }
        @media print { .noprint { display: none !important; } }
    ';
}

/**
 * Gabungkan kelas body modul keuangan.
 */
function keuangan_body_class(string ...$extra): string
{
    $parts = ['keuangan-module'];
    foreach ($extra as $c) {
        $c = trim($c);
        if ($c !== '' && !in_array($c, $parts, true)) {
            $parts[] = $c;
        }
    }

    return implode(' ', $parts);
}

/** Apakah memuat assets/css/keuangan.css dari header. */
function keuangan_should_load_typography_css(?string $bodyClass, string $requestPath): bool
{
    if ($bodyClass !== null && $bodyClass !== '') {
        if (preg_match('/\b(keuangan|neraca|aruskas|pembayaran|inventaris|bendahara)-/i', $bodyClass)) {
            return true;
        }
        if (str_contains($bodyClass, 'keuangan-module')) {
            return true;
        }
    }

    return (bool) preg_match('#/(keuangan|pembayaran)(/|$)#', $requestPath);
}
