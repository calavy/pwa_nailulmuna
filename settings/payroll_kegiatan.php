<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/payroll_pembimbing.php';

require_roles(['admin', 'pengurus']);

payroll_pembimbing_ensure_schema($pdo);

$searchQ = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_kriteria') {
        $res = payroll_pembimbing_save_taalim_kegiatan_kriteria($pdo, $_POST['payroll_kriteria'] ?? []);
        set_flash($res['ok'] ? 'success' : 'error', (string) ($res['message'] ?? ''));
    } else {
        set_flash('error', 'Aksi tidak dikenal.');
    }
    $qs = $searchQ !== '' ? ('?q=' . urlencode($searchQ)) : '';
    header('Location: ' . app_href('/settings/payroll_kegiatan.php' . $qs));
    exit;
}

$kegiatanRows = payroll_pembimbing_taalim_kegiatan_settings_rows($pdo, $searchQ);
$kegiatanIds = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $kegiatanRows);
$jadwalMap = payroll_pembimbing_jadwal_slots_by_kegiatan($pdo, $kegiatanIds);
$tarifMap = payroll_pembimbing_tarif_map($pdo);
$labels = payroll_pembimbing_kriteria_labels();

$totalKegiatan = count($kegiatanRows);
$totalDenganJadwal = count(array_filter($kegiatanRows, static fn(array $r): bool => (int) ($r['jumlah_jadwal'] ?? 0) > 0));
$totalTanpaJadwal = $totalKegiatan - $totalDenganJadwal;

$pageTitle = 'Beban Payroll Ta\'lim';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/payroll_kegiatan.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/menu/menu_hub.php?id=menu-grp-pengaturan')) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Beban Payroll per Kegiatan Ta'lim</h1>
    <p class="text-muted mb-0">
        Tentukan kategori beban kerja (<strong>Berat / Sedang / Ringan / Khusus</strong>) untuk setiap kegiatan Ta'lim & Ta'alum
        yang sudah ada di jadwal. Presensi scan mengikuti kategori kegiatan ini saat menghitung gaji pembimbing.
        Kegiatan <strong>Jama'ah</strong> tidak ditampilkan di sini (dihitung tarif Ringan).
    </p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Kegiatan Ta'lim</div>
            <div class="app-mini-stat-value"><?= $totalKegiatan ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Sudah punya jadwal</div>
            <div class="app-mini-stat-value text-success"><?= $totalDenganJadwal ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Belum ada slot jadwal</div>
            <div class="app-mini-stat-value <?= $totalTanpaJadwal > 0 ? 'text-warning' : '' ?>"><?= $totalTanpaJadwal ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small mb-0">Cari kegiatan</label>
                <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($searchQ) ?>" placeholder="Nama kegiatan / mapel…">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search me-1"></i> Cari</button>
                <?php if ($searchQ !== ''): ?>
                    <a href="<?= htmlspecialchars(app_href('/settings/payroll_kegiatan.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                <?php endif; ?>
            </div>
            <div class="col-auto ms-md-auto">
                <a href="<?= htmlspecialchars(app_href('/settings/tarif_payroll.php')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-sack-dollar me-1"></i> Tarif Rp/jam
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-calendar me-1"></i> Kelola jadwal
                </a>
            </div>
        </form>
    </div>
</div>

<form method="post">
    <input type="hidden" name="action" value="update_kriteria">
    <div class="card shadow-sm mb-4">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Daftar kegiatan Ta'lim</strong>
            <span class="small text-muted"><?= $totalKegiatan ?> kegiatan</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kegiatan / mapel</th>
                        <th>Slot jadwal</th>
                        <th>Ringkasan jadwal</th>
                        <th style="min-width: 11rem;">Beban payroll</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($kegiatanRows === []): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            Tidak ada kegiatan Ta'lim<?= $searchQ !== '' ? ' untuk pencarian ini' : '' ?>.
                            <?php if ($searchQ === ''): ?>
                                <br><a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>">Tambah kegiatan</a> lalu buat slot di jadwal.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($kegiatanRows as $row): ?>
                    <?php
                    $kid = (int) ($row['id'] ?? 0);
                    $slots = $jadwalMap[$kid] ?? [];
                    $jumlahJadwal = (int) ($row['jumlah_jadwal'] ?? 0);
                    $kriteriaNow = (string) ($row['payroll_kriteria'] ?? 'RINGAN');
                    $tarifNow = (int) round((float) ($tarifMap[$kriteriaNow] ?? 0));
                    ?>
                    <tr class="<?= (int) ($row['is_active'] ?? 1) !== 1 ? 'table-secondary' : '' ?>">
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($row['nama_kegiatan'] ?? '')) ?></div>
                            <?php if ((int) ($row['is_active'] ?? 1) !== 1): ?>
                                <span class="badge text-bg-secondary">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($jumlahJadwal > 0): ?>
                                <span class="badge text-bg-primary"><?= $jumlahJadwal ?></span>
                                <div class="small text-muted">
                                    <?= (int) ($row['jumlah_jadwal_kajian'] ?? 0) ?> kajian
                                    <?php if ((int) ($row['jumlah_jadwal_pkpps'] ?? 0) > 0): ?>
                                        · <?= (int) $row['jumlah_jadwal_pkpps'] ?> PKPPS
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="badge text-bg-warning">0</span>
                                <div class="small text-muted">Belum dijadwalkan</div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($slots === []): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach (array_slice($slots, 0, 4) as $slot): ?>
                                        <li>
                                            <span class="badge text-bg-light border text-dark"><?= htmlspecialchars((string) ($slot['sumber'] ?? '')) ?></span>
                                            <?= htmlspecialchars(payroll_pembimbing_hari_jadwal_label((int) ($slot['hari_ke'] ?? 0))) ?>
                                            <?= htmlspecialchars((string) ($slot['jam_mulai'] ?? '')) ?>–<?= htmlspecialchars((string) ($slot['jam_selesai'] ?? '')) ?>
                                            · <?= htmlspecialchars((string) ($slot['tingkatan'] ?? '-')) ?>
                                            <?php if (($slot['nama_pembimbing'] ?? '-') !== '-'): ?>
                                                <span class="text-muted">(<?= htmlspecialchars((string) $slot['nama_pembimbing']) ?>)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (count($slots) > 4): ?>
                                        <li class="text-muted">+<?= count($slots) - 4 ?> slot lainnya</li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="form-select form-select-sm" name="payroll_kriteria[<?= $kid ?>]">
                                <?php foreach (PAYROLL_PEMBIMBING_KRITERIA as $pk): ?>
                                    <option value="<?= htmlspecialchars($pk) ?>" <?= $kriteriaNow === $pk ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($labels[$pk] ?? $pk) ?> — Rp <?= number_format((int) round((float) ($tarifMap[$pk] ?? 0)), 0, ',', '.') ?>/jam
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Saat ini: Rp <?= number_format($tarifNow, 0, ',', '.') ?>/jam</div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($kegiatanRows !== []): ?>
        <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-floppy-disk me-1"></i> Simpan semua beban payroll
            </button>
        </div>
        <?php endif; ?>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Cara kerja</h2>
        <ol class="small text-muted mb-0">
            <li>Atur nominal tarif per kategori di <a href="<?= htmlspecialchars(app_href('/settings/tarif_payroll.php')) ?>">Tarif Payroll</a>.</li>
            <li>Di halaman ini, pilih beban payroll tiap kegiatan Ta'lim sesuai tingkat kesulitan mengajar/mengasuh.</li>
            <li>Daftar kegiatan diambil dari master <a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>">Jadwal → Kegiatan</a> (kategori Ta'lim saja).</li>
            <li>Sistem menghitung gaji per jam pembimbing dari presensi: <code>Σ(jam scan × tarif[beban kegiatan])</code> + gaji pokok.</li>
            <li>Lihat hasil di <a href="<?= htmlspecialchars(app_href('/rekap/pembimbing.php')) ?>">Rekap Pembimbing</a>.</li>
        </ol>
    </div>
</div>

<?php
$settingsNavInclude = __DIR__ . '/includes/settings_nav.php';
if (is_file($settingsNavInclude)) {
    require_once $settingsNavInclude;
}
require_once __DIR__ . '/../includes/footer.php';
