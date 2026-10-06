<?php

declare(strict_types=1);

/**
 * Tab Minggu — grid 7 hari (konten menurut view_kat).
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var int $filterHari
 * @var string $jadwalDensity comfort|full
 * @var array<string,int> $tingkatanSortIndex
 * @var PDO $pdo
 * @var string $viewKat taalim|jamaah
 * @var callable $jadwalTabQs
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$filterHari = (int) ($filterHari ?? 0);
$jadwalDensity = ($jadwalDensity ?? 'comfort') === 'full' ? 'full' : 'comfort';
$tingkatanSortIndex = $tingkatanSortIndex ?? [];
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$maxShowComfort = 6;

$byHari = jadwal_kelompokkan_per_hari_tampilan($jadwalList);
$kolom = jadwal_minggu_kolom();
$todayCol = (int) date('N');
?>
<?php if ($jadwalList === []): ?>
    <div class="jadwal-minggu-empty">
        <?php require __DIR__ . '/jadwal_empty_kat.php'; ?>
        <div class="text-center mt-2">
            <button type="button" class="btn btn-success btn-sm jadwal-panel-toggle" data-panel="jadwal">
                <i class="fa-solid fa-calendar-plus me-1"></i> Tambah jadwal
            </button>
        </div>
    </div>
<?php else: ?>
    <div class="jadwal-minggu-grid jadwal-minggu-grid--desktop">
        <?php foreach ($kolom as $hk):
            $rawItems = $byHari[$hk] ?? [];
            $slug = jadwal_hari_badge_slug($hk);
            $label = jadwal_hari_singkat($hk, $hari);
            $isToday = $hk > 0 && $hk === $todayCol;
            $isFiltered = $filterHari >= 1 && $filterHari <= 7 && $filterHari !== $hk && $hk !== 0;
            $maxShow = $jadwalDensity === 'full' ? 18 : $maxShowComfort;
            $totalItems = jadwal_minggu_hitung_unit_view($rawItems, $viewKat);
            $practicalCompact = $jadwalDensity !== 'full';
            ?>
            <section class="jadwal-minggu-col<?= $isToday ? ' jadwal-minggu-col--today' : '' ?><?= $isFiltered ? ' jadwal-minggu-col--dim' : '' ?>"
                aria-label="Jadwal <?= htmlspecialchars($label) ?>">
                <header class="jadwal-minggu-col__head">
                    <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($label) ?></span>
                    <?php if ($isToday): ?><span class="jadwal-minggu-col__today">Hari ini</span><?php endif; ?>
                    <span class="jadwal-minggu-col__count"><?= (int) $totalItems ?></span>
                </header>
                <div class="jadwal-minggu-col__body">
                    <?php if ($totalItems === 0): ?>
                        <div class="jadwal-minggu-col__empty small text-muted">Kosong</div>
                    <?php else:
                        $mobileLayout = false;
                        require __DIR__ . '/jadwal_minggu_hari_isi.php';
                    endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php require __DIR__ . '/jadwal_hari_tabs.php'; ?>
    <p class="small text-muted mt-2 mb-0 d-none d-lg-block jadwal-kat-hint">
        <i class="fa-solid fa-hand-pointer me-1"></i>
        Ketuk grup <?= $viewKat === 'jamaah' ? "Jama'ah (kegiatan)" : 'Ta\'lim (tingkatan)' ?> untuk melihat slot.
    </p>
<?php endif; ?>
