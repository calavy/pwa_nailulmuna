<?php

declare(strict_types=1);

/**
 * Langkah 3 wizard: kuota, import tabs, form soal.
 *
 * @var list<int> $kuotaPg
 * @var list<int> $kuotaEsai
 * @var int $jumlahPg
 * @var int $jumlahEsai
 * @var bool $aiOcrEnabled
 * @var string $googleTemplateUrl
 * @var string $templateXlsxUrl
 * @var list<array<string,mixed>> $kriteriaList
 */
$kuotaPg = $kuotaPg ?? [];
$kuotaEsai = $kuotaEsai ?? [];
$jumlahPg = $jumlahPg ?? 10;
$jumlahEsai = $jumlahEsai ?? 0;
$aiOcrEnabled = $aiOcrEnabled ?? false;
$googleTemplateUrl = $googleTemplateUrl ?? '';
$templateXlsxUrl = $templateXlsxUrl ?? '';
$kriteriaList = $kriteriaList ?? [];

?>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label" for="jumlah_pg">Pilihan ganda (PG)</label>
                <select name="jumlah_pg" id="jumlah_pg" class="form-select">
                    <?php foreach ($kuotaPg as $k): ?>
                        <option value="<?= $k ?>" <?= $jumlahPg === $k ? 'selected' : '' ?>><?= $k ?> soal</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="jumlah_esai">Isian singkat / Esai</label>
                <select name="jumlah_esai" id="jumlah_esai" class="form-select">
                    <option value="0" <?= $jumlahEsai === 0 ? 'selected' : '' ?>>Tidak ada</option>
                    <?php foreach ($kuotaEsai as $k): ?>
                        <option value="<?= $k ?>" <?= $jumlahEsai === $k ? 'selected' : '' ?>><?= $k ?> soal</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php require __DIR__ . '/ikhtibar_import_tabs.php'; ?>

        <div id="wrap-pg-global" class="d-none mb-2">
            <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                <label class="form-label small mb-0" for="pg_opsi_global">Default pilihan jawaban:</label>
                <select id="pg_opsi_global" class="form-select form-select-sm" style="max-width:140px">
                    <option value="2">A–B</option>
                    <option value="3">A–C</option>
                    <option value="4" selected>A–D</option>
                    <option value="5">A–E</option>
                </select>
                <span class="small text-muted">Berlaku untuk semua soal PG.</span>
            </div>
        </div>

        <div id="wrap-pg"></div>
        <div id="wrap-esai" class="mt-3"></div>

        <?php if ($kriteriaList !== []): ?>
        <div class="alert alert-light border small mt-3 mb-0">
            <strong>Kunci esai + nilai otomatis:</strong> gunakan format <code>[KODE] kata1, kata2</code> per baris.
            Kriteria aktif:
            <?php foreach ($kriteriaList as $kr): ?>
                <span class="badge text-bg-secondary me-1"><?= htmlspecialchars((string) ($kr['kode'] ?? '')) ?> (<?= htmlspecialchars((string) ($kr['bobot_persen'] ?? '0')) ?>%)</span>
            <?php endforeach; ?>
            · <a href="<?= htmlspecialchars(app_href('/settings/ikhtibar_kriteria.php')) ?>">Atur kriteria</a>
        </div>
        <?php endif; ?>
    </div>
</div>
