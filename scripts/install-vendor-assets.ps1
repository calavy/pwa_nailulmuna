# Unduh Bootstrap + Font Awesome ke assets/vendor (jalankan sekali saat online)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$base = Join-Path $root 'assets\vendor'
New-Item -ItemType Directory -Force -Path (Join-Path $base 'bootstrap\5.3.3'), (Join-Path $base 'fontawesome\6.5.2\webfonts'), (Join-Path $base 'html5-qrcode\2.3.8') | Out-Null
$files = @(
    @{ Url = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'; Out = 'bootstrap\5.3.3\bootstrap.min.css' },
    @{ Url = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'; Out = 'bootstrap\5.3.3\bootstrap.bundle.min.js' },
    @{ Url = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css'; Out = 'fontawesome\6.5.2\all.min.css' },
    @{ Url = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-solid-900.woff2'; Out = 'fontawesome\6.5.2\webfonts\fa-solid-900.woff2' },
    @{ Url = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-regular-400.woff2'; Out = 'fontawesome\6.5.2\webfonts\fa-regular-400.woff2' },
    @{ Url = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-brands-400.woff2'; Out = 'fontawesome\6.5.2\webfonts\fa-brands-400.woff2' },
    @{ Url = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js'; Out = 'html5-qrcode\2.3.8\html5-qrcode.min.js' }
)
foreach ($f in $files) {
    $dest = Join-Path $base $f.Out
    Write-Host "GET $($f.Url)"
    Invoke-WebRequest -Uri $f.Url -OutFile $dest -UseBasicParsing
}
Write-Host "Selesai: $base"
