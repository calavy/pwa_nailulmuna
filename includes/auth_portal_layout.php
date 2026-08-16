<?php

declare(strict_types=1);

/**
 * Layout halaman login mandiri (kartu responsif, salam portal).
 */

/** @var bool|null */
$GLOBALS['_auth_portal_split_clean'] = null;

/** @var bool|null */
$GLOBALS['_auth_portal_center_card'] = null;

function auth_portal_layout_is_split_clean(): bool
{
    return !empty($GLOBALS['_auth_portal_split_clean']);
}

function auth_portal_layout_is_center_card(): bool
{
    return !empty($GLOBALS['_auth_portal_center_card']);
}

function auth_portal_salam_waktu(?DateTimeInterface $when = null): string
{
    $tz = new DateTimeZone('Asia/Jakarta');
    $dt = $when instanceof DateTimeInterface
        ? (new DateTime($when->format('Y-m-d H:i:s'), $tz))
        : new DateTime('now', $tz);
    $hour = (int) $dt->format('G');
    if ($hour >= 3 && $hour < 11) {
        return 'Selamat pagi';
    }
    if ($hour >= 11 && $hour < 15) {
        return 'Selamat siang';
    }
    if ($hour >= 15 && $hour < 18) {
        return 'Selamat sore';
    }

    return 'Selamat malam';
}

/** Salam pembuka standar untuk halaman masuk. */
function auth_portal_salam_islami(): string
{
    return 'Assalamu\'alaikum warahmatullahi wabarakatuh';
}

/** Teks sambutan formal portal — per paragraf agar mudah dibaca sekaligus. */
function auth_portal_formal_body_paragraphs(): array
{
    return [
        'Selamat datang di portal resmi A.P.I Nailul Muna, silahkan masuk dengan akun yang telah diberikan.',
    ];
}

function auth_portal_formal_body(): string
{
    return implode(' ', auth_portal_formal_body_paragraphs());
}

/**
 * URL logo pondok untuk halaman portal (path relatif / eksternal).
 */
function auth_portal_logo_href(PDO $pdo, string $override = ''): string
{
    require_once __DIR__ . '/../helpers/app.php';
    require_once __DIR__ . '/../helpers/app_path.php';

    $raw = trim($override);
    if ($raw === '') {
        $raw = app_pondok_logo_src($pdo);
    }
    if ($raw === '') {
        return app_href(app_pwa_default_icon_src());
    }
    if (preg_match('#^https?://#i', $raw)) {
        return $raw;
    }

    return app_href('/' . ltrim($raw, '/'));
}

/**
 * Data kop portal login: logo, nama, jenis, alamat.
 *
 * @return array{logo_url:string,nama:string,jenis:string,alamat:string,initials:string}
 */
function auth_portal_kop_context(PDO $pdo, string $logoUrl = ''): array
{
    require_once __DIR__ . '/../helpers/app.php';
    $nama = auth_portal_brand_nama($pdo);
    $jenis = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
    $alamat = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
    $lettersOnly = preg_replace('/[^A-Za-z]/u', '', $nama);

    return [
        'logo_url' => auth_portal_logo_href($pdo, $logoUrl),
        'nama' => $nama,
        'jenis' => $jenis,
        'alamat' => $alamat,
        'initials' => strtoupper(substr($lettersOnly !== '' ? $lettersOnly : 'AP', 0, 2)),
    ];
}

/**
 * @return array{salam:string,salam_waktu:string,tagline:string,tagline_portal:string,ponpes:string}
 */
function auth_portal_welcome_copy(PDO $pdo): array
{
    require_once __DIR__ . '/../helpers/app.php';
    $ponpes = app_brand_nama_ponpes($pdo, 'A.P.I Nailul Muna');
    $waktu = auth_portal_salam_waktu();

    return [
        'salam' => '',
        'salam_waktu' => '',
        'tagline' => 'Silakan pilih peran lalu masuk dengan akun yang telah diberikan.',
        'tagline_portal' => 'Portal ' . $ponpes,
        'ponpes' => $ponpes,
    ];
}

/** Nama pondok untuk tampilan hero (satu sumber, tanpa duplikat). */
function auth_portal_brand_nama(PDO $pdo): string
{
    require_once __DIR__ . '/../helpers/app.php';

    return app_brand_nama_ponpes($pdo, 'A.P.I Nailul Muna');
}

/**
 * @param array{
 *   title: string,
 *   subtitle?: string,
 *   kicker?: string,
 *   nama_ponpes?: string,
 *   logo_url?: string,
 *   welcome?: string,
 *   welcome_tagline?: string,
 *   welcome_salam?: string,
 *   welcome_salam_waktu?: string,
 *   headline?: string,
 *   formal_body?: string,
 *   card_title?: string,
 *   card_meta?: string,
 *   subtitle_mobile?: string,
 *   subtitle_desktop?: string,
 *   max_width?: string,
 *   layout?: 'stack'|'split'|'split_clean'|'center_card',
 *   card_style?: 'default'|'minimal'|'center',
 *   card_subtitle?: string,
 *   show_alt_nav?: bool,
 *   shell_mod?: 'default'|'wali'|'pb_scan',
 *   accent?: 'teal'|'indigo'
 * } $ctx
 */
function auth_portal_layout_begin(array $ctx): void
{
    $titleRaw = (string) ($ctx['title'] ?? 'Login');
    $title = htmlspecialchars($titleRaw);
    $welcomeSalam = isset($ctx['welcome_salam'])
        ? htmlspecialchars((string) $ctx['welcome_salam'])
        : (isset($ctx['welcome']) ? htmlspecialchars((string) $ctx['welcome']) : '');
    $welcomeSalamWaktu = isset($ctx['welcome_salam_waktu'])
        ? htmlspecialchars((string) $ctx['welcome_salam_waktu'])
        : '';
    $welcomeTagline = isset($ctx['welcome_tagline']) ? htmlspecialchars((string) $ctx['welcome_tagline']) : '';
    $headlineRaw = trim((string) ($ctx['headline'] ?? ''));
    $headlineHtml = $headlineRaw !== '' ? htmlspecialchars($headlineRaw) : '';
    $formalBodyRaw = trim((string) ($ctx['formal_body'] ?? ''));
    $formalParagraphs = [];
    if ($formalBodyRaw !== '') {
        $formalParagraphs = auth_portal_formal_body_paragraphs();
    }
    $kopCtx = ['logo_url' => '', 'nama' => '', 'jenis' => '', 'alamat' => '', 'initials' => 'AP'];
    $subtitleFallback = trim((string) ($ctx['subtitle'] ?? ''));
    $subtitleMobile = trim((string) ($ctx['subtitle_mobile'] ?? $subtitleFallback));
    $subtitleDesktop = trim((string) ($ctx['subtitle_desktop'] ?? $subtitleFallback));
    $subtitleMobileHtml = $subtitleMobile !== '' ? htmlspecialchars($subtitleMobile) : '';
    $subtitleDesktopHtml = $subtitleDesktop !== '' ? htmlspecialchars($subtitleDesktop) : '';
    $kickerRaw = trim((string) ($ctx['kicker'] ?? ''));
    $namaPonpesRaw = trim((string) ($ctx['nama_ponpes'] ?? ''));
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        require_once __DIR__ . '/../helpers/app.php';
        app_settings_cache($pdo, true);
        if ($kickerRaw === '') {
            $kickerRaw = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
        }
        if ($namaPonpesRaw === '') {
            $namaPonpesRaw = auth_portal_brand_nama($pdo);
        }
    }
    $kicker = $kickerRaw !== '' ? htmlspecialchars($kickerRaw) : '';
    $namaPonpes = $namaPonpesRaw !== '' ? htmlspecialchars($namaPonpesRaw) : '';
    $lettersOnly = preg_replace('/[^A-Za-z]/u', '', $namaPonpesRaw);
    $initials = strtoupper(substr($lettersOnly !== '' ? $lettersOnly : 'AP', 0, 2));
    $logoUrl = trim((string) ($ctx['logo_url'] ?? ''));
    if (isset($pdo) && $pdo instanceof PDO) {
        app_settings_cache($pdo, true);
        $kopCtx = auth_portal_kop_context($pdo, $logoUrl);
        $logoUrl = (string) ($kopCtx['logo_url'] ?? $logoUrl);
    }
    $kopAlamatRaw = trim((string) ($kopCtx['alamat'] ?? ''));
    $kopNama = htmlspecialchars((string) ($kopCtx['nama'] ?? ''));
    $kopJenis = htmlspecialchars((string) ($kopCtx['jenis'] ?? ''));
    $kopAlamat = $kopAlamatRaw !== '' ? nl2br(htmlspecialchars($kopAlamatRaw, ENT_QUOTES, 'UTF-8')) : '';
    $kopInitials = htmlspecialchars((string) ($kopCtx['initials'] ?? 'AP'));
    $layoutRaw = (string) ($ctx['layout'] ?? 'stack');
    $allowedLayouts = ['split', 'split_clean', 'center_card'];
    $layout = in_array($layoutRaw, $allowedLayouts, true) ? $layoutRaw : 'stack';
    $isSplitClean = $layout === 'split_clean';
    $isCenterCard = $layout === 'center_card';
    $GLOBALS['_auth_portal_split_clean'] = $isSplitClean;
    $GLOBALS['_auth_portal_center_card'] = $isCenterCard;
    $cardStyleRaw = (string) ($ctx['card_style'] ?? 'default');
    $cardStyle = $cardStyleRaw === 'center' ? 'center' : (($cardStyleRaw === 'minimal') ? 'minimal' : 'default');
    $showAltNav = !empty($ctx['show_alt_nav']);
    $shellClass = 'auth-portal-shell';
    if ($isCenterCard) {
        $shellClass .= ' auth-portal-shell--center-card';
    } elseif ($isSplitClean) {
        $shellClass .= ' auth-portal-shell--split-clean';
    } elseif ($layout === 'split') {
        $shellClass .= ' auth-portal-shell--wide';
    } else {
        $shellClass .= ' auth-portal-shell--narrow';
    }
    $shellMod = (string) ($ctx['shell_mod'] ?? '');
    if ($shellMod === 'wali') {
        $shellClass .= ' auth-portal-shell--wali';
    } elseif ($shellMod === 'pb_scan') {
        $shellClass .= ' auth-portal-shell--pb-scan';
    }
    $bodyClass = 'auth-portal-page';
    if ($shellMod === 'pb_scan') {
        $bodyClass .= ' auth-portal-page--pb-scan';
    } elseif ($isCenterCard) {
        $bodyClass .= ' auth-portal-page--center-card';
    } elseif ($isSplitClean) {
        $bodyClass .= ' auth-portal-page--split-clean';
    }
    $kopClass = 'auth-portal-kop' . ($isSplitClean ? ' auth-portal-kop--flat' : '');
    $cardClass = 'auth-portal-card';
    if ($cardStyle === 'center') {
        $cardClass .= ' auth-portal-card--center';
    } elseif ($cardStyle === 'minimal') {
        $cardClass .= ' auth-portal-card--minimal';
    }
    $cardSubtitle = trim((string) ($ctx['card_subtitle'] ?? ''));
    $cardSubtitleHtml = $cardSubtitle !== '' ? htmlspecialchars($cardSubtitle) : '';
    $accent = ($ctx['accent'] ?? 'teal') === 'indigo' ? 'indigo' : 'teal';
    $gradStart = $accent === 'indigo' ? '#312e81' : '#0f766e';
    $gradMid = $accent === 'indigo' ? '#4338ca' : '#0d9488';
    $gradEnd = $accent === 'indigo' ? '#6366f1' : '#0891b2';
    $accentHex = $accent === 'indigo' ? '#4f46e5' : '#0f766e';
    $accentDark = $accent === 'indigo' ? '#3730a3' : '#115e59';
    $accentMid = $accent === 'indigo' ? '#6366f1' : '#0891b2';
    $cardTitle = isset($ctx['card_title']) ? htmlspecialchars((string) $ctx['card_title']) : $title;
    $cardMeta = isset($ctx['card_meta']) ? htmlspecialchars((string) $ctx['card_meta']) : '';
    $cssHref = function_exists('app_asset_href')
        ? app_asset_href('/assets/css/auth-portal.css')
        : (function_exists('app_href') ? app_href('/assets/css/auth-portal.css') : '/assets/css/auth-portal.css');
    $manifestHref = function_exists('app_href') ? app_href('/manifest.php') : '/manifest.php';
    $pwaTheme = function_exists('app_pwa_theme') ? app_pwa_theme(isset($pdo) && $pdo instanceof PDO ? $pdo : null) : [
        'theme_color' => $accentHex,
        'background_color' => '#0d9488',
    ];
    $logoFallbackHref = function_exists('app_href')
        ? app_href(app_pwa_default_icon_src())
        : app_pwa_default_icon_src();
    $iconHref = function_exists('app_pwa_icon_href') && isset($pdo) && $pdo instanceof PDO
        ? app_pwa_icon_href($pdo)
        : ($logoUrl !== '' ? $logoUrl : $logoFallbackHref);
    $splashBgHref = function_exists('app_href') ? app_href('/assets/img/pwa-splash-bg.svg') : '/assets/img/pwa-splash-bg.svg';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="<?= htmlspecialchars((string) ($pwaTheme['theme_color'] ?? $accentHex)) ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="robots" content="noindex, nofollow">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($namaPonpesRaw !== '' ? $namaPonpesRaw : 'Nailul Muna') ?>">
    <title><?= $title ?></title>
    <link rel="manifest" href="<?= htmlspecialchars($manifestHref) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($iconHref) ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= htmlspecialchars($iconHref) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($iconHref) ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars($iconHref) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet"></noscript>
    <?php require __DIR__ . '/partials/app_vendor_assets.php'; ?>
    <?php if ($logoUrl !== ''): ?>
    <meta name="pondok-pwa-logo" content="<?= htmlspecialchars($logoUrl) ?>">
    <?php endif; ?>
    <meta name="pondok-pwa-logo-fallback" content="<?= htmlspecialchars($logoFallbackHref) ?>">
    <link rel="preload" href="<?= htmlspecialchars($cssHref) ?>" as="style">
    <link href="<?= htmlspecialchars($cssHref) ?>" rel="stylesheet">
    <style>
        :root {
            --ap-auth-accent: <?= $accentHex ?>;
            --ap-auth-accent-dark: <?= $accentDark ?>;
            --ap-auth-accent-mid: <?= $accentMid ?>;
            --ap-auth-surface: rgba(255, 255, 255, 0.94);
        }
        [data-theme="dark"] {
            --ap-auth-surface: rgba(30, 41, 59, 0.96);
        }
        html {
            background-color: <?= htmlspecialchars((string) ($pwaTheme['background_color'] ?? $gradMid)) ?>;
        }
        body.auth-portal-page {
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            min-height: 100dvh;
            margin: 0;
            background: linear-gradient(145deg, <?= htmlspecialchars($gradStart) ?> 0%, <?= htmlspecialchars($gradMid) ?> 42%, <?= htmlspecialchars($gradEnd) ?> 100%);
            background-attachment: fixed;
            background-color: <?= htmlspecialchars((string) ($pwaTheme['background_color'] ?? $gradMid)) ?>;
            padding: max(1rem, env(safe-area-inset-top, 0px)) max(0.85rem, env(safe-area-inset-right, 0px)) max(1.25rem, env(safe-area-inset-bottom, 0px)) max(0.85rem, env(safe-area-inset-left, 0px));
        }
        body.auth-portal-page--split-clean {
            padding: 0;
            background: <?= htmlspecialchars($gradStart) ?>;
        }
        body.auth-portal-page--center-card {
            padding: max(1.25rem, env(safe-area-inset-top, 0px)) max(1rem, env(safe-area-inset-right, 0px)) max(1.25rem, env(safe-area-inset-bottom, 0px)) max(1rem, env(safe-area-inset-left, 0px));
        }
        @media (min-width: 992px) {
            body.auth-portal-page {
                padding: max(1.5rem, env(safe-area-inset-top, 0px)) max(1.25rem, env(safe-area-inset-right, 0px)) max(1.5rem, env(safe-area-inset-bottom, 0px)) max(1.25rem, env(safe-area-inset-left, 0px));
            }
            body.auth-portal-page--split-clean {
                padding: 0;
            }
            body.auth-portal-page--center-card {
                padding: max(2rem, env(safe-area-inset-top, 0px)) max(1.5rem, env(safe-area-inset-right, 0px)) max(2rem, env(safe-area-inset-bottom, 0px)) max(1.5rem, env(safe-area-inset-left, 0px));
            }
        }
        .btn-auth-primary {
            background: var(--ap-auth-accent) !important;
            border-color: var(--ap-auth-accent) !important;
            color: #fff !important;
            font-weight: 600;
            border-radius: 0.75rem;
            padding-block: 0.65rem;
        }
        .btn-auth-primary:hover {
            filter: brightness(1.06);
            color: #fff !important;
        }
        .auth-portal-links a {
            color: rgba(255, 255, 255, 0.95);
            text-decoration: underline;
            text-underline-offset: 3px;
            font-size: 0.88rem;
        }
        .auth-portal-links a:hover { color: #fff; }
    </style>
    <script>
        (function () {
            try {
                var m = localStorage.getItem('theme-mode') === 'dark' ? 'dark' : 'light';
                var d = document.documentElement;
                d.setAttribute('data-theme', m);
                d.style.colorScheme = m;
                d.style.backgroundColor = m === 'dark' ? '#0f172a' : <?= json_encode($gradMid, JSON_UNESCAPED_UNICODE) ?>;
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="auth-portal-bg<?= $isSplitClean ? ' auth-portal-bg--clean' : '' ?><?= $isCenterCard ? ' auth-portal-bg--center-card' : '' ?>" aria-hidden="true">
        <span class="auth-portal-bg__orb auth-portal-bg__orb--1"></span>
        <span class="auth-portal-bg__orb auth-portal-bg__orb--2"></span>
        <span class="auth-portal-bg__grid"></span>
    </div>
    <div class="auth-portal-viewport">
    <?php if ($showAltNav): ?>
        <?php require __DIR__ . '/partials/auth_portal_topnav.php'; ?>
    <?php endif; ?>
    <div class="<?= htmlspecialchars($shellClass) ?>">
        <?php if ($isSplitClean): ?><div class="auth-portal-split"><div class="auth-portal-split__brand"><?php endif; ?>
        <?php if (!$isCenterCard): ?>
        <header class="auth-portal-hero">
            <div class="<?= htmlspecialchars($kopClass) ?>">
                <div class="auth-portal-kop__logo">
                    <div class="logo-ring logo-ring--round<?= $logoUrl === '' ? ' logo-ring--fallback' : '' ?>">
                        <?php if ($logoUrl !== ''): ?>
                            <img
                                src="<?= htmlspecialchars($logoUrl) ?>"
                                alt="Logo <?= $kopNama ?>"
                                class="auth-portal-logo-img"
                                decoding="async"
                                fetchpriority="high"
                                data-pondok-cache="1"
                                data-fallback-src="<?= htmlspecialchars($logoFallbackHref) ?>"
                            >
                        <?php else: ?>
                            <span class="logo-fallback"><?= $kopInitials ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="auth-portal-kop__teks">
                    <?php if ($kopJenis !== ''): ?>
                        <p class="auth-portal-kop__jenis mb-0"><?= $kopJenis ?></p>
                    <?php elseif ($kicker !== ''): ?>
                        <p class="auth-portal-kop__jenis mb-0"><?= $kicker ?></p>
                    <?php endif; ?>
                    <p class="auth-portal-kop__nama mb-0"><?= $kopNama !== '' ? $kopNama : ($namaPonpes !== '' ? $namaPonpes : 'Pondok Pesantren') ?></p>
                    <?php if ($kopAlamatRaw !== ''): ?>
                        <p class="auth-portal-kop__alamat mb-0"><i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i><?= $kopAlamat ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="auth-portal-hero-text">
                <?php if ($welcomeSalam !== ''): ?>
                    <p class="auth-portal-salam auth-portal-salam--utama"><?= $welcomeSalam ?></p>
                <?php endif; ?>
                <?php if ($headlineHtml !== ''): ?>
                    <h1 class="auth-portal-headline"><?= $headlineHtml ?></h1>
                <?php elseif ($welcomeSalam === '' && $headlineHtml === '' && $title !== '' && $layout === 'stack'): ?>
                    <h1 class="auth-portal-headline"><?= $title ?></h1>
                <?php endif; ?>
                <?php if ($welcomeSalamWaktu !== ''): ?>
                    <p class="auth-portal-salam-waktu"><?= $welcomeSalamWaktu ?></p>
                <?php endif; ?>
                <?php if ($formalParagraphs !== []): ?>
                    <div class="auth-portal-formal-body">
                        <?php foreach ($formalParagraphs as $para): ?>
                            <p><?= htmlspecialchars($para) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($welcomeTagline !== ''): ?>
                    <p class="auth-portal-tagline"><?= $welcomeTagline ?></p>
                <?php endif; ?>
                <?php if ($subtitleMobileHtml !== '' || $subtitleDesktopHtml !== ''): ?>
                    <p class="auth-portal-pintu-hint">
                        <?php if ($subtitleMobileHtml !== ''): ?>
                            <span class="auth-portal-sub-line auth-portal-sub-line--mobile"><?= $subtitleMobileHtml ?></span>
                        <?php endif; ?>
                        <?php if ($subtitleDesktopHtml !== ''): ?>
                            <span class="auth-portal-sub-line auth-portal-sub-line--desktop"><?= $subtitleDesktopHtml ?></span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </header>
        <?php endif; ?>
        <?php if ($isSplitClean): ?></div><div class="auth-portal-split__form"><?php endif; ?>
        <div class="<?= htmlspecialchars($cardClass) ?>">
            <?php if ($cardStyle !== 'minimal' && $cardStyle !== 'center'): ?>
            <div class="auth-portal-card__head">
                <p class="auth-portal-card__head-title mb-0">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    <?= $cardTitle ?>
                </p>
                <?php if ($cardMeta !== ''): ?>
                    <p class="auth-portal-card__head-meta"><?= $cardMeta ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="auth-portal-card__body">
            <?php if ($cardStyle === 'center'): ?>
                <div class="auth-portal-center-head">
                    <div class="auth-portal-center-head__logo">
                        <div class="auth-portal-center-head__logo-box<?= $logoUrl === '' ? ' auth-portal-center-head__logo-box--fallback' : '' ?>">
                            <?php if ($logoUrl !== ''): ?>
                                <img
                                    src="<?= htmlspecialchars($logoUrl) ?>"
                                    alt="Logo <?= $kopNama ?>"
                                    class="auth-portal-center-head__logo-img"
                                    decoding="async"
                                    fetchpriority="high"
                                    data-pondok-cache="1"
                                    data-fallback-src="<?= htmlspecialchars($logoFallbackHref) ?>"
                                >
                            <?php else: ?>
                                <span class="auth-portal-center-head__logo-fallback"><?= $kopInitials ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <h1 class="auth-portal-center-head__title"><?= $kopNama !== '' ? $kopNama : ($namaPonpes !== '' ? $namaPonpes : 'Pondok Pesantren') ?></h1>
                    <?php if ($kopAlamatRaw !== ''): ?>
                        <p class="auth-portal-center-head__alamat mb-0">
                            <i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i><?= $kopAlamat ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($cardSubtitleHtml !== ''): ?>
                        <p class="auth-portal-center-head__subtitle"><?= $cardSubtitleHtml ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif ($cardStyle === 'minimal'): ?>
                <h2 class="auth-portal-form-title"><?= $cardTitle ?></h2>
                <?php if ($cardMeta !== ''): ?>
                    <p class="auth-portal-form-meta"><?= $cardMeta ?></p>
                <?php endif; ?>
            <?php endif; ?>
    <?php
}

/**
 * @param list<array{href:string,label:string}> $footerLinks
 */
function auth_portal_layout_end(array $footerLinks = [], bool $enableFcm = false): void
{
    ?>
            </div>
        </div>
        <?php if (auth_portal_layout_is_split_clean()): ?>
        </div></div>
        <?php endif; ?>
        <?php if ($footerLinks !== []): ?>
            <nav class="auth-portal-links text-center mt-2 d-flex flex-wrap justify-content-center gap-3" aria-label="Tautan portal">
                <?php foreach ($footerLinks as $lnk): ?>
                    <?php
                    $footerHref = (string) ($lnk['href'] ?? '#');
                    $footerHrefOut = function_exists('app_href') ? app_href($footerHref) : $footerHref;
                    ?>
                    <a href="<?= htmlspecialchars($footerHrefOut) ?>"><?= htmlspecialchars((string) ($lnk['label'] ?? '')) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
    </div>
    <?php require_once __DIR__ . '/../helpers/app_vendor.php'; ?>
    <script src="<?= htmlspecialchars(app_vendor_bootstrap_js_href()) ?>" defer crossorigin="anonymous"></script>
    <?php if (function_exists('app_asset_href')): ?>
    <script>window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/theme-mode.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-media-cache.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-register.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/app-shell.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if ($enableFcm && (isset($_SESSION['santri_portal']) || isset($_SESSION['wali']))): ?>
    <?php require_once __DIR__ . '/partials/push_fcm_bootstrap.php'; ?>
    <?php endif; ?>
</body>
</html>
    <?php
}

/**
 * Render satu tombol peran login.
 *
 * @param array{href:string,icon:string,icon_mod?:string,title:string,desc:string,full?:bool} $role
 */
function auth_portal_role_link(array $role): void
{
    $href = htmlspecialchars((string) ($role['href'] ?? '#'));
    $icon = htmlspecialchars((string) ($role['icon'] ?? 'fa-circle'));
    $iconMod = htmlspecialchars((string) ($role['icon_mod'] ?? 'pengurus'));
    $title = htmlspecialchars((string) ($role['title'] ?? ''));
    $desc = htmlspecialchars((string) ($role['desc'] ?? ''));
    $fullClass = !empty($role['full']) ? ' auth-portal-role--full auth-portal-role--full-sm' : '';
    ?>
    <a class="auth-portal-role<?= $fullClass ?>" href="<?= $href ?>">
        <span class="auth-portal-role__icon auth-portal-role__icon--<?= $iconMod ?>" aria-hidden="true">
            <i class="fa-solid <?= $icon ?>"></i>
        </span>
        <span class="auth-portal-role__text">
            <strong><?= $title ?></strong>
            <span><?= $desc ?></span>
        </span>
        <span class="auth-portal-role__go" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
    </a>
    <?php
}
