<?php

declare(strict_types=1);

if (!function_exists('app_time_input_attrs')) {
    require_once __DIR__ . '/../../helpers/app.php';
}
?>
<div class="modal fade" id="pgIzinSetujuiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Setujui izin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div id="pg-izin-setujui-detail" class="pg-izin-setujui-detail mb-3">
                    <div id="pg-izin-setujui-judul" class="pg-izin-setujui-detail__title"></div>
                    <div id="pg-izin-setujui-sub" class="pg-izin-setujui-detail__sub small text-muted d-none"></div>
                    <dl class="pg-izin-setujui-detail__rows small mb-0">
                        <div class="pg-izin-setujui-detail__row d-none" id="pg-izin-setujui-pemohon-wrap">
                            <dt>Pemohon</dt>
                            <dd id="pg-izin-setujui-pemohon"></dd>
                        </div>
                        <div class="pg-izin-setujui-detail__row" id="pg-izin-setujui-jenis-wrap">
                            <dt>Jenis</dt>
                            <dd id="pg-izin-setujui-jenis"></dd>
                        </div>
                        <div class="pg-izin-setujui-detail__row d-none" id="pg-izin-setujui-keperluan-wrap">
                            <dt>Keperluan</dt>
                            <dd id="pg-izin-setujui-keperluan"></dd>
                        </div>
                        <div class="pg-izin-setujui-detail__row d-none" id="pg-izin-setujui-keterangan-wrap">
                            <dt>Keterangan / alasan</dt>
                            <dd id="pg-izin-setujui-keterangan"></dd>
                        </div>
                        <div class="pg-izin-setujui-detail__row d-none" id="pg-izin-setujui-tujuan-wrap">
                            <dt>Tujuan</dt>
                            <dd id="pg-izin-setujui-tujuan"></dd>
                        </div>
                    </dl>
                </div>
                <div id="pg-izin-setujui-jadwal-wrap" class="d-none">
                    <div class="alert alert-info py-2 mb-3 small">
                        Sesuaikan <strong>tanggal</strong>, <strong>jam</strong>, dan <strong>durasi</strong> bila perlu sebelum setujui. Jadwal tersimpan untuk surat dan QR.
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small mb-1" for="pg-izin-setujui-tanggal-mulai">Tanggal mulai</label>
                            <input type="date" id="pg-izin-setujui-tanggal-mulai" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1" for="pg-izin-setujui-tanggal-selesai">Tanggal selesai</label>
                            <input type="date" id="pg-izin-setujui-tanggal-selesai" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-1" for="pg-izin-setujui-jam-mulai">Jam mulai</label>
                            <input type="text" id="pg-izin-setujui-jam-mulai" class="form-control form-control-sm" <?= app_time_input_attrs() ?> required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-1" for="pg-izin-setujui-jam-selesai">Jam selesai</label>
                            <input type="text" id="pg-izin-setujui-jam-selesai" class="form-control form-control-sm" <?= app_time_input_attrs() ?> required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-1" for="pg-izin-setujui-durasi-jam">Durasi (jam)</label>
                            <input type="number" step="0.25" min="0" id="pg-izin-setujui-durasi-jam" class="form-control form-control-sm" placeholder="3.5">
                        </div>
                    </div>
                </div>
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
