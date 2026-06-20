<?php

declare(strict_types=1);

/**
 * Daftar kegiatan tanpa scan — per baris: nama kegiatan + jml jadwal (bukan jml santri).
 *
 * @var list<array<string, mixed>> $ktsSlotRows baris per slot dari rekap_keaktifan_kegiatan_tanpa_scan_bulan()
 * @var string $ktsListPrefix id unik untuk panel detail
 * @var bool $ktsShowHint
 */
$ktsSlotRows = (array) ($ktsSlotRows ?? []);
$ktsListPrefix = preg_replace('/[^a-z0-9_-]/i', '', (string) ($ktsListPrefix ?? 'kts')) ?: 'kts';
$ktsShowHint = !isset($ktsShowHint) || $ktsShowHint;
$ktsGrouped = rekap_keaktifan_kegiatan_tanpa_scan_group_by_kegiatan($ktsSlotRows);

if ($ktsGrouped === []) {
    return;
}
?>
<?php if ($ktsShowHint): ?>
<p class="small text-muted mb-2">Ketuk baris kegiatan untuk lihat tanggal &amp; waktu jadwal tanpa scan.</p>
<?php endif; ?>
<ol class="kts-grouped-list list-unstyled mb-0">
    <?php foreach ($ktsGrouped as $idx => $kgRow):
        $jmlTidak = (int) ($kgRow['jumlah_tidak_scan'] ?? 0);
        $detailSlots = (array) ($kgRow['detail'] ?? []);
        $itemId = $ktsListPrefix . '-detail-' . (int) ($kgRow['kegiatan_id'] ?? $idx);
        ?>
        <li class="kts-grouped-item mb-2">
            <button type="button" class="kts-grouped-item__head w-100" aria-expanded="false" aria-controls="<?= htmlspecialchars($itemId) ?>" data-kts-toggle>
                <span class="kts-grouped-item__chev"><i class="fa-solid fa-chevron-right"></i></span>
                <span class="kts-grouped-item__no"><?= $idx + 1 ?></span>
                <span class="kts-grouped-item__nama flex-grow-1 text-start"><?= htmlspecialchars((string) ($kgRow['nama_kegiatan'] ?? '')) ?></span>
                <span class="kts-grouped-item__count" title="Jumlah jadwal tanpa scan">
                    <?= $jmlTidak ?>
                    <small>jadwal</small>
                </span>
            </button>
            <div class="kts-grouped-item__detail" id="<?= htmlspecialchars($itemId) ?>" hidden>
                <?php if ($detailSlots === []): ?>
                    <p class="small text-muted mb-0">Detail tanggal/waktu tidak tersedia.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Hari</th>
                                <th>Waktu</th>
                                <th>Tingkatan</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($detailSlots as $slot): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <?= htmlspecialchars((string) ($slot['tanggal_tampil'] ?? '')) ?>
                                        <?php if (!empty($slot['tanggal_hijri'])): ?>
                                            <span class="text-muted d-block small"><?= htmlspecialchars((string) $slot['tanggal_hijri']) ?> H</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($slot['hari'] ?? '')) ?></td>
                                    <td class="text-nowrap"><?= htmlspecialchars((string) ($slot['jam'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($slot['tingkatan'] ?? '')) ?></td>
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
    document.addEventListener('click', function (ev) {
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
</style>
