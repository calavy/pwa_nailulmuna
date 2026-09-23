<?php

declare(strict_types=1);

/** @var int $totalSantri */
/** @var bool $isMunawibPortal */
/** @var list<array<string,mixed>> $santriIzinHariIni */
/** @var int $jumlahIzinHariIni */

$totalSantri = (int) ($totalSantri ?? 0);
$isMunawibPortal = !empty($isMunawibPortal);
$santriIzinHariIni = is_array($santriIzinHariIni ?? null) ? $santriIzinHariIni : [];
$jumlahIzinHariIni = (int) ($jumlahIzinHariIni ?? 0);
$perizinanHref = app_href('/pembimbing/perizinan.php');
$izinListCount = count($santriIzinHariIni);
$izinSisa = max(0, $jumlahIzinHariIni - $izinListCount);
?>
<footer class="pb-dash-simple-footer pb-dash-simple-footer--stacked" aria-label="Ringkasan bimbingan">
    <div class="pb-dash-simple-footer__stat pb-dash-simple-footer__stat--center">
        <span class="pb-dash-simple-footer__stat-icon" aria-hidden="true"><i class="fa-solid fa-users"></i></span>
        <span class="pb-dash-simple-footer__stat-body">
            <strong class="pb-dash-simple-footer__stat-value"><?= $totalSantri ?></strong>
            <span class="pb-dash-simple-footer__stat-label">santri dibimbing</span>
        </span>
    </div>

    <?php if (!$isMunawibPortal && $jumlahIzinHariIni > 0): ?>
    <section class="pb-dash-simple-footer__izin" aria-label="Santri sedang izin">
        <button type="button"
                class="pb-dash-simple-footer__izin-toggle js-pb-footer-izin-toggle"
                aria-expanded="false"
                aria-controls="pb-footer-izin-panel">
            <span class="pb-dash-simple-footer__izin-toggle-text">
                <span class="pb-dash-simple-footer__izin-title">Sedang izin hari ini</span>
                <span class="pb-dash-simple-footer__izin-badge"><?= $jumlahIzinHariIni ?></span>
            </span>
            <span class="pb-dash-simple-footer__izin-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <div id="pb-footer-izin-panel" class="pb-dash-simple-footer__izin-panel" hidden>
            <ul class="pb-dash-simple-footer__izin-list list-unstyled mb-0">
                <?php foreach ($santriIzinHariIni as $izRow): ?>
                    <?php
                    $jenisRaw = trim((string) ($izRow['jenis_izin'] ?? 'KELUAR'));
                    $jenisLabel = function_exists('jenis_izin_label') ? jenis_izin_label($jenisRaw) : $jenisRaw;
                    $tkLabel = trim((string) ($izRow['tingkatan'] ?? ''));
                    ?>
                    <li class="pb-dash-simple-footer__izin-item">
                        <span class="pb-dash-simple-footer__izin-name"><?= htmlspecialchars((string) ($izRow['nama_santri'] ?? '—')) ?></span>
                        <span class="pb-dash-simple-footer__izin-meta">
                            <?php if ($tkLabel !== ''): ?>
                                <?= htmlspecialchars($tkLabel) ?>
                            <?php endif; ?>
                            <?php if ($tkLabel !== '' && $jenisLabel !== ''): ?> · <?php endif; ?>
                            <?php if ($jenisLabel !== ''): ?>
                                <?= htmlspecialchars($jenisLabel) ?>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($izinSisa > 0): ?>
                <p class="pb-dash-simple-footer__izin-more small text-muted mb-0">+ <?= $izinSisa ?> santri lainnya</p>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($perizinanHref) ?>" class="pb-dash-simple-footer__izin-link small">Lihat perizinan</a>
        </div>
    </section>
    <script>
    (function () {
        var toggle = document.querySelector('.js-pb-footer-izin-toggle');
        var panel = document.getElementById('pb-footer-izin-panel');
        if (!toggle || !panel) return;
        toggle.addEventListener('click', function () {
            var open = panel.hidden;
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.classList.toggle('is-open', open);
        });
    })();
    </script>
    <?php endif; ?>
</footer>
