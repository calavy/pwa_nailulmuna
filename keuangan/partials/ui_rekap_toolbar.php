<?php

declare(strict_types=1);

/**
 * Toolbar rekap kas: toggle view, filter TA, aksi sekunder.
 *
 * @var string $view pondok|full
 * @var string $baseQs query tanpa view
 * @var array{tahun_mulai:int,tahun_selesai:int,bulan_berjalan:int} $rekap
 * @var array{mulai:int,selesai:int,bulan:int} $periode
 */
$view = (string) ($view ?? 'pondok');
$baseQs = (string) ($baseQs ?? '');
$showSaku = $view === 'full';
$printQs = 'tm=' . (int) ($rekap['tahun_mulai'] ?? 0)
    . '&ts=' . (int) ($rekap['tahun_selesai'] ?? 0)
    . '&bulan=' . (int) ($rekap['bulan_berjalan'] ?? 0)
    . '&view=' . rawurlencode($view)
    . '&print=1';
?>
<div class="keu-rekap-toolbar mb-3">
    <div class="keu-rekap-toolbar__view btn-group btn-group-sm mb-2 mb-md-0" role="group" aria-label="Tampilan rekap kas">
        <a class="btn btn-outline-secondary<?= !$showSaku ? ' active' : '' ?>"
           href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php?' . $baseQs . '&view=pondok')) ?>">Kas pondok</a>
        <a class="btn btn-outline-secondary<?= $showSaku ? ' active' : '' ?>"
           href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php?' . $baseQs . '&view=full')) ?>">Lengkap (+ titipan saku)</a>
    </div>

    <div class="keu-filter-bar keu-rekap-toolbar__filter">
        <form method="get" class="row g-2 align-items-end keu-rekap-filter-form">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="col-6 col-md-2 col-lg-auto">
                <label class="form-label small mb-0">TA mulai</label>
                <input type="number" name="tm" class="form-control form-control-sm" value="<?= (int) ($rekap['tahun_mulai'] ?? 0) ?>" min="1300" max="2105">
            </div>
            <div class="col-6 col-md-2 col-lg-auto">
                <label class="form-label small mb-0">TA selesai</label>
                <input type="number" name="ts" class="form-control form-control-sm" value="<?= (int) ($rekap['tahun_selesai'] ?? 0) ?>" min="1300" max="2105">
            </div>
            <div class="col-12 col-md-3 col-lg-auto">
                <label class="form-label small mb-0">Sampai bulan</label>
                <select name="bulan" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int) ($rekap['bulan_berjalan'] ?? 0) ? 'selected' : '' ?>>
                            Bulan <?= $m ?><?= $m === (int) ($periode['bulan'] ?? 0) ? ' (berjalan)' : '' ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php?view=' . rawurlencode($view))) ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="keu-secondary-actions d-flex flex-wrap gap-2 mt-2 mt-lg-0">
        <a class="btn btn-outline-primary btn-sm" href="#rekap-rincian-pos">
            <i class="fa-solid fa-arrow-down me-1" aria-hidden="true"></i> Rincian POS
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php?' . $printQs)) ?>" target="_blank" rel="noopener">
            <i class="fa-solid fa-print me-1" aria-hidden="true"></i> Cetak / PDF
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/arus-kas.php')) ?>">Arus kas</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_href()) ?>">Riwayat</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/keuangan/neraca.php')) ?>">Neraca</a>
    </div>
</div>
