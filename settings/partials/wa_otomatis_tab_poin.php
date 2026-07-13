<?php

declare(strict_types=1);

?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">WA otomatis ambang poin</h2>
        <p class="small text-muted mb-3">
            Saat total poin kedisiplinan santri dalam <strong>bulan berjalan</strong> mencapai ambang (mis. 5, 10, 15),
            sistem mengirim WA ke pengurus. Tiap ambang bisa punya <strong>jam kirim</strong> sendiri.
            Kosongkan jam = kirim segera setelah ambang tercapai. Nomor kosong = pakai nomor pengurus default.
            Poin auto ALPA/telat sebelum tanggal mulai scan keaktivan tidak dihitung.
        </p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="save_poin_wa_enabled">
            <input type="hidden" name="redirect_tab" value="poin">
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="poinWaOn" name="poin_wa_notif_enabled" value="1"
                        <?= $poinWaEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="poinWaOn">Aktifkan notifikasi ambang poin</label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success btn-sm">Simpan status</button>
            </div>
            <div class="col-12">
                <div class="form-text">
                    Penerima default:
                    <code><?= htmlspecialchars(trim((string) ($values['wa_pengurus'] ?? '')) !== '' ? (string) $values['wa_pengurus'] : '(belum diisi — atur di tab Alpa / Gateway)') ?></code>
                    · Template: <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=template')) ?>">poin_ambang_pengurus</a>
                </div>
            </div>
        </form>
        <?php if (!empty($poinWaLastCronAt)): ?>
            <p class="small text-muted mb-0 mt-2">Cron terakhir: <?= htmlspecialchars((string) $poinWaLastCronAt) ?>
                <?php if (is_array($poinWaLastCronStats ?? null)): ?>
                    · cek <?= (int) ($poinWaLastCronStats['checked'] ?? 0) ?>,
                    kirim <?= (int) ($poinWaLastCronStats['sent'] ?? 0) ?>,
                    menunggu jam <?= (int) ($poinWaLastCronStats['pending'] ?? 0) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body pb-0">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <h2 class="h6 mb-0">Ambang &amp; jam kirim</h2>
            <form method="post" onsubmit="return confirm('Reset log kirim ambang poin bulan ini / semua?');">
                <input type="hidden" name="action" value="reset_poin_tier_log">
                <input type="hidden" name="redirect_tab" value="poin">
                <button class="btn btn-outline-danger btn-sm" type="submit">Reset log (<?= (int) $poinLogTotal ?>)</button>
            </form>
        </div>
        <p class="small text-muted">Contoh: ambang 5 jam 07:00, ambang 10 jam 12:00, ambang 15 jam 16:00. Sekali kirim per santri per ambang per bulan.</p>
    </div>
    <?php foreach ($poinTiers as $t): ?>
        <form method="post" id="poin-tier-form-<?= (int) $t['id'] ?>">
            <input type="hidden" name="action" value="save_poin_tier">
            <input type="hidden" name="redirect_tab" value="poin">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        </form>
        <form method="post" id="poin-tier-del-<?= (int) $t['id'] ?>" onsubmit="return confirm('Hapus ambang ini?');">
            <input type="hidden" name="action" value="delete_poin_tier">
            <input type="hidden" name="redirect_tab" value="poin">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        </form>
    <?php endforeach; ?>
    <form method="post" id="poin-tier-form-new">
        <input type="hidden" name="action" value="save_poin_tier">
        <input type="hidden" name="redirect_tab" value="poin">
    </form>
    <div class="table-responsive border-top">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr class="small">
                    <th>Ambang</th>
                    <th>Label</th>
                    <th>No. WA (opsional)</th>
                    <th>Jam kirim</th>
                    <th>Aktif</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($poinTiers as $t): ?>
                <tr>
                    <td style="width:5rem">
                        <input form="poin-tier-form-<?= (int) $t['id'] ?>" type="number" min="1" class="form-control form-control-sm"
                               name="threshold" value="<?= (int) $t['threshold'] ?>" required>
                    </td>
                    <td>
                        <input form="poin-tier-form-<?= (int) $t['id'] ?>" type="text" class="form-control form-control-sm"
                               name="label" value="<?= htmlspecialchars((string) $t['label']) ?>" placeholder="Label">
                    </td>
                    <td>
                        <input form="poin-tier-form-<?= (int) $t['id'] ?>" type="text" class="form-control form-control-sm"
                               name="wa" value="<?= htmlspecialchars((string) $t['wa']) ?>" placeholder="Kosong = pengurus default">
                    </td>
                    <td style="width:7rem">
                        <input form="poin-tier-form-<?= (int) $t['id'] ?>" type="time" class="form-control form-control-sm input-time-24"
                               name="jam_kirim" value="<?= htmlspecialchars((string) $t['jam_kirim']) ?>">
                    </td>
                    <td class="text-center">
                        <input form="poin-tier-form-<?= (int) $t['id'] ?>" class="form-check-input" type="checkbox" name="is_active" value="1"
                            <?= (int) $t['is_active'] === 1 ? 'checked' : '' ?>>
                    </td>
                    <td class="text-end text-nowrap">
                        <button form="poin-tier-form-<?= (int) $t['id'] ?>" class="btn btn-outline-primary btn-sm" type="submit">Simpan</button>
                        <button form="poin-tier-del-<?= (int) $t['id'] ?>" class="btn btn-outline-danger btn-sm" type="submit">Hapus</button>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr class="table-light">
                    <td>
                        <input form="poin-tier-form-new" type="number" min="1" class="form-control form-control-sm"
                               name="threshold" placeholder="mis. 20" required>
                    </td>
                    <td>
                        <input form="poin-tier-form-new" type="text" class="form-control form-control-sm"
                               name="label" placeholder="Label baru">
                    </td>
                    <td>
                        <input form="poin-tier-form-new" type="text" class="form-control form-control-sm"
                               name="wa" placeholder="Opsional">
                    </td>
                    <td>
                        <input form="poin-tier-form-new" type="time" class="form-control form-control-sm input-time-24"
                               name="jam_kirim" value="07:00">
                    </td>
                    <td class="text-center">
                        <input form="poin-tier-form-new" class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                    </td>
                    <td class="text-end">
                        <button form="poin-tier-form-new" class="btn btn-success btn-sm" type="submit">Tambah</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
