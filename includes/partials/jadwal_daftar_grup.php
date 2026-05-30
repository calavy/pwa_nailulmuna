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

    <?php foreach ($jadwalGrouped as $namaGrup => $byHari): ?>
        <div class="jadwal-grup-blok mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h3 class="jadwal-grup-judul h6 text-primary mb-0">
                    <?php if ($byKegiatan): ?>
                        <i class="fa-solid fa-calendar-check me-1 opacity-75"></i><?= htmlspecialchars($namaGrup) ?>
                    <?php else: ?>
                        <span class="badge text-bg-light border text-dark fw-semibold jadwal-tingkatan-badge"><?= htmlspecialchars($namaGrup) ?></span>
                    <?php endif; ?>
                </h3>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 jadwal-select-grup" data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>">
                    Pilih grup ini
                </button>
            </div>
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
                <div class="jadwal-hari-blok mb-2" data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>">
                    <div class="fw-semibold small text-secondary mb-1">
                        <?= htmlspecialchars($hari[$hariKe] ?? 'Hari #' . $hariKe) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas">
                            <thead>
                                <tr>
                                    <th style="width:2.5rem">
                                        <span class="visually-hidden">Pilih</span>
                                    </th>
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
                                <?php foreach ($items as $item):
                                    $jid = (int) ($item['id'] ?? 0);
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($jid > 0): ?>
                                                <input class="form-check-input jadwal-row-check" type="checkbox"
                                                    name="ids[]" value="<?= $jid ?>"
                                                    data-grup="<?= htmlspecialchars($namaGrup, ENT_QUOTES) ?>"
                                                    aria-label="Pilih jadwal #<?= $jid ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap small font-monospace js-time-24"><?= htmlspecialchars(jadwal_jam_ringkas($item)) ?></td>
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
                                            <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $jid)) ?>" class="btn btn-outline-warning btn-sm py-0 px-2">Edit</a>
                                            <button type="submit" form="form-hapus-jadwal-<?= $jid ?>" class="btn btn-outline-danger btn-sm py-0 px-2"
                                                onclick="return confirm('Hapus jadwal ini?');">Hapus</button>
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
    </form>

    <?php
    // Form hapus tunggal (di luar form bulk agar tidak bentrok nested form)
    foreach ($jadwalGrouped as $byHari) {
        foreach ($byHari as $items) {
            foreach ($items as $item) {
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
