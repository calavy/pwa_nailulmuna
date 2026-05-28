<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/munawib.php';
require_once __DIR__ . '/../helpers/kartu_brand_colors.php';

require_roles(['admin', 'pengurus']);
munawib_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'ID munawib tidak valid.');
    header('Location: ' . app_href('/pembimbing/munawib.php'));
    exit;
}

$st = $pdo->prepare('SELECT id, nama, nip, qr, no_wa, is_aktif FROM munawib WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    set_flash('error', 'Data munawib tidak ditemukan.');
    header('Location: ' . app_href('/pembimbing/munawib.php'));
    exit;
}

$kodeQr = trim((string) ($row['qr'] ?? ''));
if ($kodeQr === '') {
    $kodeQr = trim((string) ($row['nip'] ?? ''));
}
if ($kodeQr === '') {
    $kodeQr = 'MN-' . (int) $row['id'];
}
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=10&data=' . rawurlencode($kodeQr);
$namaPonpes = app_brand_nama_ponpes($pdo);
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrl = $logoPath !== '' ? app_href('/' . ltrim($logoPath, '/')) : '';
$brandTheme = kartu_brand_theme_for_cards($pdo, 'emerald');
$cardStyleAttrs = kartu_brand_card_style_attrs($brandTheme);

$pageTitle = 'Cetak Kartu Munawib';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Kartu Munawib</h1>
        <p class="text-muted small mb-0">Desain khusus munawib (berbeda dari pembimbing), ukuran 86 x 54 mm.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <label for="themePicker" class="small text-muted mb-0">Tema warna</label>
        <select id="themePicker" class="form-select form-select-sm" style="width: 220px;">
            <option value="brand" selected>Brand Pondok (Hijau Gelap)</option>
            <option value="emerald">Hijau Emerald</option>
            <option value="ocean">Biru Ocean</option>
            <option value="royal">Ungu Royal</option>
            <option value="sunset">Oranye Sunset</option>
        </select>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/munawib.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <button class="btn btn-outline-primary btn-sm" type="button" id="btnDownloadJpg">
            <i class="fa-solid fa-image me-1"></i> Download JPG
        </button>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak
        </button>
    </div>
</div>

<style>
.mw-card-wrap { display:flex; justify-content:center; }
.mw-id-card {
    width: 86mm; height: 54mm; border: 1px solid var(--card-border, #99f6e4); border-radius: 4mm;
    background: linear-gradient(150deg, var(--card-grad-1, #065f46) 0%, var(--card-grad-2, #0f766e) 48%, var(--card-grad-3, #0ea5a4) 100%);
    color: #fff; position: relative; overflow: hidden; padding: 3.2mm; display:flex; flex-direction:column; justify-content:space-between;
    box-shadow: 0 10px 30px var(--card-shadow, rgba(5, 92, 77, .34));
}
.mw-id-card:before { content:""; position:absolute; inset:0; background:radial-gradient(circle at 12% 88%, var(--card-gloss, rgba(255,255,255,.22)), transparent 42%); pointer-events:none; }
.mw-top { display:flex; justify-content:flex-start; gap:3mm; position:relative; z-index:1; }
.mw-logo { width:12mm; height:12mm; border-radius:2.2mm; object-fit:cover; padding:0; flex-shrink:0; }
.mw-brand h2 { margin:0; font-size:3.7mm; font-weight:800; line-height:1.2; }
.mw-brand .sub { font-size:2.6mm; opacity:.94; margin:0 0 .7mm; letter-spacing:.05em; font-weight:700; }
.mw-brand .addr { font-size:2.2mm; opacity:.88; margin-top:.7mm; line-height:1.2; }
.mw-body { display:flex; justify-content:space-between; gap:3mm; align-items:flex-end; position:relative; z-index:1; margin-top:1.4mm; }
.mw-name { margin:0 0 1mm; font-size:4.2mm; line-height:1.1; font-weight:900; }
.mw-line { font-size:3mm; margin:.35mm 0; opacity:.96; }
.mw-qrbox { width:31mm; height:31mm; border-radius:2mm; background:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.mw-qrbox img { width:27.6mm; height:27.6mm; object-fit:contain; }
.mw-foot { font-size:2.5mm; opacity:.9; text-align:right; position:relative; z-index:1; }
@media print {
    body * { visibility: hidden; }
    .mw-card-wrap, .mw-card-wrap * { visibility: visible; }
    .mw-card-wrap { position: fixed; left: 0; top: 0; width: 100%; }
    .mw-id-card { box-shadow: none; border-color: var(--card-print-border, #5eead4); }
}
</style>

<div class="mw-card-wrap">
    <div class="mw-id-card" id="munawib-id-card"<?= $cardStyleAttrs ?>>
        <div class="mw-top">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" class="mw-logo" alt="Logo pondok">
            <?php endif; ?>
            <div class="mw-brand">
                <div class="sub">KARTU MUNAWIB</div>
                <h2><?= htmlspecialchars((string) $namaPonpes) ?></h2>
                <?php if ($alamatPonpes !== ''): ?>
                    <div class="addr"><?= htmlspecialchars($alamatPonpes) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="mw-body">
            <div>
                <p class="mw-name"><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></p>
                <div class="mw-line">NIP: <?= htmlspecialchars((string) ($row['nip'] ?? '-')) ?></div>
                <div class="mw-line">Status: <?= (int) ($row['is_aktif'] ?? 0) === 1 ? 'AKTIF' : 'NONAKTIF' ?></div>
            </div>
            <div class="mw-qrbox">
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Munawib">
            </div>
        </div>
        <div class="mw-foot"></div>
    </div>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/kartu-brand-theme.js')) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
(function () {
    var card = document.getElementById('munawib-id-card');
    var themePicker = document.getElementById('themePicker');
    var btn = document.getElementById('btnDownloadJpg');
    if (!card || typeof KartuBrandTheme === 'undefined') return;

    KartuBrandTheme.init({
        cards: [card],
        picker: themePicker,
        logoUrl: <?= json_encode($logoUrl, JSON_UNESCAPED_SLASHES) ?>,
        brandTheme: <?= json_encode($brandTheme, JSON_UNESCAPED_SLASHES) ?>,
        fallback: 'emerald'
    });

    if (!btn || typeof html2canvas === 'undefined') return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        html2canvas(card, { scale: 3, backgroundColor: null, useCORS: true }).then(function (canvas) {
            var a = document.createElement('a');
            a.href = canvas.toDataURL('image/jpeg', 0.96);
            a.download = 'kartu-munawib-<?= (int) $row['id'] ?>.jpg';
            a.click();
        }).finally(function () {
            btn.disabled = false;
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
