<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengaturan_acl.php';

require_roles(['admin', 'pengurus']);
migrate_legacy_permissions_to_pengaturan($pdo);
require_once __DIR__ . '/includes/wa_akun_logic.php';

$pageTitle = 'Nomor WhatsApp';
$bodyClass = 'settings-module-page wa-nomor-page';
$settingsNavActive = '/settings/wa_akun.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(settings_pengaturan_hub_url()) ?>">Pengaturan</a> · WhatsApp</p>
    <h1 class="h4 mb-1">Nomor WhatsApp</h1>
    <p class="text-muted mb-0 small">
        Simpan dan atur nomor penerima notifikasi WA (pengurus, petugas pendidikan, izin, cashless, dll.).
        Pengaturan gateway &amp; jadwal kirim ada di <a href="<?= htmlspecialchars(app_href('/settings/wa_otomatis.php')) ?>">WA Otomatis</a>.
    </p>
</div>

<?php if ($msg = get_flash('success')): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>
<?php if ($msg = get_flash('error')): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars((string) $msg) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total kontak</div>
            <div class="app-mini-stat-value"><?= $totalKontak ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Aktif</div>
            <div class="app-mini-stat-value text-success"><?= $totalAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Peran terisi</div>
            <div class="app-mini-stat-value"><?= count(array_filter($peranCounts, static fn(int $c): bool => $c > 0)) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Filter</div>
            <div class="app-mini-stat-value" style="font-size:.9rem;"><?= $filterPeran !== '' ? htmlspecialchars(wa_nomor_peran_label($filterPeran)) : 'Semua' ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-3"><?= $editRow ? 'Edit nomor' : 'Tambah nomor baru' ?></h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">
                    <div class="col-12">
                        <label class="form-label">Nama / label <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama" required maxlength="120"
                               value="<?= htmlspecialchars((string) ($editRow['nama'] ?? '')) ?>"
                               placeholder="Contoh: Ust. Ahmad (Pengurus Alpa)">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Nomor WhatsApp <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" name="no_wa" required
                               value="<?= htmlspecialchars($editRow ? wa_nomor_display((string) ($editRow['no_wa'] ?? '')) : '') ?>"
                               placeholder="08xxxxxxxxxx atau ID grup">
                        <div class="form-text">Format 08xxx, 62xxx, atau ID grup WhatsApp.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Urutan</label>
                        <input type="number" class="form-control" name="urutan" min="0"
                               value="<?= (int) ($editRow['urutan'] ?? 0) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Peran / jenis notifikasi</label>
                        <?php
                        $selectedPeran = $editRow ? wa_nomor_parse_peran((string) ($editRow['peran'] ?? '')) : [];
                        ?>
                        <?php foreach ($peranGroups as $groupLabel => $items): ?>
                            <p class="small fw-semibold text-muted mb-1 mt-2"><?= htmlspecialchars($groupLabel) ?></p>
                            <div class="row g-1 mb-1">
                                <?php foreach ($items as $pKey => $pMeta): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   name="peran[]" value="<?= htmlspecialchars($pKey) ?>"
                                                   id="peran_<?= htmlspecialchars($pKey) ?>"
                                                   <?= in_array($pKey, $selectedPeran, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="peran_<?= htmlspecialchars($pKey) ?>">
                                                <?= htmlspecialchars((string) $pMeta['label']) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-text">Satu nomor bisa menerima beberapa jenis notifikasi sekaligus.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Catatan</label>
                        <textarea class="form-control" name="catatan" rows="2" maxlength="500"
                                  placeholder="Keterangan tambahan (opsional)"><?= htmlspecialchars((string) ($editRow['catatan'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_aktif" name="is_aktif" value="1"
                                <?= !isset($editRow) || (int) ($editRow['is_aktif'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_aktif">Aktif (menerima notifikasi)</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i>
                            <?= $editRow ? 'Simpan perubahan' : 'Tambah nomor' ?>
                        </button>
                        <?php if ($editRow): ?>
                            <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php' . ($filterPeran !== '' ? '?peran=' . rawurlencode($filterPeran) : ''))) ?>"
                               class="btn btn-outline-secondary btn-sm">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body pb-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="h6 mb-0">Daftar nomor</h2>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="sync_settings">
                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Sinkronkan ke pengaturan WA Otomatis">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> Sinkronkan
                        </button>
                    </form>
                </div>

                <div class="d-flex flex-wrap gap-1 mb-3">
                    <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php')) ?>"
                       class="btn btn-sm <?= $filterPeran === '' ? 'btn-primary' : 'btn-outline-primary' ?>">Semua</a>
                    <?php foreach ($peranDefs as $pKey => $pMeta): ?>
                        <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php?peran=' . rawurlencode($pKey))) ?>"
                           class="btn btn-sm <?= $filterPeran === $pKey ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            <?= htmlspecialchars((string) $pMeta['label']) ?>
                            <?php if (($peranCounts[$pKey] ?? 0) > 0): ?>
                                <span class="badge text-bg-light ms-1"><?= (int) $peranCounts[$pKey] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($kontakList === []): ?>
                    <div class="alert alert-warning small mb-0">
                        Belum ada nomor WA tersimpan.
                        <?php if ($totalKontak === 0): ?>
                            Nomor dari pengaturan lama (tab Alpa, Izin, Gateway) akan diimpor otomatis saat ada data.
                        <?php else: ?>
                            Tidak ada kontak untuk filter ini.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama</th>
                                    <th>Nomor</th>
                                    <th>Peran</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kontakList as $row): ?>
                                    <?php
                                    $rowId = (int) $row['id'];
                                    $isAktif = (int) ($row['is_aktif'] ?? 0) === 1;
                                    $rowPeran = wa_nomor_parse_peran((string) ($row['peran'] ?? ''));
                                    ?>
                                    <tr class="<?= !$isAktif ? 'table-secondary' : '' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars((string) $row['nama']) ?></strong>
                                            <?php if (!$isAktif): ?>
                                                <span class="badge text-bg-secondary ms-1">Nonaktif</span>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($row['catatan'] ?? '')) !== ''): ?>
                                                <div class="small text-muted"><?= htmlspecialchars((string) $row['catatan']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-monospace small">
                                            <?= htmlspecialchars(wa_nomor_display((string) ($row['no_wa'] ?? ''))) ?>
                                        </td>
                                        <td>
                                            <?php foreach ($rowPeran as $pKey): ?>
                                                <span class="badge text-bg-light border me-1 mb-1"><?= htmlspecialchars(wa_nomor_peran_label($pKey)) ?></span>
                                            <?php endforeach; ?>
                                            <?php if ($rowPeran === []): ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="<?= htmlspecialchars(app_href('/settings/wa_akun.php?edit=' . $rowId . ($filterPeran !== '' ? '&peran=' . rawurlencode($filterPeran) : ''))) ?>"
                                               class="btn btn-outline-primary btn-sm py-0 px-2" title="Edit">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                                <button type="submit" class="btn btn-outline-<?= $isAktif ? 'warning' : 'success' ?> btn-sm py-0 px-2"
                                                        title="<?= $isAktif ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                    <i class="fa-solid fa-<?= $isAktif ? 'pause' : 'play' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus nomor ini?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Hapus">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h6 mb-2">Ringkasan per peran</h2>
                <p class="small text-muted mb-3">Nomor yang aktif dan ditandai peran berikut akan menerima notifikasi otomatis.</p>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Peran</th>
                                <th>Jumlah</th>
                                <th>Nomor terdaftar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($peranDefs as $pKey => $pMeta): ?>
                                <?php $targets = wa_nomor_targets($pdo, $pKey); ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $pMeta['label']) ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars((string) $pMeta['desc']) ?></div>
                                    </td>
                                    <td><?= (int) ($peranCounts[$pKey] ?? 0) ?></td>
                                    <td class="small font-monospace text-break">
                                        <?php if ($targets !== ''): ?>
                                            <?= htmlspecialchars($targets) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Belum diisi</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alert alert-light border small mt-3 mb-0">
            <strong>Catatan:</strong> Perubahan di sini otomatis disinkronkan ke pengaturan WA Otomatis.
            Nomor wali santri dikelola per santri di menu Data Santri / Data Wali, bukan di halaman ini.
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/settings_nav.php';
require_once __DIR__ . '/../includes/footer.php';
