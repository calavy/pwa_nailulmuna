<?php

declare(strict_types=1);

function sdm_is_embed(): bool
{
    return isset($_GET['embed']) && (string) $_GET['embed'] === '1';
}

/** Pertahankan ?embed=1 pada redirect saat formulir dibuka di modal iframe. */
function sdm_embed_url(string $url): string
{
    if (!sdm_is_embed()) {
        return $url;
    }
    if (preg_match('/(?:\?|&)embed=1(?:&|$)/', $url)) {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'embed=1';
}

function sdm_embed_done_redirect(string $url): void
{
    if (sdm_is_embed()) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Selesai</title></head><body>';
        echo '<p class="small text-muted p-3">Berhasil disimpan. Menutup formulir…</p>';
        echo '<script>try{if(window.parent&&window.parent!==window){window.parent.dispatchEvent(new CustomEvent("sdmFormDone",{detail:{url:' . json_encode($url, JSON_UNESCAPED_UNICODE) . '}}));}}catch(e){}</script>';
        echo '</body></html>';
        exit;
    }
    header('Location: ' . $url);
    exit;
}

function sdm_embed_layout_start(string $pageTitle): void
{
    if (!sdm_is_embed()) {
        return;
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/pwa_nailulmuna/assets/css/keuangan.css" rel="stylesheet">
    <style>body{background:#f8f9fa}.sdm-embed-close{position:sticky;top:0;z-index:5}</style>
</head>
<body class="p-3">
<div class="sdm-embed-close d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom bg-body">
    <span class="fw-semibold small"><?= htmlspecialchars($pageTitle) ?></span>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="try{window.parent.dispatchEvent(new CustomEvent('sdmFormClose'));}catch(e){window.close();}">Tutup</button>
</div>
<?php
}

function sdm_embed_layout_end(): void
{
    if (!sdm_is_embed()) {
        return;
    }
    ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
<?php
    exit;
}
