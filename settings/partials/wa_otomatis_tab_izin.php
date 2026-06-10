<?php

declare(strict_types=1);

?>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <h2 class="h6 mb-2">Notifikasi saat izin disetujui</h2>
        <p class="small text-muted mb-3">Kirim ke pembimbing terkait santri dan/atau grup Fonte. Template pesan di tab Template.</p>
        <form method="post">
            <input type="hidden" name="action" value="save_izin_wa">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="wa_izin_pembimbing_enabled" name="wa_izin_pembimbing_enabled" value="1" <?= $waIzinEnabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="wa_izin_pembimbing_enabled">Kirim WA ke pembimbing terkait</label>
            </div>
            <p class="small text-muted">Toggle per pembimbing di <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>">Data Pembimbing</a>.</p>
            <div class="border rounded-3 p-3 bg-light mb-3">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="wa_izin_grup_fonte_enabled" name="wa_izin_grup_fonte_enabled" value="1" <?= $waIzinGrupFonteEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="wa_izin_grup_fonte_enabled">Kirim ke grup WA (Fonte)</label>
                </div>
                <label class="form-label small">ID / kode grup</label>
                <input type="text" class="form-control font-monospace" name="wa_izin_grup_fonte" value="<?= htmlspecialchars($waIzinGrupFonte) ?>" placeholder="120363xxxxx@g.us">
                <div class="form-text">Salin dari panel Fonte → Grup. Bisa beberapa target dipisah koma.</div>
            </div>
            <details class="small mb-3">
                <summary class="text-muted">Pengaturan lama (nomor tambahan)</summary>
                <div class="form-check mt-2 mb-2">
                    <input class="form-check-input" type="checkbox" id="wa_izin_pembimbing_kirim_grup" name="wa_izin_pembimbing_kirim_grup" value="1" <?= $waIzinKirimGrup ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_izin_pembimbing_kirim_grup">Mode lama: kirim ke nomor di bawah</label>
                </div>
                <input type="text" class="form-control form-control-sm" name="wa_izin_pembimbing_grup" value="<?= htmlspecialchars($waIzinGrup) ?>" placeholder="628xxx">
            </details>
            <button type="submit" class="btn btn-success btn-sm">Simpan izin WA</button>
        </form>
    </div>
</div>
