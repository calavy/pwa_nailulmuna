<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/payroll_pembimbing.php';

require_roles(['admin', 'pengurus']);

payroll_pembimbing_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_tarif') {
        $nominalIn = $_POST['nominal'] ?? [];
        if (!is_array($nominalIn)) {
            $nominalIn = [];
        }
        $ok = 0;
        try {
            $stmt = $pdo->prepare('UPDATE tarif_payroll_pembimbing SET nominal_per_jam = :val WHERE kriteria = :k');
            foreach (PAYROLL_PEMBIMBING_KRITERIA as $k) {
                $raw = (string) ($nominalIn[$k] ?? '');
                $val = (float) preg_replace('/[^0-9.]/', '', $raw);
                if ($val < 0) {
                    $val = 0;
                }
                $stmt->execute(['val' => $val, 'k' => $k]);
                $ok++;
            }
            set_flash('success', 'Master tarif payroll berhasil disimpan (' . $ok . ' kriteria diperbarui).');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal menyimpan tarif: ' . $e->getMessage());
        }
    } else {
        set_flash('error', 'Aksi tidak dikenal.');
    }
    header('Location: ' . app_href('/settings/tarif_payroll.php'));
    exit;
}

$tarifMap = payroll_pembimbing_tarif_map($pdo);
$labels = payroll_pembimbing_kriteria_labels();
$totalSet = 0;
foreach ($tarifMap as $v) {
    if ($v > 0) {
        $totalSet++;
    }
}

$kriteriaIcons = [
    'BERAT' => 'fa-solid fa-weight-hanging',
    'SEDANG' => 'fa-solid fa-scale-balanced',
    'RINGAN' => 'fa-solid fa-feather-pointed',
    'KHUSUS' => 'fa-solid fa-star',
];
$kriteriaHints = [
    'BERAT' => 'Beban kerja paling berat (misal: pengasuhan harian, supervisi malam).',
    'SEDANG' => 'Beban kerja menengah (misal: mengajar harian, piket kegiatan).',
    'RINGAN' => 'Beban kerja ringan (misal: pendamping kegiatan tertentu).',
    'KHUSUS' => 'Tarif khusus / lainnya (misal: penugasan project, kepanitiaan).',
];

$pageTitle = 'Master Tarif Payroll Pembimbing';
$bodyClass = 'settings-module-page';
$settingsNavActive = '/settings/tarif_payroll.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/menu/menu_hub.php?id=menu-grp-pengaturan')) ?>">Pengaturan</a></p>
    <h1 class="h4 mb-1">Master Tarif Payroll Pembimbing</h1>
    <p class="text-muted mb-0">Atur nominal tarif per jam untuk setiap kategori beban kerja. Kategori beban per kitab (kegiatan Ta'lim) diatur di <a href="<?= htmlspecialchars(app_href('/settings/payroll_kegiatan.php')) ?>">Beban Payroll Ta'lim</a>. Gaji variabel = Σ (jam per kitab × tarif beban kitab) — dihitung per presensi scan, tanpa tarif rata-rata.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Kriteria tarif</div>
            <div class="app-mini-stat-value"><?= count(PAYROLL_PEMBIMBING_KRITERIA) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Sudah diisi</div>
            <div class="app-mini-stat-value"><?= $totalSet ?></div>
        </div>
    </div>
</div>

<form method="post" class="card shadow-sm mb-4">
    <input type="hidden" name="action" value="update_tarif">
    <div class="card-body">
        <h2 class="h5 mb-3">Nominal per jam</h2>
        <div class="row g-3">
            <?php foreach (PAYROLL_PEMBIMBING_KRITERIA as $k): ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="border rounded-3 p-3 h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-light text-dark border"><i class="<?= htmlspecialchars($kriteriaIcons[$k] ?? 'fa-solid fa-coins') ?>" aria-hidden="true"></i></span>
                            <strong><?= htmlspecialchars($labels[$k] ?? $k) ?></strong>
                        </div>
                        <label class="form-label small mb-1 text-muted">Rp / jam</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="number"
                                   class="form-control text-end"
                                   name="nominal[<?= htmlspecialchars($k) ?>]"
                                   min="0"
                                   step="500"
                                   value="<?= (int) round((float) ($tarifMap[$k] ?? 0)) ?>"
                                   inputmode="numeric"
                                   required>
                        </div>
                        <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($kriteriaHints[$k] ?? '') ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/settings/tarif_payroll.php')) ?>">Reset</a>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Simpan semua tarif
        </button>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Cara kerja</h2>
        <ol class="small text-muted mb-0">
            <li>Atur 4 nominal tarif per jam di atas, lalu klik <strong>Simpan</strong>.</li>
            <li>Atur beban Berat/Sedang/Ringan per kitab (kegiatan Ta'lim) di <a href="<?= htmlspecialchars(app_href('/settings/payroll_kegiatan.php')) ?>">Beban Payroll Ta'lim</a>.</li>
            <li>Di profil pembimbing, tentukan <strong>Gaji Pokok</strong> saja (tunjangan tetap bulanan).</li>
            <li>Sistem mengumpulkan jam kerja per kitab dari presensi scan setiap bulan.</li>
            <li>Lihat hasil di <a href="<?= htmlspecialchars(app_href('/rekap/pembimbing.php')) ?>">Rekap Pembimbing</a>:
                <code>total_gaji = gaji_pokok + Σ(jam_kitab × tarif[beban kitab])</code> — tanpa tarif rata-rata.
            </li>
        </ol>
    </div>
</div>

<?php
$settingsNavInclude = __DIR__ . '/includes/settings_nav.php';
if (is_file($settingsNavInclude)) {
    require_once $settingsNavInclude;
}
require_once __DIR__ . '/../includes/footer.php';
