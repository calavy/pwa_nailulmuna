<?php

declare(strict_types=1);

/**
 * Satu tabel sesuai view_kat — grup fold seragam.
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var array<string,int> $tingkatanSortIndex
 * @var PDO $pdo
 * @var string $tableSkin ringkas|peta
 * @var string $viewKat taalim|jamaah
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$tingkatanSortIndex = $tingkatanSortIndex ?? [];
$tableSkin = ($tableSkin ?? 'ringkas') === 'peta' ? 'peta' : 'ringkas';
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$pdo = $pdo ?? null;

$split = jadwal_pisah_taalim_jamaah($jadwalList);
$groups = $viewKat === 'jamaah'
    ? jadwal_kelompokkan_jamaah_per_kegiatan_satu_hari($split['jamaah'])
    : jadwal_kelompokkan_taalim_per_tingkatan_satu_hari($split['taalim'], $tingkatanSortIndex);

$tableClass = $tableSkin === 'peta'
    ? 'jadwal-peta-table table mb-0 jadwal-kat-grup__table'
    : 'table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas jadwal-kat-grup__table';
$wrapClass = $tableSkin === 'peta' ? 'jadwal-peta-scroll' : 'table-responsive';
$katLabel = $viewKat === 'jamaah' ? "Jama'ah" : "Ta'lim";
$hasRows = $viewKat === 'jamaah' ? $split['jamaah'] !== [] : $split['taalim'] !== [];
?>
<?php if ($jadwalList === [] || !$hasRows): ?>
    <?php require __DIR__ . '/jadwal_empty_kat.php'; ?>
<?php else: ?>
    <div class="jadwal-split-blok jadwal-split-blok--<?= htmlspecialchars($viewKat) ?>">
        <div class="jadwal-kat-grup-list">
            <?php foreach ($groups as $groupKey => $groupItems):
                if ($viewKat === 'jamaah') {
                    $first = $groupItems[0] ?? [];
                    $title = trim((string) ($first['nama_kegiatan'] ?? '—'));
                } else {
                    $title = (string) $groupKey;
                }
                $slotCount = count($groupItems);
                $variant = $viewKat;
                $bodyMode = 'table';
                $open = false;
                $items = $groupItems;
                $tableSkin = $tableSkin;
                $tableWrapClass = $wrapClass;
                $tableClass = $tableClass;
                $katGrupExtraClass = 'mb-2';
                require __DIR__ . '/jadwal_kat_grup.php';
            endforeach; ?>
        </div>
    </div>
    <p class="small text-muted mt-3 mb-0 jadwal-kat-hint">
        <i class="fa-solid fa-circle-info me-1"></i>
        Ketuk grup <?= htmlspecialchars($katLabel) ?> untuk melihat slot. Waktu sama digabung (hari &amp; tingkatan).
    </p>
<?php endif; ?>
