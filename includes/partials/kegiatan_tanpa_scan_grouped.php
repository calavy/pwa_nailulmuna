<?php

declare(strict_types=1);

/**
 * Daftar kegiatan tanpa scan — per baris: nama kegiatan + jml waktu (bukan jml tingkatan/santri).
 * Klik slot tanggal/waktu → roster santri (centang = HADIR, view-only).
 *
 * @var list<array<string, mixed>> $ktsSlotRows baris per slot dari rekap_keaktifan_kegiatan_tanpa_scan_bulan()
 * @var string $ktsListPrefix id unik untuk panel detail
 * @var bool $ktsShowHint
 * @var bool $ktsAllowKoreksi super admin — hapus ALPA / catat hadir manual
 */
$ktsSlotRows = (array) ($ktsSlotRows ?? []);
$ktsListPrefix = preg_replace('/[^a-z0-9_-]/i', '', (string) ($ktsListPrefix ?? 'kts')) ?: 'kts';
$ktsShowHint = !isset($ktsShowHint) || $ktsShowHint;
$ktsAllowKoreksi = !empty($ktsAllowKoreksi);
$ktsGrouped = rekap_keaktifan_kegiatan_tanpa_scan_group_by_kegiatan($ktsSlotRows);
$ktsRosterApi = function_exists('app_href')
    ? app_href('/api/presensi/kegiatan_slot_santri.php')
    : '/api/presensi/kegiatan_slot_santri.php';
$ktsKoreksiApi = function_exists('app_href')
    ? app_href('/api/presensi/slot_koreksi.php')
    : '/api/presensi/slot_koreksi.php';

if ($ktsGrouped === []) {
    return;
}
?>
<?php if ($ktsShowHint): ?>
<p class="small text-muted mb-2">
    Ketuk baris kegiatan untuk lihat tanggal &amp; waktu tanpa scan.
    Ketuk tanggal/waktu untuk lihat daftar santri (centang = hadir).
    <?php if ($ktsAllowKoreksi): ?>
        <span class="d-block mt-1 text-warning-emphasis"><i class="fa-solid fa-shield-halved me-1"></i>Admin super: centang santri lalu hapus ALPA atau catat hadir manual.</span>
    <?php endif; ?>
</p>
<?php endif; ?>
<ol class="kts-grouped-list list-unstyled mb-0"
    data-kts-roster-api="<?= htmlspecialchars($ktsRosterApi) ?>"
    data-kts-koreksi-api="<?= htmlspecialchars($ktsKoreksiApi) ?>"
    data-kts-allow-koreksi="<?= $ktsAllowKoreksi ? '1' : '0' ?>">
    <?php foreach ($ktsGrouped as $idx => $kgRow):
        $jmlTidak = (int) ($kgRow['jumlah_tidak_scan'] ?? 0);
        $detailSlots = (array) ($kgRow['detail'] ?? []);
        $kegiatanIdRow = (int) ($kgRow['kegiatan_id'] ?? 0);
        $itemId = $ktsListPrefix . '-detail-' . $kegiatanIdRow . '-' . $idx;
        ?>
        <li class="kts-grouped-item mb-2">
            <button type="button" class="kts-grouped-item__head w-100" aria-expanded="false" aria-controls="<?= htmlspecialchars($itemId) ?>" data-kts-toggle>
                <span class="kts-grouped-item__chev"><i class="fa-solid fa-chevron-right"></i></span>
                <span class="kts-grouped-item__no"><?= $idx + 1 ?></span>
                <span class="kts-grouped-item__nama flex-grow-1 text-start"><?= htmlspecialchars((string) ($kgRow['nama_kegiatan'] ?? '')) ?></span>
                <span class="kts-grouped-item__count" title="Jumlah waktu tanpa scan">
                    <?= $jmlTidak ?>
                    <small>waktu</small>
                </span>
            </button>
            <div class="kts-grouped-item__detail" id="<?= htmlspecialchars($itemId) ?>" hidden>
                <?php if ($detailSlots === []): ?>
                    <p class="small text-muted mb-0">Detail tanggal/waktu tidak tersedia.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Hari</th>
                                <th>Waktu</th>
                                <th>Tingkatan</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($detailSlots as $slotIdx => $slot):
                                $slotTanggal = (string) ($slot['tanggal'] ?? '');
                                $slotTk = (string) ($slot['tingkatan'] ?? '');
                                $rosterId = $itemId . '-roster-' . $slotIdx;
                                ?>
                                <tr class="kts-slot-row"
                                    role="button"
                                    tabindex="0"
                                    data-kts-slot
                                    data-kegiatan-id="<?= (int) $kegiatanIdRow ?>"
                                    data-tanggal="<?= htmlspecialchars($slotTanggal) ?>"
                                    data-tingkatan="<?= htmlspecialchars($slotTk) ?>"
                                    data-roster-target="<?= htmlspecialchars($rosterId) ?>"
                                    aria-expanded="false"
                                    aria-controls="<?= htmlspecialchars($rosterId) ?>">
                                    <td class="text-nowrap">
                                        <?= htmlspecialchars((string) ($slot['tanggal_tampil'] ?? '')) ?>
                                        <?php if (!empty($slot['tanggal_hijri'])): ?>
                                            <span class="text-muted d-block small"><?= htmlspecialchars((string) $slot['tanggal_hijri']) ?> H</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($slot['hari'] ?? '')) ?></td>
                                    <td class="text-nowrap"><?= htmlspecialchars((string) ($slot['jam'] ?? '')) ?></td>
                                    <td>
                                        <?= htmlspecialchars($slotTk) ?>
                                        <span class="kts-slot-row__hint text-muted small d-block">Ketuk untuk daftar santri</span>
                                    </td>
                                </tr>
                                <tr class="kts-slot-roster-row" id="<?= htmlspecialchars($rosterId) ?>" hidden>
                                    <td colspan="4" class="kts-slot-roster-cell p-0">
                                        <div class="kts-slot-roster" data-kts-roster-body>
                                            <div class="small text-muted p-2">Memuat…</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ol>
<script>
(function () {
    if (window.__ktsGroupedToggleInit) {
        return;
    }
    window.__ktsGroupedToggleInit = true;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function statusBadge(status) {
        var st = String(status || '').toUpperCase();
        if (st === 'HADIR') return '<span class="badge text-bg-success">HADIR</span>';
        if (st === 'BEBAS') return '<span class="badge text-bg-secondary">Bebas ALPA</span>';
        if (st === 'IZIN') return '<span class="badge text-bg-info">IZIN</span>';
        if (st === 'SAKIT') return '<span class="badge text-bg-warning">SAKIT</span>';
        if (st === 'ALPA') return '<span class="badge text-bg-danger">ALPA</span>';
        return '<span class="badge text-bg-secondary">Belum</span>';
    }

    function rosterAllowKoreksi(list) {
        if (!list) return false;
        if (list.getAttribute('data-kts-allow-koreksi') === '1') return true;
        return false;
    }

    function renderRoster(body, data, slotRow) {
        var items = (data && data.items) ? data.items : [];
        var allowKoreksi = !!(data && data.allow_koreksi) || rosterAllowKoreksi(body.closest('[data-kts-roster-api]'));
        if (!items.length) {
            body.innerHTML = '<div class="small text-muted p-2">Tidak ada santri wajib untuk slot ini.</div>';
            return;
        }
        var hadir = Number(data.hadir || 0);
        var total = Number(data.total || items.length);
        var html = '';
        html += '<div class="kts-slot-roster__meta small text-muted px-2 pt-2">';
        html += 'Hadir ' + hadir + '/' + total;
        if (allowKoreksi) {
            html += ' · centang santri untuk koreksi';
        } else {
            html += ' · centang = hadir (lihat saja)';
        }
        html += '</div>';
        if (allowKoreksi) {
            html += '<div class="kts-slot-roster__actions d-flex flex-wrap gap-2 px-2 py-2 border-bottom">';
            html += '<button type="button" class="btn btn-outline-danger btn-sm" data-kts-action="hapus_alpa"><i class="fa-solid fa-eraser me-1"></i>Hapus ALPA</button>';
            html += '<button type="button" class="btn btn-outline-success btn-sm" data-kts-action="catat_hadir"><i class="fa-solid fa-user-check me-1"></i>Catat hadir manual</button>';
            html += '</div>';
        }
        html += '<ul class="kts-slot-roster__list list-unstyled mb-0">';
        items.forEach(function (it) {
            var st = String(it.status || '').toUpperCase();
            var checked = (!allowKoreksi && it.hadir) ? ' checked' : '';
            var canSelect = allowKoreksi && st !== 'HADIR';
            html += '<li class="kts-slot-roster__item">';
            html += '<label class="kts-slot-roster__label">';
            html += '<input type="checkbox" class="kts-roster-chk" data-santri-id="' + escapeHtml(String(it.santri_id || '')) + '"'
                + (canSelect ? '' : ' disabled')
                + checked + '>';
            html += '<span class="kts-slot-roster__nama">' + escapeHtml(it.nama_santri || '-') + '</span>';
            if (it.nis) {
                html += '<span class="kts-slot-roster__nis text-muted">' + escapeHtml(it.nis) + '</span>';
            }
            html += statusBadge(it.status);
            html += '</label></li>';
        });
        html += '</ul>';
        body.innerHTML = html;
        body.setAttribute('data-loaded', '1');
        body.setAttribute('data-slot-kegiatan-id', slotRow ? (slotRow.getAttribute('data-kegiatan-id') || '') : '');
        body.setAttribute('data-slot-tanggal', slotRow ? (slotRow.getAttribute('data-tanggal') || '') : '');
        body.setAttribute('data-slot-tingkatan', slotRow ? (slotRow.getAttribute('data-tingkatan') || '') : '');
    }

    function selectedSantriIds(body) {
        var ids = [];
        body.querySelectorAll('.kts-roster-chk:checked').forEach(function (el) {
            var sid = parseInt(el.getAttribute('data-santri-id') || '0', 10);
            if (sid > 0) ids.push(sid);
        });
        return ids;
    }

    function postKoreksi(body, action, slotRow) {
        var list = body.closest('[data-kts-roster-api]');
        var api = list ? list.getAttribute('data-kts-koreksi-api') : '';
        if (!api) return;
        var ids = selectedSantriIds(body);
        if (!ids.length) {
            alert('Pilih minimal satu santri.');
            return;
        }
        var label = action === 'hapus_alpa' ? 'hapus ALPA' : 'catat hadir manual';
        if (!confirm('Yakin ' + label + ' untuk ' + ids.length + ' santri terpilih?')) {
            return;
        }
        var kid = body.getAttribute('data-slot-kegiatan-id') || (slotRow ? slotRow.getAttribute('data-kegiatan-id') : '') || '';
        var tgl = body.getAttribute('data-slot-tanggal') || (slotRow ? slotRow.getAttribute('data-tanggal') : '') || '';
        var tk = body.getAttribute('data-slot-tingkatan') || (slotRow ? slotRow.getAttribute('data-tingkatan') : '') || '';
        if (tk.indexOf(',') !== -1 || /^semua/i.test(tk)) {
            tk = '';
        }
        body.innerHTML = '<div class="small text-muted p-2"><i class="fa-solid fa-spinner fa-spin me-1"></i>Menyimpan…</div>';
        fetch(api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                action: action,
                kegiatan_id: parseInt(kid, 10),
                tanggal: tgl,
                tingkatan: tk,
                santri_ids: ids
            })
        })
            .then(function (r) { return r.json().then(function (j) { return { okHttp: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.okHttp || !res.j || !res.j.ok) {
                    body.innerHTML = '<div class="small text-danger p-2">'
                        + escapeHtml((res.j && res.j.message) ? res.j.message : 'Gagal menyimpan koreksi.')
                        + '</div>';
                    body.removeAttribute('data-loaded');
                    return;
                }
                body.removeAttribute('data-loaded');
                if (slotRow) {
                    loadRoster(slotRow, true);
                }
                alert(res.j.message || 'Berhasil disimpan.');
            })
            .catch(function () {
                body.innerHTML = '<div class="small text-danger p-2">Gagal menyimpan koreksi.</div>';
                body.removeAttribute('data-loaded');
            });
    }

    function loadRoster(row, forceReload) {
        var list = row.closest('[data-kts-roster-api]');
        var api = list ? list.getAttribute('data-kts-roster-api') : '';
        var targetId = row.getAttribute('data-roster-target');
        var rosterRow = targetId ? document.getElementById(targetId) : null;
        if (!api || !rosterRow) {
            return;
        }
        var body = rosterRow.querySelector('[data-kts-roster-body]');
        if (!body) {
            return;
        }
        var expanded = row.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            row.setAttribute('aria-expanded', 'false');
            rosterRow.hidden = true;
            return;
        }
        row.setAttribute('aria-expanded', 'true');
        rosterRow.hidden = false;
        if (body.getAttribute('data-loaded') === '1' && !forceReload) {
            return;
        }
        body.innerHTML = '<div class="small text-muted p-2">Memuat daftar santri…</div>';
        var kid = row.getAttribute('data-kegiatan-id') || '';
        var tgl = row.getAttribute('data-tanggal') || '';
        var tk = row.getAttribute('data-tingkatan') || '';
        // Jika label gabungan banyak tingkatan, kirim kosong agar semua santri slot dimuat.
        if (tk.indexOf(',') !== -1 || /^semua/i.test(tk)) {
            tk = '';
        }
        var url = api
            + (api.indexOf('?') >= 0 ? '&' : '?')
            + 'kegiatan_id=' + encodeURIComponent(kid)
            + '&tanggal=' + encodeURIComponent(tgl)
            + (tk ? '&tingkatan=' + encodeURIComponent(tk) : '');
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { okHttp: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.okHttp || !res.j || !res.j.ok) {
                    body.innerHTML = '<div class="small text-danger p-2">'
                        + escapeHtml((res.j && res.j.message) ? res.j.message : 'Gagal memuat daftar santri.')
                        + '</div>';
                    return;
                }
                renderRoster(body, res.j, row);
            })
            .catch(function () {
                body.innerHTML = '<div class="small text-danger p-2">Gagal memuat daftar santri.</div>';
            });
    }

    document.addEventListener('click', function (ev) {
        var actionBtn = ev.target.closest('[data-kts-action]');
        if (actionBtn) {
            ev.preventDefault();
            var body = actionBtn.closest('[data-kts-roster-body]');
            var rosterRow = actionBtn.closest('.kts-slot-roster-row');
            var slotRow = rosterRow && rosterRow.previousElementSibling ? rosterRow.previousElementSibling : null;
            if (body && slotRow && slotRow.hasAttribute('data-kts-slot')) {
                postKoreksi(body, actionBtn.getAttribute('data-kts-action') || '', slotRow);
            }
            return;
        }
        var slot = ev.target.closest('[data-kts-slot]');
        if (slot) {
            ev.preventDefault();
            loadRoster(slot);
            return;
        }
        var btn = ev.target.closest('[data-kts-toggle]');
        if (!btn) {
            return;
        }
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        var panel = btn.nextElementSibling;
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        if (panel) {
            panel.hidden = expanded;
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') {
            return;
        }
        var slot = ev.target.closest('[data-kts-slot]');
        if (!slot) {
            return;
        }
        ev.preventDefault();
        loadRoster(slot);
    });
})();
</script>
<style>
.kts-grouped-item {
    border: 1px solid var(--bs-border-color);
    border-radius: 10px;
    overflow: hidden;
    background: var(--bs-body-bg);
}
.kts-grouped-item__head {
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .75rem 1rem;
    border: 0;
    background: transparent;
    cursor: pointer;
}
.kts-grouped-item__head:hover {
    background: rgba(0,0,0,.025);
}
[data-theme="dark"] .kts-grouped-item__head:hover {
    background: rgba(255,255,255,.04);
}
.kts-grouped-item__head[aria-expanded="true"] {
    border-bottom: 1px solid var(--bs-border-color);
    background: rgba(254,226,226,.2);
}
.kts-grouped-item__chev {
    flex: 0 0 1rem;
    color: var(--bs-secondary-color);
    transition: transform .15s ease;
}
.kts-grouped-item__head[aria-expanded="true"] .kts-grouped-item__chev {
    transform: rotate(90deg);
}
.kts-grouped-item__no {
    flex: 0 0 1.6rem;
    height: 1.6rem;
    border-radius: 999px;
    background: #fee2e2;
    color: #b91c1c;
    font-size: .78rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.kts-grouped-item__nama {
    font-weight: 600;
    min-width: 0;
}
.kts-grouped-item__count {
    flex: 0 0 auto;
    min-width: 2.75rem;
    text-align: center;
    font-weight: 700;
    font-size: .95rem;
    padding: .3rem .5rem;
    border-radius: 8px;
    background: #fee2e2;
    color: #b91c1c;
    line-height: 1.1;
}
.kts-grouped-item__count small {
    display: block;
    font-size: .62rem;
    font-weight: 600;
}
.kts-grouped-item__detail {
    padding: .65rem 1rem .85rem;
    background: rgba(248,250,252,.65);
}
[data-theme="dark"] .kts-grouped-item__detail {
    background: rgba(15,23,42,.3);
}
.kts-slot-row {
    cursor: pointer;
}
.kts-slot-row:hover,
.kts-slot-row[aria-expanded="true"] {
    background: rgba(59, 130, 246, .08);
}
.kts-slot-row__hint {
    font-size: .68rem;
}
.kts-slot-roster {
    background: rgba(255,255,255,.7);
    border-top: 1px dashed var(--bs-border-color);
    max-height: 280px;
    overflow: auto;
}
[data-theme="dark"] .kts-slot-roster {
    background: rgba(2,6,23,.35);
}
.kts-slot-roster__item {
    border-bottom: 1px solid var(--bs-border-color);
}
.kts-slot-roster__item:last-child {
    border-bottom: 0;
}
.kts-slot-roster__label {
    display: flex;
    align-items: center;
    gap: .55rem;
    padding: .45rem .75rem;
    margin: 0;
    cursor: default;
}
.kts-slot-roster__actions .btn {
    font-size: .78rem;
}
.kts-slot-roster__nama {
    font-weight: 560;
    flex: 1 1 auto;
    min-width: 0;
}
.kts-slot-roster__nis {
    font-size: .78rem;
    font-family: ui-monospace, monospace;
}
</style>
