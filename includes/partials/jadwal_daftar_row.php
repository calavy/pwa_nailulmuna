<?php

declare(strict_types=1);

/**
 * Satu baris tabel tab Daftar.
 *
 * @var array<string,mixed> $item
 * @var array<int,string> $hari
 * @var string $namaGrup
 * @var array{kegiatan:bool,tingkatan:bool,hari:bool,pembimbing:bool,waktu:bool} $columnLayout
 * @var string $viewKat
 * @var PDO|null $pdo
 */
$item = $item ?? [];
$hari = $hari ?? [];
$namaGrup = $namaGrup ?? '';
$columnLayout = $columnLayout ?? jadwal_daftar_kolom_layout('taalim', 'kegiatan');
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$pdo = $pdo ?? null;

if ($viewKat === 'jamaah' && $pdo instanceof PDO) {
    $hkRow = (int) ($item['hari_ke'] ?? 0);
    $tampilanHk = $hkRow >= 1 && $hkRow <= 7 ? $hkRow : (int) date('N');
    $item = jadwal_minggu_slot_munawib($item, $tampilanHk, $pdo);
}

$mergeIds = array_values(array_filter(array_map('intval', $item['_merge_ids'] ?? [(int) ($item['id'] ?? 0)])));
$tingkatanList = $item['_tingkatan_list'] ?? [];
if ($tingkatanList === [] && trim((string) ($item['tingkatan'] ?? '')) !== '') {
    $tingkatanList = [trim((string) ($item['tingkatan'] ?? ''))];
}
$editId = (int) ($mergeIds[0] ?? 0);
$tempatVal = trim((string) ($item['tempat'] ?? ''));
$pem = trim((string) ($item['nama_pembimbing'] ?? ''));
?>
<tr class="jadwal-daftar-row">
    <td>
        <?php foreach ($mergeIds as $mid): ?>
            <?php if ($mid > 0): ?>
            <input class="form-check-input jadwal-row-check d-inline-block me-1" type="checkbox"
                name="ids[]" value="<?= $mid ?>"
                data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>"
                aria-label="Pilih jadwal #<?= $mid ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    </td>
    <?php if (!empty($columnLayout['kegiatan'])): ?>
        <td class="small"><?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '—')) ?></td>
    <?php endif; ?>
    <?php if (!empty($columnLayout['tingkatan'])): ?>
        <td class="small">
            <?php foreach ($tingkatanList as $tk): ?>
                <?php if ($tk === '') { continue; } ?>
                <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge me-1 mb-1"><?= htmlspecialchars((string) $tk) ?></span>
            <?php endforeach; ?>
            <?php if ($tingkatanList === []): ?>—<?php endif; ?>
        </td>
    <?php endif; ?>
    <?php if (!empty($columnLayout['hari'])): ?>
        <td class="small text-nowrap">
            <?php
            $hariListRender = $item['_hari_list'] ?? [(int) ($item['hari_ke'] ?? 0)];
            echo jadwal_hari_list_badges_html($hariListRender, $hari);
            ?>
        </td>
    <?php endif; ?>
    <?php if (!empty($columnLayout['pembimbing'])): ?>
        <td class="small">
            <?= ($pem !== '' && $pem !== '-') ? htmlspecialchars($pem) : '—' ?>
            <?php if (!empty($item['munawib_harian'])): ?>
                <span class="badge text-bg-light border ms-1">munawib</span>
            <?php endif; ?>
        </td>
    <?php endif; ?>
    <?php if (!empty($columnLayout['waktu'])): ?>
        <td class="text-nowrap small font-monospace js-time-24">
            <span class="jadwal-peta-waktu"><?= htmlspecialchars(jadwal_jam_ringkas($item)) ?></span>
        </td>
    <?php endif; ?>
    <td class="text-end text-nowrap">
        <?php if ($editId > 0): ?>
            <button type="button"
                class="btn btn-outline-primary btn-sm py-0 px-2 jadwal-quick-edit"
                title="Edit cepat"
                data-edit-id="<?= $editId ?>"
                data-kegiatan-id="<?= (int) ($item['kegiatan_id'] ?? 0) ?>"
                data-kegiatan-nama="<?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '')) ?>"
                data-kategori="<?= htmlspecialchars(strtolower((string) ($item['kategori_kegiatan'] ?? 'taalim'))) ?>"
                data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($item['jam_mulai'] ?? ''))) ?>"
                data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($item['jam_selesai'] ?? ''))) ?>"
                data-pembimbing-id="<?= (int) ($item['pembimbing_id'] ?? 0) ?>"
                data-tempat="<?= htmlspecialchars($tempatVal) ?>"
                data-tingkatan="<?= htmlspecialchars(json_encode($tingkatanList, JSON_UNESCAPED_UNICODE)) ?>"
                data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $item['_hari_list'] ?? [(int) ($item['hari_ke'] ?? 0)])), JSON_UNESCAPED_UNICODE)) ?>">
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
