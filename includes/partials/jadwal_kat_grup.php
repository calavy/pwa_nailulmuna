<?php

declare(strict_types=1);

/**
 * Grup fold seragam Ta'lim / Jama'ah (kartu atau tabel).
 *
 * @var string $variant taalim|jamaah
 * @var string $title
 * @var int $slotCount
 * @var bool $open
 * @var string $bodyMode cards|table
 * @var list<array<string,mixed>> $items
 * @var array<int,string> $hari
 * @var PDO|null $pdo
 * @var int $hk kolom hari tampilan (minggu)
 * @var bool $showJadwalAksi
 * @var bool $practicalCompact
 * @var string $tableSkin ringkas|peta
 * @var string $tableWrapClass
 * @var string $tableClass
 */
$variant = in_array(($variant ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($variant ?? 'taalim') : 'taalim';
$title = trim((string) ($title ?? '—'));
if ($title === '') {
    $title = '—';
}
$slotCount = (int) ($slotCount ?? count($items ?? []));
$open = !empty($open);
$bodyMode = ($bodyMode ?? 'cards') === 'table' ? 'table' : 'cards';
$items = $items ?? [];
$hari = $hari ?? [];
$pdo = $pdo ?? null;
$hk = (int) ($hk ?? 0);
$showJadwalAksi = $showJadwalAksi ?? true;
$practicalCompact = $practicalCompact ?? true;
$tableSkin = ($tableSkin ?? 'ringkas') === 'peta' ? 'peta' : 'ringkas';
$tableWrapClass = trim((string) ($tableWrapClass ?? 'table-responsive'));
$tableClass = trim((string) ($tableClass ?? 'table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas jadwal-kat-grup__table'));

$dotClass = $variant === 'jamaah' ? 'jadwal-kat-dot--jamaah' : 'jadwal-kat-dot--taalim';
$countLabel = $bodyMode === 'table' ? ((string) $slotCount . ' slot') : (string) $slotCount;
$extraClass = trim((string) ($katGrupExtraClass ?? ''));
$detailsClass = 'jadwal-kat-grup jadwal-kat-grup--' . $variant . ' jadwal-jamaah-fold'
    . ($bodyMode === 'table' ? ' jadwal-kat-grup--table jadwal-jamaah-fold--tabel' : '')
    . ($extraClass !== '' ? ' ' . $extraClass : '');
?>
<details class="<?= htmlspecialchars($detailsClass) ?>"<?= $open ? ' open' : '' ?>>
    <summary class="jadwal-kat-grup__summary jadwal-jamaah-fold__summary">
        <span class="jadwal-kat-grup__chev jadwal-jamaah-fold__chev" aria-hidden="true"></span>
        <span class="jadwal-kat-dot <?= htmlspecialchars($dotClass) ?>" aria-hidden="true"></span>
        <span class="jadwal-kat-grup__name jadwal-jamaah-fold__name"><?= htmlspecialchars($title) ?></span>
        <span class="badge rounded-pill text-bg-light border jadwal-kat-grup__count jadwal-jamaah-fold__count"><?= htmlspecialchars($countLabel) ?></span>
    </summary>
    <div class="jadwal-kat-grup__body jadwal-jamaah-fold__body<?= $bodyMode === 'table' ? ' jadwal-kat-grup__body--table jadwal-jamaah-fold__body--tabel' : '' ?>">
        <?php if ($bodyMode === 'cards'): ?>
            <?php
            $cardItems = $variant === 'taalim' ? jadwal_gabung_baris_serupa($items) : $items;
            if ($variant === 'jamaah') {
                usort($cardItems, static function (array $a, array $b): int {
                    $c = strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
                    if ($c !== 0) {
                        return $c;
                    }

                    return strcasecmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
                });
            }
            foreach ($cardItems as $slot):
                if ($pdo instanceof PDO) {
                    $tampilanHk = (int) ($slot['_tampilan_hari'] ?? $hk);
                    $slot = jadwal_minggu_slot_munawib($slot, $tampilanHk, $pdo);
                }
                $showActions = $showJadwalAksi;
                $compact = true;
                $jamaahGroupLine = $variant === 'jamaah';
                require __DIR__ . '/jadwal_slot_card.php';
            endforeach;
            ?>
        <?php else: ?>
            <div class="<?= htmlspecialchars($tableWrapClass) ?>">
                <table class="<?= htmlspecialchars($tableClass) ?>">
                    <thead>
                        <tr>
                            <th>Hari</th>
                            <th>Waktu</th>
                            <?php if ($variant === 'taalim'): ?>
                                <th>Kegiatan</th>
                            <?php else: ?>
                                <th>Tingkatan</th>
                            <?php endif; ?>
                            <th>Pembimbing</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tableItems = $variant === 'taalim' ? jadwal_gabung_baris_serupa($items) : $items;
                        if ($variant === 'jamaah') {
                            jadwal_urutkan_baris_tampilan($tableItems);
                        } else {
                            jadwal_urutkan_baris_tampilan($tableItems);
                        }
                        foreach ($tableItems as $item):
                            if ($pdo instanceof PDO && $variant === 'jamaah') {
                                $hkRow = (int) ($item['hari_ke'] ?? 0);
                                $tampilanHk = $hkRow >= 1 && $hkRow <= 7 ? $hkRow : (int) date('N');
                                if ($hkRow === 0) {
                                    $tampilanHk = (int) date('N');
                                }
                                $item = jadwal_minggu_slot_munawib($item, $tampilanHk, $pdo);
                            }
                            $showKegiatanCol = $variant === 'taalim';
                            $showTingkatanCol = $variant === 'jamaah';
                            require __DIR__ . '/jadwal_tabel_slot_row.php';
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</details>
