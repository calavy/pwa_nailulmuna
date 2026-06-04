<?php

declare(strict_types=1);

/**
 * Kartu slot jadwal (mingguan / daftar).
 *
 * @var array<string,mixed> $slot
 * @var array<int,string> $hari
 * @var bool $showActions
 * @var bool $compact
 */
$slot = $slot ?? [];
$hari = $hari ?? [];
$showActions = $showActions ?? true;
$compact = $compact ?? false;

$editId = (int) ($slot['id'] ?? 0);
$namaKg = trim((string) ($slot['nama_kegiatan'] ?? '—'));
$kat = (string) ($slot['kategori_kegiatan'] ?? 'TAALIM');
$tingkatan = trim((string) ($slot['tingkatan'] ?? ''));
$pem = trim((string) ($slot['nama_pembimbing'] ?? ''));
$tempat = trim((string) ($slot['tempat'] ?? ''));
$hk = (int) ($slot['hari_ke'] ?? 0);
$hariSlug = jadwal_hari_badge_slug($hk);
$hariLabel = jadwal_hari_singkat($hk, $hari);
$mergeIds = array_values(array_filter(array_map('intval', $slot['_merge_ids'] ?? [$editId])));
if ($mergeIds === [] && $editId > 0) {
    $mergeIds = [$editId];
}
$tingkatanList = $slot['_tingkatan_list'] ?? [];
if ($tingkatanList === [] && $tingkatan !== '') {
    $tingkatanList = [$tingkatan];
}
$hariList = $slot['_hari_list'] ?? [];
if ($hariList === [] && $hk >= 0) {
    $hariList = [$hk];
}
?>
<article class="jadwal-slot-card<?= $compact ? ' jadwal-slot-card--compact' : '' ?>" data-jadwal-id="<?= $editId ?>">
    <div class="jadwal-slot-card__head">
        <span class="jadwal-peta-waktu font-monospace js-time-24">
            <i class="fa-regular fa-clock jadwal-peta-waktu__ico" aria-hidden="true"></i>
            <?= htmlspecialchars(jadwal_jam_ringkas($slot)) ?>
        </span>
        <?php if (!$compact): ?>
            <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($hariSlug) ?>"><?= htmlspecialchars($hariLabel) ?></span>
        <?php endif; ?>
    </div>
    <div class="jadwal-slot-card__title">
        <span class="jadwal-kat-dot <?= htmlspecialchars(jadwal_kategori_dot_class($kat)) ?>" aria-hidden="true"></span>
        <span class="jadwal-peta-kegiatan__name"><?= htmlspecialchars($namaKg) ?></span>
        <span class="badge rounded-pill jadwal-kat-badge jadwal-kat-badge--<?= strtolower($kat) === 'jamaah' ? 'jamaah' : 'taalim' ?>"><?= htmlspecialchars(jadwal_kategori_label($kat)) ?></span>
    </div>
    <?php if ($tingkatanList !== []): ?>
        <div class="jadwal-slot-card__tingkatan">
            <?php foreach ($tingkatanList as $tk): ?>
                <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge"><?= htmlspecialchars((string) $tk) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($pem !== '' && $pem !== '-' || $tempat !== ''): ?>
        <div class="jadwal-slot-card__meta small text-muted">
            <?php if ($pem !== '' && $pem !== '-'): ?>
                <span><i class="fa-solid fa-user-tie me-1"></i><?= htmlspecialchars($pem) ?></span>
            <?php endif; ?>
            <?php if ($tempat !== ''): ?>
                <span><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($tempat) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($showActions && $editId > 0): ?>
        <div class="jadwal-slot-card__actions">
            <button type="button"
                class="btn btn-outline-primary btn-sm py-0 px-2 jadwal-quick-edit"
                title="Edit cepat"
                data-edit-id="<?= $editId ?>"
                data-kegiatan-id="<?= (int) ($slot['kegiatan_id'] ?? 0) ?>"
                data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($slot['jam_mulai'] ?? ''))) ?>"
                data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($slot['jam_selesai'] ?? ''))) ?>"
                data-pembimbing-id="<?= (int) ($slot['pembimbing_id'] ?? 0) ?>"
                data-tempat="<?= htmlspecialchars($tempat) ?>"
                data-tingkatan="<?= htmlspecialchars(json_encode($tingkatanList, JSON_UNESCAPED_UNICODE)) ?>"
                data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $hariList)), JSON_UNESCAPED_UNICODE)) ?>">
                <i class="fa-solid fa-pen"></i><span class="d-none d-sm-inline ms-1">Edit</span>
            </button>
            <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $editId)) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" title="Form lengkap">
                <i class="fa-solid fa-up-right-from-square"></i>
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 jadwal-delete-one"
                title="Hapus"
                data-delete-ids="<?= htmlspecialchars(implode(',', $mergeIds)) ?>"
                data-confirm="Hapus slot jadwal ini? Presensi terkait ikut dihapus.">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>
    <?php endif; ?>
</article>
