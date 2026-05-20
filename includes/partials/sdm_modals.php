<?php

declare(strict_types=1);

/** Modal iframe untuk formulir SDM (tambah santri, keluar, edit). */
?>
<div class="modal fade" id="sdmModalForm" tabindex="-1" aria-labelledby="sdmModalFormLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6" id="sdmModalFormLabel">Formulir</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="sdmModalIframe" class="w-100 border-0" style="min-height:75vh" title="Formulir SDM"></iframe>
            </div>
        </div>
    </div>
</div>
