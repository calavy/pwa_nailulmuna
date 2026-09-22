<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';
require_once __DIR__ . '/../helpers/poin_offline.php';
require_once __DIR__ . '/../helpers/offline_sync_http.php';
require_once __DIR__ . '/../helpers/poin_presensi_pull.php';

require_roles(['admin', 'pengurus']);
santri_list_sort_mode($_GET['santri_sort'] ?? null);
ensure_point_tables($pdo);
ensure_akademik_libur_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $formAction = trim((string) ($_POST['form_action'] ?? 'save'));
    if ($formAction === 'pull_presensi') {
        $santriPull = (int) ($_POST['santri_id'] ?? 0);
        $pullResult = poin_presensi_pull_execute($pdo, $santriPull, [], $userId);
        if ($pullResult['ok'] ?? false) {
            set_flash('success', (string) ($pullResult['message'] ?? 'Tarik presensi berhasil.'));
        } else {
            set_flash('error', (string) ($pullResult['message'] ?? 'Gagal menarik presensi.'));
        }
        header('Location: ' . app_href('/poin/input.php'));
        exit;
    }

    $result = poin_offline_submit($pdo, $_POST, $userId);

    if (offline_sync_wants_json()) {
        offline_sync_json_response(
            (string) ($result['type'] ?? ($result['ok'] ? 'success' : 'error')),
            (string) ($result['message'] ?? 'OK'),
            array_filter(['ledger_id' => $result['ledger_id'] ?? null], static fn($v): bool => $v !== null)
        );
    }

    if ($result['ok']) {
        set_flash('success', (string) ($result['message'] ?? 'Input poin berhasil disimpan.'));
    } else {
        set_flash('error', (string) ($result['message'] ?? 'Gagal menyimpan poin.'));
    }
    header('Location: ' . app_href('/poin/input.php'));
    exit;
}

$santriList = $pdo->query('SELECT id, nis, nama_santri, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri'))->fetchAll();
$ruleList = $pdo->query('SELECT id, kode_rule, kategori, nama_rule, bobot_poin, jenis_rule, contoh_pelanggaran FROM point_rules WHERE is_active = 1 ORDER BY urutan ASC, kategori ASC')->fetchAll();
$peringanList = table_exists($pdo, 'point_peringan')
    ? ($pdo->query('SELECT id, kode, nama, efek_persen FROM point_peringan WHERE is_active = 1 ORDER BY urutan ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [])
    : [];
$pemberatList = table_exists($pdo, 'point_pemberat')
    ? ($pdo->query('SELECT id, kode, nama, efek_persen FROM point_pemberat WHERE is_active = 1 ORDER BY urutan ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [])
    : [];
$pointAutoAlpa = (int) app_setting($pdo, 'point_auto_alpa', '5');
$pointAutoTelat = (int) app_setting($pdo, 'point_auto_telat', '1');
$ruleSearchUrl = app_href('/api/poin/rule_search.php');
$presensiPendingUrl = app_href('/api/poin/presensi_pending.php');
$recentRows = $pdo->query('
    SELECT pl.tanggal, pl.jenis_perubahan, pl.point_delta, pl.keterangan, s.nama_santri, s.tingkatan
    FROM point_ledger pl
    INNER JOIN santri s ON s.id = pl.santri_id
    ORDER BY pl.id DESC
    LIMIT 30
')->fetchAll();
$totalRulesAktif = count($ruleList);
$totalSantriTersedia = count($santriList);
$totalInputTerakhir = count($recentRows);

$pageTitle = 'Input Poin Kedisiplinan';
$loadSantriSelectJs = true;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Poin</p>
    <h1 class="h4 mb-1">Input poin kedisiplinan</h1>
    <p class="text-muted mb-0">Cari pelanggaran by kata kunci, atur peringan/pemberat (Sedang+), tarik alpa/telat dari presensi manual sebelum jadi catatan resmi.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Rule aktif</div>
            <div class="app-mini-stat-value"><?= $totalRulesAktif ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Santri tersedia</div>
            <div class="app-mini-stat-value"><?= $totalSantriTersedia ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Riwayat tampil</div>
            <div class="app-mini-stat-value"><?= $totalInputTerakhir ?></div>
        </div>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5">Form Input/Pengurangan Poin</h1>
                <form method="post" class="row g-2" id="form-poin-input">
                    <input type="hidden" name="form_action" value="save">
                    <div class="col-12">
                        <label class="form-label">Santri</label>
                        <select class="form-select santri-select-searchable" name="santri_id" id="poinSantriSelect" required>
                            <option value="">Pilih santri</option>
                            <?php foreach ($santriList as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars($s['nama_santri'] . ' - ' . ($s['tingkatan'] ?: '-') . ' (' . $s['nis'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Jenis</label>
                        <select class="form-select" name="jenis_perubahan" id="poinJenisSelect">
                            <option value="PLUS">Tambah Poin</option>
                            <option value="MINUS">Kurangi Poin (Remedial)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-12 d-none" id="poinPresensiPanel">
                        <div class="border rounded p-2 bg-light small">
                            <div class="fw-semibold mb-1">Presensi (belum masuk poin)</div>
                            <div id="poinPresensiSummary" class="text-muted mb-2">Pilih santri untuk melihat alpa/telat pending.</div>
                            <div id="poinPresensiRows" class="mb-2"></div>
                            <button type="submit" form="form-poin-pull" class="btn btn-outline-warning btn-sm" id="poinPullBtn" disabled>Tarik ke poin</button>
                            <span class="text-muted ms-1">ALPA +<?= $pointAutoAlpa ?>, Telat +<?= $pointAutoTelat ?> per kejadian</span>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Cari pelanggaran <span class="text-muted small" id="poinRuleHint">(min. 2 huruf)</span></label>
                        <input type="search" class="form-control" id="poinRuleSearch" placeholder="Contoh: ghosob, bolos, peci" autocomplete="off">
                        <div id="poinRuleSearchResults" class="list-group list-group-flush border rounded mt-1 d-none"></div>
                        <input type="hidden" name="rule_id" id="poinRuleIdHidden" value="0">
                        <div id="poinRulePickLabel" class="form-text"></div>
                    </div>
                    <details class="col-12">
                        <summary class="small text-muted">Pilih dari daftar rule lengkap</summary>
                        <select class="form-select mt-2" id="poinRuleSelect">
                            <option value="0">—</option>
                            <?php foreach ($ruleList as $r): ?>
                                <?php
                                $jr = strtoupper((string) ($r['jenis_rule'] ?? 'PLUS'));
                                $allowsMod = ((int) ($r['bobot_poin'] ?? 0) >= 3) && stripos((string) ($r['kategori'] ?? ''), 'D. Ringan') === false;
                                ?>
                                <option value="<?= (int) $r['id'] ?>" data-jenis="<?= htmlspecialchars($jr) ?>" data-poin="<?= (int) $r['bobot_poin'] ?>" data-kategori="<?= htmlspecialchars((string) $r['kategori']) ?>" data-allows-mod="<?= $allowsMod ? '1' : '0' ?>">
                                    <?= htmlspecialchars($r['kategori'] . ' - ' . $r['nama_rule'] . ' (' . $r['bobot_poin'] . ' poin)') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </details>
                    <div class="col-md-6">
                        <label class="form-label">Peringan (opsional)</label>
                        <select class="form-select" name="peringan_id" id="poinPeringanSelect" disabled>
                            <option value="0">—</option>
                            <?php foreach ($peringanList as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-persen="<?= (int) $p['efek_persen'] ?>"><?= htmlspecialchars($p['kode'] . ' — ' . $p['nama'] . ' (' . (int) $p['efek_persen'] . '%)') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Pemberat (opsional)</label>
                        <select class="form-select" name="pemberat_id" id="poinPemberatSelect" disabled>
                            <option value="0">—</option>
                            <?php foreach ($pemberatList as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-persen="<?= (int) $p['efek_persen'] ?>"><?= htmlspecialchars($p['kode'] . ' — ' . $p['nama'] . ' (+ ' . (int) $p['efek_persen'] . '%)') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Poin custom (opsional)</label>
                        <input type="number" min="1" class="form-control" name="point_custom" id="poinCustomInput" placeholder="Contoh: 2">
                        <div class="form-text" id="poinRulePreview"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nama pelanggaran / keterangan</label>
                        <textarea class="form-control" name="keterangan" rows="2" placeholder="Contoh: Tidak mengikuti apel, terlambat sholat subuh"></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Simpan</button>
                    </div>
                </form>
                <form method="post" id="form-poin-pull" class="d-none">
                    <input type="hidden" name="form_action" value="pull_presensi">
                    <input type="hidden" name="santri_id" id="poinPullSantriId" value="">
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Riwayat Input Terakhir</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover">
                        <thead><tr><th>Tanggal</th><th>Santri</th><th>Tingkatan</th><th>Jenis</th><th>Poin</th><th>Keterangan</th></tr></thead>
                        <tbody id="poin-recent-tbody">
                        <?php foreach ($recentRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['tanggal']) ?></td>
                                <td><?= htmlspecialchars($row['nama_santri']) ?></td>
                                <td><?= htmlspecialchars($row['tingkatan'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($row['jenis_perubahan']) ?></td>
                                <td><?= (int) $row['point_delta'] ?></td>
                                <td><?= htmlspecialchars((string) $row['keterangan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var jenis = document.getElementById('poinJenisSelect');
    var rule = document.getElementById('poinRuleSelect');
    var ruleHidden = document.getElementById('poinRuleIdHidden');
    var ruleSearch = document.getElementById('poinRuleSearch');
    var ruleResults = document.getElementById('poinRuleSearchResults');
    var rulePickLabel = document.getElementById('poinRulePickLabel');
    var preview = document.getElementById('poinRulePreview');
    var santriSel = document.getElementById('poinSantriSelect');
    var peringan = document.getElementById('poinPeringanSelect');
    var pemberat = document.getElementById('poinPemberatSelect');
    var customInput = document.getElementById('poinCustomInput');
    var presensiPanel = document.getElementById('poinPresensiPanel');
    var presensiSummary = document.getElementById('poinPresensiSummary');
    var presensiRows = document.getElementById('poinPresensiRows');
    var pullBtn = document.getElementById('poinPullBtn');
    var pullSantri = document.getElementById('poinPullSantriId');
    var ruleSearchUrl = <?= json_encode($ruleSearchUrl, JSON_UNESCAPED_UNICODE) ?>;
    var presensiUrl = <?= json_encode($presensiPendingUrl, JSON_UNESCAPED_UNICODE) ?>;

    var state = { ruleId: '0', basePoin: 0, allowsMod: false, searchTimer: null };

    function applyRule(id, label, base, allowsMod) {
        state.ruleId = String(id || '0');
        state.basePoin = parseInt(base, 10) || 0;
        state.allowsMod = !!allowsMod;
        if (ruleHidden) ruleHidden.value = state.ruleId;
        if (rulePickLabel) rulePickLabel.textContent = label || '';
        if (rule) rule.value = state.ruleId;
        syncModifierFields();
        updatePreview();
    }

    function syncModifierFields() {
        var plus = (jenis && jenis.value === 'PLUS');
        var enable = plus && state.allowsMod;
        if (peringan) peringan.disabled = !enable;
        if (pemberat) pemberat.disabled = !enable;
        if (!enable) {
            if (peringan) peringan.value = '0';
            if (pemberat) pemberat.value = '0';
        }
    }

    function calcFinal(base) {
        base = parseInt(base, 10) || 0;
        if (base <= 0) return 0;
        var persen = 0;
        if (peringan && peringan.value && peringan.value !== '0') {
            var o = peringan.options[peringan.selectedIndex];
            persen = parseInt(o.getAttribute('data-persen') || '0', 10);
        } else if (pemberat && pemberat.value && pemberat.value !== '0') {
            var o2 = pemberat.options[pemberat.selectedIndex];
            persen = parseInt(o2.getAttribute('data-persen') || '0', 10);
        }
        var fin = Math.round(base * (1 + persen / 100));
        return Math.max(1, fin);
    }

    function updatePreview() {
        if (!preview) return;
        var base = state.basePoin;
        if (customInput && customInput.value) {
            base = parseInt(customInput.value, 10) || base;
        }
        if (base <= 0) {
            preview.textContent = '';
            return;
        }
        var sign = jenis && jenis.value === 'MINUS' ? '-' : '+';
        if (jenis && jenis.value === 'PLUS' && state.allowsMod) {
            preview.textContent = 'Poin dasar ' + base + ' → final ' + calcFinal(base) + ' (' + sign + calcFinal(base) + ' di ledger)';
        } else {
            preview.textContent = 'Bobot: ' + sign + base + ' poin';
        }
    }

    function syncRulesDropdown() {
        if (!jenis || !rule) return;
        var j = jenis.value || 'PLUS';
        Array.prototype.forEach.call(rule.options, function (opt) {
            if (!opt.value || opt.value === '0') return;
            opt.hidden = (opt.getAttribute('data-jenis') || 'PLUS') !== j;
        });
    }

    function loadPresensiPending() {
        if (!santriSel || !presensiPanel) return;
        var sid = santriSel.value;
        if (pullSantri) pullSantri.value = sid;
        if (!sid) {
            presensiPanel.classList.add('d-none');
            return;
        }
        presensiPanel.classList.remove('d-none');
        presensiSummary.textContent = 'Memuat…';
        presensiRows.innerHTML = '';
        if (pullBtn) pullBtn.disabled = true;
        fetch(presensiUrl + '?santri_id=' + encodeURIComponent(sid), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var c = data.counts || {};
                var label = (data.periode && data.periode.label) ? data.periode.label : '';
                presensiSummary.textContent = label + ' — pending: ' + (c.pending || 0) + ' (Alpa ' + (c.alpa || 0) + ', Telat ' + (c.telat || 0) + ')';
                var rows = data.rows || [];
                if (rows.length === 0) {
                    presensiRows.innerHTML = '<span class="text-muted">Tidak ada alpa/telat yang belum ditarik.</span>';
                } else {
                    var ul = document.createElement('ul');
                    ul.className = 'mb-0 ps-3';
                    rows.slice(0, 8).forEach(function (row) {
                        var li = document.createElement('li');
                        li.textContent = row.tanggal + ' · ' + row.jenis + (row.kegiatan ? ' — ' + row.kegiatan : '');
                        ul.appendChild(li);
                    });
                    if (rows.length > 8) {
                        var more = document.createElement('li');
                        more.className = 'text-muted';
                        more.textContent = '… +' + (rows.length - 8) + ' lagi';
                        ul.appendChild(more);
                    }
                    presensiRows.appendChild(ul);
                }
                if (pullBtn) pullBtn.disabled = !(c.pending > 0);
            })
            .catch(function () {
                presensiSummary.textContent = 'Gagal memuat data presensi.';
            });
    }

    function runRuleSearch(q) {
        if (!ruleResults) return;
        var j = jenis ? jenis.value : 'PLUS';
        fetch(ruleSearchUrl + '?q=' + encodeURIComponent(q) + '&jenis=' + encodeURIComponent(j), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                ruleResults.innerHTML = '';
                var items = data.items || [];
                if (items.length === 0) {
                    ruleResults.classList.add('d-none');
                    return;
                }
                ruleResults.classList.remove('d-none');
                items.forEach(function (it) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action py-2 small text-start';
                    btn.innerHTML = '<strong>' + (it.kode_rule || '') + '</strong> · ' + (it.kategori || '') + ' — ' + (it.bobot_poin || 0) + ' poin<br><span class="text-muted">' + (it.contoh_pelanggaran || it.nama_rule || '') + '</span>';
                    btn.addEventListener('click', function () {
                        applyRule(it.id, it.kode_rule + ' — ' + it.nama_rule, it.bobot_poin, it.allows_modifier);
                        ruleResults.classList.add('d-none');
                        if (ruleSearch) ruleSearch.value = it.kode_rule || '';
                    });
                    ruleResults.appendChild(btn);
                });
            });
    }

    if (ruleSearch) {
        ruleSearch.addEventListener('input', function () {
            var q = ruleSearch.value.trim();
            clearTimeout(state.searchTimer);
            if (q.length < 2) {
                if (ruleResults) ruleResults.classList.add('d-none');
                return;
            }
            state.searchTimer = setTimeout(function () { runRuleSearch(q); }, 280);
        });
    }

    if (rule) {
        rule.addEventListener('change', function () {
            var opt = rule.options[rule.selectedIndex];
            if (!opt || !opt.value || opt.value === '0') {
                applyRule(0, '', 0, false);
                return;
            }
            applyRule(opt.value, opt.textContent, opt.getAttribute('data-poin'), opt.getAttribute('data-allows-mod') === '1');
        });
    }

    if (jenis) {
        jenis.addEventListener('change', function () {
            syncRulesDropdown();
            syncModifierFields();
            updatePreview();
        });
    }
    if (peringan) peringan.addEventListener('change', function () {
        if (pemberat && peringan.value !== '0') pemberat.value = '0';
        updatePreview();
    });
    if (pemberat) pemberat.addEventListener('change', function () {
        if (peringan && pemberat.value !== '0') peringan.value = '0';
        updatePreview();
    });
    if (customInput) customInput.addEventListener('input', updatePreview);
    if (santriSel) {
        santriSel.addEventListener('change', loadPresensiPending);
    }

    syncRulesDropdown();
    syncModifierFields();
    if (pullBtn) {
        pullBtn.addEventListener('click', function (e) {
            if (!confirm('Tarik semua alpa/telat pending periode ini ke catatan poin resmi?')) {
                e.preventDefault();
            }
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
