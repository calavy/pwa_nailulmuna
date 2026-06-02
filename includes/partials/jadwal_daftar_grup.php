<?php

declare(strict_types=1);

/**
 * Tabel jadwal terkelompok: kegiatan/pembimbing + hari + slot jam (tingkatan digabung per jam).
 *
 * @var array<string, array<int, array<string, list<array<string, mixed>>>>>|array<string, array<int, list<array<string, mixed>>>> $jadwalGrouped
 * @var array<int, string> $hari
 * @var string $tampilanGrup kegiatan|tingkatan|pembimbing
 */
$tampilanGrup = $tampilanGrup ?? 'kegiatan';
$byKegiatan = $tampilanGrup === 'kegiatan';
$byPembimbing = $tampilanGrup === 'pembimbing';
$byTingkatan = $tampilanGrup === 'tingkatan';
$useHariJam = $byKegiatan || $byPembimbing;

/**
 * @param list<array<string, mixed>> $items
 */
$renderJadwalRows = static function (array $items, string $namaGrup) use ($byKegiatan, $byTingkatan, $byPembimbing, $useHariJam): void {
    $rows = $useHariJam ? jadwal_gabung_baris_serupa($items) : $items;
    foreach ($rows as $item) {
        $mergeIds = $useHariJam ? array_values(array_filter(array_map('intval', $item['_merge_ids'] ?? [(int) ($item['id'] ?? 0)]))) : [(int) ($item['id'] ?? 0)];
        $tingkatanList = $useHariJam ? ($item['_tingkatan_list'] ?? []) : [trim((string) ($item['tingkatan'] ?? ''))];
        if ($tingkatanList === [] && trim((string) ($item['tingkatan'] ?? '')) !== '') {
            $tingkatanList = [trim((string) ($item['tingkatan'] ?? ''))];
        }
        $jid = (int) ($mergeIds[0] ?? 0);
        ?>
        <tr>
            <td>
                <?php foreach ($mergeIds as $mid): ?>
                    <?php if ($mid > 0): ?>
                    <input class="form-check-input jadwal-row-check d-inline-block me-1" type="checkbox"
                        name="ids[]" value="<?= $mid ?>"
                        data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>"
                        aria-label="Pilih jadwal #<?= $mid ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </td>
            <?php if ($byTingkatan): ?>
                <td class="text-nowrap small font-monospace js-time-24"><?= htmlspecialchars(jadwal_jam_ringkas($item)) ?></td>
            <?php elseif ($useHariJam): ?>
                <td class="text-nowrap small font-monospace js-time-24"><?= htmlspecialchars(jadwal_jam_ringkas($item)) ?></td>
            <?php endif; ?>
            <td class="small">
                <?php if ($byKegiatan || $byPembimbing): ?>
                    <?php foreach ($tingkatanList as $tk): ?>
                        <?php if ($tk === '') { continue; } ?>
                        <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge me-1 mb-1"><?= htmlspecialchars($tk) ?></span>
                    <?php endforeach; ?>
                    <?php if ($tingkatanList === []): ?>—<?php endif; ?>
                <?php elseif ($byTingkatan): ?>
                    <?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '—')) ?>
                <?php endif; ?>
            </td>
            <?php if ($byTingkatan): ?>
                <td class="small"><?= htmlspecialchars(trim((string) ($item['tempat'] ?? '')) !== '' ? (string) $item['tempat'] : '—') ?></td>
                <td class="small"><?= htmlspecialchars((string) ($item['nama_pembimbing'] ?? '—')) ?></td>
            <?php else: ?>
                <td class="small"><?= htmlspecialchars(trim((string) ($item['tempat'] ?? '')) !== '' ? (string) $item['tempat'] : '—') ?></td>
                <?php if ($byKegiatan): ?>
                    <td class="small"><?= htmlspecialchars((string) ($item['nama_pembimbing'] ?? '—')) ?></td>
                <?php elseif ($byPembimbing): ?>
                    <td class="small"><?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '—')) ?></td>
                <?php endif; ?>
            <?php endif; ?>
            <td class="text-end text-nowrap">
                <?php foreach ($mergeIds as $mid): ?>
                    <?php if ($mid <= 0) { continue; } ?>
                    <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $mid)) ?>" class="btn btn-outline-warning btn-sm py-0 px-2">Edit<?= count($mergeIds) > 1 ? ' #' . $mid : '' ?></a>
                    <button type="submit" form="form-hapus-jadwal-<?= $mid ?>" class="btn btn-outline-danger btn-sm py-0 px-2"
                        onclick="return confirm('Hapus jadwal ini?');">Hapus</button>
                <?php endforeach; ?>
            </td>
        </tr>
        <?php
    }
};
?>
<?php if ($jadwalGrouped === []): ?>
    <p class="text-muted mb-0">Belum ada jadwal.</p>
<?php else: ?>
    <form method="post" id="form-jadwal-bulk" class="jadwal-bulk-form">
        <input type="hidden" name="action" value="hapus_jadwal_massal">
        <div class="jadwal-bulk-toolbar d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded border bg-light">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="jadwal-select-all" aria-label="Pilih semua jadwal">
                <label class="form-check-label small" for="jadwal-select-all">Pilih semua</label>
            </div>
            <span class="small text-muted" id="jadwal-selected-count">0 dipilih</span>
            <button type="submit" class="btn btn-danger btn-sm ms-auto" id="btn-hapus-jadwal-terpilih" disabled
                onclick="return confirm('Hapus jadwal yang dicentang? Presensi terkait ikut dihapus.');">
                <i class="fa-solid fa-trash-can me-1"></i> Hapus terpilih
            </button>
        </div>

    <?php foreach ($jadwalGrouped as $namaGrup => $grupContent): ?>
        <div class="jadwal-grup-blok mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h3 class="jadwal-grup-judul h6 text-primary mb-0">
                    <?php if ($byKegiatan): ?>
                        <i class="fa-solid fa-calendar-check me-1 opacity-75"></i><?= htmlspecialchars($namaGrup) ?>
                    <?php elseif ($byPembimbing): ?>
                        <i class="fa-solid fa-user-tie me-1 opacity-75"></i><?= htmlspecialchars($namaGrup) ?>
                    <?php else: ?>
                        <span class="badge text-bg-light border text-dark fw-semibold jadwal-tingkatan-badge"><?= htmlspecialchars($namaGrup) ?></span>
                    <?php endif; ?>
                </h3>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 jadwal-select-grup" data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>">
                    Pilih grup ini
                </button>
            </div>

            <?php if ($useHariJam): ?>
                <?php
                $hariKeys = array_keys($grupContent);
                sort($hariKeys, SORT_NUMERIC);
                foreach ($hariKeys as $hariKe):
                    $byJam = $grupContent[$hariKe] ?? [];
                    if ($byJam === []) {
                        continue;
                    }
                    $allItems = [];
                    foreach ($byJam as $jamItems) {
                        foreach ($jamItems as $it) {
                            $allItems[] = $it;
                        }
                    }
                    if ($allItems === []) {
                        continue;
                    }
                    ?>
                    <div class="jadwal-hari-blok mb-3 ps-2 border-start border-3 border-primary-subtle" data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>">
                        <div class="fw-semibold small text-secondary mb-2">
                            <i class="fa-solid fa-calendar-day me-1"></i><?= htmlspecialchars($hari[$hariKe] ?? 'Hari #' . $hariKe) ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas">
                                <thead>
                                    <tr>
                                        <th style="width:2.5rem"><span class="visually-hidden">Pilih</span></th>
                                        <th>Jam</th>
                                        <th>Tingkatan</th>
                                        <th>Tempat</th>
                                        <th><?= $byPembimbing ? 'Kegiatan' : 'Pembimbing' ?></th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $jamKeys = array_keys($byJam);
                                    sort($jamKeys, SORT_STRING);
                                    foreach ($jamKeys as $jamKey):
                                        $jamItems = $byJam[$jamKey] ?? [];
                                        if ($jamItems === []) {
                                            continue;
                                        }
                                        $renderJadwalRows($jamItems, $namaGrup);
                                    endforeach;
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php
                $byHari = $grupContent;
                $hariKeys = array_keys($byHari);
                sort($hariKeys, SORT_NUMERIC);
                foreach ($hariKeys as $hariKe):
                    $items = $byHari[$hariKe] ?? [];
                    if ($items === []) {
                        continue;
                    }
                    ?>
                    <div class="jadwal-hari-blok mb-2" data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>">
                        <div class="fw-semibold small text-secondary mb-1">
                            <?= htmlspecialchars($hari[$hariKe] ?? 'Hari #' . $hariKe) ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas">
                                <thead>
                                    <tr>
                                        <th style="width:2.5rem"><span class="visually-hidden">Pilih</span></th>
                                        <th>Jam</th>
                                        <th>Kegiatan</th>
                                        <th>Tempat</th>
                                        <th>Pembimbing</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $renderJadwalRows($items, $namaGrup); ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </form>

    <?php
    foreach ($jadwalGrouped as $grupContent) {
        $iterItems = [];
        if ($useHariJam) {
            foreach ($grupContent as $byJam) {
                foreach ($byJam as $items) {
                    foreach ($items as $item) {
                        $iterItems[] = $item;
                    }
                }
            }
        } else {
            foreach ($grupContent as $items) {
                foreach ($items as $item) {
                    $iterItems[] = $item;
                }
            }
        }
        foreach ($iterItems as $item) {
            $jid = (int) ($item['id'] ?? 0);
            if ($jid <= 0) {
                continue;
            }
            ?>
            <form method="post" id="form-hapus-jadwal-<?= $jid ?>" class="d-none">
                <input type="hidden" name="action" value="hapus_jadwal">
                <input type="hidden" name="id" value="<?= $jid ?>">
            </form>
            <?php
        }
    }
    ?>
    <script>
    (function () {
        var form = document.getElementById('form-jadwal-bulk');
        if (!form) return;
        var selectAll = document.getElementById('jadwal-select-all');
        var countEl = document.getElementById('jadwal-selected-count');
        var btnHapus = document.getElementById('btn-hapus-jadwal-terpilih');
        var checks = function () { return form.querySelectorAll('.jadwal-row-check'); };

        function updateUi() {
            var list = checks();
            var n = 0;
            list.forEach(function (cb) { if (cb.checked) n++; });
            if (countEl) countEl.textContent = n + ' dipilih';
            if (btnHapus) btnHapus.disabled = n === 0;
            if (selectAll) {
                selectAll.indeterminate = n > 0 && n < list.length;
                selectAll.checked = list.length > 0 && n === list.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks().forEach(function (cb) { cb.checked = selectAll.checked; });
                updateUi();
            });
        }
        form.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('jadwal-row-check')) {
                updateUi();
            }
        });
        document.querySelectorAll('.jadwal-select-grup').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var g = btn.getAttribute('data-grup') || '';
                checks().forEach(function (cb) {
                    if ((cb.getAttribute('data-grup') || '') === g) {
                        cb.checked = true;
                    }
                });
                updateUi();
            });
        });
        updateUi();
    })();
    </script>
<?php endif; ?>
