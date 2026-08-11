<?php

declare(strict_types=1);

/**
 * Modal detail slot jadwal (mobile tap / desktop context menu).
 */
?>
<div class="modal fade" id="jadwalDetailModal" tabindex="-1" aria-labelledby="jadwalDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0" id="jadwalDetailModalLabel">Detail jadwal</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body py-3">
                <dl class="jadwal-detail-dl mb-0 small">
                    <dt>Jam</dt>
                    <dd id="jd-jam" class="fw-semibold">—</dd>
                    <dt>Kegiatan</dt>
                    <dd id="jd-kegiatan">—</dd>
                    <dt>Tingkatan</dt>
                    <dd id="jd-tingkatan">—</dd>
                    <dt>Pembimbing</dt>
                    <dd id="jd-pembimbing">—</dd>
                    <dt>Tempat</dt>
                    <dd id="jd-tempat">—</dd>
                </dl>
            </div>
            <div class="modal-footer py-2 flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm jadwal-detail-edit"><i class="fa-solid fa-pen me-1"></i>Edit</button>
                <a href="#" class="btn btn-outline-secondary btn-sm jadwal-detail-full" id="jd-link-full"><i class="fa-solid fa-up-right-from-square me-1"></i>Form lengkap</a>
                <button type="button" class="btn btn-outline-danger btn-sm jadwal-detail-delete jadwal-delete-one"><i class="fa-solid fa-trash me-1"></i>Hapus</button>
            </div>
        </div>
    </div>
</div>
