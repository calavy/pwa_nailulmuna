<?php

declare(strict_types=1);

/**
 * Toolbar fokus jadwal — satu baris desktop, ringkas mobile.
 *
 * @var string $activeTab
 * @var callable $jadwalTabQs
 * @var bool $viewRingkas
 * @var bool $jadwalPembimbingScope
 * @var int $totalKegiatan
 * @var int $totalJadwal
 * @var string $filterKat
 * @var int $filterKegiatanId
 * @var string $filterTingkatan
 * @var int $filterHari
 * @var array<int,string> $hari
 * @var list<array<string,mixed>> $kegiatanListAktif
 * @var list<string> $tingkatanList
 * @var string $tampilanGrup
 */
$isJadwalUtama = !in_array($activeTab, ['jamaah', 'jamaah_munawib'], true);
$filterActive = $filterKat !== '' || $filterKegiatanId > 0
    || ($filterTingkatan !== '' && $filterTingkatan !== 'Semua Tingkatan')
    || ($filterHari >= 1 && $filterHari <= 7);
?>
<div class="jadwal-toolbar mb-3">
    <div class="jadwal-toolbar__row">
        <div class="jadwal-toolbar__left">
            <?php if (!$isJadwalUtama): ?>
                <a class="btn btn-outline-secondary btn-sm jadwal-toolbar__back d-none d-lg-inline-flex" href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('minggu'))) ?>">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
            <?php endif; ?>
            <div class="jadwal-toolbar__title-wrap">
                <h1 class="jadwal-toolbar__title h5 mb-0">Jadwal Kegiatan</h1>
                <?php if ($isJadwalUtama): ?>
                    <span class="jadwal-toolbar__stats d-none d-lg-inline text-muted small">
                        <?= (int) $totalKegiatan ?> kegiatan · <?= (int) $totalJadwal ?> slot
                    </span>
                <?php else: ?>
                    <span class="jadwal-toolbar__stats text-muted small">
                        <?= $activeTab === 'jamaah' ? 'Atur waktu Jama\'ah' : 'Munawib Jama\'ah' ?>
                    </span>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-success btn-sm jadwal-panel-toggle d-none d-lg-inline-flex" data-panel="jadwal" aria-expanded="false">
                <i class="fa-solid fa-plus me-1"></i> Tambah Jadwal
            </button>
        </div>

        <?php if ($isJadwalUtama): ?>
        <div class="jadwal-toolbar__center d-none d-lg-flex">
            <div class="btn-group btn-group-sm jadwal-toolbar__tabs" role="tablist">
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('minggu'))) ?>"
                   class="btn btn-outline-primary<?= $activeTab === 'minggu' ? ' active' : '' ?>">Mingguan</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('daftar'))) ?>"
                   class="btn btn-outline-primary<?= $activeTab === 'daftar' ? ' active' : '' ?>">Daftar</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('tabel'))) ?>"
                   class="btn btn-outline-primary<?= $activeTab === 'tabel' ? ' active' : '' ?>">Tabel</a>
            </div>
        </div>
        <div class="jadwal-toolbar__center-mobile d-lg-none">
            <div class="btn-group btn-group-sm jadwal-toolbar__tabs w-100" role="tablist">
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('minggu'))) ?>"
                   class="btn btn-outline-primary flex-fill<?= $activeTab === 'minggu' ? ' active' : '' ?>">Mgg</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('daftar'))) ?>"
                   class="btn btn-outline-primary flex-fill<?= $activeTab === 'daftar' ? ' active' : '' ?>">Daftar</a>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('tabel'))) ?>"
                   class="btn btn-outline-primary flex-fill<?= $activeTab === 'tabel' ? ' active' : '' ?>">Tabel</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="jadwal-toolbar__right">
            <?php if ($isJadwalUtama): ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle<?= $filterActive ? ' active' : '' ?>"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                    <i class="fa-solid fa-filter me-1"></i><span class="d-none d-sm-inline">Filter</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end jadwal-filter-menu p-3 shadow">
                    <form method="get" class="jadwal-filter-form">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                        <?php if ($viewRingkas): ?><input type="hidden" name="view" value="ringkas"><?php endif; ?>
                        <div class="mb-2">
                            <label class="form-label small mb-0">Kategori</label>
                            <select name="filter_kat" class="form-select form-select-sm">
                                <option value="">Semua</option>
                                <option value="TAALIM" <?= $filterKat === 'TAALIM' ? 'selected' : '' ?>>Ta'lim</option>
                                <option value="JAMAAH" <?= $filterKat === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah</option>
                                <option value="EXTRA" <?= $filterKat === 'EXTRA' ? 'selected' : '' ?>>Extra</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-0">Kegiatan</label>
                            <select name="kegiatan_id" class="form-select form-select-sm">
                                <option value="0">Semua kegiatan</option>
                                <?php foreach ($kegiatanListAktif as $kgOpt): ?>
                                    <option value="<?= (int) $kgOpt['id'] ?>" <?= $filterKegiatanId === (int) $kgOpt['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $kgOpt['nama_kegiatan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-0">Tingkatan</label>
                            <select name="filter_tingkatan" class="form-select form-select-sm">
                                <option value="">Semua tingkatan</option>
                                <?php foreach ($tingkatanList as $tkOpt): ?>
                                    <?php if ((string) $tkOpt === 'Semua Tingkatan') { continue; } ?>
                                    <option value="<?= htmlspecialchars((string) $tkOpt) ?>" <?= $filterTingkatan === (string) $tkOpt ? 'selected' : '' ?>><?= htmlspecialchars((string) $tkOpt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-0">Hari</label>
                            <select name="filter_hari" class="form-select form-select-sm">
                                <option value="0">Semua hari</option>
                                <?php foreach ($hari as $hk => $hn): ?>
                                    <?php if ((int) $hk === 0) { continue; } ?>
                                    <option value="<?= (int) $hk ?>" <?= $filterHari === (int) $hk ? 'selected' : '' ?>><?= htmlspecialchars((string) $hn) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">Terapkan</button>
                            <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs($activeTab, []))) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-ellipsis-vertical me-1"></i><span class="d-none d-sm-inline">Aksi</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <?php if (!$jadwalPembimbingScope): ?>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(app_href('/jadwal/import.php')) ?>"><i class="fa-solid fa-file-import me-2 text-muted"></i>Import Excel</a></li>
                    <?php endif; ?>
                    <?php if (function_exists('user_can_lihat_audit_operasional') && user_can_lihat_audit_operasional()): ?>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(app_url('pembayaran/riwayat_audit.php?modul=jadwal_kegiatan')) ?>"><i class="fa-solid fa-clipboard-list me-2 text-muted"></i>Log audit</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('jamaah'))) ?>"><i class="fa-solid fa-mosque me-2 text-muted"></i>Atur waktu Jama'ah</a></li>
                    <li><a class="dropdown-item" href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $jadwalTabQs('jamaah_munawib'))) ?>"><i class="fa-solid fa-user-check me-2 text-muted"></i>Munawib Jama'ah</a></li>
                    <li><a class="dropdown-item" href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>"><i class="fa-solid fa-bookmark me-2 text-muted"></i>Kegiatan Ta'lim / Jama'ah</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ($viewRingkas): ?>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>"><i class="fa-solid fa-table me-2 text-muted"></i>Tampilan lengkap</a></li>
                    <?php else: ?>
                        <li><a class="dropdown-item" href="<?= htmlspecialchars(app_href('/jadwal/index.php?view=ringkas')) ?>"><i class="fa-solid fa-bars me-2 text-muted"></i>Tampilan ringkas</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($isJadwalUtama && $activeTab === 'daftar'): ?>
    <div class="jadwal-toolbar__sub d-flex flex-wrap align-items-center gap-1 mt-2">
        <span class="small text-muted me-1">Kelompok:</span>
        <?php
        $grupQs = static function (string $g) use ($jadwalTabQs, $activeTab): string {
            return $jadwalTabQs($activeTab, ['grup' => $g]);
        };
        ?>
        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $grupQs('kegiatan'))) ?>"
           class="btn btn-sm <?= $tampilanGrup === 'kegiatan' ? 'btn-primary' : 'btn-outline-secondary' ?>">Kegiatan</a>
        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $grupQs('pembimbing'))) ?>"
           class="btn btn-sm <?= $tampilanGrup === 'pembimbing' ? 'btn-primary' : 'btn-outline-secondary' ?>">Pembimbing</a>
        <a href="<?= htmlspecialchars(app_href('/jadwal/index.php' . $grupQs('tingkatan'))) ?>"
           class="btn btn-sm <?= $tampilanGrup === 'tingkatan' ? 'btn-primary' : 'btn-outline-secondary' ?>">Tingkatan</a>
    </div>
    <?php endif; ?>
</div>
