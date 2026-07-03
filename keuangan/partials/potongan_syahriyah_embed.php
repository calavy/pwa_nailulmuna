<?php

declare(strict_types=1);

/**
 * @var PDO $pdo
 * @var array<string, mixed> $potongan
 * @var callable(int): string $formatRupiah
 */

extract($potongan, EXTR_SKIP);
$potonganQs = $potonganQs ?? static fn (array $extra = []): string => '?' . http_build_query(array_merge(['bagian' => 'santri_bulanan', 'sub' => 'potongan'], $extra));
?>

<div class="alert alert-info small mb-3">
    Atur potongan tagihan <strong>Syahriyah</strong> dalam persen per santri.
    Makan &amp; saku per santri di sub-tab <a href="?bagian=santri_bulanan&amp;sub=opsional">Makan &amp; saku</a>.
    Tarif default di <a href="?bagian=tarif#syahriyah-pokok">Tarif &amp; komponen</a>.
    <a href="/pembayaran/tagihan_syahriyah.php">Lihat tagihan bulanan</a>
</div>

<?php require __DIR__ . '/../../includes/partials/keuangan_ta_toolbar.php'; ?>
<?php require __DIR__ . '/../../includes/partials/santri_sort_toolbar.php'; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-primary border-opacity-25">
            <div class="card-header bg-primary bg-opacity-10 fw-semibold text-primary">
                <?= $editRow ? 'Ubah potongan' : 'Tambah / ubah potongan' ?>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="simpan_potongan">
                    <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
                    <div class="col-12">
                        <label class="form-label">Santri <span class="text-danger">*</span></label>
                        <select class="form-select" name="santri_id" id="potongan-santri-select" required>
                            <option value="">— pilih santri —</option>
                            <?php foreach ($santriAktif as $s): ?>
                                <?php $sid = (int) ($s['id'] ?? 0); ?>
                                <option value="<?= $sid ?>" data-tier="<?= htmlspecialchars((string) ($s['tier'] ?? '')) ?>" <?= $sid === $editSantriId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($s['nis'] ?? '') . ' — ' . ($s['nama'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Potongan (%) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="persen" id="potongan-persen" min="0" max="100" step="0.01"
                                value="<?= htmlspecialchars($editPotongan ? (string) ($editPotongan['persen'] ?? '0') : '0') ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">0% = tarif penuh. Contoh: 25% → bayar 75% dari tarif kelas.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Keterangan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="keterangan" id="potongan-keterangan" list="ket-suggest" maxlength="255"
                            placeholder="Mis. Berprestasi juara 1 / Kaka beradik"
                            value="<?= htmlspecialchars($editPotongan ? (string) ($editPotongan['keterangan'] ?? '') : '') ?>">
                        <datalist id="ket-suggest">
                            <?php foreach ($keteranganSuggest as $ks): ?>
                                <option value="<?= htmlspecialchars($ks) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-12 form-check ms-1">
                        <input class="form-check-input" type="checkbox" name="is_aktif" value="1" id="is_aktif"
                            <?= !$editPotongan || (int) ($editPotongan['is_aktif'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_aktif">Potongan aktif</label>
                    </div>
                    <div id="potongan-preview-wrap" class="col-12" style="display:none">
                        <div id="potongan-preview" class="alert alert-light border small mb-0 py-2"></div>
                    </div>
                    <div class="col-12 d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-save me-1"></i> Simpan
                        </button>
                        <?php if ($editPotongan): ?>
                            <a href="<?= htmlspecialchars($potonganQs()) ?>" class="btn btn-outline-secondary btn-sm">Batal edit</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($editPotongan): ?>
                    <form method="post" class="mt-3" onsubmit="return confirm('Hapus potongan santri ini? Tagihan syahriyah kembali tarif penuh.');">
                        <input type="hidden" name="action" value="hapus_potongan">
                        <input type="hidden" name="santri_id" value="<?= $editSantriId ?>">
                        <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Hapus potongan</button>
                    </form>

                    <hr class="my-3">
                    <h2 class="h6 fw-semibold mb-2">Hentikan potongan per bulan</h2>
                    <p class="small text-muted mb-2">Bulan yang dijeda memakai <strong>tarif penuh</strong>; bulan lain tetap memakai potongan.</p>
                    <form method="post" class="row g-2 mb-3">
                        <input type="hidden" name="action" value="tambah_jeda">
                        <input type="hidden" name="santri_id" value="<?= $editSantriId ?>">
                        <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
                        <div class="col-6">
                            <label class="form-label small mb-0">Bulan</label>
                            <select class="form-select form-select-sm" name="bulan_tagihan" required>
                                <?php foreach ($bulanMap as $b => $lbl): ?>
                                    <option value="<?= $b ?>" <?= $b === (int) $berjalan['bulan'] ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php
                        $taMulaiJeda = $taMulai;
                        $taSelesaiJeda = $taSelesai;
                        $taColClass = 'col-6';
                        $taInputMode = 'dropdown';
                        $inputClass = 'form-select form-select-sm';
                        require __DIR__ . '/../../includes/partials/pondok_ta_fields.php';
                        ?>
                        <div class="col-12">
                            <input type="text" class="form-control form-control-sm" name="keterangan_jeda" maxlength="255" placeholder="Alasan jeda (opsional)">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-warning btn-sm w-100">Jeda potongan bulan ini</button>
                        </div>
                    </form>
                    <?php if ($jedaRows !== []): ?>
                        <ul class="list-group list-group-flush small border rounded">
                            <?php foreach ($jedaRows as $jr): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center gap-2 py-2">
                                    <span>
                                        <strong><?= htmlspecialchars($bulanMap[(int) ($jr['bulan_tagihan'] ?? 0)] ?? '?') ?></strong>
                                        TA <?= (int) ($jr['tahun_ajaran_mulai'] ?? 0) ?>/<?= (int) ($jr['tahun_ajaran_selesai'] ?? 0) ?>
                                        <?php if (trim((string) ($jr['keterangan'] ?? '')) !== ''): ?>
                                            <span class="text-muted">· <?= htmlspecialchars((string) $jr['keterangan']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <form method="post" class="m-0" onsubmit="return confirm('Aktifkan kembali potongan untuk bulan ini?');">
                                        <input type="hidden" name="action" value="hapus_jeda">
                                        <input type="hidden" name="jeda_id" value="<?= (int) ($jr['id'] ?? 0) ?>">
                                        <input type="hidden" name="santri_id" value="<?= $editSantriId ?>">
                                        <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm py-0">Aktifkan</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="small text-muted mb-0">Belum ada bulan dijeda.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="fw-semibold">Daftar santri</span>
                <span class="badge text-bg-primary"><?= $jumlahAktif ?> potongan aktif</span>
            </div>
            <div class="card-body border-bottom">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="bagian" value="santri_bulanan">
                    <input type="hidden" name="sub" value="potongan">
                    <div class="col">
                        <label class="form-label small mb-0">Cari nama / NIS</label>
                        <input type="search" class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Opsional">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    </div>
                </form>
                <p class="small text-muted mb-0 mt-2">
                    <?php if (!$tampilSemua && $q === ''): ?>
                        Menampilkan santri yang sudah punya pengaturan potongan.
                        <a href="<?= htmlspecialchars($potonganQs(['semua' => '1', 'santri_id' => $editSantriId > 0 ? $editSantriId : null])) ?>">Tampilkan semua santri</a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($potonganQs($editSantriId > 0 ? ['santri_id' => $editSantriId] : [])) ?>">Hanya yang punya potongan</a>
                    <?php endif; ?>
                </p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>NIS</th>
                                <th>Nama</th>
                                <th class="text-end">Tarif dasar</th>
                                <th class="text-center">Potongan</th>
                                <th>Keterangan</th>
                                <th class="text-end">Setelah potongan</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($listRows === []): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($listRows as $r):
                            $sid = (int) ($r['id'] ?? 0);
                            $aktif = (int) ($r['potongan_aktif'] ?? 0) === 1 && (float) ($r['persen'] ?? 0) > 0;
                            $persen = (float) ($r['persen'] ?? 0);
                            ?>
                            <tr class="<?= $aktif ? 'table-warning table-warning-subtle' : '' ?>">
                                <td class="font-monospace small"><?= htmlspecialchars((string) ($r['nis'] ?? '')) ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></td>
                                <td class="text-end font-monospace small"><?= htmlspecialchars($formatRupiah((int) ($r['sim_dasar'] ?? 0))) ?></td>
                                <td class="text-center">
                                    <?php if ($aktif): ?>
                                        <span class="badge text-bg-warning"><?= htmlspecialchars(rtrim(rtrim(number_format($persen, 2, ',', '.'), '0'), ',')) ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= $aktif ? htmlspecialchars((string) ($r['keterangan'] ?? '')) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end font-monospace small fw-semibold">
                                    <?= htmlspecialchars($formatRupiah((int) ($r['sim_expected'] ?? 0))) ?>
                                    <?php if (!empty($r['sim_dijeda'])): ?>
                                        <span class="d-block text-secondary" style="font-size:.65rem">jeda bulan ini</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($potonganQs(['santri_id' => $sid, 'q' => $q])) ?>">Atur</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.keuangan-pengaturan-page .table-warning-subtle { --bs-table-bg: rgba(255, 193, 7, 0.08); }
</style>

<script>
(function () {
    const tierTarifMap = <?= json_encode($tierTarifMap, JSON_UNESCAPED_UNICODE) ?>;
    const fmtRp = function (n) {
        return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    };
    const sel = document.getElementById('potongan-santri-select');
    const persenInp = document.getElementById('potongan-persen');
    const isAktif = document.getElementById('is_aktif');
    const ketInp = document.getElementById('potongan-keterangan');
    const wrap = document.getElementById('potongan-preview-wrap');
    const preview = document.getElementById('potongan-preview');
    const form = sel ? sel.closest('form') : null;
    const baseQs = <?= json_encode('bagian=santri_bulanan&sub=potongan', JSON_UNESCAPED_UNICODE) ?>;

    function hitungSetelah(dasar, persen, aktif) {
        if (!aktif || dasar <= 0) {
            return dasar;
        }
        const p = Math.max(0, Math.min(100, parseFloat(String(persen).replace(',', '.')) || 0));
        return Math.max(0, Math.round(dasar * (100 - p) / 100));
    }

    function updatePreview() {
        if (!sel || !wrap || !preview) {
            return;
        }
        const opt = sel.options[sel.selectedIndex];
        const tier = opt ? (opt.getAttribute('data-tier') || '') : '';
        const dasar = tier && tierTarifMap[tier] ? parseInt(tierTarifMap[tier], 10) : 0;
        if (dasar <= 0) {
            wrap.style.display = 'none';
            return;
        }
        const aktif = isAktif ? isAktif.checked : true;
        const p = persenInp ? persenInp.value : '0';
        const setelah = hitungSetelah(dasar, p, aktif);
        preview.innerHTML =
            'Tarif dasar: <strong>' + fmtRp(dasar) + '</strong><br>' +
            'Setelah potongan: <strong class="text-success">' + fmtRp(setelah) + '</strong>';
        wrap.style.display = '';
    }

    function syncKeteranganRequired() {
        if (!ketInp) {
            return;
        }
        const aktif = isAktif ? isAktif.checked : false;
        const p = parseFloat(String(persenInp ? persenInp.value : '0').replace(',', '.')) || 0;
        ketInp.required = aktif && p > 0;
    }

    if (sel) {
        sel.addEventListener('change', function () {
            const v = parseInt(sel.value, 10) || 0;
            if (v > 0) {
                const q = new URLSearchParams(window.location.search).get('q') || '';
                let url = '?' + baseQs + '&santri_id=' + v;
                if (q) {
                    url += '&q=' + encodeURIComponent(q);
                }
                window.location.href = url;
                return;
            }
            updatePreview();
        });
    }
    [persenInp, isAktif].forEach(function (el) {
        if (el) {
            el.addEventListener('input', function () {
                updatePreview();
                syncKeteranganRequired();
            });
            el.addEventListener('change', function () {
                updatePreview();
                syncKeteranganRequired();
            });
        }
    });
    if (form) {
        form.addEventListener('submit', function (ev) {
            syncKeteranganRequired();
            const aktif = isAktif ? isAktif.checked : false;
            const p = parseFloat(String(persenInp ? persenInp.value : '0').replace(',', '.')) || 0;
            if (aktif && p > 0 && ketInp && ketInp.value.trim() === '') {
                ev.preventDefault();
                alert('Keterangan wajib diisi bila potongan aktif dan persen lebih dari 0.');
                ketInp.focus();
            }
        });
    }
    updatePreview();
    syncKeteranganRequired();
})();
</script>
