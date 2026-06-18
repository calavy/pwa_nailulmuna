<?php

declare(strict_types=1);

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Notifikasi alpa otomatis (fallback)</h2>
        <p class="small text-muted mb-3">
            Dipakai saat <strong>generate alpa</strong> atau cron harian jika tier belum diatur di bawah.
            Terpisah dari notifikasi <strong>permohonan izin</strong> (<a href="?tab=izin">tab Izin</a>).
            Format pesan: <strong>nama santri</strong>, lalu daftar <strong>kegiatan</strong> di bawahnya; beberapa santri per kiriman.
            Jika melebihi batas karakter gateway, otomatis dilanjutkan pesan berikutnya.
        </p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_alpa_penerima">
            <div class="col-md-6">
                <label class="form-label">No. penerima alpa</label>
                <input type="text" class="form-control" name="wa_pengurus" value="<?= htmlspecialchars($values['wa_pengurus']) ?>">
                <div class="form-text">Beberapa nomor: pisah koma.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Jam kirim WA otomatis</label>
                <input type="time" class="form-control input-time-24" name="jam_kirim_wa_auto" value="<?= htmlspecialchars(app_format_jam($values['jam_kirim_wa_auto'])) ?>">
                <div class="form-text">Kosong = kirim langsung.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Batas alpa (mode lama)</label>
                <input type="number" min="1" class="form-control" name="batas_alpa_notif" value="<?= htmlspecialchars($values['batas_alpa_notif']) ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan penerima alpa</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Periode perhitungan alpa</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="save_periode">
            <div class="col-md-5">
                <label class="form-label small">Mode periode</label>
                <select name="periode_mode" class="form-select form-select-sm">
                    <?php foreach (['monthly', 'weekly', 'default'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $periodeMode === $opt ? 'selected' : '' ?>><?= htmlspecialchars(alpa_tier_periode_label($opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Tanggal awal (opsional)</label>
                <input type="date" name="tanggal_mulai" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggalMulaiAlpa) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body pb-0">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <h2 class="h6 mb-0">Tier penerima (ambang alpa)</h2>
            <form method="post" onsubmit="return confirm('Reset log dispatch tier?');">
                <input type="hidden" name="action" value="reset_log">
                <button class="btn btn-outline-danger btn-sm" type="submit">Reset log (<?= $logTotalAlpa ?>)</button>
            </form>
        </div>
        <p class="small text-muted">Saat generate alpa: jika jumlah alpa ≥ ambang tier dan belum pernah dikirim di periode ini → WA ke nomor tier.</p>
    </div>
    <?php foreach ($tiers as $t): ?>
        <form method="post" id="wa-tier-form-<?= (int) $t['id'] ?>"><input type="hidden" name="action" value="save_tier"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"></form>
        <form method="post" id="wa-tier-del-<?= (int) $t['id'] ?>" onsubmit="return confirm('Hapus tier ini?');"><input type="hidden" name="action" value="delete_tier"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"></form>
    <?php endforeach; ?>
    <form method="post" id="wa-tier-form-new"><input type="hidden" name="action" value="save_tier"></form>
    <div class="table-responsive border-top">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Ambang</th><th>Label</th><th>Nomor WA</th><th class="text-center">Aktif</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($tiers as $t):
                    $fid = 'wa-tier-form-' . (int) $t['id'];
                    $did = 'wa-tier-del-' . (int) $t['id'];
                    ?>
                    <tr>
                        <td><input type="number" form="<?= $fid ?>" name="threshold" class="form-control form-control-sm" min="1" value="<?= (int) $t['threshold'] ?>" required></td>
                        <td><input type="text" form="<?= $fid ?>" name="label" class="form-control form-control-sm" value="<?= htmlspecialchars($t['label']) ?>"></td>
                        <td><input type="text" form="<?= $fid ?>" name="wa" class="form-control form-control-sm" value="<?= htmlspecialchars($t['wa']) ?>"></td>
                        <td class="text-center"><input type="checkbox" form="<?= $fid ?>" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-primary" type="submit" form="<?= $fid ?>">Simpan</button>
                            <button class="btn btn-sm btn-outline-danger" type="submit" form="<?= $did ?>">Hapus</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-light">
                    <td><input type="number" form="wa-tier-form-new" name="threshold" class="form-control form-control-sm" min="1" placeholder="5" required></td>
                    <td><input type="text" form="wa-tier-form-new" name="label" class="form-control form-control-sm" placeholder="Wali kelas"></td>
                    <td><input type="text" form="wa-tier-form-new" name="wa" class="form-control form-control-sm" placeholder="628xx" required></td>
                    <td class="text-center"><input type="checkbox" form="wa-tier-form-new" name="is_active" value="1" checked></td>
                    <td class="text-end"><button class="btn btn-sm btn-success" type="submit" form="wa-tier-form-new">+ Tambah</button></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted">Tanpa tier aktif, sistem memakai mode fallback di atas: alpa ≥ batas → kirim ke <strong>No. penerima alpa</strong>.</p>
