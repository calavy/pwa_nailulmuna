<?php

declare(strict_types=1);

/**
 * Toolbar tahun ajaran pondok (dropdown, sinkron sesi antar halaman).
 *
 * @var PDO $pdo
 * @var array{mulai:int,selesai:int,is_aktif?:bool,label?:string} $pondokTa
 * @var array{mulai:int,selesai:int,is_aktif?:bool,label?:string}|null $keuanganTa alias
 * @var string $pondokTaFormAction
 * @var string $pondokTaFormMethod
 * @var bool $pondokTaPreserveQuery
 * @var bool $pondokTaShowSubmit
 * @var string|null $pondokTaSettingsHref tautan ubah TA aktif
 */
if (!function_exists('pondok_ta_pilihan_options')) {
    require_once __DIR__ . '/../../helpers/pondok_ta.php';
}

$pondokTa = $pondokTa ?? $keuanganTa ?? pondok_ta_resolve($pdo);
$pondokTaFormAction = $pondokTaFormAction ?? ($keuanganTaFormAction ?? '');
$pondokTaFormMethod = strtolower($pondokTaFormMethod ?? ($keuanganTaFormMethod ?? 'get')) === 'post' ? 'post' : 'get';
$pondokTaPreserveQuery = $pondokTaPreserveQuery ?? ($keuanganTaPreserveQuery ?? true);
$pondokTaShowSubmit = $pondokTaShowSubmit ?? ($keuanganTaShowSubmit ?? false);
$pondokTaSettingsHref = $pondokTaSettingsHref ?? app_href('/keuangan/pengaturan.php?bagian=umum&tm=' . (int) $pondokTa['mulai'] . '&ts=' . (int) $pondokTa['selesai']);
$taOptions = pondok_ta_pilihan_options($pdo);
$taAktif = pondok_tahun_ajaran_aktif($pdo);
$isAktif = !empty($pondokTa['is_aktif']);
$taLabel = (string) ($pondokTa['label'] ?? pondok_tahun_ajaran_label($pdo, $pondokTa));
$bulanAwal = pondok_ta_bulan_awal($pdo);
$bulanMap = pondok_bulan_nama_map($pdo);
$bulanAwalLabel = $bulanMap[$bulanAwal] ?? (string) $bulanAwal;

$preserve = [];
if ($pondokTaPreserveQuery && $pondokTaFormMethod === 'get') {
    foreach ($_GET as $k => $v) {
        if (!is_string($k) || in_array($k, ['tm', 'ts', 'tahun_ajaran_mulai', 'tahun_ajaran_selesai'], true)) {
            continue;
        }
        if (is_scalar($v)) {
            $preserve[$k] = (string) $v;
        }
    }
}
?>
<div class="card shadow-sm mb-3 pondok-ta-toolbar keuangan-ta-toolbar border-0">
    <div class="card-body py-2 px-3">
        <form class="row g-2 align-items-end pondok-ta-toolbar-form keuangan-ta-toolbar-form" method="<?= htmlspecialchars($pondokTaFormMethod) ?>" action="<?= htmlspecialchars($pondokTaFormAction) ?>">
            <?php foreach ($preserve as $pk => $pv): ?>
                <input type="hidden" name="<?= htmlspecialchars($pk) ?>" value="<?= htmlspecialchars($pv) ?>">
            <?php endforeach; ?>
            <div class="col-auto">
                <span class="small text-muted text-uppercase fw-semibold d-block mb-1">Tahun ajaran</span>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <select class="form-select form-select-sm pondok-ta-select keuangan-ta-select" name="tm" id="pondok-ta-select" style="min-width:11rem" <?= $pondokTaShowSubmit ? '' : 'data-auto-submit="1"' ?>>
                        <?php foreach ($taOptions as $opt): ?>
                            <?php $m = (int) $opt['mulai']; ?>
                            <option value="<?= $m ?>"
                                    data-ts="<?= (int) $opt['selesai'] ?>"
                                <?= $m === (int) $pondokTa['mulai'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $opt['label']) ?>
                                <?php if (!empty($opt['is_aktif'])): ?> · aktif<?php endif; ?>
                                <?php if (!empty($opt['is_berjalan']) && empty($opt['is_aktif'])): ?> · berjalan<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="ts" class="pondok-ta-ts-hidden keuangan-ta-ts-hidden" value="<?= (int) $pondokTa['selesai'] ?>">
                    <?php if ($pondokTaShowSubmit): ?>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-check me-1"></i> Terapkan</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col">
                <?php if ($isAktif): ?>
                    <span class="badge text-bg-success"><i class="fa-solid fa-circle-check me-1"></i> TA aktif pondok</span>
                <?php else: ?>
                    <span class="badge text-bg-warning text-dark"><i class="fa-solid fa-eye me-1"></i> Lihat: <?= htmlspecialchars($taLabel) ?></span>
                    <span class="small text-muted ms-1">TA aktif: <strong><?= htmlspecialchars(pondok_tahun_ajaran_label($pdo, $taAktif)) ?></strong></span>
                    <a class="btn btn-link btn-sm p-0 ms-2 align-baseline" href="<?= htmlspecialchars($pondokTaSettingsHref) ?>">Ubah TA aktif</a>
                <?php endif; ?>
                <span class="small text-muted d-block mt-1">Awal TA: bulan <strong><?= htmlspecialchars($bulanAwalLabel) ?></strong> (slot 1 tagihan)</span>
            </div>
        </form>
    </div>
</div>
