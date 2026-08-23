<?php

declare(strict_types=1);

/**
 * Saklar super admin: hitung ALPA jika Jama'ah/Ta'lim tanpa scan.
 *
 * @var PDO $pdo
 */
if (!isset($pdo) || !($pdo instanceof PDO) || !function_exists('is_super_admin') || !is_super_admin()) {
    return;
}
require_once __DIR__ . '/../../helpers/keaktifan_alpa_tanpa_scan.php';
$alpaTanpaScanOn = keaktifan_alpa_jika_tanpa_scan_enabled($pdo);
?>
<form method="post" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <input type="hidden" name="action" value="save_keaktifan_alpa_tanpa_scan">
        <input type="hidden" name="keaktifan_alpa_jika_tanpa_scan" value="0">
        <div class="form-check form-switch mb-2">
            <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                id="keaktifanAlpaTanpaScan"
                name="keaktifan_alpa_jika_tanpa_scan"
                value="1"
                <?= $alpaTanpaScanOn ? ' checked' : '' ?>
            >
            <label class="form-check-label fw-semibold" for="keaktifanAlpaTanpaScan">
                Hitung ALPA jika Jama'ah/Ta'lim tanpa scan
            </label>
        </div>
        <p class="small text-muted mb-2">
            Aktif: slot tanpa satu pun HADIR menandai santri terjadwal ALPA.
            Nonaktif: slot kosong (petugas tidak scan) tidak dihitung ALPA.
            Laporan tanpa scan: Jama'ah 1 per kegiatan/waktu; Ta'lim ikut dihitung.
        </p>
        <button type="submit" class="btn btn-sm btn-outline-primary">Simpan pengaturan</button>
    </div>
</form>
