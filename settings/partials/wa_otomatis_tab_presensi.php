<?php

declare(strict_types=1);

/** @var array<string, string> $values */

$kelasKosongLastAtRaw = trim((string) ($values['wa_kelas_kosong_last_sent_at'] ?? ''));
$kelasKosongLastLevel = trim((string) ($values['wa_kelas_kosong_last_level'] ?? ''));
?>
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
                <label class="form-label">Tujuan laporan ke-1</label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_1" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_1'] ?? '')) ?>" placeholder="Nomor atau grup">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tujuan laporan ke-3</label>
                <input type="text" class="form-control" name="wa_kelas_kosong_target_3" value="<?= htmlspecialchars((string) ($values['wa_kelas_kosong_target_3'] ?? '')) ?>" placeholder="Eskalasi">
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
            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan presensi</button>
            </div>
        </form>
    </div>
</div>
