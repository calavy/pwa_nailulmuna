<?php

declare(strict_types=1);

/**
 * Modal edit cepat jadwal (POST ke jadwal/edit.php).
 *
 * @var list<array<string,mixed>> $kegiatanList
 * @var list<string> $tingkatanList
 * @var list<array<string,mixed>> $pembimbingList
 * @var array<int,string> $hari
 */
$kegiatanList = $kegiatanList ?? [];
$tingkatanList = $tingkatanList ?? [];
$pembimbingList = $pembimbingList ?? [];
$hari = $hari ?? [];
?>
<div class="modal fade" id="jadwalQuickEditModal" tabindex="-1" aria-labelledby="jadwalQuickEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <form method="post" id="jadwalQuickEditForm" action="" data-edit-base="<?= htmlspecialchars(app_href('/jadwal/edit.php')) ?>">
                <div class="modal-header py-2">
                    <h2 class="modal-title h6 mb-0" id="jadwalQuickEditModalLabel">
                        <i class="fa-solid fa-pen-to-square me-1 text-primary"></i> Edit jadwal cepat
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">Ubah kegiatan, hari, tingkatan, jam, pembimbing, dan tempat. Untuk opsi lanjutan gunakan tombol form lengkap di kartu jadwal.</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kegiatan</label>
                            <select name="kegiatan_id" class="form-select form-select-sm" required id="jq-kegiatan">
                                <option value="">— Pilih —</option>
                                <?php foreach ($kegiatanList as $kg): ?>
                                    <option value="<?= (int) $kg['id'] ?>"><?= htmlspecialchars((string) $kg['nama_kegiatan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pembimbing</label>
                            <select name="pembimbing_id" class="form-select form-select-sm" id="jq-pembimbing">
                                <option value="0">Belum ditentukan</option>
                                <?php foreach ($pembimbingList as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['nama_pembimbing']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tingkatan</label>
                            <?php
                            $selectedTingkatan = [];
                            $inputName = 'tingkatan[]';
                            require __DIR__ . '/jadwal_tingkatan_chips.php';
                            ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Hari</label>
                            <div class="jadwal-hari-pills border rounded p-2 d-flex flex-wrap gap-2" id="jq-hari-wrap">
                                <?php foreach ($hari as $key => $label): ?>
                                    <label class="jadwal-hari-pill">
                                        <input class="form-check-input jq-hari-check" type="checkbox" name="hari_ke[]" value="<?= (int) $key ?>">
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Jam mulai</label>
                            <div class="input-group input-group-sm">
                                <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="jm" data-delta="-5" title="-5 menit">−5</button>
                                <input type="text" name="jam_mulai" id="jq-jam-mulai" class="form-control input-time-24" inputmode="numeric" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="HH:MM" maxlength="5" autocomplete="off" required>
                                <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="jm" data-delta="5" title="+5 menit">+5</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Jam selesai</label>
                            <div class="input-group input-group-sm">
                                <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="js" data-delta="-5" title="-5 menit">−5</button>
                                <input type="text" name="jam_selesai" id="jq-jam-selesai" class="form-control input-time-24" inputmode="numeric" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="HH:MM" maxlength="5" autocomplete="off" required>
                                <button type="button" class="btn btn-outline-secondary jadwal-time-nudge" data-target="js" data-delta="5" title="+5 menit">+5</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tempat</label>
                            <input type="text" name="tempat" id="jq-tempat" class="form-control form-control-sm" maxlength="255" placeholder="Masjid, Aula…">
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
