<?php

declare(strict_types=1);

/**
 * Tabel jadwal per grup (kegiatan atau tingkatan) + hari.
 *
 * @var array<string, array<int, list<array<string, mixed>>>> $jadwalGrouped
 * @var array<int, string> $hari
 * @var string $tampilanGrup kegiatan|tingkatan
 */
$tampilanGrup = $tampilanGrup ?? 'kegiatan';
$byKegiatan = $tampilanGrup === 'kegiatan';
?>
<?php if ($jadwalGrouped === []): ?>
    <p class="text-muted mb-0">Belum ada jadwal.</p>
<?php else: ?>
    <?php foreach ($jadwalGrouped as $namaGrup => $byHari): ?>
        <div class="jadwal-grup-blok mb-3">
            <h3 class="jadwal-grup-judul h6 text-primary mb-2">
                <?php if ($byKegiatan): ?>
                    <i class="fa-solid fa-calendar-check me-1 opacity-75"></i><?= htmlspecialchars($namaGrup) ?>
                <?php else: ?>
                    <span class="badge text-bg-light border text-dark fw-semibold jadwal-tingkatan-badge"><?= htmlspecialchars($namaGrup) ?></span>
                <?php endif; ?>
            </h3>
            <?php
            $hariKeys = array_keys($byHari);
            sort($hariKeys, SORT_NUMERIC);
            ?>
            <?php foreach ($hariKeys as $hariKe):
                $items = $byHari[$hariKe] ?? [];
                if ($items === []) {
                    continue;
                }
                ?>
                <div class="jadwal-hari-blok mb-2">
                    <div class="fw-semibold small text-secondary mb-1">
                        <?= htmlspecialchars($hari[$hariKe] ?? 'Hari #' . $hariKe) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas">
                            <thead>
                                <tr>
                                    <th>Jam</th>
                                    <?php if ($byKegiatan): ?>
                                        <th>Tingkatan</th>
                                    <?php else: ?>
                                        <th>Kegiatan</th>
                                    <?php endif; ?>
                                    <th>Tempat</th>
                                    <th>Pembimbing</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="text-nowrap small"><?= htmlspecialchars(substr((string) $item['jam_mulai'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $item['jam_selesai'], 0, 5)) ?></td>
                                        <td class="small">
                                            <?php if ($byKegiatan): ?>
                                                <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge"><?= htmlspecialchars((string) ($item['tingkatan'] ?? '—')) ?></span>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '—')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= htmlspecialchars(trim((string) ($item['tempat'] ?? '')) !== '' ? (string) $item['tempat'] : '—') ?></td>
                                        <td class="small"><?= htmlspecialchars((string) ($item['nama_pembimbing'] ?? '—')) ?></td>
                                        <td class="text-end text-nowrap">
                                            <a href="/jadwal/edit.php?id=<?= (int) $item['id'] ?>" class="btn btn-outline-warning btn-sm py-0 px-2">Edit</a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus jadwal ini?');">
                                                <input type="hidden" name="action" value="hapus_jadwal">
                                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
