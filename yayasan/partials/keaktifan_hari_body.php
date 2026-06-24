<?php

declare(strict_types=1);

/**
 * Isi dinamis keaktifan hari (dimuat via API).
 */
$khHeroSantri = rekap_keaktifan_hari_santri_agregat($detailKeg);
    $khHeroStatItems = [
        ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => (int) $totals['hadir']],
        ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => (int) $totals['izin']],
        ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => (int) $totals['sakit']],
        ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => (int) $totals['alpa']],
    ];
    ?>
    <div class="kh-hero kh-section" id="khHero">
        <div class="kh-hero__top">
            <div class="kh-hero__date"><?= htmlspecialchars($tglLabel) ?> &middot; <?= htmlspecialchars($kategoriLabel) ?><?= $tingkatan !== '' ? ' &middot; ' . htmlspecialchars($tingkatan) : '' ?></div>
            <div class="small text-muted"><?= count($detailKeg) ?> kegiatan &middot; <?= (int) $totals['total'] ?> entri (santri &times; kegiatan)</div>
        </div>
        <div class="kh-totals">
            <?php foreach ($khHeroStatItems as $hi): ?>
            <button type="button"
                class="kh-total-pill kh-total-pill--<?= htmlspecialchars($hi['key']) ?> kh-total-pill--clickable"
                data-kh-stat-tab="<?= htmlspecialchars($hi['tab']) ?>"
                data-kh-stat-scope="hero"
                aria-expanded="false"
                aria-haspopup="true">
                <?php if ($hi['key'] === 'hadir'): ?>
                <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
                <div class="kh-total-pill__pct"><?= number_format($totals['persen'], 1, ',', '.') ?>% hadir</div>
                <?php else: ?>
                <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
                <?php endif; ?>
                <div class="kh-total-pill__l"><?= htmlspecialchars($hi['label']) ?></div>
            </button>
            <?php endforeach; ?>
        </div>
        <script type="application/json" class="kh-santri-data kh-santri-data--hero"><?= json_encode($khHeroSantri, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <div class="kh-stat-popup d-none" data-kh-stat-popup data-kh-stat-scope="hero" role="region" aria-live="polite"></div>
        <div class="kh-legend">
            <span class="l-hadir">Hadir</span>
            <span class="l-izin">Izin</span>
            <span class="l-sakit">Sakit</span>
            <span class="l-alpa">Alpa</span>
        </div>
    </div>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-users-rectangle me-2"></i>Keaktifan Kelas <span class="fw-normal text-muted">(Masuk/Total)</span></h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-kelas" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-kelas" class="yp-mobile-detail">
        <?php if ($byTingkatan === []): ?>
            <div class="yp-empty-inline">Belum ada data kelas untuk filter ini.</div>
        <?php else: ?>
            <div class="yp-kelas-grid">
                <?php foreach ($byTingkatan as $tk): ?>
                    <?php
                    $full = (int) $tk['masuk'] === (int) $tk['total'] && (int) $tk['total'] > 0;
                    $kelasQs = ['tanggal' => $tanggal, 'tingkatan' => (string) ($tk['tingkatan'] ?? '')];
                    if ($kategori !== null) {
                        $kelasQs['kategori'] = $kategori;
                    }
                    $kelasHref = app_href('/yayasan/keaktifan_kelas.php?' . http_build_query($kelasQs));
                    ?>
                    <a class="yp-kelas-card text-decoration-none<?= $full ? ' yp-kelas-card--full' : '' ?>" href="<?= htmlspecialchars($kelasHref) ?>">
                        <div class="yp-kelas-card__head">
                            <div class="yp-kelas-card__tk">Kelas <?= htmlspecialchars((string) $tk['tingkatan']) ?></div>
                            <div class="yp-kelas-card__pct"><?= (int) round((float) ($tk['persen'] ?? 0)) ?>%</div>
                        </div>
                        <div class="yp-kelas-card__ratio"><strong><?= (int) $tk['masuk'] ?></strong>/<?= (int) $tk['total'] ?></div>
                        <div class="yp-kelas-card__sub">Santri hadir hari ini</div>
                        <div class="progress mt-2" style="height:6px">
                            <div class="progress-bar <?= $full ? 'bg-success' : 'bg-primary' ?>" style="width:<?= (float) $tk['persen'] ?>%"></div>
                        </div>
                        <?php if ($full): ?><div class="yp-kelas-card__badge"><i class="fa-solid fa-circle-check me-1"></i>Lengkap</div><?php else: ?><div class="yp-kelas-card__badge yp-kelas-card__badge--soft"><i class="fa-solid fa-chart-line me-1"></i>Perlu dipantau</div><?php endif; ?>
                        <div class="small text-primary mt-2"><i class="fa-solid fa-arrow-right me-1"></i>Lihat detail santri</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-user-tie me-2"></i>Pembimbing & Munawib <span class="fw-normal text-muted">(Masuk/Total)</span></h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-sdm" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-sdm" class="yp-mobile-detail">
        <div class="row g-3">
            <div class="col-md-6">
                <a class="yp-sdm-card" href="<?= htmlspecialchars(app_href('/yayasan/sdm_hari.php?role=pembimbing&tanggal=' . urlencode($tanggal))) ?>">
                    <div class="yp-sdm-card__head">
                        <span class="yp-sdm-card__label">Pembimbing</span>
                        <span class="yp-sdm-card__pct"><?= $pbPct ?>%</span>
                    </div>
                    <div class="yp-sdm-card__ratio"><?= (int) $pb['masuk'] ?>/<?= (int) $pb['total'] ?></div>
                    <div class="progress mt-2" style="height:6px"><div class="progress-bar bg-teal" style="width:<?= $pbPct ?>%;background:#0f766e"></div></div>
                    <div class="yp-sdm-card__hint">Ketuk untuk lihat yang belum datang <i class="fa-solid fa-arrow-right ms-1"></i></div>
                </a>
            </div>
            <div class="col-md-6">
                <a class="yp-sdm-card" href="<?= htmlspecialchars(app_href('/yayasan/sdm_hari.php?role=munawib&tanggal=' . urlencode($tanggal))) ?>">
                    <div class="yp-sdm-card__head">
                        <span class="yp-sdm-card__label">Munawib</span>
                        <span class="yp-sdm-card__pct"><?= $mwPct ?>%</span>
                    </div>
                    <div class="yp-sdm-card__ratio"><?= (int) $mw['masuk'] ?>/<?= (int) $mw['total'] ?></div>
                    <div class="progress mt-2" style="height:6px"><div class="progress-bar" style="width:<?= $mwPct ?>%;background:#0891b2"></div></div>
                    <div class="yp-sdm-card__hint">Ketuk untuk lihat yang belum datang <i class="fa-solid fa-arrow-right ms-1"></i></div>
                </a>
            </div>
        </div>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-clipboard-user me-2"></i>Riwayat Pembimbing Masuk</h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-riwayat" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-riwayat" class="yp-mobile-detail">
        <?php if ($riwayatPembimbingMasuk === []): ?>
            <div class="yp-empty-inline">Belum ada pembimbing yang scan masuk pada tanggal ini.</div>
        <?php else: ?>
            <div class="yp-riwayat-list">
                <?php foreach ($riwayatPembimbingMasuk as $rb): ?>
                    <article class="yp-riwayat-item">
                        <div class="yp-riwayat-item__main">
                            <div class="yp-riwayat-item__nama"><?= htmlspecialchars((string) ($rb['nama'] ?? '-')) ?></div>
                            <div class="yp-riwayat-item__meta">
                                <?= htmlspecialchars((string) ($rb['kegiatan'] ?? 'Kegiatan')) ?>
                                Â· <?= htmlspecialchars((string) (($rb['tingkatan'] ?? '') !== '' ? $rb['tingkatan'] : '-')) ?>
                                <?php if (!empty($rb['tempat'])): ?> Â· <?= htmlspecialchars((string) $rb['tempat']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="yp-riwayat-item__time"><?= htmlspecialchars((string) ($rb['jam'] ?? '--:--')) ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <section class="mb-4" id="yp-keaktifan-extra" data-lazy-extra="1">
        <h2 class="yp-section-title"><i class="fa-solid fa-triangle-exclamation me-2"></i>Kegiatan Kosong / Waspada</h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-kosong" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-kosong" class="yp-mobile-detail" data-extra-part="kosong">
            <div class="text-center text-muted py-3 small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Memuat data kegiatan kosongâ€¦</div>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-qrcode me-2"></i>Waktu tanpa scan hadir <span id="yp-tanpa-scan-count" class="text-muted">(…)</span></h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-tanpa-scan" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-tanpa-scan" class="yp-mobile-detail" data-extra-part="tanpa_scan">
            <div class="text-center text-muted py-3 small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Memuat jadwal tanpa scanâ€¦</div>
        </div>
    </section>

    <?php if ($detailKeg === []): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center text-muted py-5">
                <div class="display-6 mb-2 opacity-50"><i class="fa-regular fa-calendar-xmark"></i></div>
                <p class="mb-0 fw-semibold">Tidak ada kegiatan aktif untuk filter ini</p>
                <p class="small mb-0">Ubah kategori Jama'ah/Ta'lim, tanggal, atau tingkatan.</p>
            </div>
        </div>
    <?php else: ?>

    <section class="mb-4">
        <div class="card border-0 shadow-sm border-start border-4 border-primary">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="h6 mb-1"><i class="fa-solid fa-mosque me-2"></i>Detail per kegiatan</h2>
                    <p class="small text-muted mb-0">
                        Rincian santri per shalat/kegiatan (<?= count($detailKeg) ?> kegiatan) tidak ditampilkan di dashboard yayasan agar tetap ringan.
                    </p>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/rekap/keaktifan_hari.php?' . http_build_query(array_filter([
                    'tanggal' => $tanggal,
                    'tingkatan' => $tingkatan !== '' ? $tingkatan : null,
                    'kategori' => $kategori,
                ], static fn ($v) => $v !== null && $v !== '')))) ?>">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Buka rekap per kegiatan
                </a>
            </div>
        </div>
    </section>

    <?php endif; ?>

<script>
(function () {
    var mobileQuery = globalThis.matchMedia ? globalThis.matchMedia('(max-width: 767.98px)') : null;

    function syncMobileDetails() {
        var isMobile = mobileQuery ? mobileQuery.matches : (window.innerWidth <= 767);
        document.querySelectorAll('.yp-mobile-detail').forEach(function (box) {
            box.classList.toggle('is-open', !isMobile);
        });
        document.querySelectorAll('.yp-mobile-toggle').forEach(function (btn) {
            var targetId = btn.getAttribute('data-target') || '';
            var box = targetId ? document.getElementById(targetId) : null;
            if (!box) return;
            btn.classList.toggle('d-none', !isMobile);
            btn.setAttribute('aria-expanded', box.classList.contains('is-open') ? 'true' : 'false');
            btn.innerHTML = box.classList.contains('is-open')
                ? '<i class="fa-solid fa-eye-slash me-1"></i>Sembunyikan detail'
                : '<i class="fa-solid fa-list me-1"></i>Lihat detail';
        });
    }

    document.querySelectorAll('.yp-mobile-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target') || '';
            var box = targetId ? document.getElementById(targetId) : null;
            if (!box) return;
            var willOpen = !box.classList.contains('is-open');
            var isMobile = mobileQuery ? mobileQuery.matches : (window.innerWidth <= 767);
            if (isMobile && willOpen) {
                document.querySelectorAll('.yp-mobile-detail').forEach(function (otherBox) {
                    if (otherBox !== box) {
                        otherBox.classList.remove('is-open');
                    }
                });
            }
            box.classList.toggle('is-open', willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            btn.innerHTML = willOpen
                ? '<i class="fa-solid fa-eye-slash me-1"></i>Sembunyikan detail'
                : '<i class="fa-solid fa-list me-1"></i>Lihat detail';
            if (isMobile && willOpen) {
                document.querySelectorAll('.yp-mobile-toggle').forEach(function (otherBtn) {
                    if (otherBtn === btn) return;
                    var otherTargetId = otherBtn.getAttribute('data-target') || '';
                    var otherBox = otherTargetId ? document.getElementById(otherTargetId) : null;
                    if (!otherBox) return;
                    otherBtn.setAttribute('aria-expanded', 'false');
                    otherBtn.innerHTML = '<i class="fa-solid fa-list me-1"></i>Lihat detail';
                });
            }
        });
    });
    syncMobileDetails();

    var extraLoaded = false;
    var extraApi = <?= json_encode(app_href('/api/yayasan/keaktifan_hari_extra.php?' . http_build_query(array_filter([
        'tanggal' => $tanggal,
        'tingkatan' => $tingkatan !== '' ? $tingkatan : null,
        'kategori' => $kategori,
    ], static fn ($v) => $v !== null && $v !== ''))), JSON_UNESCAPED_UNICODE) ?>;

    function loadKeaktifanExtra() {
        if (extraLoaded || !extraApi) return;
        extraLoaded = true;
        fetch(extraApi, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                var kosong = document.querySelector('[data-extra-part="kosong"]');
                var tanpa = document.querySelector('[data-extra-part="tanpa_scan"]');
                var countEl = document.getElementById('yp-tanpa-scan-count');
                if (kosong && typeof data.kosong_html === 'string') kosong.innerHTML = data.kosong_html;
                if (tanpa && typeof data.tanpa_scan_html === 'string') tanpa.innerHTML = data.tanpa_scan_html;
                if (countEl) countEl.textContent = '(' + Number(data.jadwal_tanpa_scan_count || 0) + ' waktu)';
                syncMobileDetails();
            })
            .catch(function () { extraLoaded = false; });
    }

    if ('requestIdleCallback' in window) {
        requestIdleCallback(loadKeaktifanExtra, { timeout: 2000 });
    } else {
        setTimeout(loadKeaktifanExtra, 120);
    }

    if (mobileQuery && typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', syncMobileDetails);
    } else {
        window.addEventListener('resize', syncMobileDetails);
    }

})();
