<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/kartu_brand_colors.php';
require_once __DIR__ . '/../helpers/pembimbing_kelas.php';

require_roles(['admin', 'pengurus']);

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
    set_flash('error', 'Pilih minimal satu pembimbing untuk cetak batch kartu.');
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$sql = 'SELECT p.id, p.nip, p.nama_pembimbing, p.no_wa, p.qr, p.is_aktif
        FROM pembimbing p
        WHERE p.id IN (' . $ph . ')
        ORDER BY p.nama_pembimbing ASC';
$st = $pdo->prepare($sql);
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cards = [];
foreach ($rows as $row) {
    if (!pembimbing_can_print_kartu($pdo, (int) ($row['id'] ?? 0))) {
        continue;
    }
    $kodeQr = trim((string) ($row['qr'] ?? ''));
    if ($kodeQr === '') {
        $kodeQr = trim((string) ($row['nip'] ?? ''));
    }
    if ($kodeQr === '') {
        $kodeQr = 'PB-' . (int) $row['id'];
    }
    $row['kode_qr_final'] = $kodeQr;
    $row['qr_url'] = 'https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=10&data=' . rawurlencode($kodeQr);
    $cards[] = $row;
}

if ($cards === []) {
    set_flash('error', 'Tidak ada pembimbing dengan jadwal kajian atau PKPPS untuk dicetak kartunya.');
    header('Location: ' . app_href('/pembimbing/index.php'));
    exit;
}

$namaPonpes = app_brand_nama_ponpes($pdo);
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoUrl = app_pondok_logo_href($pdo, false);
$brandTheme = kartu_brand_theme_for_cards($pdo, 'emerald');
$cardStyleAttrs = kartu_brand_card_style_attrs($brandTheme);

$pageTitle = 'Batch Cetak Kartu Pembimbing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Batch Cetak Kartu Pembimbing</h1>
        <p class="text-muted small mb-0"><?= count($cards) ?> kartu siap cetak (86 x 54 mm).</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <label for="themePickerBatch" class="small text-muted mb-0">Tema warna</label>
        <select id="themePickerBatch" class="form-select form-select-sm" style="width: min(100%, 14rem);">
            <?php require __DIR__ . '/partials/kartu_theme_options.php';
            foreach ($kartuThemeOptions as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"<?= $val === 'brand' ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak Semua
        </button>
    </div>
</div>

<style>
.pb-batch-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(88mm, 1fr)); gap: 6mm; justify-items:center; }
.pb-id-card {
    width: 86mm; height: 54mm; border: 1px solid var(--card-border, #bfdbfe); border-radius: 4mm;
    background: linear-gradient(155deg, var(--card-grad-1, #1e3a8a) 0%, var(--card-grad-2, #1d4ed8) 42%, var(--card-grad-3, #0ea5e9) 100%); color: #fff;
    position: relative; overflow: hidden; padding: 3.2mm; display:flex; flex-direction:column; justify-content:space-between;
}
.pb-id-card:before {
    content: ""; position: absolute; inset: 0; background: radial-gradient(circle at 85% 20%, var(--card-gloss, rgba(255,255,255,.24)), transparent 45%);
    pointer-events:none;
}
.pb-id-top { display:flex; justify-content:flex-start; gap:3mm; position:relative; z-index:1; }
.pb-id-brand h2 { font-size: 3.7mm; margin:0; font-weight:700; line-height:1.2; }
.pb-id-brand .sub { font-size:2.7mm; opacity:.94; margin:0 0 .7mm; font-weight:700; letter-spacing:.04em; }
.pb-id-brand .addr { font-size:2.2mm; opacity:.88; margin-top:.7mm; line-height:1.2; }
.pb-id-logo-wrap { width:12mm; height:12mm; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; padding:0.4mm; box-sizing:border-box; }
.pb-id-logo { width:100%; height:100%; border-radius:50%; object-fit:contain; display:block; background:#fff; }
.pb-id-body { display:flex; justify-content:space-between; gap:3mm; align-items:flex-end; position:relative; z-index:1; margin-top:1.4mm; }
.pb-id-name { font-size:4.2mm; font-weight:800; line-height:1.1; margin:0 0 1mm; }
.pb-id-line { font-size:3mm; margin:.35mm 0; opacity:.97; }
.pb-id-qrbox { width:31mm; height:31mm; border-radius:2mm; background:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pb-id-qrbox img { width:27.6mm; height:27.6mm; object-fit:contain; }
.pb-id-foot { font-size:2.5mm; opacity:.9; text-align:right; position:relative; z-index:1; }
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    .no-print { display:none !important; }
    .pb-batch-grid { gap: 3.5mm; grid-template-columns: repeat(2, 1fr); }
    .pb-id-card { box-shadow:none; border-color:var(--card-print-border, #93c5fd); break-inside: avoid; }
}
</style>

<div class="pb-batch-grid">
    <?php foreach ($cards as $row): ?>
        <div class="pb-id-card"<?= $cardStyleAttrs ?>>
            <div class="pb-id-top">
                <?php if ($logoUrl !== ''): ?>
                    <span class="pb-id-logo-wrap" aria-hidden="true">
                        <img src="<?= htmlspecialchars($logoUrl) ?>" class="pb-id-logo" alt="Logo pondok">
                    </span>
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
                <div>
                    <p class="pb-id-name"><?= htmlspecialchars((string) ($row['nama_pembimbing'] ?? '-')) ?></p>
                    <div class="pb-id-line">NIP: <?= htmlspecialchars((string) ($row['nip'] ?? '-')) ?></div>
                    <div class="pb-id-line">Status: <?= (int) ($row['is_aktif'] ?? 0) === 1 ? 'AKTIF' : 'NONAKTIF' ?></div>
                </div>
                <div class="pb-id-qrbox">
                    <img src="<?= htmlspecialchars((string) ($row['qr_url'] ?? '')) ?>" alt="QR Pembimbing">
                </div>
            </div>
            <div class="pb-id-foot">ID: <?= (int) ($row['id'] ?? 0) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/kartu-brand-theme.js')) ?>"></script>
<script>
(function () {
    var picker = document.getElementById('themePickerBatch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.pb-id-card'));
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
