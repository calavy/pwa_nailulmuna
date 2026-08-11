<?php

declare(strict_types=1);

/**
 * Kartu slot jadwal (mingguan / daftar / mobile).
 *
 * @var array<string,mixed> $slot
 * @var array<int,string> $hari
 * @var bool $showActions
 * @var bool $compact
 * @var bool $mobileLayout
 */
$slot = $slot ?? [];
$hari = $hari ?? [];
$showActions = $showActions ?? true;
$compact = $compact ?? false;
$mobileLayout = $mobileLayout ?? false;

$editId = (int) ($slot['id'] ?? 0);
$namaKg = trim((string) ($slot['nama_kegiatan'] ?? '—'));
$kat = (string) ($slot['kategori_kegiatan'] ?? 'TAALIM');
$tingkatan = trim((string) ($slot['tingkatan'] ?? ''));
$pem = trim((string) ($slot['nama_pembimbing'] ?? ''));
$pemHarian = !empty($slot['munawib_harian']);
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
$setiapHari = !empty($slot['_setiap_hari']) || $hk === 0;
$tkRingkas = jadwal_tingkatan_tampilan_ringkas($tingkatanList, $mobileLayout ? 1 : ($compact ? 1 : 2));
$jamTampil = jadwal_jam_ringkas($slot);
$cardClasses = 'jadwal-slot-card'
    . ($compact ? ' jadwal-slot-card--compact' : '')
    . ($mobileLayout ? ' jadwal-slot-card--mobile' : '')
    . (strtolower($kat) === 'jamaah' ? ' jadwal-slot-card--jamaah' : '')
    . ($showActions && $editId > 0 ? ' jadwal-slot-card--clickable' : '');
?>
<article class="<?= htmlspecialchars($cardClasses) ?>"
    data-jadwal-id="<?= $editId ?>"
    <?php if ($showActions && $editId > 0): ?>
    tabindex="0"
    data-edit-id="<?= $editId ?>"
    data-kegiatan-id="<?= (int) ($slot['kegiatan_id'] ?? 0) ?>"
    data-kegiatan-nama="<?= htmlspecialchars((string) ($slot['nama_kegiatan'] ?? '')) ?>"
    data-kategori="<?= htmlspecialchars(strtolower($kat)) ?>"
    data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($slot['jam_mulai'] ?? ''))) ?>"
    data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($slot['jam_selesai'] ?? ''))) ?>"
    data-jam-tampil="<?= htmlspecialchars($jamTampil) ?>"
    data-pembimbing-id="<?= (int) ($slot['pembimbing_id'] ?? 0) ?>"
    data-pembimbing-nama="<?= htmlspecialchars($pem !== '' && $pem !== '-' ? $pem : '') ?>"
    data-tempat="<?= htmlspecialchars($tempat) ?>"
    data-tingkatan="<?= htmlspecialchars(json_encode($tingkatanList, JSON_UNESCAPED_UNICODE)) ?>"
    data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $hariList)), JSON_UNESCAPED_UNICODE)) ?>"
    data-delete-ids="<?= htmlspecialchars(implode(',', $mergeIds)) ?>"
    data-edit-url="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $editId)) ?>"
    data-tingkatan-label="<?= htmlspecialchars($tkRingkas['title'] !== '' ? $tkRingkas['title'] : implode(', ', $tkRingkas['visible'])) ?>"
    <?php endif; ?>>
    <?php if ($mobileLayout): ?>
        <div class="jadwal-slot-card__mobile-row">
            <span class="jadwal-slot-card__mobile-time font-monospace js-time-24"><?= htmlspecialchars($jamTampil) ?></span>
            <span class="jadwal-slot-card__mobile-name">
                <span class="jadwal-kat-dot <?= htmlspecialchars(jadwal_kategori_dot_class($kat)) ?>" aria-hidden="true"></span>
                <?= htmlspecialchars($namaKg) ?>
            </span>
            <?php if ($tkRingkas['visible'] !== []): ?>
                <span class="badge text-bg-light border jadwal-slot-card__mobile-badge"><?= htmlspecialchars((string) ($tkRingkas['visible'][0] ?? '')) ?><?= $tkRingkas['extra'] > 0 ? ' +' . (int) $tkRingkas['extra'] : '' ?></span>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="jadwal-slot-card__head">
        <span class="jadwal-peta-waktu font-monospace js-time-24">
            <i class="fa-regular fa-clock jadwal-peta-waktu__ico" aria-hidden="true"></i>
            <?= htmlspecialchars($jamTampil) ?>
        </span>
        <?php if (!$compact): ?>
            <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($hariSlug) ?>"><?= htmlspecialchars($hariLabel) ?></span>
        <?php endif; ?>
    </div>
    <div class="jadwal-slot-card__title">
        <span class="jadwal-kat-dot <?= htmlspecialchars(jadwal_kategori_dot_class($kat)) ?>" aria-hidden="true"></span>
        <span class="jadwal-peta-kegiatan__name"><?= htmlspecialchars($namaKg) ?></span>
        <span class="badge rounded-pill jadwal-kat-badge jadwal-kat-badge--<?= strtolower($kat) === 'jamaah' ? 'jamaah' : 'taalim' ?>"><?= htmlspecialchars(jadwal_kategori_label($kat)) ?></span>
        <?php if ($setiapHari && $compact): ?>
            <span class="badge text-bg-light border ms-1" title="Berlaku setiap hari">harian</span>
        <?php endif; ?>
    </div>
    <?php if ($tkRingkas['visible'] !== [] || $tkRingkas['extra'] > 0): ?>
        <div class="jadwal-slot-card__tingkatan"<?= $tkRingkas['title'] !== '' ? ' title="' . htmlspecialchars($tkRingkas['title']) . '"' : '' ?>>
            <?php foreach ($tkRingkas['visible'] as $tk): ?>
                <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge"><?= htmlspecialchars((string) $tk) ?></span>
            <?php endforeach; ?>
            <?php if ($tkRingkas['extra'] > 0): ?>
                <span class="badge text-bg-secondary jadwal-tingkatan-badge">+<?= (int) $tkRingkas['extra'] ?> tingkatan</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($pem !== '' && $pem !== '-' || $tempat !== ''): ?>
        <div class="jadwal-slot-card__meta small text-muted">
            <?php if ($pem !== '' && $pem !== '-'): ?>
                <span><i class="fa-solid fa-user-check me-1"></i><?= htmlspecialchars($pem) ?><?php if ($pemHarian): ?><span class="badge text-bg-light border ms-1" title="Munawib harian jamaah">munawib</span><?php endif; ?></span>
            <?php endif; ?>
            <?php if ($tempat !== ''): ?>
                <span><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($tempat) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($showActions && $editId > 0 && !$mobileLayout): ?>
        <div class="jadwal-slot-card__actions">
            <?php if (strtolower($kat) === 'jamaah'): ?>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah')) ?>" class="btn btn-outline-indigo btn-sm py-0 px-2 jadwal-slot-card__jamaah-link" title="Ubah waktu semua slot jamaah ini">
                    <i class="fa-solid fa-mosque"></i><span class="d-none d-sm-inline ms-1">Waktu</span>
                </a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?tab=jamaah_munawib')) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" title="Munawib harian Putra/Putri">
                    <i class="fa-solid fa-user-check"></i><span class="d-none d-sm-inline ms-1">Mw</span>
                </a>
            <?php endif; ?>
            <button type="button"
                class="btn btn-outline-primary btn-sm py-0 px-2 jadwal-quick-edit"
                title="Edit cepat"
                data-edit-id="<?= $editId ?>"
                data-kegiatan-id="<?= (int) ($slot['kegiatan_id'] ?? 0) ?>"
                data-kegiatan-nama="<?= htmlspecialchars((string) ($slot['nama_kegiatan'] ?? '')) ?>"
                data-kategori="<?= htmlspecialchars(strtolower($kat)) ?>"
                data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($slot['jam_mulai'] ?? ''))) ?>"
                data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($slot['jam_selesai'] ?? ''))) ?>"
                data-pembimbing-id="<?= (int) ($slot['pembimbing_id'] ?? 0) ?>"
                data-tempat="<?= htmlspecialchars($tempat) ?>"
                data-tingkatan="<?= htmlspecialchars(json_encode($tingkatanList, JSON_UNESCAPED_UNICODE)) ?>"
                data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $hariList)), JSON_UNESCAPED_UNICODE)) ?>">
                <i class="fa-solid fa-pen"></i><span class="d-none d-sm-inline ms-1">Edit</span>
            </button>
            <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $editId)) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2 jadwal-slot-card__full-link" title="Form lengkap">
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
