<?php

declare(strict_types=1);

/** @var array<string, string> $values */

$kelasKosongLastAtRaw = trim((string) ($values['wa_kelas_kosong_last_sent_at'] ?? ''));
$kelasKosongLastLevel = trim((string) ($values['wa_kelas_kosong_last_level'] ?? ''));
?>
<?php $delayKind = 'presensi'; require __DIR__ . '/wa_otomatis_delay_card.php'; ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Pengingat scan pembimbing / munawib</h2>
        <p class="small text-muted mb-3">WA otomatis ~10 menit sebelum kegiatan selesai jika belum scan. Toggle per pembimbing di Data Pembimbing.</p>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="save_presensi">
            <input type="hidden" name="redirect_tab" value="presensi">
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_pembimbing_scan_enabled" name="wa_pembimbing_scan_enabled" value="1" <?= $scanEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_pembimbing_scan_enabled">Aktifkan pengingat scan</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Menit sebelum selesai</label>
                <input type="number" class="form-control form-control-sm" name="wa_pembimbing_scan_menit_sebelum" min="5" max="30" value="<?= (int) $scanMenit ?>">
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12"><h3 class="h6 text-primary mb-2">Timeline & Tugas Yayasan</h3></div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_yayasan_tugas_enabled" name="wa_yayasan_tugas_enabled" value="1" <?= $ytTugasWaEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_yayasan_tugas_enabled">WA penugasan & perubahan tugas</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_yayasan_tugas_noprogress_enabled" name="wa_yayasan_tugas_noprogress_enabled" value="1" <?= $ytTugasNoProgressEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_yayasan_tugas_noprogress_enabled">Pengingat belum ada progres</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Jam setelah mulai (baru ingatkan)</label>
                <input type="number" class="form-control form-control-sm" name="wa_yayasan_tugas_noprogress_jam" min="1" max="72" value="<?= (int) $ytTugasNoProgressJam ?>">
            </div>
            <div class="col-12">
                <div class="form-text small mb-0">Template pesan di tab <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=template')) ?>">Template</a> (yayasan_tugas_*). PIC harus punya nomor WA di profil atau data pembimbing.</div>
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12"><h3 class="h6 text-primary mb-2">Munawib belum hadir (pembimbing izin)</h3></div>
            <div class="col-md-4">
                <label class="form-label">Notifikasi munawib</label>
                <select class="form-select" name="wa_notif_mudabir_enabled">
                    <option value="1" <?= ($values['wa_notif_mudabir_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_notif_mudabir_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Batas munawib (menit dari jam mulai)</label>
                <input type="number" min="5" max="180" class="form-control" name="mudabir_batas_menit" value="<?= htmlspecialchars((string) (($values['mudabir_batas_menit'] ?? '') !== '' ? $values['mudabir_batas_menit'] : '30')) ?>">
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12"><h3 class="h6 text-primary mb-2">Laporan presensi musyawarah (pengasuh)</h3></div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_musyawarah_enabled" name="wa_musyawarah_enabled" value="1" <?= ($values['wa_musyawarah_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_musyawarah_enabled">Aktifkan laporan musyawarah</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_musyawarah_auto_selesai" name="wa_musyawarah_auto_selesai" value="1" <?= ($values['wa_musyawarah_auto_selesai'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_musyawarah_auto_selesai">Kirim otomatis saat rapat selesai</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Nomor WA pengasuh / grup</label>
                <input type="text" class="form-control form-control-sm" name="wa_musyawarah_target" value="<?= htmlspecialchars((string) ($values['wa_musyawarah_target'] ?? '')) ?>" placeholder="Nomor atau grup Fonte">
            </div>
            <div class="col-12">
                <div class="form-text small">Isi daftar hadir, izin, dan tidak hadir dari halaman <a href="<?= htmlspecialchars(app_href('/yayasan/musyawarah_presensi.php')) ?>">Presensi Musyawarah</a> — scan di <a href="<?= htmlspecialchars(app_href('/yayasan/scan_musyawarah.php')) ?>">Scan Musyawarah</a>.</div>
            </div>
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12"><h3 class="h6 text-primary mb-2">Kegiatan kosong → WA otomatis</h3></div>
            <div class="col-12">
                <p class="small text-muted mb-2">
                    Sistem mengecek slot jadwal aktif (setelah batas menit dari jam mulai).
                    Deteksi ke-1 dikirim ke petugas pendidikan (+ grup jika diaktifkan); setelah berturut-turut N kali (default 3) dikirim ke
                    <strong>nomor pengurus</strong> (tab Alpa) kecuali diisi override di bawah.
                </p>
            </div>
            <div class="col-md-3">
                <label class="form-label">Aktifkan</label>
                <select class="form-select" name="wa_kelas_kosong_enabled">
                    <option value="1" <?= ($values['wa_kelas_kosong_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= ($values['wa_kelas_kosong_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Batas awal (menit)</label>
                <input type="number" min="5" max="180" class="form-control" name="wa_kelas_kosong_batas_menit" value="<?= htmlspecialchars((string) (($values['wa_kelas_kosong_batas_menit'] ?? '') !== '' ? $values['wa_kelas_kosong_batas_menit'] : '20')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Eskalasi ke pengurus (x deteksi)</label>
                <input type="number" min="2" max="10" class="form-control" name="wa_kelas_kosong_batas_kali" value="<?= htmlspecialchars((string) (($values['wa_kelas_kosong_batas_kali'] ?? '') !== '' ? $values['wa_kelas_kosong_batas_kali'] : '3')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tujuan laporan awal <span class="text-muted">(opsional)</span></label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_1" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_1'] ?? '')) ?>" placeholder="Kosong = petugas pendidikan">
            </div>
            <div class="col-md-6">
                <label class="form-label">Tujuan eskalasi <span class="text-muted">(opsional)</span></label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_3" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_3'] ?? '')) ?>" placeholder="Kosong = nomor pengurus (<?= (int) ($pengurusWaCount ?? 0) ?> terdaftar)">
            </div>
            <div class="col-12">
                <div class="alert alert-secondary py-2 small mb-0">
                    Kirim terakhir kelas kosong:
                    <?php if ($kelasKosongLastAtRaw !== ''): ?>
                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($kelasKosongLastAtRaw) ?: time())) ?>
                        <?= $kelasKosongLastLevel !== '' ? ' · level ' . htmlspecialchars($kelasKosongLastLevel) : '' ?>
                    <?php else: ?>
                        belum pernah
                    <?php endif; ?>
                    · <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php?tab=log')) ?>">Lihat riwayat</a>
                </div>
            </div>
            <div class="col-md-6">
                <?php
                $delayFieldName = 'wa_delay_presensi';
                $delayFieldValue = (string) ($values['wa_delay_presensi'] ?? '');
                require __DIR__ . '/wa_otomatis_delay_field.php';
                ?>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan presensi</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3 border-success-subtle">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-users text-success me-1"></i> Grup WA — presensi</h2>
        <p class="small text-muted mb-2">
            Otomatis kirim ke grup saat <strong>munawib belum hadir</strong>, <strong>kelas kosong</strong>, dan <strong>update kelas terisi</strong>
            (bersamaan dengan nomor petugas pendidikan).
        </p>
        <?php if ($waPresensiGrupFonte === ''): ?>
            <div class="alert alert-warning py-2 small mb-3">Isi ID grup di bawah agar notifikasi presensi terkirim ke grup.</div>
        <?php elseif ($waPresensiGrupAktifOtomatis): ?>
            <div class="alert alert-success py-2 small mb-3">Grup presensi aktif — laporan akan terkirim otomatis ke grup &amp; petugas pendidikan.</div>
        <?php else: ?>
            <div class="alert alert-secondary py-2 small mb-3">Toggle grup nonaktif — centang di bawah untuk mengaktifkan.</div>
        <?php endif; ?>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_presensi">
            <input type="hidden" name="redirect_tab" value="presensi">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="wa_presensi_grup_fonte_enabled" name="wa_presensi_grup_fonte_enabled" value="1" <?= $waPresensiGrupFonteEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="wa_presensi_grup_fonte_enabled">Kirim otomatis ke grup WA</label>
                </div>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="wa_presensi_grup_fonte">ID / kode grup Fonte</label>
                <input type="text" class="form-control font-monospace" id="wa_presensi_grup_fonte" name="wa_presensi_grup_fonte" value="<?= htmlspecialchars($waPresensiGrupFonte) ?>" placeholder="120363xxxxx@g.us" autocomplete="off">
                <div class="form-text">Salin dari panel Fonte → Grup. Beberapa grup: pisah koma.</div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch mt-md-4 pt-md-2">
                    <input class="form-check-input" type="checkbox" id="wa_presensi_kirim_pembimbing_enabled" name="wa_presensi_kirim_pembimbing_enabled" value="1" <?= $waPresensiKirimPembimbingEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="wa_presensi_kirim_pembimbing_enabled">Kirim juga ke WA pembimbing terkait</label>
                </div>
                <div class="form-text">Laporan kelas kosong &amp; munawib ke <code>no_wa</code> pembimbing (bukan pengingat scan).</div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan grup presensi</button>
            </div>
        </form>
    </div>
</div>
