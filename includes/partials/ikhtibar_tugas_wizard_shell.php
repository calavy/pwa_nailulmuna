<?php

declare(strict_types=1);

/**
 * Shell wizard 3 langkah buat/edit tugas ikhtibar.
 *
 * @var array<string,mixed>|null $tugas
 * @var string $mapelMode
 * @var list<array<string,mixed>> $kelasMapelOptions
 * @var list<array<string,mixed>> $pkppsKelasOptions
 * @var bool $wajibPilihMapel
 * @var bool $wajibPilihJadwal
 * @var string $selKelasKey
 * @var bool $perluBuatPinTugas
 * @var string $statusTugas
 * @var string $initialMetode
 * @var list<int> $kuotaPg
 * @var list<int> $kuotaEsai
 * @var int $jumlahPg
 * @var int $jumlahEsai
 * @var bool $aiOcrEnabled
 * @var string $googleTemplateUrl
 * @var string $templateXlsxUrl
 * @var list<array<string,mixed>> $kriteriaList
 * @var bool $hasActiveSesi
 * @var bool $bolehPratinjau
 * @var string $cancelUrl
 * @var string $pratinjauUrl
 */
$tugas = $tugas ?? null;
$hasActiveSesi = $hasActiveSesi ?? false;
$statusTugas = $statusTugas ?? 'draft';
$bolehPratinjau = $bolehPratinjau ?? false;
$cancelUrl = $cancelUrl ?? '#';
$pratinjauUrl = $pratinjauUrl ?? '';

?>
<form method="post" enctype="multipart/form-data" id="form-ikhtibar" novalidate>
    <?php if ($tugas): ?><input type="hidden" name="id" value="<?= (int) $tugas['id'] ?>"><?php endif; ?>

    <nav class="ikhtibar-wizard-steps mb-3" aria-label="Langkah pembuatan tugas">
        <ol class="ikhtibar-wizard-steps-list list-unstyled mb-0">
            <li class="ikhtibar-wizard-step is-active" data-step="1">
                <span class="ikhtibar-wizard-step-num">1</span>
                <span class="ikhtibar-wizard-step-label">Info dasar</span>
            </li>
            <li class="ikhtibar-wizard-step" data-step="2">
                <span class="ikhtibar-wizard-step-num">2</span>
                <span class="ikhtibar-wizard-step-label">Metode input</span>
            </li>
            <li class="ikhtibar-wizard-step" data-step="3">
                <span class="ikhtibar-wizard-step-num">3</span>
                <span class="ikhtibar-wizard-step-label">Isi soal</span>
            </li>
        </ol>
    </nav>

    <div class="ikhtibar-wizard-panel" data-wizard-step="1">
        <?php require __DIR__ . '/ikhtibar_tugas_meta_form.php'; ?>
    </div>

    <div class="ikhtibar-wizard-panel d-none" data-wizard-step="2">
        <?php require __DIR__ . '/ikhtibar_tugas_step_metode.php'; ?>
    </div>

    <div class="ikhtibar-wizard-panel d-none" data-wizard-step="3">
        <?php require __DIR__ . '/ikhtibar_tugas_step_soal.php'; ?>
    </div>

    <div class="ikhtibar-wizard-footer sticky-bottom bg-body border-top mt-3 py-3 px-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="ikhtibar-wizard-nav-steps d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary d-none" id="btn-wizard-back">
                    <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                </button>
                <button type="button" class="btn btn-primary" id="btn-wizard-next">
                    Lanjut <i class="fa-solid fa-arrow-right ms-1"></i>
                </button>
            </div>
            <div class="ikhtibar-wizard-nav-submit d-none flex-wrap gap-2 ms-auto">
                <?php if ($bolehPratinjau && $pratinjauUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($pratinjauUrl) ?>" class="btn btn-outline-info" target="_blank" rel="noopener">
                        <i class="fa-solid fa-eye me-1"></i> Pratinjau tersimpan
                    </a>
                <?php elseif ($tugas && ikhtibar_tugas_status_draf($tugas)): ?>
                    <span class="small text-muted align-self-center">Pratinjau membutuhkan PIN draf.</span>
                <?php endif; ?>
                <button type="submit" name="action" value="simpan" class="btn btn-outline-secondary"<?= $hasActiveSesi && $statusTugas === 'published' ? ' disabled title="Tugas sudah aktif"' : '' ?>>Simpan draf</button>
                <button type="submit" name="publish" value="1" class="btn btn-primary">Publikasikan tugas</button>
                <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn btn-link">Batal</a>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="modal-preview-import" tabindex="-1" aria-labelledby="modalPreviewLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6" id="modalPreviewLabel">Pratinjau soal (tampilan portal santri)</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-2" id="preview-import-body" style="background:#f1f5f9;max-height:70vh;overflow-y:auto;">
                <p class="text-muted small mb-0">Memuat…</p>
            </div>
            <div class="modal-footer py-2 flex-wrap">
                <div id="preview-import-errors" class="small text-danger me-auto"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-sm btn-primary" id="btn-apply-preview" disabled>Terapkan ke form</button>
            </div>
        </div>
    </div>
</div>
