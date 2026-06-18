<?php

declare(strict_types=1);

/**
 * @var list<array{nama:string,label:string,jam?:string,hari?:string}> $izinTetapKegiatanList
 * @var list<string> $izinTetapKegiatanChecked
 */
$izinTetapKegiatanList = $izinTetapKegiatanList ?? [];
$izinTetapKegiatanChecked = $izinTetapKegiatanChecked ?? [];
$checkedMap = [];
foreach ($izinTetapKegiatanChecked as $nama) {
    $checkedMap[trim((string) $nama)] = true;
}
?>
<div id="izin-tetap-kegiatan-wrap" class="border rounded p-2 bg-light" style="max-height:11rem;overflow-y:auto">
    <?php if ($izinTetapKegiatanList === []): ?>
        <p class="text-muted small mb-0 py-1" id="izin-tetap-kegiatan-kosong">
            Isi jadwal hari &amp; jam hidmah terlebih dahulu. Sistem akan menampilkan kegiatan Jama'ah yang bertabrakan dengan durasi tersebut.
        </p>
    <?php else: ?>
        <div class="form-text mb-1">Kegiatan otomatis dari jadwal &amp; durasi jam hidmah — centang yang ditinggalkan:</div>
        <?php foreach ($izinTetapKegiatanList as $kg):
            $nama = trim((string) ($kg['nama'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $kgId = 'kg-ditinggalkan-' . md5($nama);
            $label = trim((string) ($kg['label'] ?? ''));
            $checked = !empty($checkedMap[$nama]);
            ?>
            <div class="form-check izin-tetap-kg-row" data-nama="<?= htmlspecialchars($nama) ?>">
                <input class="form-check-input izin-tetap-kg-cb" type="checkbox"
                       name="kegiatan_ditinggalkan_items[]"
                       id="<?= htmlspecialchars($kgId) ?>"
                       value="<?= htmlspecialchars($nama) ?>"
                    <?= $checked ? ' checked' : '' ?>>
                <label class="form-check-label small" for="<?= htmlspecialchars($kgId) ?>">
                    <span class="fw-semibold"><?= htmlspecialchars($nama) ?></span>
                    <?php if ($label !== ''): ?>
                        <span class="text-muted">(<?= htmlspecialchars($label) ?>)</span>
                    <?php endif; ?>
                </label>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
