<?php

declare(strict_types=1);

/**
 * @var PDO $pdo
 * @var string $alokasiJenisDana
 * @var string $alokasiSectionBagian
 * @var list<array<string, mixed>> $alokasiRowsFiltered
 * @var array<string, mixed>|null $editAlokasiScoped
 * @var callable(int): string $formatRupiah
 */

$alokasiLabel = keuangan_alokasi_label_jenis($alokasiJenisDana);
$alokasiAktifRows = keuangan_fetch_alokasi_aktif($pdo, $alokasiJenisDana);
$realisasiPagu = keuangan_alokasi_realisasi_ta($pdo, $alokasiJenisDana);
$simulasi = keuangan_alokasi_simulasi($pdo, [], $alokasiJenisDana);
$periodeTa = keuangan_tahun_ajaran_aktif($pdo);
$simSuffix = $alokasiJenisDana === KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN ? 'at' : 'sy';
$paguSumber = $alokasiJenisDana === KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN
    ? 'pembayaran awal tahun santri'
    : 'syahriyah bulanan';
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold"><?= $editAlokasiScoped ? 'Ubah alokasi' : 'Tambah alokasi' ?> — <?= htmlspecialchars($alokasiLabel) ?></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_alokasi">
                    <input type="hidden" name="jenis_dana" value="<?= htmlspecialchars($alokasiJenisDana) ?>">
                    <input type="hidden" name="alokasi_id" value="<?= (int) ($editAlokasiScoped['id'] ?? 0) ?>">
                    <div class="col-12">
                        <label class="form-label">Nama komponen <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama_komponen" required value="<?= htmlspecialchars((string) ($editAlokasiScoped['nama_komponen'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                        <input class="form-control" name="kategori" required value="<?= htmlspecialchars((string) ($editAlokasiScoped['kategori'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Persen %</label>
                        <input type="number" step="0.01" class="form-control" name="persen" value="<?= htmlspecialchars((string) ($editAlokasiScoped['persen'] ?? '0')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Urutan</label>
                        <input type="number" class="form-control" name="urutan" value="<?= (int) ($editAlokasiScoped['urutan'] ?? 0) ?>">
                    </div>
                    <?php if ($editAlokasiScoped): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="alok-aktif-<?= $simSuffix ?>"
                                <?= (int) ($editAlokasiScoped['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="alok-aktif-<?= $simSuffix ?>">Aktif</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Simpan alokasi</button>
                        <?php if ($editAlokasiScoped): ?>
                            <a class="btn btn-outline-secondary" href="?bagian=<?= htmlspecialchars($alokasiSectionBagian) ?>">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
                <p class="small text-muted mt-2 mb-0">
                    Persentase pembagian dana <strong><?= htmlspecialchars($alokasiLabel) ?></strong>.
                    Pagu dihitung dari total <?= htmlspecialchars($paguSumber) ?> TA aktif.
                    Total alokasi aktif <strong>tidak boleh melebihi 100%</strong> per jenis dana.
                </p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Daftar alokasi <?= htmlspecialchars($alokasiLabel) ?></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Komponen</th>
                                <th class="text-end">%</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($alokasiRowsFiltered === []): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">Belum ada alokasi. Simpan komponen pertama atau tunggu data default.</td></tr>
                        <?php else: ?>
                            <?php
                            $totalPersen = 0.0;
                            foreach ($alokasiRowsFiltered as $al):
                                if ((int) ($al['is_active'] ?? 1) === 1) {
                                    $totalPersen += (float) ($al['persen'] ?? 0);
                                }
                                ?>
                                <tr class="<?= (int) ($al['is_active'] ?? 1) !== 1 ? 'table-secondary' : '' ?>">
                                    <td class="small">
                                        <?= htmlspecialchars((string) $al['nama_komponen']) ?>
                                        <div class="text-muted"><?= htmlspecialchars((string) $al['kategori']) ?></div>
                                    </td>
                                    <td class="text-end small"><?= htmlspecialchars((string) $al['persen']) ?>%</td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="?bagian=<?= htmlspecialchars($alokasiSectionBagian) ?>&amp;edit_alokasi=<?= (int) $al['id'] ?>">Ubah</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($alokasiRowsFiltered !== []): ?>
                    <p class="small text-muted px-3 py-2 mb-0">
                        Jumlah persen aktif:
                        <strong class="<?= $totalPersen > 100 ? 'text-danger' : '' ?>"><?= htmlspecialchars((string) round($totalPersen, 2)) ?>%</strong>
                        <?php if ($totalPersen > 100): ?><span class="text-danger"> — melebihi 100%</span><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($alokasiAktifRows !== []): ?>
<div class="card shadow-sm mt-3 border-info border-opacity-25" id="alokasi-sim-card-<?= $simSuffix ?>">
    <div class="card-header bg-info bg-opacity-10 fw-semibold text-info-emphasis">Simulasi alokasi <?= htmlspecialchars($alokasiLabel) ?></div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Pagu dari realisasi <strong><?= htmlspecialchars($paguSumber) ?></strong> TA <?= (int) $periodeTa['mulai'] ?>/<?= (int) $periodeTa['selesai'] ?>:
            <strong><?= htmlspecialchars($formatRupiah($realisasiPagu)) ?></strong>.
            Ubah persen untuk melihat nominal per pos <em>sebelum</em> disimpan.
            <?php if ($alokasiJenisDana === KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN): ?>
                <span class="d-block mt-1">Catat pembayaran santri dengan jenis periode <strong>Awal tahun</strong> di <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a>.</span>
            <?php endif; ?>
        </p>
        <div class="row g-2 mb-3">
            <div class="col-md-4"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Total %</div><div class="app-mini-stat-value" id="sim-total-persen-<?= $simSuffix ?>"><?= htmlspecialchars((string) $simulasi['total_persen']) ?>%</div></div></div>
            <div class="col-md-4"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Sisa %</div><div class="app-mini-stat-value text-primary" id="sim-sisa-persen-<?= $simSuffix ?>"><?= htmlspecialchars((string) $simulasi['sisa_persen']) ?>%</div></div></div>
            <div class="col-md-4"><div class="app-mini-stat h-100"><div class="app-mini-stat-label">Status</div><div class="app-mini-stat-value small <?= $simulasi['ok'] ? 'text-success' : 'text-danger' ?>" id="sim-status-label-<?= $simSuffix ?>"><?= $simulasi['ok'] ? 'Valid' : 'Melebihi 100%' ?></div></div></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Komponen</th><th>Persen simulasi</th><th class="text-end">Nominal</th></tr></thead>
                <tbody>
                <?php foreach ($alokasiAktifRows as $al):
                    $aid = (int) ($al['id'] ?? 0);
                    $pct = (float) ($al['persen'] ?? 0);
                    $nomSim = $realisasiPagu > 0 ? (int) floor($realisasiPagu * $pct / 100) : 0;
                    ?>
                    <tr>
                        <td class="small"><strong><?= htmlspecialchars((string) $al['nama_komponen']) ?></strong><div class="text-muted"><?= htmlspecialchars((string) $al['kategori']) ?></div></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <input type="range" class="form-range flex-grow-1 sim-persen-range-<?= $simSuffix ?>" min="0" max="100" step="0.5" data-id="<?= $aid ?>" value="<?= htmlspecialchars((string) $pct) ?>">
                                <input type="number" class="form-control form-control-sm sim-persen-input-<?= $simSuffix ?> text-end" style="width:4.5rem" min="0" max="100" step="0.5" data-id="<?= $aid ?>" value="<?= htmlspecialchars((string) $pct) ?>">
                                <span class="small">%</span>
                            </div>
                        </td>
                        <td class="text-end font-monospace small sim-nominal-<?= $simSuffix ?>" data-id="<?= $aid ?>"><?= htmlspecialchars($formatRupiah($nomSim)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-danger mb-0 mt-2 d-none" id="sim-warn-<?= $simSuffix ?>"></p>
    </div>
</div>
<script>
(function () {
    const suffix = <?= json_encode($simSuffix, JSON_THROW_ON_ERROR) ?>;
    const realisasi = <?= (int) $realisasiPagu ?>;
    const fmt = function (n) { return 'Rp ' + Math.max(0, Math.floor(n)).toLocaleString('id-ID'); };
    function recalc() {
        let total = 0;
        document.querySelectorAll('.sim-persen-input-' + suffix).forEach(function (inp) {
            const p = Math.max(0, parseFloat(inp.value) || 0);
            total += p;
            const id = inp.getAttribute('data-id');
            const nomEl = document.querySelector('.sim-nominal-' + suffix + '[data-id="' + id + '"]');
            if (nomEl) nomEl.textContent = fmt(realisasi * p / 100);
        });
        total = Math.round(total * 100) / 100;
        const sisa = Math.max(0, Math.round((100 - total) * 100) / 100);
        const ok = total <= 100.0001;
        const totalEl = document.getElementById('sim-total-persen-' + suffix);
        const sisaEl = document.getElementById('sim-sisa-persen-' + suffix);
        const statusEl = document.getElementById('sim-status-label-' + suffix);
        const warnEl = document.getElementById('sim-warn-' + suffix);
        if (totalEl) { totalEl.textContent = total + '%'; totalEl.classList.toggle('text-danger', !ok); }
        if (sisaEl) sisaEl.textContent = sisa + '%';
        if (statusEl) { statusEl.textContent = ok ? 'Valid' : 'Melebihi 100%'; statusEl.className = 'app-mini-stat-value small ' + (ok ? 'text-success' : 'text-danger'); }
        if (warnEl) {
            if (!ok) { warnEl.textContent = 'Total ' + total + '% melebihi 100%. Sesuaikan sebelum menyimpan.'; warnEl.classList.remove('d-none'); }
            else warnEl.classList.add('d-none');
        }
    }
    function syncPair(id, value) {
        document.querySelectorAll('.sim-persen-range-' + suffix + '[data-id="' + id + '"], .sim-persen-input-' + suffix + '[data-id="' + id + '"]').forEach(function (el) { el.value = value; });
    }
    document.querySelectorAll('.sim-persen-range-' + suffix).forEach(function (r) {
        r.addEventListener('input', function () { syncPair(r.getAttribute('data-id'), r.value); recalc(); });
    });
    document.querySelectorAll('.sim-persen-input-' + suffix).forEach(function (inp) {
        inp.addEventListener('input', function () { syncPair(inp.getAttribute('data-id'), inp.value); recalc(); });
    });
    recalc();
})();
</script>
<?php endif; ?>
