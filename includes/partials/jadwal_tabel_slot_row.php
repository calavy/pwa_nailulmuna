<?php

declare(strict_types=1);

/**
 * Satu baris tabel slot jadwal.
 *
 * @var array<string,mixed> $item
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var bool $showKegiatanCol
 * @var bool $showTingkatanCol
 * @var string $tableSkin ringkas|peta
 */
$item = $item ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$showKegiatanCol = $showKegiatanCol ?? true;
$showTingkatanCol = $showTingkatanCol ?? true;
$tableSkin = ($tableSkin ?? 'ringkas') === 'peta' ? 'peta' : 'ringkas';

$mergeIds = array_values(array_filter(array_map('intval', $item['_merge_ids'] ?? [(int) ($item['id'] ?? 0)])));
$tingkatanList = $item['_tingkatan_list'] ?? [];
if ($tingkatanList === [] && trim((string) ($item['tingkatan'] ?? '')) !== '') {
    $tingkatanList = [trim((string) ($item['tingkatan'] ?? ''))];
}
$hariListRender = $item['_hari_list'] ?? [(int) ($item['hari_ke'] ?? 0)];
$editId = (int) ($mergeIds[0] ?? 0);
$namaKg = trim((string) ($item['nama_kegiatan'] ?? '—'));
$kat = (string) ($item['kategori_kegiatan'] ?? 'TAALIM');
$pem = trim((string) ($item['nama_pembimbing'] ?? ''));
$tempatVal = trim((string) ($item['tempat'] ?? ''));
$hkPrimary = (int) ($hariListRender[0] ?? 0);
$hariLabelRingkas = jadwal_hari_list_label_ringkas($hariListRender, $hari);
$hariLabelFull = jadwal_hari_list_label($hariListRender, $hari);
$hariSlug = jadwal_hari_badge_slug($hkPrimary === 0 ? 1 : $hkPrimary);
?>
<tr class="<?= $tableSkin === 'peta' ? 'jadwal-peta-row' : '' ?>">
    <td class="<?= $tableSkin === 'peta' ? 'jadwal-peta-td' : 'small text-nowrap' ?>">
        <?php if ($tableSkin === 'peta'): ?>
            <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($hariSlug) ?>" title="<?= htmlspecialchars($hariLabelFull) ?>"><?= htmlspecialchars($hariLabelRingkas) ?></span>
        <?php else: ?>
            <?= jadwal_hari_list_badges_html($hariListRender, $hari) ?>
        <?php endif; ?>
    </td>
    <td class="<?= $tableSkin === 'peta' ? 'jadwal-peta-td' : 'text-nowrap small font-monospace js-time-24' ?>">
        <?php if ($tableSkin === 'peta'): ?>
            <span class="jadwal-peta-waktu font-monospace js-time-24">
                <i class="fa-regular fa-clock jadwal-peta-waktu__ico" aria-hidden="true"></i>
                <?= htmlspecialchars(jadwal_jam_ringkas($item)) ?>
            </span>
        <?php else: ?>
            <span class="jadwal-peta-waktu"><?= htmlspecialchars(jadwal_jam_ringkas($item)) ?></span>
        <?php endif; ?>
    </td>
    <?php if ($showKegiatanCol): ?>
        <td class="<?= $tableSkin === 'peta' ? 'jadwal-peta-td' : 'small' ?>">
            <?php if ($tableSkin === 'peta'): ?>
                <span class="jadwal-peta-kegiatan">
                    <span class="jadwal-kat-dot <?= htmlspecialchars(jadwal_kategori_dot_class($kat)) ?>"></span>
                    <?= htmlspecialchars($namaKg) ?>
                </span>
            <?php else: ?>
                <?= htmlspecialchars($namaKg) ?>
            <?php endif; ?>
        </td>
    <?php endif; ?>
    <?php if ($showTingkatanCol): ?>
        <td class="<?= $tableSkin === 'peta' ? 'jadwal-peta-td' : 'small' ?>">
            <?php if ($tingkatanList !== []): ?>
                <?php foreach ($tingkatanList as $tk): ?>
                    <?php if ($tk === '') { continue; } ?>
                    <?php if ($tableSkin === 'peta'): ?>
                        <span class="jadwal-peta-tingkatan me-1"><?= htmlspecialchars((string) $tk) ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge me-1 mb-1"><?= htmlspecialchars((string) $tk) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
    <?php endif; ?>
    <td class="<?= $tableSkin === 'peta' ? 'jadwal-peta-td jadwal-peta-meta' : 'small' ?>">
        <?= ($pem !== '' && $pem !== '-') ? htmlspecialchars($pem) : '—' ?>
        <?php if (!empty($item['munawib_harian'])): ?>
            <span class="badge text-bg-light border ms-1">munawib</span>
        <?php endif; ?>
    </td>
    <td class="text-end text-nowrap">
        <?php if ($editId > 0 && $showJadwalAksi): ?>
            <button type="button"
                class="btn btn-outline-primary btn-sm py-0 px-2 jadwal-quick-edit"
                title="Edit cepat"
                data-edit-id="<?= $editId ?>"
                data-kegiatan-id="<?= (int) ($item['kegiatan_id'] ?? 0) ?>"
                data-kegiatan-nama="<?= htmlspecialchars($namaKg) ?>"
                data-kategori="<?= htmlspecialchars(strtolower($kat)) ?>"
                data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($item['jam_mulai'] ?? ''))) ?>"
                data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($item['jam_selesai'] ?? ''))) ?>"
                data-pembimbing-id="<?= (int) ($item['pembimbing_id'] ?? 0) ?>"
                data-tempat="<?= htmlspecialchars($tempatVal) ?>"
                data-tingkatan="<?= htmlspecialchars(json_encode($tingkatanList, JSON_UNESCAPED_UNICODE)) ?>"
                data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $hariListRender)), JSON_UNESCAPED_UNICODE)) ?>">
                <i class="fa-solid fa-pen"></i>
            </button>
            <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $editId)) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" title="Form lengkap">
                <i class="fa-solid fa-up-right-from-square"></i>
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 jadwal-delete-one"
                title="Hapus jadwal"
                data-delete-ids="<?= htmlspecialchars(implode(',', $mergeIds)) ?>"
                data-confirm="Hapus <?= count($mergeIds) > 1 ? count($mergeIds) . ' slot jadwal' : 'jadwal ini' ?>? Presensi terkait ikut dihapus.">
                <i class="fa-solid fa-trash"></i>
            </button>
        <?php endif; ?>
    </td>
</tr>
