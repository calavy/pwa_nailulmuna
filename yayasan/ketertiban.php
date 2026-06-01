<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_portal.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);
$ket = yayasan_ketertiban_ringkasan($pdo);
$tab = trim((string) ($_GET['tab'] ?? 'izin'));
if (!in_array($tab, ['izin', 'sakit', 'alpa'], true)) {
    $tab = 'izin';
}
$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';

$pageTitle = 'Menu Ketertiban';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap">
    <header class="mb-4">
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a> · <a href="<?= htmlspecialchars(app_href('/yayasan/pengawasan.php')) ?>">Pengawasan</a></p>
        <h1 class="h3 mb-1">Menu Ketertiban</h1>
        <p class="text-muted mb-0">Pemantauan disiplin santri — per <?= htmlspecialchars(date('d F Y')) ?></p>
    </header>

    <ul class="nav nav-pills mb-3 flex-wrap gap-1">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'izin' ? 'active' : '' ?>" href="?tab=izin">
                Izin Lewat Toleransi <span class="badge text-bg-danger ms-1"><?= (int) $ket['izin_lewat'] ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'sakit' ? 'active' : '' ?>" href="?tab=sakit">
                Sakit Perlu Penanganan <span class="badge text-bg-info ms-1"><?= (int) $ket['sakit'] ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'alpa' ? 'active' : '' ?>" href="?tab=alpa">
                Alpa Kebangetan <span class="badge text-bg-dark ms-1"><?= (int) $ket['alpa_beruntun'] ?></span>
            </a>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if ($tab === 'izin'): ?>
                <?php $rows = $ket['izin_rows'] ?? []; ?>
                <?php if ($rows === []): ?>
                    <div class="p-4 text-center text-muted">Tidak ada santri melewati batas toleransi izin.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Santri</th><th>Izin s/d</th><th>Telat</th><th>Alasan</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($r['tingkatan'] ?? '')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($r['tanggal_selesai'] ?? '')) ?></td>
                                    <td><span class="badge text-bg-danger"><?= htmlspecialchars((string) ($r['telat_label'] ?? '')) ?></span></td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['alasan'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'sakit'): ?>
                <?php $rows = $ket['sakit_rows'] ?? []; ?>
                <?php if ($rows === []): ?>
                    <div class="p-4 text-center text-muted">Tidak ada santri sakit yang perlu perhatian khusus hari ini.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Santri</th><th>Periode</th><th>Sumber</th><th>Catatan</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?> · <?= htmlspecialchars((string) ($r['tingkatan'] ?? '')) ?></div>
                                    </td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['tanggal_mulai'] ?? '')) ?> — <?= htmlspecialchars((string) ($r['tanggal_selesai'] ?? '')) ?></td>
                                    <td><span class="badge text-bg-info"><?= ($r['sumber'] ?? '') === 'presensi_sakit' ? 'Presensi' : 'Izin' ?></span></td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['alasan'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php $rows = $ket['alpa_rows'] ?? []; ?>
                <?php if ($rows === []): ?>
                    <div class="p-4 text-center text-muted">Tidak ada santri dengan alpa berturut-turut ≥ 3 hari.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Santri</th><th>Tingkatan</th><th>Hari alpa beruntun</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($r['tingkatan'] ?? '-')) ?></td>
                                    <td><span class="badge text-bg-dark"><?= (int) ($r['hari_alpa_beruntun'] ?? 0) ?> hari</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3 small text-muted">
        <a href="<?= htmlspecialchars(app_href('/rekap/perizinan.php')) ?>">Rekap perizinan lengkap</a>
        · <a href="<?= htmlspecialchars(app_href('/poin/rekap.php')) ?>">Rekap poin</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
