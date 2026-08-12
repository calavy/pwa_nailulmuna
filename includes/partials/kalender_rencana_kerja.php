<?php

declare(strict_types=1);

/** @var array{start:string,end:string} $rencanaRange */
/** @var array{total:int,selesai:int,progress_pct:int,hari_ini:int,berjalan:int,prioritas_tinggi:int} $rencanaStats */
/** @var array{range_start:string,range_end:string,items:list<array<string,mixed>>} $rencanaGantt */
/** @var list<array{label:string,date:string,items:list<array<string,mixed>>}> $rencanaSidebar */
/** @var array<string, scalar> $retHidden */
/** @var string $todayMasehi */

$rencanaRange = $rencanaRange ?? akademik_agenda_rencana_range($todayMasehi ?? date('Y-m-d'));
$rencanaStats = $rencanaStats ?? ['total' => 0, 'selesai' => 0, 'progress_pct' => 0, 'hari_ini' => 0, 'berjalan' => 0, 'prioritas_tinggi' => 0];
$rencanaGantt = $rencanaGantt ?? ['range_start' => $rencanaRange['start'], 'range_end' => $rencanaRange['end'], 'items' => []];
$rencanaSidebar = $rencanaSidebar ?? [];
$retHidden = $retHidden ?? ['view' => 'rencana'];
$progressPct = (int) ($rencanaStats['progress_pct'] ?? 0);
?>
<div class="akr-wrap" id="akr-dashboard"
     data-gantt="<?= htmlspecialchars(json_encode($rencanaGantt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
    <header class="akr-header mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h2 class="h4 fw-bold mb-1">Rencana Kerja &amp; Jadwal Aktifitas</h2>
                <p class="text-muted small mb-0">
                    Periode 4 Minggu ke Depan
                    · <?= htmlspecialchars(app_format_tanggal_id($rencanaRange['start'])) ?>
                    – <?= htmlspecialchars(app_format_tanggal_id($rencanaRange['end'])) ?>
                </p>
            </div>
            <span class="badge rounded-pill text-bg-success akr-badge-internal">
                <i class="fa-solid fa-circle me-1"></i> Agenda internal pondok
            </span>
        </div>
    </header>

    <div class="row g-3 mb-3 akr-stats-row">
        <div class="col-6 col-lg-3">
            <div class="akr-stat-card">
                <div class="akr-stat-ring" style="--akr-pct: <?= $progressPct ?>">
                    <span class="akr-stat-ring__value"><?= $progressPct ?>%</span>
                </div>
                <div class="akr-stat-card__body">
                    <div class="akr-stat-card__label">Total Progress</div>
                    <div class="akr-stat-card__value"><?= (int) ($rencanaStats['selesai'] ?? 0) ?> / <?= (int) ($rencanaStats['total'] ?? 0) ?> selesai</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="akr-stat-card akr-stat-card--accent">
                <div class="akr-stat-card__big"><?= (int) ($rencanaStats['hari_ini'] ?? 0) ?></div>
                <div class="akr-stat-card__label">Agenda hari ini</div>
                <div class="akr-stat-card__sub">Aktif pada rentang periode</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="akr-stat-card akr-stat-card--info">
                <div class="akr-stat-card__big"><?= (int) ($rencanaStats['berjalan'] ?? 0) ?></div>
                <div class="akr-stat-card__label">Sedang berjalan</div>
                <div class="akr-stat-card__sub">Tugas belum selesai</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="akr-stat-card akr-stat-card--danger">
                <div class="akr-stat-card__big"><?= (int) ($rencanaStats['prioritas_tinggi'] ?? 0) ?></div>
                <div class="akr-stat-card__label">Prioritas tinggi</div>
                <div class="akr-stat-card__sub">Belum selesai</div>
            </div>
        </div>
    </div>

    <div class="row g-3 akr-main-row">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 akr-sidebar-card">
                <div class="card-header bg-white border-0 pt-3 pb-2">
                    <h3 class="h6 fw-bold mb-2">Jadwal Gabungan Kalender</h3>
                    <div class="akr-filter-chips" role="group" aria-label="Filter jenis">
                        <button type="button" class="akr-chip active" data-jenis-filter="semua">Semua</button>
                        <button type="button" class="akr-chip" data-jenis-filter="acara">Acara</button>
                        <button type="button" class="akr-chip" data-jenis-filter="tugas">Tugas</button>
                    </div>
                </div>
                <div class="card-body pt-0 akr-sidebar-scroll">
                    <?php if ($rencanaSidebar === []): ?>
                        <p class="small text-muted mb-0 py-3 text-center">Belum ada jadwal pada periode ini.</p>
                    <?php else: ?>
                        <?php foreach ($rencanaSidebar as $group): ?>
                            <div class="akr-date-group">
                                <div class="akr-date-group__label"><?= htmlspecialchars((string) $group['label']) ?></div>
                                <?php foreach ($group['items'] as $item):
                                    $aid = (int) ($item['id'] ?? 0);
                                    $jenis = (string) ($item['jenis'] ?? 'acara');
                                    $prioritas = akademik_agenda_prioritas_normalize((string) ($item['prioritas'] ?? 'sedang'));
                                    $jam = $item['jam_mulai'] ? substr((string) $item['jam_mulai'], 0, 5) : '';
                                    $canManage = akademik_agenda_user_can_manage($item, $currentUserId, $currentUserRole, $currentUserSuper);
                                    ?>
                                    <div class="akr-event-card akr-event-card--<?= htmlspecialchars($jenis) ?>"
                                         data-agenda-id="<?= $aid ?>"
                                         data-jenis="<?= htmlspecialchars($jenis) ?>"
                                         id="akr-event-<?= $aid ?>">
                                        <div class="akr-event-card__dot"></div>
                                        <div class="akr-event-card__body">
                                            <div class="akr-event-card__title"><?= htmlspecialchars((string) ($item['judul'] ?? '')) ?></div>
                                            <div class="akr-event-card__meta">
                                                <?= $jam !== '' ? htmlspecialchars($jam) . ' WIB' : 'Seharian' ?>
                                                · <?= htmlspecialchars($jenis === 'tugas' ? 'Tugas' : 'Acara') ?>
                                                <?php if ($prioritas === 'tinggi'): ?>
                                                    <span class="badge text-bg-danger ms-1">Utama</span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['selesai'])): ?>
                                                    <span class="badge text-bg-success ms-1">Selesai</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($canManage): ?>
                                        <div class="akr-event-card__actions">
                                            <?php if ($jenis === 'tugas' && empty($item['selesai'])): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="tandai_agenda_selesai">
                                                <input type="hidden" name="agenda_id" value="<?= $aid ?>">
                                                <?php foreach ($retHidden as $k => $v): ?>
                                                    <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                                                <?php endforeach; ?>
                                                <button type="submit" class="btn btn-sm btn-outline-success akr-btn-touch" title="Tandai selesai"><i class="fa-solid fa-check"></i></button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus jadwal ini?');">
                                                <input type="hidden" name="action" value="hapus_agenda">
                                                <input type="hidden" name="agenda_id" value="<?= $aid ?>">
                                                <?php foreach ($retHidden as $k => $v): ?>
                                                    <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                                                <?php endforeach; ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger akr-btn-touch" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm akr-gantt-card">
                <div class="card-header bg-white border-0 pt-3 pb-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-chart-gantt me-1 text-primary"></i> Timeline &amp; Deteksi Overlap</h3>
                            <p class="small text-muted mb-0">Klik batang jadwal untuk lompat ke detail di sidebar</p>
                        </div>
                        <div class="akr-gantt-legend">
                            <span class="akr-gantt-legend__item akr-gantt-legend__item--acara">Acara</span>
                            <span class="akr-gantt-legend__item akr-gantt-legend__item--tugas">Tugas</span>
                            <span class="akr-gantt-legend__item akr-gantt-legend__item--selesai">Selesai</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="akr-gantt" class="akr-gantt-mount"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3 akr-form-card">
        <div class="card-header bg-white border-0 pt-3 pb-0">
            <h3 class="h6 fw-bold mb-1">Rencana Kerja Aktif</h3>
            <p class="small text-muted mb-0">Kelola dan tambah jadwal harian / rentang tanggal</p>
        </div>
        <div class="card-body">
            <form method="post" class="akr-inline-form row g-2 align-items-end" id="akr-form-tambah">
                <input type="hidden" name="action" value="tambah_agenda">
                <?php foreach ($retHidden as $k => $v): ?>
                    <input type="hidden" name="ret_<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                <?php endforeach; ?>
                <div class="col-12 col-lg-4">
                    <label class="form-label small mb-0">Nama jadwal / tugas</label>
                    <input type="text" name="judul" class="form-control" required maxlength="200" placeholder="Nama tugas baru…">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-0">Jenis</label>
                    <select name="jenis" class="form-select">
                        <option value="acara">Acara</option>
                        <option value="tugas">Tugas</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-0">Mulai</label>
                    <input type="date" name="tanggal" class="form-control" required value="<?= htmlspecialchars($todayMasehi) ?>" id="akr-tgl-mulai">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-0">Selesai</label>
                    <input type="date" name="tanggal_selesai" class="form-control" value="<?= htmlspecialchars($todayMasehi) ?>" id="akr-tgl-selesai">
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label small mb-0">Prioritas</label>
                    <select name="prioritas" class="form-select">
                        <option value="rendah">Rendah</option>
                        <option value="sedang" selected>Sedang</option>
                        <option value="tinggi">Tinggi</option>
                    </select>
                </div>
                <div class="col-12 col-lg-1">
                    <button type="submit" class="btn btn-primary w-100 akr-btn-add">
                        <i class="fa-solid fa-plus me-1"></i> Tambah
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/kalender-rencana-kerja.js')) ?>"></script>
