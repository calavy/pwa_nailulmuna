<?php

declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/app_path.php';

$homeHref = function_exists('app_href') ? app_href('/login.php') : '/login.php';
$iconHref = function_exists('app_href') ? app_href('/assets/img/stempel-pondok.png') : '/assets/img/stempel-pondok.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <meta name="robots" content="noindex">
    <title>Tidak ada koneksi — Pondok</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100dvh;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(145deg, #0f766e 0%, #0891b2 50%, #1d4ed8 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(1rem, env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) max(1rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left));
        }
        .card {
            width: min(420px, 100%);
            background: rgba(255, 255, 255, 0.96);
            color: #0f172a;
            border-radius: 18px;
            padding: 1.5rem 1.35rem;
            text-align: center;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
        }
        .card img { width: 72px; height: 72px; object-fit: contain; margin-bottom: 0.75rem; }
        h1 { font-size: 1.15rem; margin: 0 0 0.5rem; }
        p { margin: 0 0 1rem; font-size: 0.92rem; color: #475569; line-height: 1.5; }
        .btn {
            display: inline-block;
            padding: 0.6rem 1.1rem;
            border-radius: 10px;
            background: #0f766e;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn:hover { filter: brightness(1.06); }
        .status { font-size: 0.8rem; color: #64748b; margin-top: 0.75rem; }
        .status.is-online { color: #047857; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <img src="<?= htmlspecialchars($iconHref) ?>" alt="" width="72" height="72" decoding="async">
        <h1>Tidak ada koneksi internet</h1>
        <p>
            Halaman ini disimpan di perangkat Anda. Sambungkan Wi‑Fi atau data seluler,
            lalu muat ulang untuk melanjutkan ke aplikasi pondok.
        </p>
        <button type="button" class="btn" id="btn-retry">Coba lagi</button>
        <a class="btn" href="<?= htmlspecialchars($homeHref) ?>" style="margin-left:0.35rem;background:#475569;">Ke login</a>
        <p class="status" id="net-status" aria-live="polite">Status: offline</p>
    </div>
    <script>
        (function () {
            var statusEl = document.getElementById('net-status');
            function refreshStatus() {
                var on = navigator.onLine;
                if (statusEl) {
                    statusEl.textContent = on ? 'Status: online — memuat ulang…' : 'Status: offline';
                    statusEl.classList.toggle('is-online', on);
                }
                if (on) {
                    window.location.reload();
                }
            }
            window.addEventListener('online', refreshStatus);
            document.getElementById('btn-retry').addEventListener('click', function () {
                if (navigator.onLine) {
                    window.location.reload();
                } else if (statusEl) {
                    statusEl.textContent = 'Masih offline. Periksa koneksi Anda.';
                }
            });
        })();
    </script>
</body>
</html>
