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
                        <div class="pg-izin-setujui-detail__row">
                            <dt>Waktu</dt>
                            <dd id="pg-izin-setujui-tanggal"></dd>
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
