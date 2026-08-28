<?php

declare(strict_types=1);

/**
 * Saklar super admin: tanpa scan dihitung Hadir + Telat dihitung Hadir + bobot PRESNA.
 *
 * @var PDO $pdo
 */
if (!isset($pdo) || !($pdo instanceof PDO) || !function_exists('is_super_admin') || !is_super_admin()) {
    return;
}
require_once __DIR__ . '/../../helpers/keaktifan_alpa_tanpa_scan.php';
require_once __DIR__ . '/../../helpers/penilaian_kehadiran.php';
$tanpaScanHadir = keaktifan_tanpa_scan_dihitung_hadir($pdo);
$telatDihitungHadir = penilaian_kehadiran_telat_dihitung_hadir($pdo);
$bobot = penilaian_kehadiran_bobot($pdo);
?>
<form method="post" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <input type="hidden" name="action" value="save_keaktifan_alpa_tanpa_scan">
        <input type="hidden" name="keaktifan_tanpa_scan_dihitung_hadir" value="0">
        <input type="hidden" name="keaktifan_telat_dihitung_hadir" value="0">
        <div class="form-check form-switch mb-2">
            <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                id="keaktifanTanpaScanHadir"
                name="keaktifan_tanpa_scan_dihitung_hadir"
                value="1"
                <?= $tanpaScanHadir ? ' checked' : '' ?>
            >
            <label class="form-check-label fw-semibold" for="keaktifanTanpaScanHadir">
                Kegiatan tanpa scan dihitung Hadir
            </label>
        </div>
        <p class="small text-muted mb-3">
            Aktif: slot Jama'ah/Ta'lim tanpa petugas (tidak ada scan) dihitung Hadir, N.HARI tidak berkurang. Izin/Sakit tidak diubah.
            Nonaktif: slot kosong tidak dihitung ALPA dan tidak masuk N.HARI.
        </p>
        <div class="form-check form-switch mb-2">
            <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                id="keaktifanTelatDihitungHadir"
                name="keaktifan_telat_dihitung_hadir"
                value="1"
                <?= $telatDihitungHadir ? ' checked' : '' ?>
            >
            <label class="form-check-label fw-semibold" for="keaktifanTelatDihitungHadir">
                Telat dihitung Hadir
            </label>
        </div>
        <p class="small text-muted mb-3">
            Aktif: HADIR lewat batas telat tidak kena penalti Telat×<?= (int) $bobot['telat'] ?> di penilaian (Baik–Buruk).
            Nonaktif: telat tetap dihitung Telat. Daftar operasional siapa yang telat tidak diubah.
        </p>
        <p class="fw-semibold small mb-1">Bobot penilaian PRESNA</p>
        <p class="small text-muted mb-2">
            Pengali 0–10, satu tombol simpan bersama saklar di atas.
            Default Alpa 4, Izin 2, Sakit 1, Telat 3, Hadir 1 (persen sama seperti rumus sekarang).
            Naikkan Hadir untuk kredit ekstra tiap sesi hadir; turunkan Alpa agar 0% lebih jarang.
        </p>
        <div class="row g-2 mb-3">
            <div class="col">
                <label class="form-label small mb-0" for="penilaianBobotAlpa">Alpa</label>
                <input type="number" class="form-control form-control-sm" id="penilaianBobotAlpa" name="penilaian_bobot_alpa" min="0" max="10" step="1" value="<?= (int) $bobot['alpa'] ?>" required>
            </div>
            <div class="col">
                <label class="form-label small mb-0" for="penilaianBobotIzin">Izin</label>
                <input type="number" class="form-control form-control-sm" id="penilaianBobotIzin" name="penilaian_bobot_izin" min="0" max="10" step="1" value="<?= (int) $bobot['izin'] ?>" required>
            </div>
            <div class="col">
                <label class="form-label small mb-0" for="penilaianBobotSakit">Sakit</label>
                <input type="number" class="form-control form-control-sm" id="penilaianBobotSakit" name="penilaian_bobot_sakit" min="0" max="10" step="1" value="<?= (int) $bobot['sakit'] ?>" required>
            </div>
            <div class="col">
                <label class="form-label small mb-0" for="penilaianBobotTelat">Telat</label>
                <input type="number" class="form-control form-control-sm" id="penilaianBobotTelat" name="penilaian_bobot_telat" min="0" max="10" step="1" value="<?= (int) $bobot['telat'] ?>" required>
            </div>
            <div class="col">
                <label class="form-label small mb-0" for="penilaianBobotHadir">Hadir</label>
                <input type="number" class="form-control form-control-sm" id="penilaianBobotHadir" name="penilaian_bobot_hadir" min="0" max="10" step="1" value="<?= (int) $bobot['hadir'] ?>" required>
            </div>
        </div>
        <p class="small text-muted mb-2"><?= htmlspecialchars(penilaian_kehadiran_rumus_absensi($pdo)) ?>. Predikat 20/40/60/80 tidak diubah.</p>
        <button type="submit" class="btn btn-sm btn-outline-primary">Simpan pengaturan</button>
    </div>
</form>
