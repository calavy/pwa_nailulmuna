<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/kartu_brand_colors.php';
require_once __DIR__ . '/../helpers/yayasan_musyawarah.php';

require_roles(['admin', 'pengurus']);

yayasan_musyawarah_ensure_schema($pdo);

$idsRaw = $_GET['ids'] ?? [];
if (!is_array($idsRaw)) {
    $idsRaw = explode(',', (string) $idsRaw);
}
$ids = [];
foreach ($idsRaw as $raw) {
    $id = (int) $raw;
    if ($id > 0) {
        $ids[] = $id;
    }
}
$ids = array_values(array_unique($ids));
if ($ids === []) {
    set_flash('error', 'Pilih minimal satu SDM untuk cetak kartu.');
    header('Location: ' . app_href('/yayasan/sdm.php'));
    exit;
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare('SELECT * FROM yayasan_pengurus WHERE id IN (' . $ph . ') ORDER BY kategori ASC, urutan ASC, nama ASC');
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cards = [];
foreach ($rows as $row) {
    $kodeQr = yayasan_sdm_resolve_qr($row);
    $row['kode_qr_final'] = $kodeQr;
    $row['qr_url'] = yayasan_sdm_qr_image_url($kodeQr);
    $cards[] = $row;
}

if ($cards === []) {
    set_flash('error', 'Data SDM tidak ditemukan.');
    header('Location: ' . app_href('/yayasan/sdm.php'));
    exit;
}

$namaPonpes = app_brand_nama_ponpes($pdo);
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoUrl = app_pondok_logo_href($pdo, false);
$brandTheme = kartu_brand_theme_for_cards($pdo, 'emerald');
$cardStyleAttrs = kartu_brand_card_style_attrs($brandTheme);

$pageTitle = 'Cetak Kartu SDM Musyawarah';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Cetak Kartu SDM Musyawarah</h1>
        <p class="text-muted small mb-0"><?= count($cards) ?> kartu siap cetak (86 x 54 mm).</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <label for="themePickerBatch" class="small text-muted mb-0">Tema warna</label>
        <select id="themePickerBatch" class="form-select form-select-sm" style="width: min(100%, 14rem);">
            <?php require __DIR__ . '/../pembimbing/partials/kartu_theme_options.php';
            foreach ($kartuThemeOptions as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"<?= $val === 'brand' ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?= htmlspecialchars(app_href('/yayasan/sdm.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak Semua
        </button>
    </div>
</div>

<style>
.yy-batch-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(88mm, 1fr)); gap: 6mm; justify-items:center; }
.yy-id-card {
    width: 86mm; height: 54mm; border: 1px solid var(--card-border, #bfdbfe); border-radius: 4mm;
    background: linear-gradient(155deg, var(--card-grad-1, #0f766e) 0%, var(--card-grad-2, #0d9488) 42%, var(--card-grad-3, #14b8a6) 100%); color: #fff;
    position: relative; overflow: hidden; padding: 3.2mm; display:flex; flex-direction:column; justify-content:space-between;
}
.yy-id-card:before {
    content: ""; position: absolute; inset: 0; background: radial-gradient(circle at 85% 20%, var(--card-gloss, rgba(255,255,255,.24)), transparent 45%);
    pointer-events:none;
}
.yy-id-top { display:flex; justify-content:flex-start; gap:3mm; position:relative; z-index:1; }
.yy-id-brand h2 { font-size: 3.7mm; margin:0; font-weight:700; line-height:1.2; }
.yy-id-brand .sub { font-size:2.7mm; opacity:.94; margin:0 0 .7mm; font-weight:700; letter-spacing:.04em; }
.yy-id-brand .addr { font-size:2.2mm; opacity:.88; margin-top:.7mm; line-height:1.2; }
.yy-id-logo-wrap { width:12mm; height:12mm; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; padding:0.4mm; box-sizing:border-box; }
.yy-id-logo { width:100%; height:100%; border-radius:50%; object-fit:contain; display:block; background:#fff; }
.yy-id-body { display:flex; justify-content:space-between; gap:3mm; align-items:flex-end; position:relative; z-index:1; margin-top:1.4mm; }
.yy-id-name { font-size:4.2mm; font-weight:800; line-height:1.1; margin:0 0 1mm; }
.yy-id-line { font-size:3mm; margin:.35mm 0; opacity:.97; }
.yy-id-qrbox { width:31mm; height:31mm; border-radius:2mm; background:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.yy-id-qrbox img { width:27.6mm; height:27.6mm; object-fit:contain; }
.yy-id-foot { font-size:2.5mm; opacity:.9; text-align:right; position:relative; z-index:1; }
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    .no-print { display:none !important; }
    .yy-batch-grid { gap: 3.5mm; grid-template-columns: repeat(2, 1fr); }
    .yy-id-card { box-shadow:none; border-color:var(--card-print-border, #93c5fd); break-inside: avoid; }
}
</style>

<div class="yy-batch-grid">
    <?php foreach ($cards as $row): ?>
        <?php
        $kat = strtoupper((string) ($row['kategori'] ?? 'YAYASAN'));
        $subLabel = $kat === 'LEMBAGA' ? 'KARTU SDM LEMBAGA' : 'KARTU PENGURUS YAYASAN';
        ?>
        <div class="yy-id-card"<?= $cardStyleAttrs ?>>
            <div class="yy-id-top">
                <?php if ($logoUrl !== ''): ?>
                    <span class="yy-id-logo-wrap" aria-hidden="true">
                        <img src="<?= htmlspecialchars($logoUrl) ?>" class="yy-id-logo" alt="Logo pondok">
                    </span>
                <?php endif; ?>
                <div class="yy-id-brand">
                    <div class="sub"><?= htmlspecialchars($subLabel) ?></div>
                    <h2><?= htmlspecialchars((string) $namaPonpes) ?></h2>
                    <?php if ($alamatPonpes !== ''): ?>
                        <div class="addr"><?= htmlspecialchars($alamatPonpes) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="yy-id-body">
                <div>
                    <p class="yy-id-name"><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></p>
                    <div class="yy-id-line"><?= htmlspecialchars((string) ($row['jabatan'] ?? '-')) ?></div>
                    <?php if ($kat === 'LEMBAGA' && !empty($row['lembaga_nama'])): ?>
                        <div class="yy-id-line"><?= htmlspecialchars((string) $row['lembaga_nama']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['periode_mulai']) || !empty($row['periode_selesai'])): ?>
                        <div class="yy-id-line"><?= htmlspecialchars((string) (($row['periode_mulai'] ?? '') . '–' . ($row['periode_selesai'] ?? 'sekarang'))) ?></div>
                    <?php endif; ?>
                </div>
                <div class="yy-id-qrbox">
                    <img src="<?= htmlspecialchars((string) ($row['qr_url'] ?? '')) ?>" alt="QR SDM">
                </div>
            </div>
            <div class="yy-id-foot"><?= htmlspecialchars((string) ($row['kode_qr_final'] ?? '')) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/kartu-brand-theme.js')) ?>"></script>
<script>
(function () {
    var picker = document.getElementById('themePickerBatch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.yy-id-card'));
    if (!cards.length || typeof KartuBrandTheme === 'undefined') return;
    KartuBrandTheme.init({
        cards: cards,
        picker: picker,
        logoUrl: <?= json_encode($logoUrl, JSON_UNESCAPED_SLASHES) ?>,
        brandTheme: <?= json_encode($brandTheme, JSON_UNESCAPED_SLASHES) ?>,
        fallback: 'emerald'
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
