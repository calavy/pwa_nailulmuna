<?php

declare(strict_types=1);

/**
 * Tabel jadwal terkelompok: satu baris per slot waktu (hari & tingkatan digabung).
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
$renderJadwalRows = static function (array $items, string $namaGrup) use ($byKegiatan, $byTingkatan, $byPembimbing, $hari): void {
    $rows = jadwal_gabung_baris_serupa($items);
    foreach ($rows as $item) {
        $mergeIds = array_values(array_filter(array_map('intval', $item['_merge_ids'] ?? [(int) ($item['id'] ?? 0)])));
        $tingkatanList = $item['_tingkatan_list'] ?? [];
        if ($tingkatanList === [] && trim((string) ($item['tingkatan'] ?? '')) !== '') {
            $tingkatanList = [trim((string) ($item['tingkatan'] ?? ''))];
        }
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
                <td class="small"><?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '—')) ?></td>
            <?php elseif ($byPembimbing): ?>
                <td class="small"><?= htmlspecialchars((string) ($item['nama_kegiatan'] ?? '—')) ?></td>
            <?php endif; ?>
            <td class="small">
                <?php foreach ($tingkatanList as $tk): ?>
                    <?php if ($tk === '') { continue; } ?>
                    <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge me-1 mb-1"><?= htmlspecialchars($tk) ?></span>
                <?php endforeach; ?>
                <?php if ($tingkatanList === []): ?>—<?php endif; ?>
            </td>
            <td class="small text-nowrap">
                <?php
                $hariListRender = $item['_hari_list'] ?? [(int) ($item['hari_ke'] ?? 0)];
                foreach ($hariListRender as $hkBadge):
                    $slug = jadwal_hari_badge_slug((int) $hkBadge);
                    $lbl = jadwal_hari_singkat((int) $hkBadge, $hari);
                    ?>
                    <span class="jadwal-peta-hari jadwal-peta-hari--<?= htmlspecialchars($slug) ?> me-1"><?= htmlspecialchars($lbl) ?></span>
                <?php endforeach; ?>
            </td>
            <?php if ($byKegiatan): ?>
                <td class="small"><?= htmlspecialchars((string) ($item['nama_pembimbing'] ?? '—')) ?></td>
            <?php elseif ($byTingkatan): ?>
                <td class="small"><?= htmlspecialchars((string) ($item['nama_pembimbing'] ?? '—')) ?></td>
            <?php endif; ?>
            <td class="text-nowrap small font-monospace js-time-24">
                <span class="jadwal-peta-waktu"><?= htmlspecialchars(jadwal_jam_ringkas($item)) ?></span>
            </td>
            <td class="text-end text-nowrap">
                <?php
                $mergeIds = array_values(array_filter(array_map('intval', $item['_merge_ids'] ?? [(int) ($item['id'] ?? 0)])));
                $editId = (int) ($mergeIds[0] ?? 0);
                $tempatVal = trim((string) ($item['tempat'] ?? ''));
                ?>
                <?php if ($editId > 0): ?>
                    <button type="button"
                        class="btn btn-outline-primary btn-sm py-0 px-2 jadwal-quick-edit"
                        title="Edit cepat"
                        data-edit-id="<?= $editId ?>"
                        data-kegiatan-id="<?= (int) ($item['kegiatan_id'] ?? 0) ?>"
                        data-jam-mulai="<?= htmlspecialchars(app_format_jam((string) ($item['jam_mulai'] ?? ''))) ?>"
                        data-jam-selesai="<?= htmlspecialchars(app_format_jam((string) ($item['jam_selesai'] ?? ''))) ?>"
                        data-pembimbing-id="<?= (int) ($item['pembimbing_id'] ?? 0) ?>"
                        data-tempat="<?= htmlspecialchars($tempatVal) ?>"
                        data-tingkatan="<?= htmlspecialchars(json_encode($tingkatanList, JSON_UNESCAPED_UNICODE)) ?>"
                        data-hari="<?= htmlspecialchars(json_encode(array_values(array_map('intval', $item['_hari_list'] ?? [(int) ($item['hari_ke'] ?? 0)])), JSON_UNESCAPED_UNICODE)) ?>">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <a href="<?= htmlspecialchars(app_href('/jadwal/edit.php?id=' . $editId)) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" title="Form lengkap">
                        <i class="fa-solid fa-up-right-from-square"></i>
                    </a>
                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 jadwal-delete-one"
                        title="Hapus jadwal"
                        data-delete-ids="<?= htmlspecialchars(implode(',', $mergeIds)) ?>"
                        data-confirm="Hapus <?= count($mergeIds) > 1 ? count($mergeIds) . ' slot jadwal' : 'jadwal ini' ?>? Presensi terkait ikut dihapus.">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
};

/**
 * @param array<int, array<string, list<array<string, mixed>>>>|array<int, list<array<string, mixed>>> $grupContent
 * @return list<array<string, mixed>>
 */
$flattenGrupItems = static function (array $grupContent) use ($useHariJam): array {
    $all = [];
    if ($useHariJam) {
        foreach ($grupContent as $byJam) {
            foreach ($byJam as $jamItems) {
                foreach ($jamItems as $it) {
                    $all[] = $it;
                }
            }
        }
    } else {
        foreach ($grupContent as $items) {
            foreach ($items as $it) {
                $all[] = $it;
            }
        }
    }

    return $all;
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
        <?php $allItems = $flattenGrupItems($grupContent); ?>
        <?php if ($allItems === []) { continue; } ?>
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
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0 jadwal-tabel-ringkas">
                    <thead>
                        <tr>
                            <th style="width:2.5rem"><span class="visually-hidden">Pilih</span></th>
                            <?php if ($byTingkatan || $byPembimbing): ?>
                                <th>Kegiatan</th>
                            <?php endif; ?>
                            <th>Tingkatan</th>
                            <th>Hari</th>
                            <?php if ($byKegiatan || $byTingkatan): ?>
                                <th>Pembimbing</th>
                            <?php endif; ?>
                            <th>Waktu</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $renderJadwalRows($allItems, $namaGrup); ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
    </form>

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
