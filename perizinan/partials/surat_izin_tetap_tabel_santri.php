<?php

declare(strict_types=1);

/**
 * Tabel identitas santri pada surat izin tetap gabungan.
 *
 * @var list<array<string, mixed>> $anggotaRows
 * @var int $tabelCols
 */
$anggotaRows = is_array($anggotaRows ?? null) ? $anggotaRows : [];
$tabelCols = max(1, min(2, (int) ($tabelCols ?? 1)));

$renderTable = static function (array $rows, int $offset = 0): void {
    ?>
    <table class="santri">
        <thead>
            <tr>
                <th class="num">No</th>
                <th class="nis">NIS</th>
                <th>Nama Santri</th>
                <th>Tingkatan</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="4">—</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $a): ?>
                    <tr>
                        <td class="num"><?= $offset + (int) $i + 1 ?></td>
                        <td class="nis"><?= htmlspecialchars((string) ($a['nis'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($a['nama_santri'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($a['tingkatan'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
};

if ($tabelCols === 2 && count($anggotaRows) > 6) {
    $half = (int) ceil(count($anggotaRows) / 2);
    $kiri = array_slice($anggotaRows, 0, $half);
    $kanan = array_slice($anggotaRows, $half);
    ?>
    <div class="santri-cols">
        <div class="santri-col"><?php $renderTable($kiri, 0); ?></div>
        <div class="santri-col"><?php $renderTable($kanan, $half); ?></div>
    </div>
    <?php
} else {
    $renderTable($anggotaRows, 0);
}
