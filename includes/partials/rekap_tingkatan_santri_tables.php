<?php

declare(strict_types=1);

/**
 * Tabel santri per kategori untuk satu tingkatan (rekap keaktifan).
 *
 * @var string $tg
 * @var array<string, mixed> $data
 * @var callable(array): string $buildQuery
 */
$data = $data ?? [];
$tg = (string) ($tg ?? '-');
if (!isset($buildQuery) || !is_callable($buildQuery)) {
    $buildQuery = static fn (array $overrides = []): string => '#';
}
?>
<?php foreach (rekap_keaktifan_kategori_urutan() as $katKey): ?>
    <?php $santriKat = $data['santri_by_kategori'][$katKey] ?? []; ?>
    <?php if ($santriKat === []) { continue; } ?>
    <?php $katBadge = rekap_keaktifan_kategori_badge_class($katKey); ?>
    <div class="keaktifan-kategori-group mb-3">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge text-bg-<?= htmlspecialchars($katBadge) ?>"><?= htmlspecialchars($katKey) ?></span>
            <span class="small text-muted"><?= count($santriKat) ?> santri</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead>
                <tr>
                    <th>NIS</th>
                    <th>Nama</th>
                    <th class="text-center">Hadir</th>
                    <th class="text-center">Alpa</th>
                    <th class="text-center">Izin</th>
                    <th class="text-center">Sakit</th>
                    <th class="text-center">Total</th>
                    <th class="text-center">%</th>
                    <th class="print-controls"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($santriKat as $sRow): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $sRow['nis']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $sRow['nama_santri']) ?></td>
                        <td class="text-center text-success"><?= (int) $sRow['hadir'] ?></td>
                        <td class="text-center text-danger"><?= (int) $sRow['alpa'] ?></td>
                        <td class="text-center"><?= (int) $sRow['izin'] ?></td>
                        <td class="text-center"><?= (int) $sRow['sakit'] ?></td>
                        <td class="text-center"><?= (int) $sRow['total'] ?></td>
                        <td class="text-center"><?= htmlspecialchars((string) $sRow['persen_hadir']) ?>%</td>
                        <td class="print-controls">
                            <a href="<?= htmlspecialchars($buildQuery(['santri_id' => (int) $sRow['santri_id'], 'tampilan' => 'santri', 'tingkatan' => $tg])) ?>" class="btn btn-sm btn-outline-primary">Kartu</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
