<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/skbt_settings.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';

require_roles(['admin', 'pengurus']);
skbt_settings_ensure_schema($pdo);

$tab = trim((string) ($_GET['tab'] ?? 'nomor'));
if (!in_array($tab, ['nomor', 'ttd'], true)) {
    $tab = 'nomor';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = skbt_settings_save($pdo, $_POST);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    $redirectTab = (string) ($_POST['action'] ?? '') === 'save_ttd' ? 'ttd' : 'nomor';
    header('Location: ' . app_href('/settings/skbt.php?tab=' . $redirectTab));
    exit;
}

require_once __DIR__ . '/../helpers/akademik_skbt.php';

$stats = skbt_nomor_stats($pdo);
$nomorCfg = $stats['config'] ?? skbt_nomor_config($pdo);
$mulai = (int) ($stats['mulai'] ?? $nomorCfg['mulai']);
$statsPerTahun = skbt_nomor_stats_per_tahun($pdo);
$tahunSyawalDefault = skbt_tahun_syawal_default($pdo);
$contohNomor = skbt_nomor_format_string($pdo, (int) $stats['nomor_berikutnya'], 52, 1444, '2023-2024');
$formatPresets = [
    '{prefix}/{urut}/{periode_prefix}{periode}/{hijri}-{masehi}' => 'Standar — SKBT/10/P52/1444-2024',
    '{prefix}/{urut}/{periode_prefix}{periode}/{ta}' => 'Pakai placeholder {ta}',
    '{urut}/{prefix}/{periode_prefix}{periode}/{tahun}' => 'Urut di depan — 10/SKBT/P52/2024',
    '{prefix}/{urut}/{bulan_romawi}/{tahun}' => 'Gaya administrasi (padding 4 digit)',
];
$tingkatanList = skbt_settings_tingkatan_list($pdo);
$ttdDefaults = skbt_settings_ttd_defaults($pdo);
$ttdPerTingkatan = skbt_settings_ttd_per_tingkatan($pdo);
$kop = pondok_kop_data($pdo);

$pageTitle = 'Pengaturan SKBT';
$bodyClass = 'settings-module-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/akademik/skbt.php')) ?>">SKBT</a></p>
    <h1 class="h4 mb-1">Pengaturan SKBT</h1>
    <p class="text-muted mb-0 small">
        Atur nomor surat berurutan dan nama penandatangan (TTD) per tingkatan untuk cetak Surat Keterangan Belajar dan Tingkatan.
    </p>
</div>

<?php if ($m = get_flash('success')): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($m) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'nomor' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/settings/skbt.php?tab=nomor')) ?>">Nomor surat</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'ttd' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/settings/skbt.php?tab=ttd')) ?>">TTD per tingkatan</a>
    </li>
</ul>

<?php if ($tab === 'nomor'): ?>
    <form method="post" class="card shadow-sm mb-3" id="skbtNomorForm">
        <input type="hidden" name="action" value="save_nomor">
        <div class="card-header py-2"><strong>Format &amp; penomoran SKBT</strong></div>
        <div class="card-body">
            <div class="alert alert-light border small mb-3">
                Pratinjau nomor berikutnya: <code id="skbtNomorPreview"><?= htmlspecialchars($contohNomor) ?></code>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small mb-0">Kode / prefix surat</label>
                    <input type="text" name="skbt_nomor_prefix" class="form-control form-control-sm skbt-nomor-field"
                           value="<?= htmlspecialchars($nomorCfg['prefix']) ?>" maxlength="32" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Awalan periode</label>
                    <input type="text" name="skbt_nomor_periode_prefix" class="form-control form-control-sm skbt-nomor-field"
                           value="<?= htmlspecialchars($nomorCfg['periode_prefix']) ?>" maxlength="8">
                    <div class="form-text">Digabung dengan angka periode → P52</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Mulai nomor urut</label>
                    <input type="number" name="skbt_nomor_mulai" class="form-control form-control-sm skbt-nomor-field"
                           min="1" max="999999" value="<?= (int) $mulai ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Padding digit urut</label>
                    <select name="skbt_nomor_urut_padding" class="form-select form-select-sm skbt-nomor-field">
                        <?php foreach ([0 => 'Tanpa nol (10)', 2 => '2 digit (10)', 3 => '3 digit (010)', 4 => '4 digit (0010)'] as $pad => $lab): ?>
                            <option value="<?= (int) $pad ?>" <?= (int) $nomorCfg['urut_padding'] === (int) $pad ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-0">Template format nomor</label>
                    <input type="text" name="skbt_nomor_format" id="skbt_nomor_format" class="form-control form-control-sm font-monospace skbt-nomor-field"
                           value="<?= htmlspecialchars($nomorCfg['format']) ?>" required>
                    <div class="form-text">
                        Placeholder:
                        <?php foreach (skbt_nomor_format_placeholders() as $ph): ?>
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline skbt-ph-insert" data-ph="<?= htmlspecialchars($ph) ?>"><?= htmlspecialchars($ph) ?></button><?= $ph !== '{bulan_romawi}' ? ' · ' : '' ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-0">Preset cepat</label>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($formatPresets as $fmt => $label): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm skbt-preset" data-format="<?= htmlspecialchars($fmt) ?>"><?= htmlspecialchars($label) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="skbt_nomor_reset_tahun" id="skbt_nomor_reset_tahun" value="1"
                            <?= !empty($nomorCfg['reset_per_tahun']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="skbt_nomor_reset_tahun">
                            Reset penomoran setiap tahun Syawal (TA hijriyah baru mulai dari nomor awal)
                        </label>
                    </div>
                </div>
            </div>

            <div class="bg-light rounded p-3 small mt-3">
                <div class="row g-2">
                    <div class="col-sm-6"><span class="text-muted">Nomor urut berikutnya:</span> <strong><?= (int) $stats['nomor_berikutnya'] ?></strong></div>
                    <div class="col-sm-6"><span class="text-muted">Urut terakhir terbit:</span> <strong><?= $stats['max_urut'] > 0 ? (int) $stats['max_urut'] : '—' ?></strong></div>
                    <div class="col-sm-6"><span class="text-muted">Total dokumen:</span> <strong><?= (int) $stats['total_terbit'] ?></strong></div>
                    <div class="col-sm-6"><span class="text-muted">Cetak ulang:</span> nomor sama per santri + TA + periode</div>
                </div>
            </div>
        </div>
        <div class="card-footer py-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan pengaturan nomor</button>
        </div>
    </form>

    <div class="card shadow-sm mb-3">
        <div class="card-header py-2"><strong>Set manual urut terakhir</strong> <span class="text-muted small">(nomor berikutnya = urut terakhir + 1)</span></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="set_urut_terakhir">
                <?php if (!empty($nomorCfg['reset_per_tahun'])): ?>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Tahun Syawal</label>
                        <input type="number" name="tahun_syawal" class="form-control form-control-sm" min="1300" max="1500" value="<?= (int) $tahunSyawalDefault ?>" required>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="tahun_syawal" value="0">
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Urut terakhir</label>
                    <input type="number" name="urut_terakhir" class="form-control form-control-sm" min="0" max="999999"
                           value="<?= $stats['max_urut'] > 0 ? (int) $stats['max_urut'] : 0 ?>" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Terapkan urut</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($statsPerTahun !== []): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2"><strong>Riwayat per tahun Syawal</strong></div>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tahun Syawal</th>
                            <th class="text-end">Urut terakhir</th>
                            <th class="text-end">Total dokumen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statsPerTahun as $row): ?>
                            <tr>
                                <td><?= (int) ($row['tahun_syawal'] ?? 0) ?></td>
                                <td class="text-end font-monospace"><?= (int) ($row['max_urut'] ?? 0) ?></td>
                                <td class="text-end"><?= (int) ($row['total'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <script>
    (function () {
        var preview = document.getElementById('skbtNomorPreview');
        var formatInput = document.getElementById('skbt_nomor_format');
        if (!preview || !formatInput) return;

        function val(name) {
            var el = document.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        }

        function padUrut(n, p) {
            n = String(Math.max(0, parseInt(n, 10) || 0));
            p = parseInt(p, 10) || 0;
            while (p > 0 && n.length < p) n = '0' + n;
            return n;
        }

        function updatePreview() {
            var urut = padUrut(<?= (int) $stats['nomor_berikutnya'] ?>, val('skbt_nomor_urut_padding'));
            var fmt = val('skbt_nomor_format') || '{prefix}/{urut}/{periode_prefix}{periode}/{hijri}-{masehi}';
            var out = fmt
                .split('{prefix}').join(val('skbt_nomor_prefix') || 'SKBT')
                .split('{urut}').join(urut)
                .split('{periode_prefix}').join(val('skbt_nomor_periode_prefix') || 'P')
                .split('{periode}').join('52')
                .split('{hijri}').join('1444')
                .split('{masehi}').join('2024')
                .split('{ta}').join('1444-2024')
                .split('{tahun}').join('2024')
                .split('{bulan_romawi}').join('VIII');
            preview.textContent = out;
        }

        document.querySelectorAll('.skbt-nomor-field').forEach(function (el) {
            el.addEventListener('input', updatePreview);
            el.addEventListener('change', updatePreview);
        });

        document.querySelectorAll('.skbt-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                formatInput.value = btn.getAttribute('data-format') || '';
                if (btn.getAttribute('data-format') && btn.getAttribute('data-format').indexOf('bulan_romawi') >= 0) {
                    var pad = document.querySelector('[name="skbt_nomor_urut_padding"]');
                    if (pad) pad.value = '4';
                }
                updatePreview();
            });
        });

        document.querySelectorAll('.skbt-ph-insert').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ph = btn.getAttribute('data-ph') || '';
                var start = formatInput.selectionStart || formatInput.value.length;
                var end = formatInput.selectionEnd || start;
                formatInput.value = formatInput.value.slice(0, start) + ph + formatInput.value.slice(end);
                formatInput.focus();
                updatePreview();
            });
        });
    })();
    </script>
<?php else: ?>
    <form method="post" class="card shadow-sm mb-3">
        <input type="hidden" name="action" value="save_ttd">
        <div class="card-header py-2"><strong>TTD default (semua tingkatan)</strong></div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Isi default jika tingkatan tidak punya override. Wali kamar diambil dari data santri.
                Wali kelas kosong per tingkatan → fallback dari data kelas santri.
            </p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small mb-0">Pengasuh (default)</label>
                    <input type="text" name="ttd_default_pengasuh" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string) ($ttdDefaults['pengasuh'] ?? '')) ?>"
                           placeholder="<?= htmlspecialchars(trim((string) ($kop['nama_pengasuh'] ?? '')) ?: 'Dari kop pondok') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-0">Kepala pondok (default)</label>
                    <input type="text" name="ttd_default_kepala" class="form-control form-control-sm"
                           value="<?= htmlspecialchars((string) ($ttdDefaults['kepala_pondok'] ?? '')) ?>"
                           placeholder="<?= htmlspecialchars(trim((string) app_setting($pdo, 'nama_kepala_pondok', '')) ?: 'Dari profil pondok') ?>">
                </div>
            </div>
        </div>

        <div class="card-header py-2 border-top"><strong>Override per tingkatan</strong></div>
        <div class="card-body p-0">
            <?php if ($tingkatanList === []): ?>
                <p class="small text-muted p-3 mb-0">
                    Belum ada tingkatan. Tambahkan di
                    <a href="<?= htmlspecialchars(app_href('/settings/tingkatan.php')) ?>">Tingkatan &amp; PKPPS</a>.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:10rem">Tingkatan</th>
                                <th>Wali kelas</th>
                                <th>Pengasuh</th>
                                <th>Kepala pondok</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tingkatanList as $idx => $nama): ?>
                                <?php $row = $ttdPerTingkatan[$nama] ?? ['wali_kelas' => '', 'pengasuh' => '', 'kepala_pondok' => '']; ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="ttd_tingkatan[]" value="<?= htmlspecialchars($nama) ?>">
                                        <strong class="small"><?= htmlspecialchars($nama) ?></strong>
                                    </td>
                                    <td>
                                        <input type="text" name="ttd_wali_kelas[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars((string) ($row['wali_kelas'] ?? '')) ?>" placeholder="Kosong = dari kelas santri">
                                    </td>
                                    <td>
                                        <input type="text" name="ttd_pengasuh[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars((string) ($row['pengasuh'] ?? '')) ?>" placeholder="Kosong = default">
                                    </td>
                                    <td>
                                        <input type="text" name="ttd_kepala[]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars((string) ($row['kepala_pondok'] ?? '')) ?>" placeholder="Kosong = default">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-footer py-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan TTD</button>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
