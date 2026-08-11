<?php

declare(strict_types=1);

/**
 * Grid jadwal per hari (Senin–Minggu + setiap hari).
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var int $filterHari 0 = semua kolom
 * @var string $filterTingkatan
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$filterHari = (int) ($filterHari ?? 0);
$filterTingkatan = trim((string) ($filterTingkatan ?? ''));

$byHari = jadwal_kelompokkan_per_hari_tampilan($jadwalList);
$kolom = jadwal_minggu_kolom();
$todayCol = (int) date('N');
?>
<?php if ($jadwalList === []): ?>
    <div class="jadwal-minggu-empty text-center py-4">
        <div class="jadwal-peta-empty__ico mb-2"><i class="fa-regular fa-calendar-xmark"></i></div>
        <p class="text-muted small mb-2">Belum ada jadwal untuk filter ini.</p>
        <button type="button" class="btn btn-success btn-sm jadwal-panel-toggle" data-panel="jadwal">
            <i class="fa-solid fa-calendar-plus me-1"></i> Tambah jadwal
        </button>
    </div>
<?php else: ?>
    <div class="jadwal-minggu-grid jadwal-minggu-grid--desktop">
        <?php foreach ($kolom as $hk):
            $rawItems = $byHari[$hk] ?? [];
            $items = jadwal_gabung_baris_serupa($rawItems);
            $slug = jadwal_hari_badge_slug($hk);
            $label = jadwal_hari_singkat($hk, $hari);
            $isToday = $hk > 0 && $hk === $todayCol;
            $isFiltered = $filterHari >= 1 && $filterHari <= 7 && $filterHari !== $hk && $hk !== 0;
            $maxShow = 18;
            $totalItems = count($items);
            $visibleItems = $totalItems > $maxShow ? array_slice($items, 0, $maxShow) : $items;
            $hiddenCount = $totalItems - count($visibleItems);
            ?>
            <section class="jadwal-minggu-col<?= $isToday ? ' jadwal-minggu-col--today' : '' ?><?= $isFiltered ? ' jadwal-minggu-col--dim' : '' ?>"
                aria-label="Jadwal <?= htmlspecialchars($label) ?>">
                <header class="jadwal-minggu-col__head">
                    <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($label) ?></span>
                    <?php if ($isToday): ?><span class="jadwal-minggu-col__today">Hari ini</span><?php endif; ?>
                    <span class="jadwal-minggu-col__count"><?= $totalItems ?></span>
                </header>
                <div class="jadwal-minggu-col__body">
                    <?php if ($visibleItems === []): ?>
                        <div class="jadwal-minggu-col__empty small text-muted">—</div>
                    <?php else: ?>
                        <?php foreach ($visibleItems as $slot):
                            $tampilanHk = (int) ($slot['_tampilan_hari'] ?? $hk);
                            if (strtoupper((string) ($slot['kategori_kegiatan'] ?? 'TAALIM')) === 'JAMAAH' && (int) ($slot['hari_ke'] ?? 0) === 0 && $tampilanHk >= 1 && $tampilanHk <= 7) {
                                $namaMw = jadwal_jamaah_munawib_nama_untuk_slot($pdo, (string) ($slot['tingkatan'] ?? ''), $tampilanHk);
                                if ($namaMw !== '') {
                                    $slot['nama_pembimbing'] = $namaMw;
                                    $slot['munawib_harian'] = true;
                                }
                            }
                            ?>
                            <?php
                            $showActions = $showJadwalAksi;
                            $compact = true;
                            require __DIR__ . '/jadwal_slot_card.php';
                            ?>
                        <?php endforeach; ?>
                        <?php if ($hiddenCount > 0 && $hk >= 1 && $hk <= 7): ?>
                            <?php
                            $moreQs = ['tab' => 'minggu', 'filter_hari' => (string) $hk];
                            if ($filterTingkatan !== '' && $filterTingkatan !== 'Semua Tingkatan') {
                                $moreQs['filter_tingkatan'] = $filterTingkatan;
                            }
                            ?>
                            <a class="jadwal-minggu-col__more small" href="<?= htmlspecialchars(app_href('/jadwal/index.php?' . http_build_query($moreQs))) ?>">
                                +<?= (int) $hiddenCount ?> lainnya
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php require __DIR__ . '/jadwal_hari_tabs.php'; ?>
    <p class="small text-muted mt-2 mb-0 d-none d-lg-block">
        <i class="fa-solid fa-circle-info me-1"></i>
        Jadwal <strong>setiap hari</strong> (mis. jamaah) tampil di semua kolom Senin–Minggu. Geser kiri/kanan di layar kecil.
    </p>
<?php endif; ?>
