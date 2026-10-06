<?php

declare(strict_types=1);

/**
 * Isi satu kolom hari (grup fold seragam per view_kat).
 *
 * @var list<array<string,mixed>> $rawItems
 * @var int $hk
 * @var array<int,string> $hari
 * @var PDO $pdo
 * @var bool $showJadwalAksi
 * @var bool $practicalCompact
 * @var array<string,int> $tingkatanSortIndex
 * @var int $maxShow 0 = tanpa batas
 * @var callable|null $jadwalTabQs
 * @var string $viewKat taalim|jamaah
 */
$rawItems = $rawItems ?? [];
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$hk = (int) ($hk ?? 0);
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$practicalCompact = $practicalCompact ?? true;
$tingkatanSortIndex = $tingkatanSortIndex ?? [];
$maxShow = (int) ($maxShow ?? 0);
$budget = $maxShow > 0 ? $maxShow : PHP_INT_MAX;
$used = 0;
$truncated = false;

$totalUnits = jadwal_minggu_hitung_unit_view($rawItems, $viewKat);
$split = jadwal_pisah_taalim_jamaah($rawItems);
$variant = $viewKat;
$groups = $viewKat === 'jamaah'
    ? jadwal_kelompokkan_jamaah_per_kegiatan_satu_hari($split['jamaah'])
    : jadwal_kelompokkan_taalim_per_tingkatan_satu_hari($split['taalim'], $tingkatanSortIndex);

if ($groups !== []): ?>
    <div class="jadwal-minggu-section jadwal-minggu-section--<?= htmlspecialchars($variant) ?>">
        <?php foreach ($groups as $groupKey => $groupItems):
            if ($used >= $budget) {
                $truncated = true;
                break;
            }
            if ($viewKat === 'jamaah') {
                $first = $groupItems[0] ?? [];
                $title = trim((string) ($first['nama_kegiatan'] ?? '—'));
            } else {
                $title = (string) $groupKey;
            }
            if ($title === '' || $title === '—' && $viewKat === 'taalim') {
                $title = '—';
            }
            $slotCount = $viewKat === 'taalim'
                ? count(jadwal_gabung_baris_serupa($groupItems))
                : count($groupItems);
            $used++;
            $variant = $viewKat;
            $bodyMode = 'cards';
            $open = false;
            $items = $groupItems;
            require __DIR__ . '/jadwal_kat_grup.php';
        endforeach; ?>
    </div>
<?php endif; ?>

<?php
$hiddenCount = $totalUnits > $used || $truncated ? max(0, $totalUnits - $used) : 0;
if ($hiddenCount > 0 && $hk >= 1 && $hk <= 7 && is_callable($jadwalTabQs ?? null)): ?>
    <a class="jadwal-minggu-col__more small" href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('daftar', ['filter_hari' => $hk]))) ?>">
        Lihat semua (<?= (int) $totalUnits ?>)
    </a>
<?php endif; ?>
