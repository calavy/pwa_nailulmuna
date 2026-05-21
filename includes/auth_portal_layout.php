<?php

declare(strict_types=1);

/**
 * Layout ringkas untuk halaman login mandiri (tanpa sidebar aplikasi utama).
 *
 * @param array{
 *   title: string,
 *   subtitle?: string,
 *   kicker?: string,
 *   nama_ponpes?: string,
 *   logo_url?: string,
 *   welcome?: string,
 *   max_width?: string,
 *   accent?: 'teal'|'indigo'
 * } $ctx
 */
function auth_portal_layout_begin(array $ctx): void
{
    $titleRaw = (string) ($ctx['title'] ?? 'Login');
    $title = htmlspecialchars($titleRaw);
    $welcome = isset($ctx['welcome']) ? htmlspecialchars((string) $ctx['welcome']) : '';
    $subtitle = isset($ctx['subtitle']) ? htmlspecialchars((string) $ctx['subtitle']) : '';
    $kicker = isset($ctx['kicker']) ? htmlspecialchars((string) $ctx['kicker']) : '';
    $namaPonpesRaw = trim((string) ($ctx['nama_ponpes'] ?? ''));
    $namaPonpes = $namaPonpesRaw !== '' ? htmlspecialchars($namaPonpesRaw) : '';
    $lettersOnly = preg_replace('/[^A-Za-z]/u', '', $namaPonpesRaw);
    $initials = strtoupper(substr($lettersOnly !== '' ? $lettersOnly : 'AP', 0, 2));
    $logoUrl = trim((string) ($ctx['logo_url'] ?? ''));
    $maxWidth = trim((string) ($ctx['max_width'] ?? '420px'));
    if (!preg_match('/^\d+(\.\d+)?(px|rem|em|%)$/', $maxWidth)) {
        $maxWidth = '420px';
    }
    $accent = ($ctx['accent'] ?? 'teal') === 'indigo' ? 'indigo' : 'teal';
    $gradStart = $accent === 'indigo' ? '#312e81' : '#0f766e';
    $gradMid = $accent === 'indigo' ? '#4338ca' : '#0d9488';
    $gradEnd = $accent === 'indigo' ? '#6366f1' : '#0891b2';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title><?= $title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root {
            --ap-auth-accent: <?= $accent === 'indigo' ? '#4f46e5' : '#0f766e' ?>;
            --ap-auth-accent-dark: <?= $accent === 'indigo' ? '#3730a3' : '#115e59' ?>;
            --ap-auth-surface: rgba(255, 255, 255, 0.92);
        }
        [data-theme="dark"] {
            --ap-auth-surface: rgba(30, 41, 59, 0.94);
        }
        body.auth-portal-page {
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            min-height: 100dvh;
            margin: 0;
            background: linear-gradient(145deg, <?= htmlspecialchars($gradStart) ?> 0%, <?= htmlspecialchars($gradMid) ?> 42%, <?= htmlspecialchars($gradEnd) ?> 100%);
            padding: 1.25rem 1rem calc(1.5rem + env(safe-area-inset-bottom, 0px));
        }
        .auth-portal-wrap {
            margin: 0 auto;
        }
        .auth-portal-card {
            border-radius: 1.15rem;
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: var(--ap-auth-surface);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
            backdrop-filter: blur(10px);
        }
        .auth-portal-hero {
            text-align: center;
            color: #fff;
            margin-bottom: 1.25rem;
        }
        .auth-portal-hero .logo-ring {
            margin: 0 auto 0.75rem;
            background: transparent;
            border: none;
            border-radius: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
            max-width: 140px;
        }
        .auth-portal-hero .logo-ring img {
            display: block;
            width: auto;
            height: auto;
            max-width: 140px;
            max-height: 104px;
            object-fit: contain;
            object-position: center;
            filter: drop-shadow(0 3px 10px rgba(15, 23, 42, 0.35));
        }
        .auth-portal-hero .logo-ring--fallback {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.35);
            overflow: hidden;
        }
        .auth-portal-hero .logo-fallback {
            font-weight: 800;
            font-size: 1.35rem;
            letter-spacing: 0.06em;
        }
        .auth-portal-hero h1 {
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0 0 0.35rem;
            letter-spacing: -0.02em;
        }
        .auth-portal-hero .auth-portal-ponpes {
            font-size: 1.05rem;
            font-weight: 600;
            margin: 0 0 0.5rem;
            line-height: 1.35;
        }
        .auth-portal-hero .sub {
            font-size: 0.9rem;
            opacity: 0.92;
            margin: 0;
            line-height: 1.45;
        }
        .auth-portal-hero .kicker {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            opacity: 0.85;
            margin-bottom: 0.35rem;
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
            filter: brightness(1.05);
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
    <div class="auth-portal-wrap" style="max-width: <?= htmlspecialchars($maxWidth) ?>">
        <div class="auth-portal-hero">
            <?php if ($logoUrl !== '' || $namaPonpesRaw !== '' || $welcome !== ''): ?>
                <div class="logo-ring<?= $logoUrl === '' ? ' logo-ring--fallback' : '' ?>" aria-hidden="<?= $logoUrl === '' ? 'true' : 'false' ?>">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo pesantren" decoding="async">
                    <?php else: ?>
                        <span class="logo-fallback"><?= htmlspecialchars($initials) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($kicker !== ''): ?>
                <div class="kicker"><?= $kicker ?></div>
            <?php endif; ?>
            <?php if ($welcome !== ''): ?>
                <h1><?= $welcome ?></h1>
                <?php if ($namaPonpes !== ''): ?>
                    <p class="auth-portal-ponpes"><?= $namaPonpes ?></p>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($namaPonpes !== ''): ?>
                    <div class="fw-semibold opacity-90 small mb-2"><?= $namaPonpes ?></div>
                <?php endif; ?>
                <h1><?= $title ?></h1>
            <?php endif; ?>
            <?php if ($subtitle !== ''): ?>
                <p class="sub"><?= $subtitle ?></p>
            <?php endif; ?>
        </div>
        <div class="auth-portal-card">
            <div class="p-4 p-md-4">
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
            <div class="auth-portal-links text-center mt-3 d-flex flex-wrap justify-content-center gap-3">
                <?php foreach ($footerLinks as $lnk): ?>
                    <a href="<?= htmlspecialchars((string) ($lnk['href'] ?? '#')) ?>"><?= htmlspecialchars((string) ($lnk['label'] ?? '')) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php if ($enableFcm && (isset($_SESSION['santri_portal']) || isset($_SESSION['wali']))): ?>
    <?php require_once __DIR__ . '/partials/push_fcm_bootstrap.php'; ?>
    <?php endif; ?>
</body>
</html>
    <?php
}
