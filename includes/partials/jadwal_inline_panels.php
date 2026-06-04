<?php

declare(strict_types=1);

/**
 * Form inline tambah kegiatan & jadwal di halaman jadwal/index.php
 *
 * @var list<array<string,mixed>> $kegiatanListAktif
 * @var list<array<string,mixed>> $kegiatanRows
 * @var list<string> $tingkatanList
 * @var array<int,string> $hari
 * @var list<array<string,mixed>> $pembimbingList
 * @var bool $jadwalPembimbingScope
 * @var string $panelOpen
 * @var int $preselectKegiatanId
 */
$kegiatanListAktif = $kegiatanListAktif ?? [];
$kegiatanRows = $kegiatanRows ?? [];
$tingkatanList = $tingkatanList ?? [];
$hari = $hari ?? [];
$pembimbingList = $pembimbingList ?? [];
$jadwalPembimbingScope = $jadwalPembimbingScope ?? false;
$panelOpen = $panelOpen ?? '';
$preselectKegiatanId = (int) ($preselectKegiatanId ?? 0);
$showKegiatan = $panelOpen === 'kegiatan';
$showJadwal = $panelOpen === 'jadwal';
?>
<div class="card shadow-sm border-0 mb-3 jadwal-inline-panels">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 mb-0">
            <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>">
                <i class="fa-solid fa-bookmark me-1"></i> Kegiatan Ta'lim / Jama'ah
            </a>
            <button type="button" class="btn btn-outline-success btn-sm jadwal-panel-toggle" data-panel="kegiatan" aria-expanded="<?= $showKegiatan ? 'true' : 'false' ?>">
                <i class="fa-solid fa-plus me-1"></i> Tambah cepat
            </button>
            <button type="button" class="btn btn-success btn-sm jadwal-panel-toggle" data-panel="jadwal" aria-expanded="<?= $showJadwal ? 'true' : 'false' ?>">
                <i class="fa-solid fa-calendar-plus me-1"></i> Tambah jadwal
            </button>
        </div>

        <div id="jadwal-panel-kegiatan" class="jadwal-inline-panel mt-3<?= $showKegiatan ? '' : ' d-none' ?>">
            <h3 class="h6 mb-2">Form tambah kegiatan</h3>
            <form method="post" class="row g-2 align-items-end" style="max-width:36rem">
                <input type="hidden" name="action" value="tambah_kegiatan">
                <div class="col-md-7">
                    <label class="form-label small mb-0">Nama kegiatan</label>
                    <input type="text" class="form-control form-control-sm" name="nama_kegiatan" required maxlength="120" placeholder="Mis. Kajian Pagi">
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-0">Kategori</label>
                    <select class="form-select form-select-sm" name="kategori_kegiatan">
                        <option value="TAALIM">Ta'lim & Ta'alum</option>
                        <option value="JAMAAH">Jama'ah</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan kegiatan</button>
                </div>
            </form>
        </div>

        <div id="jadwal-panel-jadwal" class="jadwal-inline-panel mt-3<?= $showJadwal ? '' : ' d-none' ?>">
            <h3 class="h6 mb-2">Form tambah slot jadwal</h3>
            <p class="text-muted small mb-2">Setiap kombinasi <strong>hari × tingkatan</strong> disimpan sebagai baris terpisah. Jam berbeda untuk kegiatan yang sama = slot/blok terpisah di daftar jadwal.</p>
            <?php if ($kegiatanListAktif === []): ?>
                <p class="text-warning small mb-0">Belum ada kegiatan aktif. Tambah kegiatan dulu.</p>
            <?php else: ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="tambah_jadwal">
                <div class="col-md-6">
                    <label class="form-label">Kegiatan</label>
                    <select class="form-select" name="kegiatan_id" required>
                        <option value="">— Pilih kegiatan —</option>
                        <?php foreach ($kegiatanListAktif as $kegiatan): ?>
                            <option value="<?= (int) $kegiatan['id'] ?>"<?= $preselectKegiatanId === (int) $kegiatan['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $kegiatan['nama_kegiatan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$jadwalPembimbingScope): ?>
                <div class="col-md-6">
                    <label class="form-label">Pembimbing (opsional)</label>
                    <select class="form-select" name="pembimbing_id">
                        <option value="0">Belum ditentukan</option>
                        <?php foreach ($pembimbingList as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['nama_pembimbing']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Tingkatan (boleh banyak)</label>
                    <?php $selectedTingkatan = []; require __DIR__ . '/jadwal_tingkatan_chips.php'; ?>
                </div>
                <div class="col-12">
                    <label class="form-label">Hari (boleh banyak)</label>
                    <div class="jadwal-hari-pills border rounded p-2 d-flex flex-wrap gap-2">
                        <?php foreach ($hari as $key => $label): ?>
                            <label class="jadwal-hari-pill">
                                <input class="form-check-input" type="checkbox" name="hari_ke[]" value="<?= (int) $key ?>">
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jam mulai</label>
                    <input type="text" name="jam_mulai" <?= app_time_input_attrs() ?> value="07:00" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jam selesai</label>
                    <input type="text" name="jam_selesai" <?= app_time_input_attrs() ?> value="08:00" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tempat</label>
                    <input type="text" class="form-control" name="tempat" maxlength="255" placeholder="Masjid, Aula, …">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-calendar-plus me-1"></i> Simpan jadwal</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var toggles = document.querySelectorAll('.jadwal-panel-toggle');
    var panels = {
        kegiatan: document.getElementById('jadwal-panel-kegiatan'),
        jadwal: document.getElementById('jadwal-panel-jadwal')
    };
    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.getAttribute('data-panel');
            var target = panels[key];
            if (!target) return;
            var willShow = target.classList.contains('d-none');
            Object.keys(panels).forEach(function (k) {
                if (!panels[k]) return;
                panels[k].classList.add('d-none');
            });
            if (willShow) {
                target.classList.remove('d-none');
            }
            toggles.forEach(function (b) {
                b.setAttribute('aria-expanded', b === btn && willShow ? 'true' : 'false');
            });
        });
    });
})();
</script>
