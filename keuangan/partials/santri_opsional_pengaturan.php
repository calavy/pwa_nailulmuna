<?php

declare(strict_types=1);

/**
 * @var PDO $pdo
 * @var array<string,mixed> $ops
 * @var string $opsEmbedBase query prefix e.g. bagian=santri_bulanan&sub=opsional
 */

extract($ops, EXTR_SKIP);
$opsEmbedBase = $opsEmbedBase ?? 'bagian=santri_bulanan&sub=opsional';
$buildQs = static function (array $extra = []) use ($opsEmbedBase): string {
    parse_str($opsEmbedBase, $base);
    $merged = array_merge($base, $extra);

    return '?' . http_build_query($merged);
};
?>

<div class="alert alert-info small mb-3">
    Atur tagihan <strong>opsional bulanan</strong> per santri: aktif/nonaktif <strong>Makan</strong> &amp; <strong>Saku</strong>, serta nominal khusus.
    Tarif default tier ada di tab <a href="?bagian=tarif#syahriyah-pokok">Tarif &amp; komponen</a>.
    Syahriyah wajib &amp; potongan % di sub-tab <a href="?bagian=santri_bulanan&amp;sub=potongan">Potongan syahriyah</a>.
</div>

<div class="row g-3 mb-3">
    <?php foreach ($opsionalSlugs as $slug): ?>
        <div class="col-6 col-md-3">
            <div class="app-mini-stat h-100">
                <div class="app-mini-stat-label"><?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?> dinonaktifkan</div>
                <div class="app-mini-stat-value text-danger"><?= (int) $jumlahNonaktif[$slug] ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Santri sudah diatur</div>
            <div class="app-mini-stat-value text-primary"><?= (int) $totalConfigured ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total ditampilkan</div>
            <div class="app-mini-stat-value"><?= (int) count($rows) ?> / <?= (int) $totalRows ?></div>
        </div>
    </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="bagian" value="santri_bulanan">
    <input type="hidden" name="sub" value="opsional">
    <div class="col-md-4 col-lg-3">
        <label class="form-label small mb-0">Cari nama / NIS</label>
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" placeholder="Nama atau NIS">
    </div>
    <div class="col-md-3 col-lg-3">
        <label class="form-label small mb-0">Tingkatan / kelas</label>
        <select name="tingkatan" class="form-select form-select-sm">
            <option value="">Semua</option>
            <?php foreach ($tingkatanOptions as $kode => $lab): ?>
                <option value="<?= htmlspecialchars($kode) ?>"<?= $tingkatanFilter === $kode ? ' selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 col-lg-2">
        <label class="form-label small mb-0">Tampilkan</label>
        <select name="tampil" class="form-select form-select-sm">
            <option value="semua"<?= $tampilFilter === 'semua' ? ' selected' : '' ?>>Semua</option>
            <option value="sudah_diatur"<?= $tampilFilter === 'sudah_diatur' ? ' selected' : '' ?>>Sudah diatur</option>
            <option value="belum_diatur"<?= $tampilFilter === 'belum_diatur' ? ' selected' : '' ?>>Belum diatur</option>
        </select>
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-0">Per hal</label>
        <select name="per_page" class="form-select form-select-sm">
            <?php foreach ([25, 50, 100, 200] as $pp): ?>
                <option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
        <a href="<?= htmlspecialchars($buildQs()) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
    </div>
</form>

<form method="post" class="card shadow-sm mb-3">
    <input type="hidden" name="action" value="save_opsional_santri">
    <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
    <input type="hidden" name="_redir_tingkatan" value="<?= htmlspecialchars($tingkatanFilter) ?>">
    <input type="hidden" name="_redir_tampil" value="<?= htmlspecialchars($tampilFilter) ?>">
    <input type="hidden" name="_redir_page" value="<?= (int) $page ?>">
    <input type="hidden" name="_redir_per_page" value="<?= (int) $perPage ?>">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">NIS</th>
                        <th>Nama</th>
                        <th>Tingkatan / kelas</th>
                        <?php foreach ($opsionalSlugs as $slug): ?>
                            <th class="text-center"><?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?></th>
                            <th class="text-end" style="min-width:8rem">Nominal <?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= 3 + 2 * count($opsionalSlugs) ?>" class="text-center text-muted py-4">Tidak ada santri pada filter ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $sid = (int) $r['id'];
                    $kelas = trim((string) ($r['kategori_kelas'] ?? ''));
                    if ($kelas === '') {
                        $kelas = trim((string) ($r['tingkatan'] ?? ''));
                    }
                    $tier = keuangan_tier_key_from_kelas($kelas, $pdo);
                    $labelKelas = $kelasLabel[$kelas] ?? $kelas;
                    ?>
                    <tr>
                        <td class="ps-3 font-monospace small"><?= htmlspecialchars((string) ($r['nis'] ?? '—')) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string) ($r['nama_santri'] ?? '')) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($labelKelas !== '' ? $labelKelas : '—') ?><br><span class="badge bg-light text-muted text-uppercase"><?= htmlspecialchars($tier) ?></span></td>
                        <?php foreach ($opsionalSlugs as $slug):
                            $entry = $overridesMap[$sid][$slug] ?? null;
                            $aktif = $entry === null ? true : (bool) $entry['aktif'];
                            $nomVal = $entry['nominal'] ?? null;
                            $tierTarif = max(0, (int) ($tarifByTier[$slug][$tier] ?? 0));
                            ?>
                            <td class="text-center">
                                <input type="hidden" name="ids[]" value="<?= $sid ?>">
                                <div class="form-check form-switch d-inline-block">
                                    <input type="checkbox" class="form-check-input" name="aktif[<?= $sid ?>][<?= htmlspecialchars($slug) ?>]" value="1" <?= $aktif ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-end">
                                <input type="number" min="0" step="500" inputmode="numeric"
                                    class="form-control form-control-sm text-end"
                                    name="nominal[<?= $sid ?>][<?= htmlspecialchars($slug) ?>]"
                                    value="<?= $nomVal === null ? '' : (int) $nomVal ?>"
                                    placeholder="<?= $tierTarif > 0 ? 'Default ' . number_format($tierTarif, 0, ',', '.') : 'belum diatur' ?>">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($rows !== []): ?>
        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm">Simpan halaman ini</button>
            <span class="small text-muted">Centang = ditagih; nominal kosong = pakai tarif tier kelas.</span>
        </div>
    <?php endif; ?>
</form>

<?php if ($idsScope !== []): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">Aksi massal</h2>
            <div class="row g-3">
                <?php foreach ($opsionalSlugs as $slug): ?>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h3 class="h6 mb-2"><?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?></h3>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                                <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                <input type="hidden" name="_redir_q" value="<?= htmlspecialchars($q) ?>">
                                <input type="hidden" name="_redir_tingkatan" value="<?= htmlspecialchars($tingkatanFilter) ?>">
                                <input type="hidden" name="_redir_tampil" value="<?= htmlspecialchars($tampilFilter) ?>">
                                <input type="hidden" name="_redir_page" value="<?= (int) $page ?>">
                                <input type="hidden" name="_redir_per_page" value="<?= (int) $perPage ?>">
                                <?php foreach ($idsScope as $sid): ?>
                                    <input type="hidden" name="ids_scope[]" value="<?= (int) $sid ?>">
                                <?php endforeach; ?>
                                <div>
                                    <label class="form-label small mb-0">Lingkup</label>
                                    <select name="scope" class="form-select form-select-sm">
                                        <option value="filter">Filter ini (<?= count($idsScope) ?>)</option>
                                        <option value="all_active">Semua santri aktif</option>
                                    </select>
                                </div>
                                <button type="submit" name="action" value="bulk_aktif" class="btn btn-success btn-sm" onclick="return confirm('Aktifkan <?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?>?');">Aktifkan</button>
                                <button type="submit" name="action" value="bulk_nonaktif" class="btn btn-outline-danger btn-sm" onclick="return confirm('Nonaktifkan <?= htmlspecialchars($slugLabel[$slug] ?? $slug) ?>?');">Nonaktifkan</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($totalPages > 1):
    $pageBase = ['bagian' => 'santri_bulanan', 'sub' => 'opsional', 'per_page' => $perPage];
    if ($q !== '') {
        $pageBase['q'] = $q;
    }
    if ($tingkatanFilter !== '') {
        $pageBase['tingkatan'] = $tingkatanFilter;
    }
    if ($tampilFilter !== 'semua') {
        $pageBase['tampil'] = $tampilFilter;
    }
    ?>
    <nav class="mt-2 d-flex flex-wrap justify-content-center gap-1" aria-label="Halaman opsional santri">
        <?php if ($page > 1): $prev = $pageBase; $prev['page'] = $page - 1; ?>
            <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($prev)) ?>">«</a>
        <?php endif;
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($p = $startP; $p <= $endP; $p++):
            $pq = $pageBase;
            $pq['page'] = $p;
            ?>
            <a class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= htmlspecialchars(http_build_query($pq)) ?>"><?= $p ?></a>
        <?php endfor;
        if ($page < $totalPages): $next = $pageBase; $next['page'] = $page + 1; ?>
            <a class="btn btn-sm btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query($next)) ?>">»</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
