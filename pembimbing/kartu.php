<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'ID pembimbing tidak valid.');
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$st = $pdo->prepare('SELECT id, nip, nama_pembimbing, no_wa, qr, is_aktif FROM pembimbing WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    set_flash('error', 'Data pembimbing tidak ditemukan.');
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$jadwalCount = 0;
if (table_exists($pdo, 'jadwal_kegiatan')) {
    $stJ = $pdo->prepare('SELECT COUNT(*) FROM jadwal_kegiatan WHERE pembimbing_id = :id');
    $stJ->execute(['id' => $id]);
    $jadwalCount = (int) ($stJ->fetchColumn() ?: 0);
}
if ($jadwalCount <= 0) {
    set_flash('error', 'Kartu baru bisa dicetak setelah pembimbing tertaut ke jadwal.');
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$kodeQr = trim((string) ($row['qr'] ?? ''));
if ($kodeQr === '') {
    $kodeQr = trim((string) ($row['nip'] ?? ''));
}
if ($kodeQr === '') {
    $kodeQr = 'PB-' . (int) $row['id'];
}
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=10&data=' . rawurlencode($kodeQr);
$namaPonpes = app_brand_nama_ponpes($pdo);
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrl = $logoPath !== '' ? app_href('/' . ltrim($logoPath, '/')) : '';

$pageTitle = 'Cetak Kartu Pembimbing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Kartu Pembimbing</h1>
        <p class="text-muted small mb-0">Ukuran ID-1 (86 mm x 54 mm), QR besar untuk scan cepat.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <label for="themePicker" class="small text-muted mb-0">Tema warna</label>
        <select id="themePicker" class="form-select form-select-sm" style="width: 220px;">
            <option value="brand" selected>Brand Pondok (dari logo)</option>
            <option value="ocean">Biru Ocean</option>
            <option value="emerald">Hijau Emerald</option>
            <option value="royal">Ungu Royal</option>
            <option value="sunset">Oranye Sunset</option>
        </select>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <button class="btn btn-outline-primary btn-sm" type="button" id="btnDownloadJpg">
            <i class="fa-solid fa-image me-1"></i> Download JPG
        </button>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak
        </button>
    </div>
</div>

<style>
.pb-card-wrap { display:flex; justify-content:center; }
.pb-id-card {
    width: 86mm;
    height: 54mm;
    border: 1px solid var(--card-border, #bfdbfe);
    border-radius: 4mm;
    background: linear-gradient(
        155deg,
        var(--card-grad-1, #1e3a8a) 0%,
        var(--card-grad-2, #1d4ed8) 45%,
        var(--card-grad-3, #0ea5e9) 100%
    );
    color: #fff;
    box-shadow: 0 10px 30px var(--card-shadow, rgba(30, 64, 175, .30));
    position: relative;
    overflow: hidden;
    padding: 3.2mm;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.pb-id-card:before {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 82% 18%, var(--card-gloss, rgba(255, 255, 255, .24)), transparent 42%);
    pointer-events: none;
}
.pb-id-top { display:flex; justify-content:flex-start; gap: 3mm; }
.pb-id-brand { min-width:0; }
.pb-id-brand h2 { font-size: 3.8mm; margin:0; font-weight:700; line-height:1.2; }
.pb-id-brand .sub { font-size:2.8mm; opacity:.92; margin:0 0 .7mm; font-weight:700; letter-spacing:.04em; }
.pb-id-brand .addr { font-size:2.35mm; opacity:.88; margin-top:.7mm; line-height:1.2; }
.pb-id-logo {
    width: 12mm; height: 12mm; border-radius: 2.2mm;
    object-fit: cover; padding: 0; flex-shrink:0;
}
.pb-id-body { display:flex; justify-content:space-between; gap: 3mm; align-items:flex-end; margin-top: 1.4mm; }
.pb-id-meta { min-width:0; flex:1; }
.pb-id-name { font-size:4.4mm; font-weight:800; line-height:1.15; margin:0 0 1mm; }
.pb-id-line { font-size:3.1mm; margin: .4mm 0; opacity:.96; }
.pb-id-qrbox {
    width: 31mm; height: 31mm;
    border-radius: 2mm;
    background:#fff;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.pb-id-qrbox img { width: 27.6mm; height: 27.6mm; object-fit: contain; }
.pb-id-foot { font-size: 2.6mm; opacity:.9; text-align:right; min-height: 2.6mm; }
@media print {
    body * { visibility: hidden; }
    .pb-card-wrap, .pb-card-wrap * { visibility: visible; }
    .pb-card-wrap { position: fixed; left: 0; top: 0; width: 100%; }
    .pb-id-card { box-shadow: none; border-color: var(--card-print-border, #93c5fd); }
}
</style>

<div class="pb-card-wrap">
    <div class="pb-id-card" id="pembimbing-id-card">
        <div class="pb-id-top">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" class="pb-id-logo" alt="Logo pondok">
            <?php endif; ?>
            <div class="pb-id-brand">
                <div class="sub">KARTU PEMBIMBING</div>
                <h2><?= htmlspecialchars((string) $namaPonpes) ?></h2>
                <?php if ($alamatPonpes !== ''): ?>
                    <div class="addr"><?= htmlspecialchars($alamatPonpes) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="pb-id-body">
            <div class="pb-id-meta">
                <p class="pb-id-name"><?= htmlspecialchars((string) ($row['nama_pembimbing'] ?? '-')) ?></p>
                <div class="pb-id-line">NIP: <?= htmlspecialchars((string) ($row['nip'] ?? '-')) ?></div>
                <div class="pb-id-line">Status: <?= (int) ($row['is_aktif'] ?? 0) === 1 ? 'AKTIF' : 'NONAKTIF' ?></div>
            </div>
            <div class="pb-id-qrbox">
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Pembimbing">
            </div>
        </div>
        <div class="pb-id-foot"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
(function () {
    var card = document.getElementById('pembimbing-id-card');
    var themePicker = document.getElementById('themePicker');
    var btn = document.getElementById('btnDownloadJpg');
    var logoUrl = <?= json_encode($logoUrl, JSON_UNESCAPED_SLASHES) ?>;
    if (!card) return;

    var themes = {
        ocean: ['#1e3a8a', '#1d4ed8', '#0ea5e9', '#bfdbfe', '#93c5fd', 'rgba(30,64,175,.30)'],
        emerald: ['#065f46', '#0f766e', '#0ea5a4', '#99f6e4', '#5eead4', 'rgba(5,92,77,.34)'],
        royal: ['#312e81', '#5b21b6', '#7c3aed', '#d8b4fe', '#c4b5fd', 'rgba(67,24,161,.32)'],
        sunset: ['#9a3412', '#c2410c', '#f97316', '#fdba74', '#fb923c', 'rgba(154,52,18,.32)']
    };

    function hexToRgb(hex) {
        var h = String(hex || '').replace('#', '').trim();
        if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
        if (h.length !== 6) return null;
        var n = parseInt(h, 16);
        if (isNaN(n)) return null;
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }

    function rgbToHex(r, g, b) {
        function toHex(v) {
            var s = Math.max(0, Math.min(255, Math.round(v))).toString(16);
            return s.length < 2 ? '0' + s : s;
        }
        return '#' + toHex(r) + toHex(g) + toHex(b);
    }

    function adjust(hex, amount) {
        var rgb = hexToRgb(hex);
        if (!rgb) return hex;
        return rgbToHex(rgb.r + amount, rgb.g + amount, rgb.b + amount);
    }

    function applyTheme(values) {
        card.style.setProperty('--card-grad-1', values[0]);
        card.style.setProperty('--card-grad-2', values[1]);
        card.style.setProperty('--card-grad-3', values[2]);
        card.style.setProperty('--card-border', values[3]);
        card.style.setProperty('--card-print-border', values[4]);
        card.style.setProperty('--card-shadow', values[5]);
    }

    function applyThemeByName(name) {
        var key = (name || '').toLowerCase();
        if (themes[key]) {
            applyTheme(themes[key]);
            return;
        }
        applyBrandTheme();
    }

    function applyBrandTheme() {
        if (!logoUrl) {
            applyTheme(themes.ocean);
            return;
        }
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = 24;
                canvas.height = 24;
                var ctx = canvas.getContext('2d');
                if (!ctx) throw new Error('Canvas context tidak tersedia');
                ctx.drawImage(img, 0, 0, 24, 24);
                var data = ctx.getImageData(0, 0, 24, 24).data;
                var total = 0, r = 0, g = 0, b = 0;
                for (var i = 0; i < data.length; i += 4) {
                    var a = data[i + 3];
                    if (a < 24) continue;
                    r += data[i];
                    g += data[i + 1];
                    b += data[i + 2];
                    total++;
                }
                if (total < 20) throw new Error('Sampel warna logo terlalu kecil');
                var base = rgbToHex(r / total, g / total, b / total);
                var c1 = adjust(base, -58);
                var c2 = adjust(base, -18);
                var c3 = adjust(base, 34);
                var border = adjust(base, 72);
                var printBorder = adjust(base, 56);
                var rgb = hexToRgb(c1) || { r: 30, g: 64, b: 175 };
                applyTheme([c1, c2, c3, border, printBorder, 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',.33)']);
            } catch (e) {
                applyTheme(themes.ocean);
            }
        };
        img.onerror = function () { applyTheme(themes.ocean); };
        img.src = logoUrl + (logoUrl.indexOf('?') >= 0 ? '&' : '?') + 'v=' + Date.now();
    }

    if (themePicker) {
        themePicker.addEventListener('change', function () {
            applyThemeByName(themePicker.value);
        });
        applyThemeByName(themePicker.value || 'brand');
    } else {
        applyThemeByName('brand');
    }

    if (!btn || typeof html2canvas === 'undefined') return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        html2canvas(card, {scale: 3, backgroundColor: null, useCORS: true}).then(function (canvas) {
            var a = document.createElement('a');
            a.href = canvas.toDataURL('image/jpeg', 0.96);
            a.download = 'kartu-pembimbing-<?= (int) $row['id'] ?>.jpg';
            a.click();
        }).finally(function () {
            btn.disabled = false;
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
