<form method="get" class="mb-3 alumni-filter-form">
    <?php if ($editId > 0): ?>
        <input type="hidden" name="edit" value="<?= $editId ?>">
    <?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-lg-3">
            <label class="form-label small text-muted mb-1" for="alumni-cari">Cari nama atau NIS</label>
            <input type="search" id="alumni-cari" name="cari" class="form-control" placeholder="Nama atau NIS" value="<?= htmlspecialchars($filters['cari']) ?>" autocomplete="off">
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="alumni-dusun">Dusun</label>
            <select id="alumni-dusun" name="dusun" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($dusunOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['dusun'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="alumni-desa">Desa/Kelurahan</label>
            <select id="alumni-desa" name="desa_kelurahan" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($desaOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['desa_kelurahan'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="alumni-kecamatan">Kecamatan</label>
            <select id="alumni-kecamatan" name="kecamatan" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($kecamatanOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['kecamatan'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="alumni-kabupaten">Kabupaten</label>
            <select id="alumni-kabupaten" name="kabupaten" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($kabupatenOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"<?= ($filters['kabupaten'] ?? '') === $opt ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="alumni-th-masuk">Th. masuk</label>
            <select id="alumni-th-masuk" name="th_masuk" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($thMasukOptions as $th): ?>
                    <option value="<?= $th ?>"<?= ($filters['th_masuk'] ?? '') === (string) $th ? ' selected' : '' ?>><?= $th ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1" for="alumni-th-keluar">Th. keluar</label>
            <select id="alumni-th-keluar" name="th_keluar" class="form-select">
                <option value="">Semua</option>
                <?php foreach ($thKeluarOptions as $th): ?>
                    <option value="<?= $th ?>"<?= ($filters['th_keluar'] ?? '') === (string) $th ? ' selected' : '' ?>><?= $th ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm">Terapkan filter</button>
            <a class="btn btn-outline-secondary btn-sm" href="/santri/alumni.php<?= $editId > 0 ? '?edit=' . $editId : '' ?>">Reset</a>
            <p class="small text-muted mb-0 ms-lg-auto">Menampilkan <strong><?= $total ?></strong> dari <?= $totalAll ?> alumni &middot; urut <strong>baris Excel / NIS</strong></p>
        </div>
    </div>
</form>
