<?php

declare(strict_types=1);

/**
 * Banner tahun ajaran aktif (bukan dropdown — TA diatur terpusat di pengaturan keuangan).
 *
 * @var PDO $pdo
 * @var array{mulai:int,selesai:int,is_aktif?:bool,label?:string}|null $pondokTa
 * @var array{mulai:int,selesai:int,is_aktif?:bool,label?:string}|null $keuanganTa
 * @var string|null $pondokTaSettingsHref
 */
if (!function_exists('pondok_ta_resolve')) {
    require_once __DIR__ . '/../../helpers/pondok_ta.php';
}

$pondokTa = pondok_ta_resolve($pdo);
$taLabel = (string) ($pondokTa['label'] ?? pondok_tahun_ajaran_label($pdo, $pondokTa));
$settingsHref = $pondokTaSettingsHref ?? pondok_ta_central_settings_href();
$canEditTa = pondok_ta_user_can_edit_central($pdo);
$bulanAwal = pondok_ta_bulan_awal($pdo);
$bulanMap = pondok_bulan_nama_map($pdo);
$bulanAwalLabel = $bulanMap[$bulanAwal] ?? (string) $bulanAwal;
$kalenderLabel = pondok_kalender_hijriyah($pdo) ? 'Hijriyah' : 'Masehi';
?>
<div class="card shadow-sm mb-3 pondok-ta-banner keuangan-ta-banner border-0 bg-light">
    <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <span class="small text-muted text-uppercase fw-semibold d-block">Tahun ajaran aktif</span>
            <span class="fw-semibold fs-6"><?= htmlspecialchars($taLabel) ?></span>
            <span class="small text-muted ms-1">· kalender <?= htmlspecialchars($kalenderLabel) ?> · awal slot 1: <strong><?= htmlspecialchars($bulanAwalLabel) ?></strong></span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge text-bg-success"><i class="fa-solid fa-circle-check me-1"></i> Terpusat</span>
            <?php if ($canEditTa): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($settingsHref) ?>">
                    <i class="fa-solid fa-gear me-1"></i> Ubah di pengaturan
                </a>
            <?php else: ?>
                <span class="small text-muted">Ubah TA: menu Keuangan → Pengaturan (admin keuangan)</span>
            <?php endif; ?>
        </div>
    </div>
</div>
