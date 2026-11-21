<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_vendor.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/inventaris_barang.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

if (is_logged_in()) {
    migrate_inventaris_barang_permission($pdo);
}

$kode = trim((string) ($_REQUEST['kode'] ?? ''));
$barang = $kode !== '' ? inventaris_barang_find_by_kode($pdo, $kode) : null;
$bolehCatat = inventaris_barang_boleh_mengelola();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'catat_cek') {
    if (!$bolehCatat || $barang === null) {
        set_flash('error', 'Anda tidak dapat mencatat pengecekan untuk barang ini.');
    } else {
        $result = inventaris_barang_catat_cek(
            $pdo,
            (int) $barang['id'],
            $_POST,
            (int) ($_SESSION['user']['id'] ?? 0),
            (string) ($_SESSION['user']['nama'] ?? '')
        );
        set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    }
    $kembali = inventaris_barang_extract_kode($kode);
    header('Location: ' . app_href('/inventaris/cek.php?kode=' . rawurlencode($kembali !== '' ? $kembali : $kode)));
    exit;
}

$riwayat = ($barang && $bolehCatat) ? inventaris_barang_riwayat($pdo, (int) $barang['id']) : [];
$komponen = ($barang && $bolehCatat) ? inventaris_barang_komponen_list($pdo, (int) $barang['id']) : [];
$success = get_flash('success');
$error = get_flash('error');
$loginHref = app_href('/login.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $barang ? htmlspecialchars((string) $barang['nama']) : 'Kelengkapan barang' ?></title>
    <link href="<?= htmlspecialchars(app_vendor_bootstrap_css_href()) ?>" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="card shadow-sm mx-auto" style="max-width:720px">
        <div class="card-body">
            <p class="text-muted small mb-1">Kelengkapan barang inventaris</p>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!$barang): ?>
                <h1 class="h4">Barang tidak ditemukan</h1>
                <p class="mb-0">Kode QR tidak terdaftar. Periksa kode atau catat barang terlebih dahulu.</p>
            <?php else: ?>
                <h1 class="h4 mb-2"><?= htmlspecialchars((string) $barang['nama']) ?></h1>
                <?php if (!$bolehCatat): ?>
                    <p class="mb-2">Kode: <?= htmlspecialchars((string) $barang['kode']) ?></p>
                    <p class="mb-0">
                        Status kelengkapan:
                        <span class="badge <?= inventaris_barang_badge_status((string) $barang['status_kelengkapan']) ?>"><?= htmlspecialchars((string) $barang['status_kelengkapan']) ?></span>
                    </p>
                    <?php if (!is_logged_in()): ?>
                        <p class="small text-muted mt-3 mb-0">Petugas dapat <a href="<?= htmlspecialchars($loginHref) ?>">masuk</a> untuk mencatat hasil pengecekan.</p>
                    <?php endif; ?>
                <?php else: ?>
                <?php if ((int) $barang['is_aktif'] !== 1): ?>
                    <p class="mb-2"><span class="badge text-bg-secondary">Nonaktif</span></p>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge <?= inventaris_barang_badge_status_pakai((string) ($barang['status_barang'] ?? 'Tersedia')) ?>"><?= htmlspecialchars((string) ($barang['status_barang'] ?? 'Tersedia')) ?></span>
                    <span class="badge <?= inventaris_barang_badge_status((string) $barang['status_kelengkapan']) ?>"><?= htmlspecialchars((string) $barang['status_kelengkapan']) ?></span>
                    <span class="badge <?= inventaris_barang_badge_kondisi((string) $barang['kondisi']) ?>"><?= htmlspecialchars((string) $barang['kondisi']) ?></span>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0">
                        <tr><th class="text-nowrap">Kode</th><td><?= htmlspecialchars((string) $barang['kode']) ?></td></tr>
                        <?php if (trim((string) ($barang['kode_induk'] ?? '')) !== ''): ?>
                            <tr><th>Kode induk</th><td><?= htmlspecialchars((string) $barang['kode_induk']) ?></td></tr>
                        <?php endif; ?>
                        <tr><th>Kategori</th><td><?= htmlspecialchars((string) $barang['kategori']) ?></td></tr>
                        <tr><th>Lokasi</th><td><?= htmlspecialchars((string) ($barang['lokasi'] ?: '—')) ?></td></tr>
                        <tr><th>Merk</th><td><?= htmlspecialchars((string) ($barang['merk'] ?: '—')) ?></td></tr>
                        <tr><th>Jumlah</th><td><?= (int) $barang['jumlah'] ?> <?= htmlspecialchars((string) $barang['satuan']) ?></td></tr>
                        <tr><th>Asal barang</th><td><?= htmlspecialchars((string) (($barang['asal_barang'] ?? '') !== '' ? $barang['asal_barang'] : '—')) ?></td></tr>
                        <tr><th>Status barang</th><td><?= htmlspecialchars((string) ($barang['status_barang'] ?? 'Tersedia')) ?></td></tr>
                        <tr><th>Penanggung jawab</th><td><?= htmlspecialchars((string) ($barang['penanggung_jawab'] ?: '—')) ?></td></tr>
                        <tr><th>Tanggal cek</th><td><?= htmlspecialchars(app_format_tanggal_id((string) ($barang['tanggal_cek'] ?? ''))) ?></td></tr>
                        <tr><th>Catatan kekurangan</th><td><?= htmlspecialchars((string) ($barang['catatan_kekurangan'] ?: '—')) ?></td></tr>
                        <tr><th>Keterangan</th><td><?= htmlspecialchars((string) ($barang['keterangan'] ?: '—')) ?></td></tr>
                    </table>
                </div>

                <h2 class="h6">Komponen</h2>
                <?php if (!$komponen): ?>
                    <p class="text-muted small">Belum ada komponen.</p>
                <?php else: ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Komponen</th>
                                    <th>Kelengkapan</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($komponen as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $item['nama']) ?></td>
                                        <td><span class="badge <?= inventaris_barang_badge_status((string) $item['status']) ?>"><?= htmlspecialchars((string) $item['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string) ($item['catatan'] ?: '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                    <h2 class="h6">Catat hasil pengecekan</h2>
                    <form method="post" class="row g-2 mb-4">
                        <input type="hidden" name="action" value="catat_cek">
                        <input type="hidden" name="kode" value="<?= htmlspecialchars((string) $barang['kode']) ?>">
                        <div class="col-md-4">
                            <label class="form-label small">Kelengkapan</label>
                            <select name="status_kelengkapan" class="form-select form-select-sm" required>
                                <?php foreach (inventaris_barang_status_options() as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>"<?= (string) $barang['status_kelengkapan'] === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success btn-sm">Simpan pengecekan</button>
                        </div>
                    </form>

                <h2 class="h6">Riwayat pengecekan</h2>
                <?php if (!$riwayat): ?>
                    <p class="text-muted small mb-0">Belum ada catatan pengecekan.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Hasil</th>
                                    <th>Petugas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riwayat as $cek): ?>
                                    <tr>
                                        <td class="text-nowrap small"><?= htmlspecialchars(app_format_datetime_id((string) $cek['created_at'])) ?></td>
                                        <td class="small">
                                            <?= htmlspecialchars((string) $cek['status_kelengkapan']) ?> · <?= htmlspecialchars((string) $cek['kondisi']) ?>
                                            <?php if ((string) ($cek['catatan'] ?? '') !== ''): ?>
                                                <div class="text-muted"><?= htmlspecialchars((string) $cek['catatan']) ?></div>
                                            <?php endif; ?>
                                            <?php
                                            $snap = json_decode((string) ($cek['komponen_json'] ?? ''), true);
                                            if (is_array($snap) && $snap !== []):
                                            ?>
                                                <div class="text-muted">
                                                    <?php foreach ($snap as $bagian): ?>
                                                        <div><?= htmlspecialchars((string) ($bagian['nama'] ?? '')) ?>: <?= htmlspecialchars((string) ($bagian['status'] ?? '')) ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= htmlspecialchars((string) ($cek['nama_petugas'] ?: '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
