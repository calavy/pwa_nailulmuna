<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';

require_roles(['admin', 'pengurus', 'kiai']);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($_GET['kategori'] ?? null);

if ($tingkatan === '') {
    set_flash('warning', 'Pilih kelas/tingkatan terlebih dahulu.');
    header('Location: ' . app_href('/yayasan/keaktifan.php?tanggal=' . urlencode($tanggal)));
    exit;
}

$rows = rekap_keaktifan_hari_data($pdo, $tanggal, $tingkatan, $kategori);
$detail = rekap_keaktifan_hari_detail_kelas($rows, $tingkatan);

$backQs = ['tanggal' => $tanggal];
if ($kategori !== null) {
    $backQs['kategori'] = $kategori;
}

$pageTitle = 'Detail Keaktifan — ' . $tingkatan;
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php?' . http_build_query($backQs))) ?>">Keaktifan Hari Ini</a>
    </p>
    <h1 class="h4 mb-1">Kelas <?= htmlspecialchars($tingkatan) ?></h1>
    <p class="text-muted small mb-0">
        <?= htmlspecialchars($tanggal) ?> ·
        <strong><?= (int) $detail['masuk'] ?></strong>/<?= (int) $detail['total'] ?> santri hadir
        (<?= (int) round((float) $detail['persen']) ?>%)
    </p>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card shadow-sm border-success">
            <div class="card-header bg-success text-white py-2">
                <strong>Sudah hadir</strong> <span class="badge bg-light text-success"><?= count($detail['hadir']) ?></span>
            </div>
            <ul class="list-group list-group-flush" style="max-height:420px;overflow:auto">
                <?php if ($detail['hadir'] === []): ?>
                    <li class="list-group-item text-muted small">Belum ada yang tercatat hadir.</li>
                <?php else: ?>
                    <?php foreach ($detail['hadir'] as $s): ?>
                        <li class="list-group-item py-2">
                            <div class="fw-semibold small"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '-')) ?></div>
                            <div class="text-muted small">NIS <?= htmlspecialchars((string) ($s['nis'] ?? '-')) ?></div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-warning">
            <div class="card-header bg-warning py-2">
                <strong>Belum hadir</strong> <span class="badge bg-dark"><?= count($detail['belum']) ?></span>
            </div>
            <ul class="list-group list-group-flush" style="max-height:420px;overflow:auto">
                <?php if ($detail['belum'] === []): ?>
                    <li class="list-group-item text-muted small">Semua santri sudah hadir di minimal satu kegiatan.</li>
                <?php else: ?>
                    <?php foreach ($detail['belum'] as $s): ?>
                        <?php
                        $st = (string) ($s['status'] ?? 'ALPA');
                        $badge = match ($st) {
                            'IZIN' => 'text-bg-info',
                            'SAKIT' => 'text-bg-success',
                            default => 'text-bg-danger',
                        };
                        ?>
                        <li class="list-group-item py-2 d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold small"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '-')) ?></div>
                                <div class="text-muted small">NIS <?= htmlspecialchars((string) ($s['nis'] ?? '-')) ?></div>
                            </div>
                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
