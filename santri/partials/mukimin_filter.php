<form method="get" class="mb-3 mukimin-filter-form">
    <?php if ($editId > 0): ?>
        <input type="hidden" name="edit" value="<?= $editId ?>">
    <?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-lg-3">
            <label class="form-label small text-muted mb-1" for="mukimin-cari">Cari nama atau NIS</label>
            <input type="search" id="mukimin-cari" name="cari" class="form-control" placeholder="Nama atau NIS" value="<?= htmlspecialchars($filters['cari']) ?>" autocomplete="off">
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <label class="form-label small text-muted mb-1" for="mukimin-keterangan">Keterangan</label>
            <input type="search" id="mukimin-keterangan" name="keterangan" class="form-control" list="mukimin-keterangan-list" placeholder="Cari kata dalam keterangan" value="<?= htmlspecialchars($filters['keterangan'] ?? '') ?>" autocomplete="off">
            <datalist id="mukimin-keterangan-list">
                <?php foreach ($keteranganOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="mukimin-dusun">Dusun</label>
            <select id="mukimin-dusun" name="dusun" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($dusunOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['dusun'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="mukimin-desa">Desa/Kelurahan</label>
            <select id="mukimin-desa" name="desa_kelurahan" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($desaOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['desa_kelurahan'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="mukimin-kecamatan">Kecamatan</label>
            <select id="mukimin-kecamatan" name="kecamatan" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($kecamatanOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['kecamatan'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="mukimin-kabupaten">Kabupaten</label>
            <select id="mukimin-kabupaten" name="kabupaten" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($kabupatenOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['kabupaten'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="mukimin-th-masuk">Th. masuk</label>
            <select id="mukimin-th-masuk" name="th_masuk" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($thMasukOptions as $th): ?>
                    <option value="<?= $th ?>"<?= ($filters['th_masuk'] ?? '') === (string) $th ? ' selected' : '' ?>><?= $th ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="mukimin-th-keluar">Th. keluar</label>
            <select id="mukimin-th-keluar" name="th_keluar" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($thKeluarOptions as $th): ?>
                    <option value="<?= $th ?>"<?= ($filters['th_keluar'] ?? '') === (string) $th ? ' selected' : '' ?>><?= $th ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm">Terapkan filter</button>
            <a class="btn btn-outline-secondary btn-sm" href="/santri/mukimin.php<?= $editId > 0 ? '?edit=' . $editId : '' ?>">Reset</a>
            <p class="small text-muted mb-0 ms-lg-auto">Menampilkan <strong><?= $total ?></strong> dari <?= $totalAll ?> mukimin &middot; urut <strong>baris Excel / NIS</strong></p>
        </div>
    </div>
</form>
