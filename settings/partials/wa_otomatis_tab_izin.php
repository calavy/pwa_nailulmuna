<?php

declare(strict_types=1);

/** @var bool $waIzinPengurusEnabled */
/** @var bool $waIzinSelesaiEnabled */
/** @var bool $waIzinWaliEnabled */
/** @var string $waIzinPengurus */
/** @var bool $waIzinEnabled */
/** @var bool $waIzinGrupFonteEnabled */
/** @var string $waIzinGrupFonte */
/** @var bool $waIzinKirimGrup */
/** @var string $waIzinGrup */
/** @var bool $waIzinGrupAktifOtomatis */
/** @var array<string, string> $values */

$waPermohonanIzinEnabled = ($values['wa_permohonan_izin_enabled'] ?? '1') === '1';

?>
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h2 class="h6 mb-2">Permohonan izin baru (PENDING)</h2>
                <p class="small text-muted mb-3">
                    WA dikirim saat wali/petugas mengajukan izin. Terpisah dari notifikasi alpa (tab Alpa).
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="save_permohonan_izin_wa">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wa_permohonan_izin_enabled" name="wa_permohonan_izin_enabled" value="1" <?= $waPermohonanIzinEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="wa_permohonan_izin_enabled">Kirim WA saat ada permohonan izin baru</label>
                    </div>
                    <label class="form-label" for="wa_permohonan_izin">No. penerima permohonan izin</label>
                    <input type="text" class="form-control mb-1" id="wa_permohonan_izin" name="wa_permohonan_izin" value="<?= htmlspecialchars((string) ($values['wa_permohonan_izin'] ?? '')) ?>" placeholder="628xxxxxxxxxx" inputmode="tel" autocomplete="off">
                    <div class="form-text mb-3">Beberapa nomor: pisah koma. Kosong = fallback nomor alpa (tab Alpa).</div>
                    <button type="submit" class="btn btn-success btn-sm w-100 w-sm-auto">Simpan permohonan izin</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 border-success-subtle">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-users text-success me-1"></i> Grup WA — izin disetujui</h2>
                <p class="small text-muted mb-2">
                    Otomatis kirim ke grup saat pengurus <strong>menyetujui</strong> izin (bersamaan dengan WA pembimbing jika aktif).
                </p>
                <?php if ($waIzinGrupFonte === ''): ?>
                    <div class="alert alert-warning py-2 small mb-3 mb-0">Isi ID grup di bawah agar notifikasi grup terkirim otomatis.</div>
                <?php elseif ($waIzinGrupAktifOtomatis): ?>
                    <div class="alert alert-success py-2 small mb-3">Grup aktif — akan terkirim otomatis saat izin disetujui.</div>
                <?php else: ?>
                    <div class="alert alert-secondary py-2 small mb-3">Toggle grup nonaktif — centang di bawah untuk mengaktifkan.</div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_izin_grup_wa">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wa_izin_grup_fonte_enabled" name="wa_izin_grup_fonte_enabled" value="1" <?= $waIzinGrupFonteEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="wa_izin_grup_fonte_enabled">Kirim otomatis ke grup WA</label>
                    </div>
                    <label class="form-label" for="wa_izin_grup_fonte">ID / kode grup Fonte</label>
                    <input type="text" class="form-control font-monospace mb-1" id="wa_izin_grup_fonte" name="wa_izin_grup_fonte" value="<?= htmlspecialchars($waIzinGrupFonte) ?>" placeholder="120363xxxxx@g.us" autocomplete="off">
                    <div class="form-text mb-3">Salin dari panel Fonte → Grup. Beberapa grup: pisah koma.</div>
                    <button type="submit" class="btn btn-success btn-sm w-100 w-sm-auto">Simpan grup WA</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 border-info-subtle">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-user-group text-info me-1"></i> Wali santri — izin disetujui</h2>
                <p class="small text-muted mb-3">
                    WA otomatis ke nomor wali santri saat permohonan izin (dari wali/petugas) <strong>disetujui</strong> pengurus.
                    Nomor diambil dari data wali terhubung, no. WA wali, atau kontak ayah/ibu.
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="save_izin_wali_wa">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wa_izin_wali_enabled" name="wa_izin_wali_enabled" value="1" <?= $waIzinWaliEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="wa_izin_wali_enabled">Kirim WA ke wali saat izin disetujui</label>
                    </div>
                    <p class="small text-muted mb-3">
                        Template pesan dapat disesuaikan di tab <strong>Template</strong> (izin disetujui → wali santri).
                        Teks otomatis membedakan <strong>izin sakit</strong>, <strong>izin keluar</strong>, <strong>izin</strong>, dan <strong>izin tugas</strong>.
                    </p>
                    <button type="submit" class="btn btn-success btn-sm w-100 w-sm-auto">Simpan notifikasi wali</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 border-primary-subtle">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-user-tie text-primary me-1"></i> Pengurus — izin disetujui &amp; selesai</h2>
                <p class="small text-muted mb-3">
                    WA ke petugas pengurus yang mencetak/menyetujui surat izin. Nomor yang sama dipakai untuk laporan otomatis saat santri kembali.
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="save_izin_pengurus_wa">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="wa_izin_pengurus_enabled" name="wa_izin_pengurus_enabled" value="1" <?= $waIzinPengurusEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="wa_izin_pengurus_enabled">Kirim WA saat izin disetujui (surat siap cetak)</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wa_izin_selesai_enabled" name="wa_izin_selesai_enabled" value="1" <?= $waIzinSelesaiEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="wa_izin_selesai_enabled">Kirim laporan WA saat izin selesai (santri kembali)</label>
                    </div>
                    <label class="form-label" for="wa_izin_pengurus">No. penerima pengurus</label>
                    <input type="text" class="form-control mb-1" id="wa_izin_pengurus" name="wa_izin_pengurus" value="<?= htmlspecialchars($waIzinPengurus) ?>" placeholder="628xxxxxxxxxx" inputmode="tel" autocomplete="off">
                    <div class="form-text mb-3">Beberapa nomor: pisah koma. Kosong = sama dengan nomor permohonan izin di atas. Jika pengurus punya no. WA di profil akun, ikut menerima saat ia yang menyetujui.</div>
                    <button type="submit" class="btn btn-success btn-sm w-100 w-sm-auto">Simpan pengurus izin</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-2">Izin disetujui → pembimbing</h2>
                <p class="small text-muted mb-3">Kirim ke nomor pembimbing terkait santri. Template pesan di tab Template.</p>
                <form method="post">
                    <input type="hidden" name="action" value="save_izin_wa">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="wa_izin_pembimbing_enabled" name="wa_izin_pembimbing_enabled" value="1" <?= $waIzinEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="wa_izin_pembimbing_enabled">Kirim WA ke pembimbing terkait</label>
                    </div>
                    <p class="small text-muted mb-3">Toggle per pembimbing di <a href="<?= htmlspecialchars(app_href('/pembimbing/index.php')) ?>">Data Pembimbing</a> (kolom notif izin &amp; no WA).</p>
                    <details class="small mb-3">
                        <summary class="text-muted">Pengaturan lama (nomor personal tambahan)</summary>
                        <div class="form-check mt-2 mb-2">
                            <input class="form-check-input" type="checkbox" id="wa_izin_pembimbing_kirim_grup" name="wa_izin_pembimbing_kirim_grup" value="1" <?= $waIzinKirimGrup ? 'checked' : '' ?>>
                            <label class="form-check-label" for="wa_izin_pembimbing_kirim_grup">Mode lama: kirim ke nomor di bawah</label>
                        </div>
                        <input type="text" class="form-control form-control-sm" name="wa_izin_pembimbing_grup" value="<?= htmlspecialchars($waIzinGrup) ?>" placeholder="628xxx">
                    </details>
                    <button type="submit" class="btn btn-success btn-sm w-100 w-sm-auto">Simpan pembimbing</button>
                </form>
            </div>
        </div>
    </div>
</div>
