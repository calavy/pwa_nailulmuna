<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/app_vendor.php';
require_once __DIR__ . '/../helpers/inventaris_barang.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

migrate_inventaris_barang_permission($pdo);
require_roles(['admin', 'pengurus']);
ensure_inventaris_barang_tables($pdo);

$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = $_POST['ids'];
} elseif ((int) ($_GET['id'] ?? 0) > 0) {
    $ids = [(int) $_GET['id']];
}
$rows = inventaris_barang_by_ids($pdo, $ids);
if ($rows === []) {
    set_flash('error', 'Pilih barang yang akan dibuat stikernya.');
    header('Location: ' . app_href('/inventaris/index.php'));
    exit;
}

$items = [];
foreach ($rows as $row) {
    $items[] = inventaris_barang_stiker_payload($row);
}

$orgName = strtoupper(app_brand_nama_ponpes($pdo, 'PONPES A.P.I NAILUL MUNA'));
if (!str_contains($orgName, 'PONPES') && !str_contains($orgName, 'PONDOK')) {
    $orgName = 'PONPES ' . $orgName;
}
$orgAddress = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
if ($orgAddress === '') {
    $orgAddress = 'Gondosuli, Muntilan, Magelang';
}
$logoUrl = app_pondok_logo_href($pdo, false);
$stikerConfig = [
    'orgName' => $orgName,
    'orgAddress' => $orgAddress,
    'logoUrl' => $logoUrl,
    'cekBase' => app_public_url() . app_href('/inventaris/cek.php?kode='),
    'digitUrut' => inventaris_barang_digit_urut($pdo),
    'items' => $items,
    'lokasi' => inventaris_barang_lokasi_sheet(),
    'kategori' => array_map(static fn(array $meta): string => $meta['label'], inventaris_barang_kategori_sheet()),
];

$pageTitle = 'Stiker Inventaris';
$pageStylesheets = [
    'https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800;900&family=Montserrat:wght@700;800;900&family=Oswald:wght@700&family=Poppins:wght@800;900&family=Roboto+Mono:wght@700&display=swap',
    app_asset_href('/assets/css/inventaris-stiker.css'),
];
$pageScripts = [
    app_vendor_qrcodejs_js_href(),
    app_asset_href('/assets/js/inventaris-stiker.js'),
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/inventaris/index.php')) ?>">Kembali ke daftar</a>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="reset-stiker">Reset tampilan</button>
        <button type="button" class="btn btn-success btn-sm" id="unduh-jpg">Download JPG</button>
        <?php if (count($items) > 1): ?>
            <button type="button" class="btn btn-success btn-sm" id="unduh-semua">Unduh semua JPG</button>
        <?php endif; ?>
    </div>
</div>

<script type="application/json" id="inv-stiker-data"><?= json_encode($stikerConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="row g-3 align-items-start">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0">Edit teks stiker</h2>
                    <span class="badge text-bg-success">Live preview</span>
                </div>

                <?php if (count($items) > 1): ?>
                    <div class="mb-3">
                        <label class="form-label small" for="pilih-barang">Barang</label>
                        <select id="pilih-barang" class="form-select form-select-sm">
                            <?php foreach ($items as $i => $item): ?>
                                <option value="<?= (int) $i ?>"><?= htmlspecialchars($item['kode'] . ' · ' . $item['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="border border-success-subtle rounded-3 p-3 bg-success-subtle mb-3">
                    <div class="small fw-bold text-success-emphasis mb-2">Format kode sheet · LOKASI-KATEGORI-URUT</div>
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label small mb-1" for="sheetLocCode">Kode lokasi</label>
                            <select id="sheetLocCode" class="form-select form-select-sm">
                                <option value="">—</option>
                                <?php foreach (inventaris_barang_lokasi_sheet() as $kodeLok => $namaLok): ?>
                                    <option value="<?= htmlspecialchars($kodeLok) ?>"><?= htmlspecialchars($kodeLok) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-1" for="sheetCatCode">Kode kategori</label>
                            <select id="sheetCatCode" class="form-select form-select-sm">
                                <option value="">—</option>
                                <?php foreach (inventaris_barang_kategori_sheet() as $kodeKat => $metaKat): ?>
                                    <option value="<?= htmlspecialchars($kodeKat) ?>"><?= htmlspecialchars($kodeKat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-1" for="sheetSeqNum">No. urut</label>
                            <input type="text" id="sheetSeqNum" class="form-control form-control-sm text-uppercase" maxlength="8" value="01">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="sheetParentCode">Kode induk</label>
                            <input type="text" id="sheetParentCode" class="form-control form-control-sm text-uppercase" maxlength="64" placeholder="Kosongkan, atau LAB-PC-01">
                        </div>
                    </div>
                    <p class="form-text mb-0 mt-2">Tata letak, ukuran, dan warna mengikuti pratinjau. Berkas JPG memakai kode, nama, kategori, induk, dan tautan cek yang tersimpan, tanpa mengubah data barang.</p>
                </div>

                <div class="mb-3">
                    <label class="form-label small" for="mainCode">Kode utama</label>
                    <input type="text" id="mainCode" class="form-control fw-bold" maxlength="64" value="">
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="mainCodeFont">Jenis huruf kode utama</label>
                    <select id="mainCodeFont" class="form-select form-select-sm">
                        <option value="'Inter', sans-serif" selected>Inter Black</option>
                        <option value="'Montserrat', sans-serif">Montserrat ExtraBold</option>
                        <option value="'Poppins', sans-serif">Poppins Black</option>
                        <option value="'Oswald', sans-serif">Oswald Bold</option>
                        <option value="'Bebas Neue', sans-serif">Bebas Neue</option>
                        <option value="'Roboto Mono', monospace">Roboto Mono</option>
                        <option value="'Arial Black', Impact, sans-serif">Arial Black / Impact</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="boxColorPicker">Warna kotak kode utama</label>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="border rounded px-2 py-1 d-flex align-items-center gap-2">
                            <input type="color" id="boxColorPicker" value="#9cd660" class="form-control form-control-color p-0 border-0">
                            <span id="boxColorHex" class="small font-monospace">#9CD660</span>
                        </div>
                        <?php foreach (['#9cd660', '#fde047', '#6ee2eb', '#fdba74', '#fda4af', '#ffffff'] as $warna): ?>
                            <button type="button" class="inv-color-dot" data-box-color="<?= htmlspecialchars($warna) ?>" style="background:<?= htmlspecialchars($warna) ?>" aria-label="Warna kotak <?= htmlspecialchars($warna) ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <label class="form-label small mb-1" for="lineColorPicker">Warna garis bingkai dan pembatas</label>
                        <button type="button" class="btn btn-link btn-sm p-0" id="samakan-garis">Samakan dengan warna kotak</button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="border rounded px-2 py-1 d-flex align-items-center gap-2">
                            <input type="color" id="lineColorPicker" value="#9cd660" class="form-control form-control-color p-0 border-0">
                            <span id="lineColorHex" class="small font-monospace">#9CD660</span>
                        </div>
                        <?php foreach (['#9cd660', '#fde047', '#6ee2eb', '#fdba74', '#fda4af', '#ffffff'] as $warna): ?>
                            <button type="button" class="inv-color-dot" data-line-color="<?= htmlspecialchars($warna) ?>" style="background:<?= htmlspecialchars($warna) ?>" aria-label="Warna garis <?= htmlspecialchars($warna) ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label small" for="qrText">Kode di bawah QR</label>
                        <input type="text" id="qrText" class="form-control form-control-sm fw-bold" maxlength="64">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small" for="qrSubText">Sub-teks QR</label>
                        <input type="text" id="qrSubText" class="form-control form-control-sm" maxlength="80">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="qrContent">Isi data scan QR</label>
                    <input type="text" id="qrContent" class="form-control form-control-sm" maxlength="500">
                    <div class="form-text">Bawaan mengarah ke halaman cek barang. Mengubah kode pada pratinjau ikut memperbarui tautan ini selama isinya tidak diedit manual.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="orgName">Nama lembaga</label>
                    <input type="text" id="orgName" class="form-control form-control-sm" maxlength="120" value="<?= htmlspecialchars($orgName) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="orgAddress">Alamat</label>
                    <input type="text" id="orgAddress" class="form-control form-control-sm" maxlength="160" value="<?= htmlspecialchars($orgAddress) ?>">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label small" for="footerText">Kategori aset</label>
                        <input type="text" id="footerText" class="form-control form-control-sm" maxlength="80">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small" for="badgeText">Teks kapsul</label>
                        <input type="text" id="badgeText" class="form-control form-control-sm" maxlength="40" value="JANGAN DILEPAS">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Logo lembaga</label>
                    <div class="d-flex gap-2">
                        <label for="logoUpload" class="btn btn-outline-secondary btn-sm mb-0 flex-grow-1">
                            <span id="logoFileName"><?= $logoUrl !== '' ? 'Logo pondok aktif' : 'Ganti logo (PNG/JPG)' ?></span>
                        </label>
                        <input type="file" id="logoUpload" accept="image/*" class="d-none">
                        <button type="button" id="clearLogoBtn" class="btn btn-outline-danger btn-sm d-none">Hapus</button>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="form-label small">Tata letak</div>
                    <div class="row g-2">
                        <div class="col-6"><button type="button" class="inv-choice w-100 is-active" data-layout="classic"><span class="d-block small fw-bold">1. Klasik</span><span class="d-block text-muted" style="font-size:.7rem">Logo kiri, kode tengah, QR kanan</span></button></div>
                        <div class="col-6"><button type="button" class="inv-choice w-100" data-layout="modern-header"><span class="d-block small fw-bold">2. Banner atas</span><span class="d-block text-muted" style="font-size:.7rem">Kop penuh di atas, kode lebar</span></button></div>
                        <div class="col-6"><button type="button" class="inv-choice w-100" data-layout="clean-light"><span class="d-block small fw-bold">3. Kartu cerah</span><span class="d-block text-muted" style="font-size:.7rem">Latar putih hemat tinta</span></button></div>
                        <div class="col-6"><button type="button" class="inv-choice w-100" data-layout="editorial"><span class="d-block small fw-bold">4. Manajerial</span><span class="d-block text-muted" style="font-size:.7rem">Dokumen bersih dan minimal</span></button></div>
                    </div>
                </div>
                <div class="mb-3 border rounded-3 p-3 bg-light">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <div class="form-label small mb-0">Orientasi dan ukuran</div>
                        <span id="sizeBadge" class="badge text-bg-light border font-monospace">1200 × 480 px</span>
                    </div>
                    <div class="row g-2 mb-2" id="orientBtns">
                        <div class="col-4"><button type="button" class="inv-choice w-100 text-center is-active" data-orient="landscape">Lanskap</button></div>
                        <div class="col-4"><button type="button" class="inv-choice w-100 text-center" data-orient="square">Persegi</button></div>
                        <div class="col-4"><button type="button" class="inv-choice w-100 text-center" data-orient="portrait">Potret</button></div>
                    </div>
                    <div class="row g-2 align-items-center">
                        <div class="col-md-7">
                            <label class="visually-hidden" for="sizePreset">Ukuran preset</label>
                            <select id="sizePreset" class="form-select form-select-sm"></select>
                        </div>
                        <div class="col-md-5">
                            <div class="input-group input-group-sm">
                                <input type="number" id="cw" class="form-control text-center font-monospace" min="300" max="3000" step="10" value="1200" aria-label="Lebar">
                                <span class="input-group-text">×</span>
                                <input type="number" id="ch" class="form-control text-center font-monospace" min="300" max="3000" step="10" value="480" aria-label="Tinggi">
                            </div>
                        </div>
                    </div>
                    <p class="form-text mb-0 mt-2">Ukuran lanskap yang rasionya bukan 2,5:1 tetap utuh; sisanya diberi margin warna tema.</p>
                </div>
                <div>
                    <div class="form-label small">Warna tema</div>
                    <div class="row g-2">
                        <div class="col-6 col-xl-3"><button type="button" class="inv-choice w-100 text-center is-active" data-theme="emerald"><span class="inv-theme-dot" style="background:#134726"></span><span class="d-block small">Hijau</span></button></div>
                        <div class="col-6 col-xl-3"><button type="button" class="inv-choice w-100 text-center" data-theme="teal"><span class="inv-theme-dot" style="background:#135c63"></span><span class="d-block small">Teal</span></button></div>
                        <div class="col-6 col-xl-3"><button type="button" class="inv-choice w-100 text-center" data-theme="navy"><span class="inv-theme-dot" style="background:#1e3a8a"></span><span class="d-block small">Navy</span></button></div>
                        <div class="col-6 col-xl-3"><button type="button" class="inv-choice w-100 text-center" data-theme="maroon"><span class="inv-theme-dot" style="background:#7f1d1d"></span><span class="d-block small">Marun</span></button></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-body-tertiary d-flex flex-wrap justify-content-between gap-2">
                <span id="dimLabel" class="small fw-bold text-uppercase text-secondary">Pratinjau stiker (1200 × 480 px)</span>
                <span class="small text-muted">JPG · <code id="previewFilename">stiker.jpg</code></span>
            </div>
            <div class="card-body inv-stiker-canvas-wrap d-flex justify-content-center">
                <div id="wrap" class="inv-stiker-canvas-frame">
                    <canvas id="stickerCanvas" width="1200" height="480"></canvas>
                </div>
            </div>
            <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">Nama berkas mengikuti kode barang.</span>
                <button type="button" class="btn btn-success" id="unduh-jpg-bawah">Simpan dan download JPG</button>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h3 class="h6 mb-1">Nomor urut cepat</h3>
                    <p class="small text-muted mb-0">Menaikkan angka terakhir pada pratinjau. Berkas yang diunduh tetap memakai kode yang tersimpan.</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="nomor-sebelum">Sebelumnya</button>
                    <button type="button" class="btn btn-success btn-sm" id="nomor-berikut">Nomor berikutnya +1</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="hiddenQrContainer" class="d-none"></div>
<div id="toast" class="inv-stiker-toast">
    <div class="bg-dark text-white rounded-3 shadow px-3 py-2">
        <div id="toastTitle" class="fw-bold small">Berhasil</div>
        <div id="toastMessage" class="small text-white-50"></div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
