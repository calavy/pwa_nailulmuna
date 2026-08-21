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
$pendingAwal = perizinan_santri_pending_row($pdo, $waliSantriId);
$pendingBlokirAwal = perizinan_pesan_blokir_pending($pendingAwal);
$izinPerpanjanganMaxHari = max(1, (int) app_setting($pdo, 'izin_perpanjangan_max_hari', '7'));
$izinAktifMap = wali_perizinan_izin_aktif_map($pdo, $waliAnakIds);
$apiAlpaUrl = app_href('/wali/api_alpa.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'ajukan'));
    if ($action === 'perpanjang_izin') {
        $result = wali_perizinan_ajukan_perpanjangan(
            $pdo,
            (int) ($_POST['izin_id'] ?? 0),
            $waliAnakIds,
            trim((string) ($_POST['tanggal_selesai_baru'] ?? '')),
            trim((string) ($_POST['alasan_perpanjangan'] ?? ''))
        );
        if ($result['ok']) {
            set_flash('success', $result['message']);
        } else {
            set_flash('error', $result['message']);
        }
        header('Location: ' . app_href('/wali/izin.php'));
        exit;
    }

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
        trim((string) ($_POST['keterangan_alasan'] ?? '')),
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
wali_layout_head('Izin — Portal Wali', true, 'izin');
require __DIR__ . '/partials/greeting.php';
?>

        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h5 mb-0 wali-brand fw-bold">Ajukan Izin</h1>
                <p class="small text-muted mb-0">Permohonan ditinjau pengasuh. Setelah disetujui, pengurus pondok mencetak surat resmi.</p>
            </div>
            <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= htmlspecialchars(app_href('/wali/logout.php')) ?>">Keluar</a>
        </div>

        <div class="card wali-card shadow-sm mb-3 border-info border-opacity-25">
            <div class="card-body py-3">
                <h2 class="h6 mb-2"><i class="fa-solid fa-circle-info text-info me-1"></i> Cara kerja izin</h2>
                <ol class="small mb-0 ps-3">
                    <li class="mb-2"><strong>Otomatis sistem</strong> — Izin dapat diberikan otomatis bila dalam seminggu terakhir santri tidak absen kegiatan sebanyak 5 kali.</li>
                    <li class="mb-2"><strong>Pengurus</strong> — Dapat mengesahkan atau menganulir keputusan sistem.</li>
                    <li class="mb-0"><strong>Pengasuh</strong> — Dapat menganulir atau mengesahkan keputusan sistem maupun pengurus.</li>
                </ol>
            </div>
        </div>

        <div class="card wali-card shadow-sm mb-3 border-success border-opacity-25 d-none" id="card-perpanjang-izin">
            <div class="card-body">
                <h2 class="h6 mb-2"><i class="fa-solid fa-calendar-plus text-success me-1"></i> Perpanjangan izin</h2>
                <p class="small text-muted mb-3" id="perpanjang-info-izin">Anak Anda sedang izin. Ajukan perpanjangan jika diperlukan.</p>
                <form method="post" class="row g-2" id="form-perpanjang-izin">
                    <input type="hidden" name="action" value="perpanjang_izin">
                    <input type="hidden" name="izin_id" id="perpanjang-izin-id" value="">
                    <div class="col-12">
                        <label class="form-label small mb-0">Tanggal selesai baru</label>
                        <input type="date" name="tanggal_selesai_baru" id="perpanjang-tgl-baru" class="form-control form-control-sm" required>
                        <div class="form-text">Maks. tambahan <?= (int) $izinPerpanjanganMaxHari ?> hari dari tanggal selesai saat ini.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Alasan perpanjangan <span class="text-danger">*</span></label>
                        <textarea name="alasan_perpanjangan" id="perpanjang-alasan" class="form-control form-control-sm" rows="3" minlength="10" maxlength="500" required placeholder="Jelaskan alasan perpanjangan izin…"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-sm w-100">Ajukan perpanjangan izin</button>
                    </div>
                </form>
            </div>
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
                        <label class="form-label small mb-0">Keperluan izin <span class="text-danger">*</span></label>
                        <?php if ($syariKategoriOpsi === []): ?>
                            <div class="alert alert-warning small py-2 mb-0">Belum ada keperluan yang diaktifkan pengurus. Hubungi pondok untuk pengaturan izin.</div>
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
                    <div class="col-12">
                        <label class="form-label small mb-0">Keterangan / alasan <span class="text-danger">*</span></label>
                        <textarea name="keterangan_alasan" class="form-control form-control-sm" rows="3" minlength="10" maxlength="500" required placeholder="Jelaskan alasan dan keperluan izin secara singkat…"></textarea>
                        <div class="form-text">Minimal 10 karakter. Akan digabung dengan keperluan yang dipilih.</div>
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
                    <div class="col-12 d-none" id="wrap-pending-blokir">
                        <div class="alert alert-warning border-warning small mb-0 py-2">
                            <div class="fw-semibold mb-1"><i class="fa-solid fa-hourglass-half me-1"></i> Pengajuan ditahan</div>
                            <div id="pending-blokir-teks"></div>
                        </div>
                    </div>
                    <div class="col-12 d-none" id="wrap-alpa-peringatan">
                        <div class="alert alert-warning border-warning small mb-0 py-2">
                            <div class="fw-semibold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Perhatian — syarat ALPA</div>
                            <div id="alpa-peringatan-teks" class="mb-1"></div>
                            <div class="mb-0">Anda tetap dapat mengirim permohonan. Pengasuh pondok yang menilai apakah izin dapat disetujui.</div>
                        </div>
                    </div>
                    <div class="col-12 d-none" id="wrap-alpa-ok">
                        <div class="alert alert-success border-success small mb-0 py-2">
                            <div class="fw-semibold mb-1"><i class="fa-solid fa-circle-check me-1"></i> Syarat ALPA</div>
                            <div id="alpa-ok-teks"></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-teal btn-sm w-100" id="btn-kirim-wali-izin" <?= ($syariKategoriOpsi === [] || $pendingBlokirAwal !== null) ? 'disabled' : '' ?>>Kirim permohonan izin</button>
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
    var izinAktifMap = <?= json_encode($izinAktifMap, JSON_UNESCAPED_UNICODE) ?>;
    var perpanjanganMaxHari = <?= (int) $izinPerpanjanganMaxHari ?>;
    var cardPerpanjang = document.getElementById('card-perpanjang-izin');
    var formPerpanjang = document.getElementById('form-perpanjang-izin');
    var perpanjangInfo = document.getElementById('perpanjang-info-izin');
    var perpanjangIzinId = document.getElementById('perpanjang-izin-id');
    var perpanjangTglBaru = document.getElementById('perpanjang-tgl-baru');
    var perpanjangAlasan = document.getElementById('perpanjang-alasan');

    function formatDate(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function formatDateId(iso) {
        if (!iso || iso.length < 10) return iso || '—';
        var p = iso.split('-');
        if (p.length !== 3) return iso;
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function renderPerpanjanganCard(sid) {
        if (!cardPerpanjang || !formPerpanjang) return;
        var row = izinAktifMap[String(sid)] || izinAktifMap[sid];
        if (!row) {
            cardPerpanjang.classList.add('d-none');
            if (perpanjangIzinId) perpanjangIzinId.value = '';
            return;
        }
        cardPerpanjang.classList.remove('d-none');
        if (perpanjangIzinId) perpanjangIzinId.value = String(row.id || '');
        var tglLama = String(row.tanggal_selesai || '');
        var tglMulai = String(row.tanggal_mulai || '');
        if (perpanjangInfo) {
            perpanjangInfo.textContent = 'Izin aktif #' + String(row.id || '') + ' · '
                + formatDateId(tglMulai) + ' – ' + formatDateId(tglLama)
                + '. Isi alasan dan tanggal selesai baru jika perlu diperpanjang.';
        }
        if (perpanjangTglBaru && tglLama) {
            var end = new Date(tglLama + 'T00:00:00');
            var maxEnd = new Date(end);
            maxEnd.setDate(maxEnd.getDate() + (perpanjanganMaxHari > 0 ? perpanjanganMaxHari : 7));
            perpanjangTglBaru.min = tglLama;
            perpanjangTglBaru.max = formatDate(maxEnd);
            perpanjangTglBaru.value = tglLama;
        }
        if (perpanjangAlasan) {
            perpanjangAlasan.value = '';
        }
    }

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
    var wrapPending = document.getElementById('wrap-pending-blokir');
    var txtPending = document.getElementById('pending-blokir-teks');
    var btnKirim = document.getElementById('btn-kirim-wali-izin');
    var apiAlpaUrl = <?= json_encode($apiAlpaUrl, JSON_UNESCAPED_UNICODE) ?>;

    function renderPending(data) {
        if (!wrapPending || !txtPending || !btnKirim) return;
        var blocked = data && data.pending_blocked;
        if (blocked && data.pending_message) {
            wrapPending.classList.remove('d-none');
            txtPending.textContent = data.pending_message;
            btnKirim.disabled = true;
            return;
        }
        wrapPending.classList.add('d-none');
        txtPending.textContent = '';
        if (<?= $syariKategoriOpsi === [] ? 'true' : 'false' ?>) {
            btnKirim.disabled = true;
        } else {
            btnKirim.disabled = false;
        }
    }

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

    function alpaBaris(data) {
        if (!data || !data.enabled || !data.subject) return '';
        var count = data.alpa_count || 0;
        var max = data.max || 0;
        var hari = data.hari || 0;
        var batasAman = max > 0 ? max - 1 : 0;
        var lines = [];
        if (count > 0 && hari > 0) {
            lines.push('Tercatat ' + count + ' kali ALPA dalam ' + hari + ' hari terakhir.');
        }
        if (max > 0) {
            lines.push('Batas izin: maks. ' + batasAman + ' kali ALPA (terhalang dari ' + max + ' kali).');
        }
        if (data.penjelasan) {
            lines.push(data.penjelasan);
        }
        return lines.join(' ');
    }

    function renderAlpa(data) {
        if (!wrapBlocked || !wrapOk || !txtBlocked || !txtOk) return;
        wrapBlocked.classList.add('d-none');
        wrapOk.classList.add('d-none');
        if (!data || !data.enabled) {
            return;
        }
        if (!data.subject) {
            return;
        }
        var teks = alpaBaris(data);
        if (data.blocked) {
            txtBlocked.textContent = teks !== '' ? teks : 'Santri terhalang syarat ALPA saat ini.';
            wrapBlocked.classList.remove('d-none');
            return;
        }
        txtOk.textContent = teks !== '' ? teks : 'Syarat ALPA masih terpenuhi.';
        wrapOk.classList.remove('d-none');
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
            .then(function (data) {
                if (data && data.ok) {
                    renderAlpa(data);
                    renderPending(data);
                }
            })
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
        santriSelect.addEventListener('change', function () {
            refreshAlpa();
            renderPerpanjanganCard(parseInt(santriSelect.value || '0', 10));
        });
    }
    var sidAwal = form ? form.querySelector('[name="santri_id"]') : null;
    renderPerpanjanganCard(parseInt((sidAwal && sidAwal.value) ? sidAwal.value : '<?= (int) $waliSantriId ?>', 10));
    renderAlpa(<?= json_encode($alpaInfoAwal, JSON_UNESCAPED_UNICODE) ?>);
    renderPending({
        pending_blocked: <?= $pendingBlokirAwal !== null ? 'true' : 'false' ?>,
        pending_message: <?= json_encode($pendingBlokirAwal ?? '', JSON_UNESCAPED_UNICODE) ?>
    });
    refreshAlpa();
})();
</script>

<?php
wali_layout_foot(true, 'izin');
