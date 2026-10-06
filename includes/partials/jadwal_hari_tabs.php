<?php

declare(strict_types=1);

/**
 * Tampilan mingguan mobile — tab hari horizontal + daftar vertikal.
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var int $filterHari
 * @var string $jadwalDensity
 * @var array<string,int> $tingkatanSortIndex
 * @var PDO $pdo
 * @var string $viewKat
 * @var callable|null $jadwalTabQs
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$filterHari = (int) ($filterHari ?? 0);
$jadwalDensity = ($jadwalDensity ?? 'comfort') === 'full' ? 'full' : 'comfort';
$tingkatanSortIndex = $tingkatanSortIndex ?? [];
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$maxShowMobile = $jadwalDensity === 'full' ? 999 : 4;
$practicalCompact = $jadwalDensity !== 'full';
$byHari = jadwal_kelompokkan_per_hari_tampilan($jadwalList);
$kolom = array_values(array_filter(jadwal_minggu_kolom(), static fn (int $hk): bool => $hk >= 1 && $hk <= 7));
$todayCol = (int) date('N');
$initialHari = ($filterHari >= 1 && $filterHari <= 7) ? $filterHari : $todayCol;
?>
<div class="jadwal-hari-mobile d-lg-none" data-initial-hari="<?= (int) $initialHari ?>">
    <div class="jadwal-hari-tabs app-swipe-row" role="tablist">
        <?php foreach ($kolom as $hk):
            $label = jadwal_hari_singkat($hk, $hari);
            $rawItems = $byHari[$hk] ?? [];
            $count = jadwal_minggu_hitung_unit_view($rawItems, $viewKat);
            $isToday = $hk === $todayCol;
            ?>
            <button type="button"
                class="jadwal-hari-tabs__btn<?= $hk === $initialHari ? ' is-active' : '' ?><?= $isToday ? ' is-today' : '' ?>"
                data-hari="<?= (int) $hk ?>"
                role="tab"
                aria-selected="<?= $hk === $initialHari ? 'true' : 'false' ?>">
                <span class="jadwal-hari-tabs__label"><?= htmlspecialchars($label) ?></span>
                <?php if ($count > 0): ?><span class="jadwal-hari-tabs__count"><?= (int) $count ?></span><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($kolom as $hk):
        $rawItems = $byHari[$hk] ?? [];
        $maxShow = $maxShowMobile;
        $label = jadwal_hari_singkat($hk, $hari);
        ?>
        <div class="jadwal-hari-panel<?= $hk === $initialHari ? ' is-active' : '' ?>" data-hari-panel="<?= (int) $hk ?>" role="tabpanel">
            <?php if ($rawItems === [] || jadwal_minggu_hitung_unit_view($rawItems, $viewKat) === 0): ?>
                <div class="jadwal-hari-panel__empty text-muted small text-center py-4">Tidak ada jadwal <?= htmlspecialchars($label) ?>.</div>
            <?php else: ?>
                <div class="jadwal-hari-panel__list">
                    <?php
                    $mobileLayout = false;
                    require __DIR__ . '/jadwal_minggu_hari_isi.php';
                    ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
