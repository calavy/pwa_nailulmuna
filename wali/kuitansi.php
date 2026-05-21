<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Bukti pembayaran tidak ditemukan.');
    header('Location: ' . app_href('/wali/pembayaran.php'));
    exit;
}

$row = wali_portal_fetch_pembayaran_for_wali($pdo, $id, $waliSantriId);
if (!$row) {
    set_flash('error', 'Bukti pembayaran tidak dapat diakses.');
    header('Location: ' . app_href('/wali/pembayaran.php'));
    exit;
}

$details = [];
if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
    $detStmt = $pdo->prepare('SELECT pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id ORDER BY id ASC');
    $detStmt->execute(['id' => $id]);
    $details = $detStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$nominalTotal = (int) round((float) ($row['total_nominal'] ?? 0));
$noKuitansi = 'KW-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
$periodeLabel = wali_portal_label_periode($pdo, $row);

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrl = trim((string) app_setting($pdo, 'logo_url', ''));
$logo = $logoPath !== '' ? '/' . ltrim($logoPath, '/') : $logoUrl;

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Bukti pembayaran ' . $noKuitansi, true, 'pembayaran');
?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="/wali/pembayaran.php"><i class="fa-solid fa-arrow-left me-1"></i> Kembali</a>
            <button type="button" class="btn btn-sm btn-teal" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Cetak</button>
        </div>

        <div class="card shadow-sm wali-card wali-kuitansi-print mb-3" id="wali-kuitansi-sheet">
            <div class="card-body p-3">
                <div class="text-center mb-3">
                    <?php if ($logo !== ''): ?>
                        <img src="<?= htmlspecialchars($logo) ?>" alt="" width="56" height="56" class="rounded-circle mb-2" style="object-fit:cover">
                    <?php endif; ?>
                    <div class="fw-bold"><?= htmlspecialchars($namaPonpes) ?></div>
                    <?php if ($alamatPonpes !== ''): ?>
                        <div class="small text-muted"><?= htmlspecialchars($alamatPonpes) ?></div>
                    <?php endif; ?>
                    <div class="wali-kicker mt-2 mb-0">Bukti pembayaran / kuitansi</div>
                </div>

                <div class="d-flex justify-content-between small mb-3">
                    <div>
                        <div class="text-muted">No. kuitansi</div>
                        <div class="fw-bold font-monospace"><?= htmlspecialchars($noKuitansi) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted">Tanggal bayar</div>
                        <div class="fw-semibold"><?= htmlspecialchars((string) ($row['tanggal_bayar'] ?? '')) ?></div>
                    </div>
                </div>

                <div class="rounded-3 bg-light p-2 small mb-3">
                    <div><strong>Santri:</strong> <?= htmlspecialchars((string) ($row['nama_santri'] ?? '')) ?></div>
                    <div><strong>NIS:</strong> <span class="font-monospace"><?= htmlspecialchars((string) ($row['nis'] ?? '')) ?></span></div>
                    <div><strong>Periode:</strong> <?= htmlspecialchars($periodeLabel) ?></div>
                    <?php if (trim((string) ($row['metode_bayar'] ?? '')) !== ''): ?>
                        <div><strong>Metode:</strong> <?= htmlspecialchars((string) $row['metode_bayar']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($details !== []): ?>
                    <table class="table table-sm mb-2">
                        <thead><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead>
                        <tbody>
                        <?php foreach ($details as $d): ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars((string) ($d['pos_nama'] ?? '')) ?></td>
                                <td class="text-end font-monospace small"><?= htmlspecialchars(wali_portal_format_rupiah((int) round((float) ($d['nominal'] ?? 0)))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td>Total</td>
                            <td class="text-end font-monospace"><?= htmlspecialchars(wali_portal_format_rupiah($nominalTotal)) ?></td>
                        </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="fw-bold text-end font-monospace mb-2">Total: <?= htmlspecialchars(wali_portal_format_rupiah($nominalTotal)) ?></p>
                <?php endif; ?>

                <?php if (trim((string) ($row['keterangan'] ?? '')) !== ''): ?>
                    <p class="small text-muted mb-0">Catatan: <?= htmlspecialchars((string) $row['keterangan']) ?></p>
                <?php endif; ?>

                <p class="small text-muted text-center mt-3 mb-0">Dokumen ini dicetak dari portal wali santri.</p>
            </div>
        </div>

<style>
@media print {
    body.wali-portal { background: #fff !important; padding: 0 !important; }
    .wali-bottom-nav, .btn, .wali-nav-scroll { display: none !important; }
    .wali-kuitansi-print { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>

<?php
wali_layout_foot(true, 'pembayaran');
