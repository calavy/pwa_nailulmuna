<?php

declare(strict_types=1);
?>
<div class="modal fade" id="pgIzinSetujuiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Setujui izin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div id="pg-izin-setujui-judul" class="fw-semibold"></div>
                <div id="pg-izin-setujui-tanggal" class="small text-muted mb-3"></div>
                <div id="pg-izin-setujui-alpa" class="alert py-2 small mb-3 izin-alpa-panel-modal"></div>
                <div id="pg-izin-setujui-bypass-wrap" class="form-check d-none">
                    <input class="form-check-input" type="checkbox" id="pg-izin-setujui-bypass" value="1">
                    <label class="form-check-label" for="pg-izin-setujui-bypass">Lewati syarat ALPA</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="pg-izin-setujui-submit">Setujui</button>
            </div>
        </div>
    </div>
</div>
