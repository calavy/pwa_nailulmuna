<?php

declare(strict_types=1);

/**
 * Tampilan mingguan mobile — tab hari horizontal + daftar vertikal.
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var int $filterHari
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$filterHari = (int) ($filterHari ?? 0);
$byHari = jadwal_kelompokkan_per_hari_tampilan($jadwalList);
$kolom = array_values(array_filter(jadwal_minggu_kolom(), static fn (int $hk): bool => $hk >= 1 && $hk <= 7));
$todayCol = (int) date('N');
$initialHari = ($filterHari >= 1 && $filterHari <= 7) ? $filterHari : $todayCol;
?>
<div class="jadwal-hari-mobile d-lg-none" data-initial-hari="<?= (int) $initialHari ?>">
    <div class="jadwal-hari-tabs app-swipe-row" role="tablist">
        <?php foreach ($kolom as $hk):
            $slug = jadwal_hari_badge_slug($hk);
            $label = jadwal_hari_singkat($hk, $hari);
            $rawItems = $byHari[$hk] ?? [];
            $count = count(jadwal_gabung_baris_serupa($rawItems));
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
        $items = jadwal_gabung_baris_serupa($rawItems);
        $label = jadwal_hari_singkat($hk, $hari);
        ?>
        <div class="jadwal-hari-panel<?= $hk === $initialHari ? ' is-active' : '' ?>" data-hari-panel="<?= (int) $hk ?>" role="tabpanel">
            <?php if ($items === []): ?>
                <div class="jadwal-hari-panel__empty text-muted small text-center py-4">Tidak ada jadwal <?= htmlspecialchars($label) ?>.</div>
            <?php else: ?>
                <div class="jadwal-hari-panel__list">
                    <?php foreach ($items as $slot):
                        $tampilanHk = (int) ($slot['_tampilan_hari'] ?? $hk);
                        if (strtoupper((string) ($slot['kategori_kegiatan'] ?? 'TAALIM')) === 'JAMAAH' && (int) ($slot['hari_ke'] ?? 0) === 0 && $tampilanHk >= 1 && $tampilanHk <= 7) {
                            $namaMw = jadwal_jamaah_munawib_nama_untuk_slot($pdo, (string) ($slot['tingkatan'] ?? ''), $tampilanHk);
                            if ($namaMw !== '') {
                                $slot['nama_pembimbing'] = $namaMw;
                                $slot['munawib_harian'] = true;
                            }
                        }
                        $showActions = $showJadwalAksi;
                        $compact = true;
                        $mobileLayout = true;
                        require __DIR__ . '/jadwal_slot_card.php';
                    endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
