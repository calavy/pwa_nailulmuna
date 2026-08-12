<?php

declare(strict_types=1);

?>
<?php $delayKind = 'alpa'; require __DIR__ . '/wa_otomatis_delay_card.php'; ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Notifikasi alpa otomatis</h2>
        <p class="small text-muted mb-3">
            Dilapor <strong>hanya saat santri baru menyentuh/melewati ambang</strong> (mis. 5, lalu 10),
            termasuk saat <strong>jam kirim otomatis</strong>.
            Loncatan 3→8 tetap dilapor sekali untuk ambang 5 (total di pesan = 8).
            Yang sudah dilapor untuk ambang 5 <strong>tidak dikirim ulang</strong> setiap hari hanya karena masih ≥ 5.
            Format: template <a href="?tab=template">Laporan ALPA kelipatan</a> (menampilkan jumlah <strong>poin</strong>).
            <code>Batas alpa</code> dipakai seed kelipatan poin jika tier di bawah masih kosong (5 → 5,10,15,… poin).
        </p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_alpa_penerima">
            <div class="col-md-6">
                <label class="form-label">No. penerima alpa (fallback)</label>
                <input type="text" class="form-control" name="wa_pengurus" value="<?= htmlspecialchars($values['wa_pengurus']) ?>">
                <div class="form-text">Dipakai jika nomor di baris tier kosong. Beberapa nomor: pisah koma. Kelola di <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php?peran=pengurus')) ?>">Nomor WhatsApp</a>.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Jam kirim WA otomatis</label>
                <input type="time" class="form-control input-time-24" name="jam_kirim_wa_auto" value="<?= htmlspecialchars(app_format_jam($values['jam_kirim_wa_auto'])) ?>">
                <div class="form-text">Setelah jam ini, cron kirim ambang yang belum terlapor. Kosong = cek terus.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Langkah kelipatan</label>
                <input type="number" min="1" class="form-control" name="batas_alpa_notif" value="<?= htmlspecialchars($values['batas_alpa_notif']) ?>">
                <div class="form-text">Mis. 5 → ambang 5,10,15,… poin jika tier kosong.</div>
            </div>
            <div class="col-md-6">
                <?php
                $delayFieldName = 'wa_delay_alpa';
                $delayFieldValue = (string) ($values['wa_delay_alpa'] ?? '');
                require __DIR__ . '/wa_otomatis_delay_field.php';
                ?>
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
            <div class="col-md-5">
                <label class="form-label small">Tanggal mulai hitung</label>
                <div class="form-control form-control-sm bg-body-secondary" style="min-height:calc(1.5em + .5rem + 2px)">
                    <?php if ($tanggalMulaiAlpa !== ''): ?>
                        <?= htmlspecialchars(function_exists('app_format_tanggal_id') ? app_format_tanggal_id($tanggalMulaiAlpa) : $tanggalMulaiAlpa) ?>
                    <?php else: ?>
                        <span class="text-muted">Semua riwayat (belum dibatasi)</span>
                    <?php endif; ?>
                </div>
                <div class="form-text">
                    Sama dengan rekap poin/keaktivan.
                    Ubah di
                    <a href="<?= htmlspecialchars(app_href('/settings/pesantren.php')) ?>">Pengaturan pesantren</a>
                    → Tanggal mulai scan keaktivan.
                </div>
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
            <h2 class="h6 mb-0">Tier penerima (ambang poin alpa)</h2>
            <form method="post" onsubmit="return confirm('Reset log dispatch tier?');">
                <input type="hidden" name="action" value="reset_log">
                <button class="btn btn-outline-danger btn-sm" type="submit">Reset log (<?= $logTotalAlpa ?>)</button>
            </form>
        </div>
        <p class="small text-muted">Ambang silang: WA saat total poin santri baru ≥ ambang poin dan belum pernah dikirim di periode ini (generate ALPA &amp; jam otomatis). Nomor kosong → fallback pengurus di atas.</p>
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

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Kirim laporan manual</h2>
        <p class="small text-muted mb-2">
            Kirim rekap santri alpa (semua yang ≥ ambang tier) untuk periode aktif pada tanggal referensi.
            Pesan panjang otomatis dipecah ke beberapa WA berurutan.
            Kirim manual <strong>tidak</strong> memblokir notifikasi crossing otomatis.
        </p>
        <ul class="small mb-3">
            <li>Periode aktif (<?= htmlspecialchars($alpaModeLabel) ?>): <strong><?= htmlspecialchars((string) ($alpaManualPreview['periode_label'] ?? '-')) ?></strong></li>
            <?php if ($tiers !== []): ?>
                <?php foreach (($alpaManualPreview['tiers'] ?? []) as $tp): ?>
                    <li>Ambang <?= (int) ($tp['threshold'] ?? 0) ?> (<?= htmlspecialchars((string) ($tp['label'] ?? '')) ?>): <strong><?= (int) ($tp['santri_count'] ?? 0) ?></strong> santri
                        <?php if (trim((string) ($tp['wa'] ?? '')) === ''): ?>
                            <span class="text-warning">· nomor kosong</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li>Mode fallback (≥ <?= (int) ($values['batas_alpa_notif'] ?? 5) ?>): <strong><?= (int) ($alpaManualPreview['fallback_count'] ?? 0) ?></strong> santri</li>
            <?php endif; ?>
            <li>Terakhir kirim manual: <strong><?= $alpaManualLastAt !== '' ? htmlspecialchars($alpaManualLastAt) : 'Belum pernah' ?></strong>
                <?php if (is_array($alpaManualLastStats) && (int) ($alpaManualLastStats['sent'] ?? 0) > 0): ?>
                    · <?= (int) ($alpaManualLastStats['sent'] ?? 0) ?> pesan
                <?php endif; ?>
            </li>
        </ul>
        <form method="post" class="row g-2 align-items-end" id="alpa-laporan-manual-form">
            <input type="hidden" name="action" value="jalankan_alpa_laporan_manual">
            <div class="col-auto">
                <label class="form-label small mb-1" for="alpa_laporan_tanggal">Tanggal referensi</label>
                <input type="date" class="form-control form-control-sm" id="alpa_laporan_tanggal" name="alpa_laporan_tanggal" value="<?= htmlspecialchars($alpaLaporanTanggalDefault) ?>" max="<?= htmlspecialchars($alpaLaporanTanggalMax) ?>" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i> Kirim laporan</button>
            </div>
        </form>
        <script>
        (function () {
            var form = document.getElementById('alpa-laporan-manual-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                var input = document.getElementById('alpa_laporan_tanggal');
                var tgl = input && input.value ? input.value : 'hari ini';
                if (!confirm('Kirim laporan alpa untuk periode tanggal ' + tgl + ' sekarang?')) {
                    e.preventDefault();
                }
            });
        })();
        </script>
    </div>
</div>
