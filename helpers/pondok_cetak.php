<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/app_path.php';

/** Nama ketua yayasan untuk kop surat — selalu dari struktur SDM yayasan. */
function pondok_ketua_yayasan_nama(PDO $pdo): string
{
    if (!function_exists('yayasan_ketua_yayasan_nama')) {
        require_once __DIR__ . '/yayasan.php';
    }

    return yayasan_ketua_yayasan_nama($pdo);
}

/** Data kop surat pondok (logo, nama, alamat, kontak). */
function pondok_kop_data(PDO $pdo): array
{
    if (!function_exists('surat_cetak_kop_accent_color')) {
        require_once __DIR__ . '/surat_cetak_templates.php';
    }
    $logoHref = app_pondok_logo_href($pdo, false);
    $kop = [
        'nama_ponpes' => trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren')),
        'jenis_pendidikan' => trim((string) app_setting($pdo, 'jenis_pendidikan', '')),
        'alamat_ponpes' => trim((string) app_setting($pdo, 'alamat_ponpes', '')),
        'telp_ponpes' => trim((string) app_setting($pdo, 'telp_ponpes', '')),
        'website_ponpes' => trim((string) app_setting($pdo, 'website_ponpes', '')),
        'kota_ponpes' => trim((string) app_setting($pdo, 'kota_ponpes', 'Muntilan')) ?: 'Muntilan',
        'logo' => app_pondok_logo_src($pdo),
        'logo_href' => $logoHref,
        'nama_pengasuh' => trim((string) app_setting($pdo, 'nama_pengasuh', '')),
        'nama_ketua_yayasan' => pondok_ketua_yayasan_nama($pdo),
        'kop_accent_color' => surat_cetak_kop_accent_color($pdo),
    ];
    $kop['jenis_label'] = surat_cetak_kop_jenis_label($pdo, $kop);

    return $kop;
}

/** Baris kontak kop (telp / website). */
function pondok_kop_contact_line(array $kop): string
{
    $telp = trim((string) ($kop['telp_ponpes'] ?? ''));
    $web = trim((string) ($kop['website_ponpes'] ?? ''));
    $parts = [];
    if ($telp !== '') {
        $parts[] = 'Telp: ' . $telp;
    }
    if ($web !== '') {
        $parts[] = 'Website: ' . $web;
    }

    return implode(' | ', $parts);
}

/**
 * CSS kop surat resmi (seragam di semua surat cetak).
 * Mendukung class .pondok-kop dan alias lama (.header, .print-kop-*).
 */
function pondok_kop_surat_css(?string $accentColor = null, string $logoUrl = ''): string
{
    $accent = $accentColor !== null && $accentColor !== '' ? $accentColor : '#065f46';
    $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES);

    return <<<CSS
        .pondok-kop, .header, .print-kop-row {
            display: flex;
            gap: 10px;
            align-items: center;
            border-bottom: 2px solid var(--kop-accent, {$accent});
            padding-bottom: 8px;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .pondok-kop::after, .header::after, .print-kop-row::after {
            content: "";
            display: block;
            position: absolute;
            bottom: -6px;
            left: 0;
            right: 0;
            border-bottom: 1px solid #334155;
        }
        .pondok-kop__logo, .header .logo, .print-kop-logo {
            width: 52px;
            height: 52px;
            min-width: 52px;
            min-height: 52px;
            object-fit: contain;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            background: #fff;
        }
        .pondok-kop__brand, .header .brand, .print-kop-brand {
            flex: 1;
            text-align: center;
        }
        .pondok-kop__jenis, .header .brand .small, .print-kop-small {
            margin: 0;
            font-size: 8.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.35px;
            color: #0f172a;
            line-height: 1.25;
        }
        .pondok-kop__nama, .header .brand h2, .print-kop-title {
            margin: 0;
            font-size: 13.5pt;
            color: #065f46;
            font-weight: 800;
            text-transform: uppercase;
            line-height: 1.12;
            letter-spacing: 0.02em;
        }
        .pondok-kop__alamat, .header .brand .addr, .print-kop-addr {
            margin: 0;
            font-size: 8pt;
            font-style: italic;
            color: #334155;
            line-height: 1.35;
        }
        .pondok-kop__kontak, .header .brand .contact, .print-kop-contact {
            margin: 2px 0 0;
            font-size: 7.5pt;
            color: #475569;
            line-height: 1.3;
        }
        .print-kop-meta {
            margin: 8px 0 4px;
            font-size: 7.3pt;
            color: #64748b;
            font-style: italic;
            text-align: right;
        }
        .sheet--kop-watermark::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-28deg);
            width: 260px;
            height: 260px;
            background-image: url("{$logoEsc}");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.04;
            z-index: 0;
            pointer-events: none;
        }
CSS;
}

/** HTML blok kop surat (class pondok-kop). */
function pondok_kop_surat_html(array $kop, ?string $accentColor = null): string
{
    $jenisLabel = trim((string) ($kop['jenis_label'] ?? ''));
    if ($jenisLabel === '') {
        $jenis = trim((string) ($kop['jenis_pendidikan'] ?? ''));
        $jenisLabel = $jenis !== '' ? $jenis : 'Lembaga Pondok Pesantren';
    }
    $nama = trim((string) ($kop['nama_ponpes'] ?? 'Pondok Pesantren'));
    $alamat = trim((string) ($kop['alamat_ponpes'] ?? ''));
    $kontak = pondok_kop_contact_line($kop);
    $logo = trim((string) ($kop['logo_href'] ?? ''));
    $style = $accentColor !== null && $accentColor !== ''
        ? ' style="--kop-accent:' . htmlspecialchars($accentColor, ENT_QUOTES) . '"'
        : '';

    ob_start();
    ?>
    <div class="pondok-kop"<?= $style ?>>
        <?php if ($logo !== ''): ?>
            <img src="<?= htmlspecialchars($logo) ?>" alt="Logo" class="pondok-kop__logo">
        <?php endif; ?>
        <div class="pondok-kop__brand">
            <p class="pondok-kop__jenis"><?= htmlspecialchars($jenisLabel) ?></p>
            <h2 class="pondok-kop__nama"><?= htmlspecialchars($nama) ?></h2>
            <?php if ($alamat !== ''): ?>
                <p class="pondok-kop__alamat"><?= htmlspecialchars($alamat) ?></p>
            <?php endif; ?>
            <?php if ($kontak !== ''): ?>
                <p class="pondok-kop__kontak"><?= htmlspecialchars($kontak) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** Blok kop untuk halaman rekap cetak (class print-kop). */
function pondok_kop_print_block_html(array $kop, string $metaLine = ''): string
{
    $html = '<div class="print-kop print-header">' . pondok_kop_surat_html($kop);
    if (trim($metaLine) !== '') {
        $html .= '<p class="print-kop-meta">' . htmlspecialchars($metaLine) . '</p>';
    }
    $html .= '</div>';

    return $html;
}
