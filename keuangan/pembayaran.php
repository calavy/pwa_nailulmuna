<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';

require_login();
require_roles(['admin', 'pengurus']);

keuangan_ensure_schema_deferred($pdo);

$biayaDefinitions = keuangan_biaya_definitions();
$berjalan = keuangan_periode_berjalan($pdo);
$kalenderMode = pondok_kalender_mode($pdo);
$keuanganTa = keuangan_ta_resolve($pdo);
$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);

$prefillSantriId = (int) ($_GET['santri_id'] ?? 0);
$prefillBulan = max(1, min(12, (int) ($_GET['bulan'] ?? $berjalan['bulan'])));
$prefillTm = (int) $keuanganTa['mulai'];
$prefillTs = (int) $keuanganTa['selesai'];
$bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $prefillTm, $prefillTs);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pembayaran') {
    $result = keuangan_save_pembayaran($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    if (!$result['ok']) {
        set_flash('error', $result['message']);
        header('Location: ' . app_href('/keuangan/pembayaran.php'));
        exit;
    }
    set_flash('success', $result['message']);
    $newId = (int) ($result['id'] ?? 0);
    if ($newId > 0) {
        header('Location: ' . app_rewrite_internal_url('/keuangan/kuitansi.php?id=' . $newId));
        exit;
    }
    header('Location: ' . app_href('/keuangan/pembayaran.php'));
    exit;
}

$santriRows = keuangan_fetch_santri_aktif($pdo);
$santriTierById = keuangan_build_santri_tier_label_map($pdo, $santriRows);
$keuanganFeeMatrix = keuangan_fee_matrix_from_settings($pdo, $biayaDefinitions);
$akunRows = keuangan_fetch_akun_aktif($pdo);
$defaultAkunId = 0;
foreach ($akunRows as $ar) {
    if ((int) ($ar['is_default'] ?? 0) === 1) {
        $defaultAkunId = (int) $ar['id'];
        break;
    }
}
if ($defaultAkunId <= 0 && $akunRows !== []) {
    $defaultAkunId = (int) ($akunRows[0]['id'] ?? 0);
}

$recentRows = keuangan_recent_pembayaran($pdo, 12);

$pageTitle = 'Input Pembayaran';
$bodyClass = keuangan_body_class('keuangan-form-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Pemasukan</p>
    <h1 class="h3 mb-1">Formulir Pembayaran Santri</h1>
    <p class="text-muted small mb-0">
        Catat penerimaan kas/bank per komponen biaya santri. Setelah simpan, kuitansi otomatis dapat dicetak.
        Bulan tagihan ikut kalender <strong><?= $kalenderMode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?></strong>.
        <span class="text-nowrap">·</span> <a href="/keuangan/pemasukan.php">Pemasukan lain</a>
        <span class="text-nowrap">·</span> <a href="/pembayaran/tagihan_syahriyah.php">Tagihan bulanan</a>
        <span class="text-nowrap">·</span> <a href="/settings/opsional_santri.php">Atur Makan &amp; Saku</a>
    </p>
</div>

<?php require __DIR__ . '/../includes/partials/keuangan_ta_toolbar.php'; ?>

<div class="row g-4 pembayaran-layout">
    <div class="col-xl-8">
        <?php if ($santriRows === []): ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="alert alert-warning mb-0">Belum ada santri aktif. Tambahkan data santri terlebih dahulu.</div>
                </div>
            </div>
        <?php else: ?>
        <form method="post" id="form-pembayaran" autocomplete="off">
            <input type="hidden" name="action" value="save_pembayaran">

            <div class="card shadow-sm pembayaran-card mb-3">
                <div class="card-header pembayaran-card-header">
                    <span class="pembayaran-card-step">1</span>
                    <div>
                        <div class="pembayaran-card-title">Santri &amp; periode</div>
                        <div class="pembayaran-card-sub">Pilih santri yang membayar dan tentukan periode tagihan.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small text-muted mb-1">Santri <span class="text-danger">*</span></label>
                            <select name="santri_id" id="santri_id" class="form-select form-select-lg" required data-search-placeholder="Ketik NIS atau nama santri…">
                                <option value="">— Pilih santri —</option>
                                <?php foreach ($santriRows as $s): ?>
                                    <?php $sid = (int) $s['id']; ?>
                                    <option value="<?= $sid ?>" <?= $sid === $prefillSantriId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) ($s['nis'] ?: '-')) ?> — <?= htmlspecialchars((string) $s['nama_santri']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="santri-tier-hint">Tarif mengikuti kelas keuangan santri yang dipilih.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-muted mb-1">Jenis periode</label>
                            <select class="form-select" name="jenis_periode" id="jenis_periode">
                                <option value="BULANAN">Bulanan</option>
                                <option value="AWAL_TAHUN">Awal tahun</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="wrap-bulan">
                            <label class="form-label fw-semibold small text-muted mb-1">Bulan tagihan <?= $kalenderMode === 'hijriyah' ? '(H)' : '(M)' ?></label>
                            <select class="form-select" name="bulan_tagihan" id="bulan_tagihan">
                                <?php foreach ($bulanSlots as $slot): ?>
                                    <?php $m = (int) ($slot['bulan_tagihan'] ?? 0); ?>
                                    <option value="<?= $m ?>" <?= $m === $prefillBulan ? 'selected' : '' ?> data-kh="<?= htmlspecialchars((string) ($slot['kalender_hijriyah'] ?? '')) ?>">
                                        <?= htmlspecialchars(pondok_bulan_slot_label_tampilan($pdo, $slot)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-muted mb-1">Tanggal bayar</label>
                            <input type="date" class="form-control" name="tanggal_bayar" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <?php
                        $taMulai = (int) $prefillTm;
                        $taSelesai = (int) $prefillTs;
                        $inputClass = 'form-control';
                        require __DIR__ . '/../includes/partials/pondok_ta_fields.php';
                        ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm pembayaran-card mb-3">
                <div class="card-header pembayaran-card-header">
                    <span class="pembayaran-card-step">2</span>
                    <div>
                        <div class="pembayaran-card-title">Komponen dibayar</div>
                        <div class="pembayaran-card-sub">Centang pos yang dibayar, nominal otomatis diisi sesuai sisa tagihan.</div>
                    </div>
                </div>
                <div class="card-body">
                    <?php
                    $wajibSlugs = keuangan_tagihan_wajib_slugs();
                    $opsSlugs = keuangan_tagihan_opsional_bulanan_slugs();
                    $opsionalLabel = ['makan' => 'Makan', 'saku' => 'Saku'];
                    $renderKomponenRow = static function (array $def, array $wajibSlugs, array $opsSlugs): void {
                        $slug = (string) ($def['slug'] ?? '');
                        $isBulanan = $def['kategori'] === 'Bulanan';
                        $isWajibTagihan = $isBulanan && in_array($slug, $wajibSlugs, true);
                        $isOps = $isBulanan && in_array($slug, $opsSlugs, true);
                        $rowClass = $isOps ? ' class="keuangan-row-opsional"' : '';
                        ?>
                        <tr data-kategori="<?= htmlspecialchars($def['kategori']) ?>" data-slug="<?= htmlspecialchars($slug) ?>"<?= $rowClass ?>>
                            <td class="text-center align-middle">
                                <input type="checkbox" class="form-check-input bayar-pos-check" name="bayar_pos[]" value="<?= htmlspecialchars($slug) ?>">
                            </td>
                            <td class="align-middle">
                                <div class="fw-semibold"><?= htmlspecialchars($def['nama']) ?></div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <span class="badge text-bg-secondary"><?= htmlspecialchars($def['kategori']) ?></span>
                                    <?php if ($isWajibTagihan): ?>
                                        <span class="badge text-bg-warning">Wajib</span>
                                    <?php elseif ($isOps): ?>
                                        <span class="badge text-bg-info">Opsional</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="small text-muted paid-hint align-middle" data-slug="<?= htmlspecialchars($slug) ?>">—</td>
                            <td class="align-middle">
                                <div class="input-group input-group-sm pembayaran-nominal-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="text" inputmode="numeric" class="form-control form-control-sm nominal-pos text-end"
                                           name="nominal_<?= htmlspecialchars($slug) ?>" value="0" data-slug="<?= htmlspecialchars($slug) ?>">
                                </div>
                            </td>
                        </tr>
                        <?php
                    };
                    ?>
                    <p class="small text-muted mb-2" id="tagihan-summary-hint">
                        Pilih santri dan bulan untuk melihat sisa tagihan wajib (Syahriyah).
                    </p>
                    <div class="table-responsive pembayaran-table-wrap">
                        <table class="table table-sm align-middle mb-0 pembayaran-komponen-table" id="tabel-komponen">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:3rem">Bayar</th>
                                    <th>Pos</th>
                                    <th style="width:11rem">Tagihan / sisa</th>
                                    <th style="width:13rem">Nominal bayar</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-komponen-wajib">
                            <?php foreach ($biayaDefinitions as $def) {
                                $renderKomponenRow($def, $wajibSlugs, $opsSlugs);
                            } ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="opsional-inline-panel mt-3" id="panel-komponen-opsional">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <i class="fa-solid fa-utensils text-info"></i>
                            <span class="fw-semibold">Pengaturan Makan &amp; Saku</span>
                            <span class="text-muted small">(per santri — hanya berlaku untuk santri yang dipilih)</span>
                            <span class="badge bg-light text-muted ms-auto" id="opsional-status-pill">pilih santri</span>
                            <a href="#" class="btn btn-outline-secondary btn-sm" id="opsional-bulk-link" target="_blank" rel="noopener">
                                <i class="fa-solid fa-gear me-1"></i> Pengaturan massal
                            </a>
                        </div>
                        <div class="row g-2" aria-live="polite">
                            <?php foreach ($opsSlugs as $os): ?>
                                <div class="col-md-6">
                                    <div class="opsional-editor-card" data-slug="<?= htmlspecialchars($os) ?>">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars($opsionalLabel[$os] ?? ucfirst($os)) ?></div>
                                                <div class="text-muted small" data-role="default-hint">Default tier: —</div>
                                            </div>
                                            <div class="form-check form-switch m-0">
                                                <input class="form-check-input opsional-aktif-input" type="checkbox" data-slug="<?= htmlspecialchars($os) ?>" disabled>
                                            </div>
                                        </div>
                                        <div class="input-group input-group-sm mt-2">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" inputmode="numeric" class="form-control text-end opsional-nominal-input"
                                                   placeholder="kosong = pakai default tier"
                                                   data-slug="<?= htmlspecialchars($os) ?>" disabled>
                                            <button type="button" class="btn btn-outline-success opsional-save-btn" data-slug="<?= htmlspecialchars($os) ?>" disabled>
                                                <i class="fa-solid fa-floppy-disk"></i>
                                            </button>
                                        </div>
                                        <div class="opsional-editor-status small mt-1 text-muted" data-role="status">Pilih santri untuk mengatur.</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm pembayaran-card mb-3">
                <div class="card-header pembayaran-card-header">
                    <span class="pembayaran-card-step">3</span>
                    <div>
                        <div class="pembayaran-card-title">Metode &amp; akun penerimaan</div>
                        <div class="pembayaran-card-sub">Tentukan cara pembayaran, akun penerimaan, dan catatan.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small text-muted mb-1">Metode</label>
                            <select class="form-select" name="metode_bayar" id="metode_bayar">
                                <option value="KAS">Kas</option>
                                <option value="TRANSFER">Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small text-muted mb-1">Status transaksi</label>
                            <input type="text" class="form-control" id="status_lunas_label" value="Lunas" readonly>
                            <input type="hidden" name="status_lunas" id="status_lunas" value="LUNAS">
                            <div class="form-text">Otomatis: Cicilan jika masih ada sisa.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-muted mb-1">Akun penerimaan <span class="text-danger">*</span></label>
                            <select class="form-select" name="akun_id" required>
                                <?php if ($akunRows === []): ?>
                                    <option value="">Belum ada akun — buka Pengaturan Keuangan</option>
                                <?php else: ?>
                                    <?php foreach ($akunRows as $ak): ?>
                                        <option value="<?= (int) $ak['id'] ?>" <?= (int) $ak['id'] === $defaultAkunId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $ak['jenis_akun']) ?> — <?= htmlspecialchars((string) $ak['nama_akun']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if ($akunRows === []): ?>
                                <div class="form-text"><a href="/keuangan/pengaturan.php?bagian=akun">Tambah akun kas/bank</a> di pengaturan keuangan.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-muted mb-1">No. referensi / bukti</label>
                            <input type="text" class="form-control" name="no_referensi" id="no_referensi" placeholder="Wajib untuk transfer">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-muted mb-1">Keterangan</label>
                            <input type="text" class="form-control" name="keterangan" placeholder="Catatan pembayaran (opsional)">
                        </div>
                    </div>
                </div>
            </div>

            <div class="pembayaran-actions d-flex flex-wrap gap-2 align-items-center">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fa-solid fa-check me-1"></i> Simpan &amp; buka kuitansi
                </button>
                <a class="btn btn-outline-secondary" href="/pembayaran/riwayat.php">Riwayat</a>
                <a class="btn btn-outline-secondary" href="/keuangan/index.php">Dashboard keuangan</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm h-100 pembayaran-sidebar">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Pembayaran terakhir</span>
                <a class="small" href="/pembayaran/riwayat.php">Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Tanggal</th><th>Santri</th><th class="text-end">Total</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php if ($recentRows === []): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada pembayaran.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRows as $r): ?>
                                <?php
                                $bl = (int) ($r['bulan_tagihan'] ?? 0);
                                $periodeShort = ($r['jenis_periode'] ?? '') === 'BULANAN' && $bl > 0
                                    ? pondok_bulan_label($pdo, $bl, (int) ($r['tahun_ajaran_mulai'] ?? $prefillTm), (int) ($r['tahun_ajaran_selesai'] ?? $prefillTs))
                                    : 'Awal th.';
                                ?>
                                <tr>
                                    <td class="small text-nowrap"><?= htmlspecialchars((string) $r['tanggal_bayar']) ?></td>
                                    <td class="small">
                                        <div class="fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?></div>
                                        <div class="text-muted"><?= htmlspecialchars($periodeShort) ?></div>
                                    </td>
                                    <td class="text-end small fw-semibold"><?= htmlspecialchars($formatRupiah((int) ((float) $r['total_nominal']))) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm py-0" href="/keuangan/kuitansi.php?id=<?= (int) $r['id'] ?>">KW</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;
window.keuanganSantriTier = <?= json_encode($santriTierById, JSON_UNESCAPED_UNICODE) ?>;
window.keuanganFeeMatrix = <?= json_encode($keuanganFeeMatrix, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars(app_href('/assets/js/pondok-ta-fields.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_href('/assets/js/keuangan-pembayaran-form.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
