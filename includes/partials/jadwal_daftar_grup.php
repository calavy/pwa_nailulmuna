<?php

declare(strict_types=1);

/**
 * Tab Daftar — satu tabel, thead sticky, tbody per grup.
 *
 * @var array<string, array<int, array<string, list<array<string, mixed>>>>>|array<string, array<int, list<array<string, mixed>>>> $jadwalGrouped
 * @var array<int, string> $hari
 * @var string $tampilanGrup kegiatan|tingkatan|pembimbing
 * @var string $viewKat taalim|jamaah
 * @var PDO $pdo
 */
$tampilanGrup = $tampilanGrup ?? 'kegiatan';
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$katDotClass = $viewKat === 'jamaah' ? 'jadwal-kat-dot--jamaah' : 'jadwal-kat-dot--taalim';
$pdo = $pdo ?? null;
$useHariJam = in_array($tampilanGrup, ['kegiatan', 'pembimbing'], true);
$columnLayout = jadwal_daftar_kolom_layout($viewKat, $tampilanGrup);
$colspanTotal = jadwal_daftar_kolom_total($columnLayout);

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
    <?php require __DIR__ . '/jadwal_empty_kat.php'; ?>
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

        <div class="table-responsive jadwal-daftar-scroll">
            <table class="table table-sm table-hover align-middle mb-0 jadwal-daftar-table jadwal-tabel-ringkas">
                <thead class="jadwal-daftar-thead">
                    <tr>
                        <th style="width:2.5rem"><span class="visually-hidden">Pilih</span></th>
                        <?php if (!empty($columnLayout['kegiatan'])): ?><th>Kegiatan</th><?php endif; ?>
                        <?php if (!empty($columnLayout['tingkatan'])): ?><th>Tingkatan</th><?php endif; ?>
                        <?php if (!empty($columnLayout['hari'])): ?><th>Hari</th><?php endif; ?>
                        <?php if (!empty($columnLayout['pembimbing'])): ?><th>Pembimbing</th><?php endif; ?>
                        <?php if (!empty($columnLayout['waktu'])): ?><th>Waktu</th><?php endif; ?>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <?php foreach ($jadwalGrouped as $namaGrup => $grupContent):
                    $allItems = $flattenGrupItems($grupContent);
                    if ($allItems === []) {
                        continue;
                    }
                    $rows = jadwal_gabung_baris_serupa($allItems);
                    $grupRowCount = count($rows);
                    $grupEsc = htmlspecialchars($namaGrup, ENT_QUOTES);
                    ?>
                    <tbody class="jadwal-daftar-grup jadwal-daftar-grup--<?= htmlspecialchars($viewKat) ?>" data-grup="<?= $grupEsc ?>">
                        <tr class="jadwal-daftar-grup__head">
                            <td colspan="<?= (int) $colspanTotal ?>">
                                <div class="jadwal-daftar-grup__head-inner">
                                    <span class="jadwal-kat-dot <?= htmlspecialchars($katDotClass) ?>" aria-hidden="true"></span>
                                    <span class="jadwal-daftar-grup__title"><?= htmlspecialchars($namaGrup) ?></span>
                                    <span class="badge rounded-pill text-bg-light border jadwal-kat-grup__count"><?= (int) $grupRowCount ?> baris</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 ms-auto jadwal-select-grup" data-grup="<?= $grupEsc ?>">
                                        Pilih grup ini
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($rows as $item):
                            require __DIR__ . '/jadwal_daftar_row.php';
                        endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
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
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
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
