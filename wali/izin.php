<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/wali_perizinan.php';

wali_perizinan_ensure_tables($pdo);

$defaultPemohon = wali_portal_resolve_nama_wali($pdo, $waliSantriRow);
if ($defaultPemohon === '') {
    $defaultPemohon = 'Wali santri';
}

$syariKategoriOpsi = perizinan_syari_kategori_list_portal($pdo);
$alpaInfoAwal = wali_perizinan_alpa_info_portal($pdo, $waliSantriId, date('Y-m-d'));
$apiAlpaUrl = app_href('/wali/api_alpa.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetSantriId = (int) ($_POST['santri_id'] ?? $waliSantriId);
    $result = wali_perizinan_ajukan(
        $pdo,
        $targetSantriId,
        $waliAnakIds,
        (string) ($_POST['jenis_izin'] ?? wali_perizinan_jenis_portal()),
        trim((string) ($_POST['tanggal_mulai'] ?? '')),
        '',
        trim((string) ($_POST['jam_mulai'] ?? '')),
        '',
        0,
        trim((string) ($_POST['syari_kategori'] ?? '')),
        '',
        trim((string) ($_POST['tujuan'] ?? '')),
        trim((string) ($_POST['pemberi_izin'] ?? $defaultPemohon))
    );
    if ($result['ok']) {
        set_flash('success', $result['message']);
    } else {
        set_flash('error', $result['message']);
    }
    header('Location: ' . app_href('/wali/izin.php'));
    exit;
}

$riwayatIzin = wali_perizinan_list_for_santri($pdo, $waliAnakIds, 50);

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Izin Syar\'i — Portal Wali', true, 'izin');
require __DIR__ . '/partials/greeting.php';
$waliSwitcherRedirect = '/wali/izin.php';
require __DIR__ . '/partials/anak_switcher.php';
?>

        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Ajukan Izin Syar'i</h1>
                <p class="small text-muted mb-0">Permohonan ditinjau pengasuh. Setelah disetujui, pengurus pondok mencetak surat resmi.</p>
            </div>
            <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= htmlspecialchars(app_href('/wali/logout.php')) ?>">Keluar</a>
        </div>

        <div class="card wali-card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 mb-3">Form permohonan</h2>
                <form method="post" class="row g-2" id="form-wali-izin">
                    <?php if (count($waliAnakRows) > 1): ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Anak</label>
                        <select name="santri_id" class="form-select form-select-sm" required>
                            <?php foreach ($waliAnakRows as $anak): ?>
                                <option value="<?= (int) $anak['id'] ?>" <?= (int) $anak['id'] === $waliSantriId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $anak['nama_tampil']) ?> (<?= htmlspecialchars((string) $anak['nis']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="santri_id" value="<?= $waliSantriId ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label small mb-0">Jenis izin</label>
                        <input type="hidden" name="jenis_izin" value="<?= htmlspecialchars(wali_perizinan_jenis_portal()) ?>">
                        <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars(jenis_izin_label(wali_perizinan_jenis_portal())) ?>" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Pemohon</label>
                        <input type="text" name="pemberi_izin" class="form-control form-control-sm" value="<?= htmlspecialchars($defaultPemohon) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Keperluan izin syar'i <span class="text-danger">*</span></label>
                        <?php if ($syariKategoriOpsi === []): ?>
                            <div class="alert alert-warning small py-2 mb-0">Belum ada keperluan yang diaktifkan pengurus. Hubungi pondok untuk pengaturan izin syar'i.</div>
                        <?php else: ?>
                            <select name="syari_kategori" id="syari-kategori-select" class="form-select form-select-sm" required>
                                <option value="">— Pilih keperluan —</option>
                                <?php foreach ($syariKategoriOpsi as $op): ?>
                                    <option value="<?= htmlspecialchars((string) $op['kode']) ?>" data-durasi="<?= (int) ($op['durasi_hari'] ?? 1) ?>">
                                        <?= htmlspecialchars((string) $op['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" id="tanggal-mulai-input" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Tanggal selesai</label>
                        <input type="text" id="tanggal-selesai-tampil" class="form-control form-control-sm bg-light" value="—" readonly tabindex="-1" aria-readonly="true">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Jam mulai</label>
                        <input type="time" name="jam_mulai" class="form-control form-control-sm" value="<?= date('H:i') ?>" required>
                    </div>
                    <?php
                    $tujuanWrapId = 'wrap-tujuan-wali';
                    $tujuanAlwaysVisible = true;
                    $tujuanValue = '';
                    $tujuanLabelClass = 'small mb-0';
                    $tujuanInputClass = 'form-control-sm';
                    require __DIR__ . '/../perizinan/partials/tujuan_izin_field.php';
                    ?>
                    <div class="col-12 d-none" id="wrap-alpa-peringatan">
                        <div class="alert alert-warning border-warning small mb-0 py-2">
                            <div class="fw-semibold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Perhatian syarat ALPA</div>
                            <div id="alpa-peringatan-teks"></div>
                            <div class="mt-2 mb-0">Anda tetap dapat mengirim permohonan. Pengasuh pondok yang menilai apakah izin syar'i dapat disetujui.</div>
                        </div>
                    </div>
                    <div class="col-12 d-none" id="wrap-alpa-ok">
                        <div class="alert alert-success border-success small mb-0 py-2">
                            <div class="fw-semibold mb-1"><i class="fa-solid fa-circle-check me-1"></i> Syarat ALPA</div>
                            <div id="alpa-ok-teks"></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-teal btn-sm w-100" <?= $syariKategoriOpsi === [] ? 'disabled' : '' ?>>Kirim permohonan izin syar'i</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card wali-card shadow-sm">
            <div class="card-header bg-white small fw-semibold">Riwayat permohonan izin</div>
            <div class="card-body p-0">
                <?php if ($riwayatIzin === []): ?>
                    <p class="small text-muted text-center py-4 mb-0">Belum ada permohonan.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($riwayatIzin as $iz): ?>
                            <?php
                            $st = (string) ($iz['approval_status'] ?? 'PENDING');
                            $badge = wali_perizinan_status_badge($st);
                            $katKode = trim((string) ($iz['syari_kategori'] ?? ''));
                            $katLabel = $katKode !== '' ? perizinan_syari_kategori_label($pdo, $katKode) : trim((string) ($iz['alasan'] ?? ''));
                            ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small">
                                        <div class="fw-semibold"><?= htmlspecialchars(jenis_izin_label((string) ($iz['jenis_izin'] ?? 'KELUAR'))) ?></div>
                                        <?php if ($katLabel !== ''): ?>
                                            <div class="text-body"><?= htmlspecialchars($katLabel) ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted"><?= htmlspecialchars((string) ($iz['nama_santri'] ?? '')) ?> · <?= htmlspecialchars((string) ($iz['tanggal_mulai'] ?? '')) ?> – <?= htmlspecialchars((string) ($iz['tanggal_selesai'] ?? '')) ?></div>
                                        <div class="text-muted">Jam mulai <?= htmlspecialchars(substr((string) ($iz['jam_mulai'] ?? ''), 0, 5)) ?></div>
                                    </div>
                                    <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($st) ?></span>
                                </div>
                                <?php if (trim((string) ($iz['tujuan'] ?? '')) !== ''): ?>
                                    <div class="small text-muted mt-1">Tujuan: <?= htmlspecialchars((string) $iz['tujuan']) ?></div>
                                <?php endif; ?>
                                <?php if ($st === 'DITOLAK' && trim((string) ($iz['rejected_reason'] ?? '')) !== ''): ?>
                                    <div class="small text-danger mt-1"><?= htmlspecialchars((string) $iz['rejected_reason']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

<script>
(function () {
    var form = document.getElementById('form-wali-izin');
    if (!form) return;
    var select = document.getElementById('syari-kategori-select');
    var tglMulai = document.getElementById('tanggal-mulai-input');
    var tglSelesaiTampil = document.getElementById('tanggal-selesai-tampil');
    var santriSelect = form.querySelector('[name="santri_id"]');
    var wrapBlocked = document.getElementById('wrap-alpa-peringatan');
    var wrapOk = document.getElementById('wrap-alpa-ok');
    var txtBlocked = document.getElementById('alpa-peringatan-teks');
    var txtOk = document.getElementById('alpa-ok-teks');
    var apiAlpaUrl = <?= json_encode($apiAlpaUrl, JSON_UNESCAPED_UNICODE) ?>;

    function formatDate(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function selectedDurasi() {
        if (!select) return 0;
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return 0;
        return parseInt(opt.getAttribute('data-durasi') || '0', 10);
    }

    function updateSelesai() {
        if (!select || !tglMulai || !tglSelesaiTampil) return;
        var durasi = selectedDurasi();
        if (!durasi || !tglMulai.value) {
            tglSelesaiTampil.value = '—';
            return;
        }
        var start = new Date(tglMulai.value + 'T00:00:00');
        var end = new Date(start);
        end.setDate(end.getDate() + durasi - 1);
        tglSelesaiTampil.value = formatDate(end);
    }

    function renderAlpa(data) {
        if (!wrapBlocked || !wrapOk || !txtBlocked || !txtOk) return;
        wrapBlocked.classList.add('d-none');
        wrapOk.classList.add('d-none');
        if (!data || !data.enabled) {
            return;
        }
        var teks = (data.penjelasan || data.ringkasan || data.message || '').trim();
        if (data.blocked) {
            txtBlocked.textContent = teks !== '' ? teks : 'Santri terhalang syarat ALPA saat ini.';
            wrapBlocked.classList.remove('d-none');
            return;
        }
        if (data.subject) {
            txtOk.textContent = teks !== '' ? teks : 'Syarat ALPA masih terpenuhi.';
            wrapOk.classList.remove('d-none');
        }
    }

    function refreshAlpa() {
        if (!apiAlpaUrl) return;
        var sidInput = form.querySelector('[name="santri_id"]');
        var sid = sidInput ? parseInt(sidInput.value || '0', 10) : 0;
        var tgl = tglMulai && tglMulai.value ? tglMulai.value : formatDate(new Date());
        if (sid <= 0) return;
        fetch(apiAlpaUrl + '?santri_id=' + encodeURIComponent(String(sid)) + '&tanggal=' + encodeURIComponent(tgl), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data && data.ok) renderAlpa(data); })
            .catch(function () { /* abaikan */ });
    }

    if (select && tglMulai && tglSelesaiTampil) {
        select.addEventListener('change', updateSelesai);
        tglMulai.addEventListener('change', function () {
            updateSelesai();
            refreshAlpa();
        });
        updateSelesai();
    }
    if (santriSelect) {
        santriSelect.addEventListener('change', refreshAlpa);
    }
    renderAlpa(<?= json_encode($alpaInfoAwal, JSON_UNESCAPED_UNICODE) ?>);
    refreshAlpa();
})();
</script>

<?php
wali_layout_foot(true, 'izin');
