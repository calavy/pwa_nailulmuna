<?php

declare(strict_types=1);

/**
 * Langkah 2 wizard: pilih metode input soal.
 *
 * @var string $initialMetode 'import' | 'manual' | ''
 */
$initialMetode = $initialMetode ?? '';

?>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <p class="text-muted small mb-3">Pilih cara mengisi soal. Anda tetap bisa mengoreksi soal di langkah berikutnya.</p>
        <input type="hidden" name="input_metode" id="input_metode" value="<?= htmlspecialchars($initialMetode) ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="ikhtibar-metode-card w-100<?= $initialMetode === 'import' ? ' is-selected' : '' ?>" data-metode="import">
                    <input type="radio" name="input_metode_radio" value="import" class="visually-hidden"<?= $initialMetode === 'import' ? ' checked' : '' ?>>
                    <div class="ikhtibar-metode-card-inner">
                        <div class="ikhtibar-metode-icon text-primary"><i class="fa-solid fa-file-import fa-lg"></i></div>
                        <div class="ikhtibar-metode-title fw-semibold">Import otomatis</div>
                        <div class="small text-muted">Word, Excel, Google Sheets/Docs, OCR kamera, atau tempel teks.</div>
                    </div>
                </label>
            </div>
            <div class="col-md-6">
                <label class="ikhtibar-metode-card w-100<?= $initialMetode === 'manual' ? ' is-selected' : '' ?>" data-metode="manual">
                    <input type="radio" name="input_metode_radio" value="manual" class="visually-hidden"<?= $initialMetode === 'manual' ? ' checked' : '' ?>>
                    <div class="ikhtibar-metode-card-inner">
                        <div class="ikhtibar-metode-icon text-success"><i class="fa-solid fa-pen-to-square fa-lg"></i></div>
                        <div class="ikhtibar-metode-title fw-semibold">Input manual</div>
                        <div class="small text-muted">Isi soal PG dan esai langsung di form.</div>
                    </div>
                </label>
            </div>
        </div>
    </div>
</div>
