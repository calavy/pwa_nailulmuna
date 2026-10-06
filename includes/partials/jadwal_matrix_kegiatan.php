<?php

declare(strict_types=1);

/**
 * Tab Tabel — dua tabel terpisah (Ta'lim / Jama'ah), gaya peta.
 *
 * @var list<array<string,mixed>> $jadwalList
 * @var array<int,string> $hari
 * @var bool $showJadwalAksi
 * @var array<string,int> $tingkatanSortIndex
 * @var PDO $pdo
 */
$jadwalList = $jadwalList ?? [];
$hari = $hari ?? [];
$showJadwalAksi = $showJadwalAksi ?? true;
$tingkatanSortIndex = $tingkatanSortIndex ?? [];
?>
<div class="jadwal-peta">
    <?php
    $tableSkin = 'peta';
    require __DIR__ . '/jadwal_split_tabel_kategori.php';
    ?>
</div>
