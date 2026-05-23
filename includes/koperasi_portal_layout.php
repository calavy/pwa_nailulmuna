<?php

declare(strict_types=1);

/**
 * Layout ringkas portal petugas koperasi cashless (tanpa sidebar admin).
 *
 * @param array{title:string,koperasi_nama:string,active?:'scan'|'laporan'|'hub'} $ctx
 */
function koperasi_portal_layout_begin(array $ctx): void
{
    $title = htmlspecialchars((string) ($ctx['title'] ?? 'Koperasi'));
    $kopNama = htmlspecialchars((string) ($ctx['koperasi_nama'] ?? 'Koperasi'));
    $active = (string) ($ctx['active'] ?? '');
    require_once __DIR__ . '/../helpers/app_path.php';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        body { background: #f1f5f9; min-height: 100dvh; }
        .koperasi-topbar {
            background: linear-gradient(135deg, #0f766e 0%, #0891b2 100%);
            color: #fff;
            padding: .75rem 1rem calc(.75rem + env(safe-area-inset-top, 0px));
        }
        .koperasi-nav .nav-link {
            color: rgba(255,255,255,.85);
            border-radius: .5rem;
            padding: .35rem .75rem;
            font-size: .875rem;
        }
        .koperasi-nav .nav-link.active, .koperasi-nav .nav-link:hover {
            background: rgba(255,255,255,.18);
            color: #fff;
        }
    </style>
</head>
<body>
<header class="koperasi-topbar">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="fw-semibold"><i class="fa-solid fa-store me-2"></i><?= $kopNama ?></div>
        <a href="<?= htmlspecialchars(app_href('/koperasi/logout.php')) ?>" class="btn btn-sm btn-light">Keluar</a>
    </div>
    <nav class="koperasi-nav">
        <ul class="nav gap-1">
            <li class="nav-item">
                <a class="nav-link<?= $active === 'scan' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/koperasi/scan.php')) ?>"><i class="fa-solid fa-qrcode me-1"></i> Scan</a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $active === 'laporan' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/koperasi/laporan.php')) ?>"><i class="fa-solid fa-chart-column me-1"></i> Laporan</a>
            </li>
        </ul>
    </nav>
</header>
<main class="container-fluid py-3 px-3 px-md-4" style="max-width:1200px;margin:0 auto;">
    <?php
}

function koperasi_portal_layout_end(): void
{
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
    <?php
}
