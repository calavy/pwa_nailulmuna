<?php

declare(strict_types=1);

/**
 * Daftar kegiatan tanpa scan — per baris: nama kegiatan + jml waktu (bukan jml tingkatan/santri).
 * Klik slot tanggal/waktu → roster santri (centang = HADIR, view-only).
 *
 * @var list<array<string, mixed>> $ktsSlotRows baris per slot dari rekap_keaktifan_kegiatan_tanpa_scan_bulan()
 * @var string $ktsListPrefix id unik untuk panel detail
 * @var bool $ktsShowHint
 */
$ktsSlotRows = (array) ($ktsSlotRows ?? []);
$ktsListPrefix = preg_replace('/[^a-z0-9_-]/i', '', (string) ($ktsListPrefix ?? 'kts')) ?: 'kts';
$ktsShowHint = !isset($ktsShowHint) || $ktsShowHint;
$ktsGrouped = rekap_keaktifan_kegiatan_tanpa_scan_group_by_kegiatan($ktsSlotRows);
$ktsRosterApi = function_exists('app_href')
    ? app_href('/api/presensi/kegiatan_slot_santri.php')
    : '/api/presensi/kegiatan_slot_santri.php';

if ($ktsGrouped === []) {
    return;
}
?>
<?php if ($ktsShowHint): ?>
<p class="small text-muted mb-2">
    Ketuk baris kegiatan untuk lihat tanggal &amp; waktu tanpa scan.
    Ketuk tanggal/waktu untuk lihat daftar santri (centang = hadir).
</p>
<?php endif; ?>
<ol class="kts-grouped-list list-unstyled mb-0" data-kts-roster-api="<?= htmlspecialchars($ktsRosterApi) ?>">
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
        if (st === 'IZIN') return '<span class="badge text-bg-info">IZIN</span>';
        if (st === 'SAKIT') return '<span class="badge text-bg-warning">SAKIT</span>';
        if (st === 'ALPA') return '<span class="badge text-bg-danger">ALPA</span>';
        return '<span class="badge text-bg-secondary">Belum</span>';
    }

    function renderRoster(body, data) {
        var items = (data && data.items) ? data.items : [];
        if (!items.length) {
            body.innerHTML = '<div class="small text-muted p-2">Tidak ada santri wajib untuk slot ini.</div>';
            return;
        }
        var hadir = Number(data.hadir || 0);
        var total = Number(data.total || items.length);
        var html = '';
        html += '<div class="kts-slot-roster__meta small text-muted px-2 pt-2">';
        html += 'Hadir ' + hadir + '/' + total + ' · centang = hadir (lihat saja)';
        html += '</div><ul class="kts-slot-roster__list list-unstyled mb-0">';
        items.forEach(function (it) {
            var checked = it.hadir ? ' checked' : '';
            html += '<li class="kts-slot-roster__item">';
            html += '<label class="kts-slot-roster__label">';
            html += '<input type="checkbox" disabled' + checked + '>';
            html += '<span class="kts-slot-roster__nama">' + escapeHtml(it.nama_santri || '-') + '</span>';
            if (it.nis) {
                html += '<span class="kts-slot-roster__nis text-muted">' + escapeHtml(it.nis) + '</span>';
            }
            html += statusBadge(it.status);
            html += '</label></li>';
        });
        html += '</ul>';
        body.innerHTML = html;
    }

    function loadRoster(row) {
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
        if (body.getAttribute('data-loaded') === '1') {
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
                renderRoster(body, res.j);
                body.setAttribute('data-loaded', '1');
            })
            .catch(function () {
                body.innerHTML = '<div class="small text-danger p-2">Gagal memuat daftar santri.</div>';
            });
    }

    document.addEventListener('click', function (ev) {
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
