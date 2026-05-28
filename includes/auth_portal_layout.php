<?php

declare(strict_types=1);

/**
 * Layout halaman login mandiri (kartu responsif, salam portal).
 */

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

/**
 * @return array{salam:string,salam_waktu:string,tagline:string,tagline_portal:string,ponpes:string}
 */
function auth_portal_welcome_copy(PDO $pdo): array
{
    require_once __DIR__ . '/../helpers/app.php';
    $ponpes = app_brand_nama_ponpes($pdo, 'A.P.I Nailul Muna');
    $waktu = auth_portal_salam_waktu();

    return [
        'salam' => auth_portal_salam_islami(),
        'salam_waktu' => $waktu . ' — semoga Allah mudahkan urusan kita.',
        'tagline' => 'Barakallahu fiikum. Silakan pilih peran lalu masuk dengan akun yang telah diberikan.',
        'tagline_portal' => 'Portal resmi ' . $ponpes,
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
 *   card_title?: string,
 *   card_meta?: string,
 *   subtitle_mobile?: string,
 *   subtitle_desktop?: string,
 *   max_width?: string,
 *   layout?: 'stack'|'split',
 *   shell_mod?: 'default'|'wali',
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
    $layout = ($ctx['layout'] ?? 'stack') === 'split' ? 'split' : 'stack';
    $shellClass = 'auth-portal-shell';
    $shellClass .= $layout === 'split' ? ' auth-portal-shell--wide' : ' auth-portal-shell--narrow';
    if (($ctx['shell_mod'] ?? '') === 'wali') {
        $shellClass .= ' auth-portal-shell--wali';
    }
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
    $iconHref = $logoUrl !== ''
        ? $logoUrl
        : (function_exists('app_href') ? app_href('/assets/img/stempel-pondok.png') : '/assets/img/stempel-pondok.png');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="<?= htmlspecialchars($accentHex) ?>">
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" crossorigin="anonymous">
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
        body.auth-portal-page {
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            min-height: 100dvh;
            margin: 0;
            background: linear-gradient(145deg, <?= htmlspecialchars($gradStart) ?> 0%, <?= htmlspecialchars($gradMid) ?> 42%, <?= htmlspecialchars($gradEnd) ?> 100%);
            background-attachment: fixed;
            padding: max(1rem, env(safe-area-inset-top, 0px)) max(0.85rem, env(safe-area-inset-right, 0px)) max(1.25rem, env(safe-area-inset-bottom, 0px)) max(0.85rem, env(safe-area-inset-left, 0px));
        }
        @media (min-width: 992px) {
            body.auth-portal-page {
                padding: max(1.5rem, env(safe-area-inset-top, 0px)) max(1.25rem, env(safe-area-inset-right, 0px)) max(1.5rem, env(safe-area-inset-bottom, 0px)) max(1.25rem, env(safe-area-inset-left, 0px));
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
            const saved = localStorage.getItem('theme-mode');
            document.documentElement.setAttribute('data-theme', saved === 'dark' ? 'dark' : 'light');
        })();
    </script>
</head>
<body class="auth-portal-page">
    <div class="auth-portal-bg" aria-hidden="true">
        <span class="auth-portal-bg__orb auth-portal-bg__orb--1"></span>
        <span class="auth-portal-bg__orb auth-portal-bg__orb--2"></span>
        <span class="auth-portal-bg__grid"></span>
    </div>
    <div class="auth-portal-viewport">
    <div class="<?= htmlspecialchars($shellClass) ?>">
        <header class="auth-portal-hero">
            <?php if ($logoUrl !== '' || $namaPonpesRaw !== '' || $welcomeSalam !== ''): ?>
                <div class="logo-ring<?= $logoUrl === '' ? ' logo-ring--fallback' : '' ?>" aria-hidden="<?= $logoUrl === '' ? 'true' : 'false' ?>">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo <?= htmlspecialchars(strip_tags($namaPonpesRaw !== '' ? $namaPonpesRaw : 'pesantren')) ?>" class="auth-portal-logo-img" decoding="async" fetchpriority="high">
                    <?php else: ?>
                        <span class="logo-fallback"><?= htmlspecialchars($initials) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($kicker !== '' || $namaPonpes !== ''): ?>
                <div class="auth-portal-pondok-identity">
                    <?php if ($kicker !== ''): ?>
                        <div class="kicker auth-portal-brand-kicker"><?= $kicker ?></div>
                    <?php endif; ?>
                    <?php if ($namaPonpes !== ''): ?>
                        <p class="auth-portal-brand mb-0"><?= $namaPonpes ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="auth-portal-hero-text">
                <div class="auth-portal-hero-lead">
                    <?php if ($welcomeSalam !== ''): ?>
                        <h1 class="auth-portal-salam auth-portal-salam--utama"><?= $welcomeSalam ?></h1>
                    <?php elseif ($namaPonpes === '' && $title !== ''): ?>
                        <h1 class="auth-portal-salam"><?= $title ?></h1>
                    <?php endif; ?>
                    <?php if ($welcomeSalamWaktu !== ''): ?>
                        <p class="auth-portal-salam-waktu mb-0"><?= $welcomeSalamWaktu ?></p>
                    <?php endif; ?>
                </div>
                <div class="auth-portal-hero-follow">
                    <?php if ($welcomeTagline !== ''): ?>
                        <p class="auth-portal-tagline"><?= $welcomeTagline ?></p>
                    <?php endif; ?>
                    <?php if ($subtitleMobileHtml !== '' || $subtitleDesktopHtml !== ''): ?>
                        <p class="sub mb-0">
                            <?php if ($subtitleMobileHtml !== ''): ?>
                                <span class="auth-portal-sub-line auth-portal-sub-line--mobile"><?= $subtitleMobileHtml ?></span>
                            <?php endif; ?>
                            <?php if ($subtitleDesktopHtml !== ''): ?>
                                <span class="auth-portal-sub-line auth-portal-sub-line--desktop"><?= $subtitleDesktopHtml ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <div class="auth-portal-card">
            <div class="auth-portal-card__head">
                <p class="auth-portal-card__head-title mb-0">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    <?= $cardTitle ?>
                </p>
                <?php if ($cardMeta !== ''): ?>
                    <p class="auth-portal-card__head-meta"><?= $cardMeta ?></p>
                <?php endif; ?>
            </div>
            <div class="auth-portal-card__body">
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php if (function_exists('app_asset_href')): ?>
    <script>window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
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
