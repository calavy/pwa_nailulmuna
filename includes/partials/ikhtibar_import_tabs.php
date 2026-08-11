<?php

declare(strict_types=1);

/**
 * Tab import soal ikhtibar.
 *
 * @var bool $aiOcrEnabled
 * @var string $googleTemplateUrl
 * @var string $templateXlsxUrl
 */
$aiOcrEnabled = $aiOcrEnabled ?? false;
$googleTemplateUrl = $googleTemplateUrl ?? '';
$templateXlsxUrl = $templateXlsxUrl ?? '';
$initialMetode = $initialMetode ?? '';

?>
<div id="wrap-import-tabs" class="border rounded mb-3 bg-light-subtle ikhtibar-import-tabs<?= $initialMetode === 'manual' ? ' d-none' : '' ?>">
    <div class="p-3 pb-0">
        <h3 class="h6 mb-1">Import soal</h3>
        <p class="small text-muted mb-2">
            Format Word/Docs: nomor soal, opsi A–E, baris <code>kunci: A</code>.
            Sheets/Excel: gunakan template kolom yang sama. Teks Arab didukung.
            <?php if ($aiOcrEnabled): ?>
                <span class="badge text-bg-info ms-1">AI OCR aktif</span>
            <?php endif; ?>
        </p>
    </div>

    <ul class="nav nav-tabs px-3 ikhtibar-import-nav" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-ocr" data-bs-toggle="tab" data-bs-target="#pane-ocr" type="button" role="tab">
                <i class="fa-solid fa-camera me-1"></i> Kamera / OCR
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-upload" data-bs-toggle="tab" data-bs-target="#pane-upload" type="button" role="tab">
                <i class="fa-solid fa-file-arrow-up me-1"></i> Upload File
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-google" data-bs-toggle="tab" data-bs-target="#pane-google" type="button" role="tab">
                <i class="fa-brands fa-google-drive me-1"></i> Link Google
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-paste" data-bs-toggle="tab" data-bs-target="#pane-paste" type="button" role="tab">
                <i class="fa-solid fa-paste me-1"></i> Tempel Teks
            </button>
        </li>
    </ul>

    <div class="tab-content p-3 ikhtibar-import-tab-content">
        <div class="tab-pane fade show active" id="pane-ocr" role="tabpanel" aria-labelledby="tab-ocr">
            <label class="form-label small">Kamera / foto soal (OCR Arab)</label>
            <input type="file" id="ocr_file" accept="image/*" capture="environment" class="form-control form-control-sm">
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btn-ocr-run">
                <i class="fa-solid fa-camera me-1"></i> Scan teks Arab
            </button>
            <div id="ocr_status" class="small text-muted mt-2"></div>
        </div>

        <div class="tab-pane fade" id="pane-upload" role="tabpanel" aria-labelledby="tab-upload">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small">Upload Word (.docx)</label>
                    <input type="file" name="import_docx" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="form-control form-control-sm">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Upload Excel (.xlsx)</label>
                    <input type="file" name="import_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control form-control-sm">
                    <?php if ($templateXlsxUrl !== ''): ?>
                        <a class="small d-inline-block mt-1" href="<?= htmlspecialchars($templateXlsxUrl) ?>">Unduh template Excel</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pane-google" role="tabpanel" aria-labelledby="tab-google">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small">Link Google Sheets (dibagikan publik)</label>
                    <input type="url" name="import_google_sheet" class="form-control form-control-sm" placeholder="https://docs.google.com/spreadsheets/d/...">
                    <div class="form-text">Bagikan sheet: <strong>Anyone with the link → Viewer</strong>. Tempel URL dari address bar (bukan embed).</div>
                    <?php if ($googleTemplateUrl !== ''): ?>
                        <a class="small d-inline-block mt-1" href="<?= htmlspecialchars($googleTemplateUrl) ?>" target="_blank" rel="noopener">Salin template Google Sheets</a>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Link Google Docs (dibagikan publik)</label>
                    <input type="url" name="import_google_doc" class="form-control form-control-sm" placeholder="https://docs.google.com/document/d/...">
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pane-paste" role="tabpanel" aria-labelledby="tab-paste">
            <label class="form-label small">Tempel teks soal</label>
            <textarea name="ocr_teks_import" id="ocr_teks_import" class="form-control font-monospace small" rows="6" placeholder="Teks hasil scan atau tempel manual…"></textarea>
        </div>
    </div>

    <div class="px-3 pb-3">
        <button type="button" class="btn btn-sm btn-outline-info" id="btn-preview-import">
            <i class="fa-solid fa-eye me-1"></i> Pratinjau import (seperti portal santri)
        </button>
    </div>
</div>
