<?php

declare(strict_types=1);

/**
 * Form ringkas metadata tugas ikhtibar: judul, mapel/kelas, tanggal, durasi, PIN draf.
 *
 * @var array<string,mixed>|null $tugas
 * @var string $mapelMode 'ikhtibar' | 'pkpps'
 * @var list<array<string,mixed>> $kelasMapelOptions
 * @var list<array<string,mixed>> $pkppsKelasOptions
 * @var bool $wajibPilihMapel
 * @var bool $wajibPilihJadwal
 * @var string $selKelasKey
 * @var bool $perluBuatPinTugas
 * @var string $statusTugas
 */
$tugas = $tugas ?? null;
$mapelMode = $mapelMode ?? 'ikhtibar';
$kelasMapelOptions = $kelasMapelOptions ?? [];
$pkppsKelasOptions = $pkppsKelasOptions ?? [];
$wajibPilihMapel = $wajibPilihMapel ?? false;
$wajibPilihJadwal = $wajibPilihJadwal ?? false;
$selKelasKey = $selKelasKey ?? '';
$perluBuatPinTugas = $perluBuatPinTugas ?? false;
$statusTugas = $statusTugas ?? 'draft';

$tglDefault = (string) ($tugas['tanggal'] ?? date('Y-m-d'));
$durasiDefault = (int) ($tugas['durasi_menit'] ?? 60);
if ($durasiDefault < 5) {
    $durasiDefault = 60;
}

?>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="judul_tugas">Nama tugas</label>
                <input type="text" name="judul" id="judul_tugas" class="form-control" required maxlength="200"
                       value="<?= htmlspecialchars((string) ($tugas['judul'] ?? '')) ?>"
                       placeholder="Contoh: Ulangan Bab Al-Wudhu">
            </div>
            <?php if ($mapelMode === 'pkpps'): ?>
            <div class="col-md-6">
                <label class="form-label">Mapel / kelas PKPPS</label>
                <select name="pkpps_kelas_key" class="form-select"<?= $wajibPilihJadwal ? ' required' : '' ?>>
                    <option value="">— Pilih kelas PKPPS —</option>
                    <?php foreach ($pkppsKelasOptions as $opt): ?>
                        <option value="<?= htmlspecialchars((string) ($opt['key'] ?? '')) ?>" <?= $selKelasKey === (string) ($opt['key'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($opt['label'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($pkppsKelasOptions === []): ?>
                    <div class="form-text text-warning">Belum ada kelas PKPPS dengan NIP Anda.</div>
                <?php else: ?>
                    <div class="form-text">Satu kelas untuk seluruh santri tingkatan PKPPS tersebut (tanpa pilih hari).</div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="col-md-6">
                <label class="form-label">Mapel / kelas</label>
                <select name="kelas_mapel_key" class="form-select"<?= $wajibPilihMapel ? ' required' : '' ?>>
                    <option value="">— Pilih kelas —</option>
                    <?php foreach ($kelasMapelOptions as $opt): ?>
                        <option value="<?= htmlspecialchars((string) ($opt['key'] ?? '')) ?>" <?= $selKelasKey === (string) ($opt['key'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($opt['label'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($kelasMapelOptions === []): ?>
                    <div class="form-text text-warning">Belum ada kelas dengan NIP Anda di menu Jadwal.</div>
                <?php else: ?>
                    <div class="form-text">Kelas keseluruhan per mapel &amp; tingkatan (tanpa pilih hari).</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label">Tanggal pelaksanaan</label>
                <input type="date" name="tanggal" id="tanggal_pelaksanaan" class="form-control" required
                       value="<?= htmlspecialchars($tglDefault) ?>">
                <div class="form-text">Tugas tampil di portal santri pada tanggal ini.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Durasi waktu (menit)</label>
                <input type="number" name="durasi_menit" id="durasi_menit" class="form-control" min="5" max="300" required
                       value="<?= (int) $durasiDefault ?>">
                <div class="form-text">Hitung mundur saat santri menekan Mulai Tugas.</div>
            </div>
        </div>

        <?php if ($perluBuatPinTugas && $statusTugas === 'draft'): ?>
            <?= ikhtibar_tugas_render_akses_pin_buat_html() ?>
        <?php endif; ?>

        <?php if ($tugas && (int) ($tugas['pakai_token'] ?? 0) === 1 && !empty($tugas['token_plain'])): ?>
            <p class="small mt-3 mb-0 border-top pt-2">Token kunci saat ini: <code class="user-select-all"><?= htmlspecialchars((string) $tugas['token_plain']) ?></code>
                <button type="submit" name="action" value="token_baru" class="btn btn-sm btn-outline-warning ms-2">Buat token baru</button>
            </p>
        <?php endif; ?>
    </div>
</div>
