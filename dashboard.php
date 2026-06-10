<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/akademik.php';
require_once __DIR__ . '/helpers/hijri_kalender.php';
require_once __DIR__ . '/helpers/akademik_hari_khusus.php';
require_once __DIR__ . '/helpers/akademik_pasaran.php';
require_once __DIR__ . '/helpers/santri_operasional.php';

require_once __DIR__ . '/helpers/mukimin.php';
require_once __DIR__ . '/helpers/dashboard_menu.php';
require_once __DIR__ . '/helpers/jadwal_ui.php';
require_once __DIR__ . '/helpers/user_profil.php';
require_once __DIR__ . '/helpers/pembimbing_dashboard.php';

// Pembimbing & pengasuh punya dashboard khusus.
if (isset($_SESSION['user'])) {
    $currentRole = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($currentRole === 'pembimbing' && !(function_exists('is_super_admin') && is_super_admin())) {
        app_redirect('pembimbing/dashboard.php');
    }
    if ($currentRole === 'kiai') {
        app_redirect('pengasuh/dashboard.php');
    }
}

require_roles(['admin', 'pengurus', 'petugas_absensi', 'kiai']);

$today = date('Y-m-d');

ensure_hijri_mappings_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
$hijriBulanNamaDash = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
$dashSyncKey = 'dashboard_hijri_sync_' . date('Y-m-d');
if (empty($_SESSION[$dashSyncKey])) {
    hijri_sync_from_akademik_awal_bulan($pdo);
    akademik_libur_sinkron_hari_khusus_tahun($pdo, (int) date('Y'), $hijriBulanNamaDash);
    $_SESSION[$dashSyncKey] = 1;
}
$dashHijriLabel = akademik_hijri_label_dari_masehi($pdo, $today, $hijriBulanNamaDash);
$dashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';
$nowTime = date('H:i:s');
$hariKe = (int) date('N');

$putra = 0;
$putri = 0;
if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'jenis_kelamin')) {
    $aktifSql = '';
    if (column_exists($pdo, 'santri', 'status_santri')) {
        $aktifSql = ' WHERE ' . santri_sql_aktif_only('santri');
    } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
        $aktifSql = ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $row = $pdo->query(
        'SELECT
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra,
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri
         FROM santri' . $aktifSql
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $putra = (int) ($row['putra'] ?? 0);
    $putri = (int) ($row['putri'] ?? 0);
}

$mukiminCount = mukimin_count($pdo);

$izinAktifCount = 0;
$izinAktifRows = [];
$sqlAktifSantri = santri_sql_aktif_only('s');
if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
    $approvalSql = '';
    if (column_exists($pdo, 'perizinan', 'approval_status')) {
        $approvalSql = ' AND i.approval_status = "DISETUJUI"';
    }
    $cntStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifSantri . '
         WHERE i.status_izin = "IZIN"
           AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql
    );
    $cntStmt->execute(['today' => $today]);
    $izinAktifCount = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, s.nama_santri, s.nis, s.tingkatan
         FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifSantri . '
         WHERE i.status_izin = "IZIN"
           AND :today2 BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql . '
         ORDER BY s.nama_santri ASC
         LIMIT 24'
    );
    $stmt->execute(['today2' => $today]);
    $izinAktifRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$kegiatanAktif = [];
$kegiatanAktifGrouped = [];
$kegiatanAktifPresensi = [];
if (table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
    ensure_jadwal_kegiatan_tempat($pdo);
    $pbSelect = '';
    $pbJoin = '';
    if (column_exists($pdo, 'jadwal_kegiatan', 'pembimbing_id') && table_exists($pdo, 'pembimbing')) {
        $pbSelect = ', j.pembimbing_id, p.nama_pembimbing';
        $pbJoin = ' LEFT JOIN pembimbing p ON p.id = j.pembimbing_id';
    }
    $stmt = $pdo->prepare(
        'SELECT k.id AS kegiatan_id, k.nama_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, j.tempat'
        . $pbSelect . '
         FROM jadwal_kegiatan j
         INNER JOIN kegiatan k ON k.id = j.kegiatan_id'
        . $pbJoin . '
         WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
           AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
           AND k.is_active = 1
         ORDER BY j.jam_mulai ASC, j.tingkatan ASC'
    );
    $stmt->execute(['hari_ke' => $hariKe, 'jam_now' => $nowTime]);
    $kegiatanAktif = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$kegiatanAktifGrouped = jadwal_kelompokkan_kegiatan_aktif($kegiatanAktif);
if ($kegiatanAktifGrouped !== []) {
    // Tampilan saja — finalize presensi berat; dijalankan di scan/rekap/cron.
    $kegiatanAktifPresensi = pembimbing_dashboard_presensi_kegiatan_berlangsung($pdo, $kegiatanAktifGrouped, $today, false);
}
/** Anchor jam live agar selaras dengan waktu server yang dipakai query jadwal (bukan jam lokal browser). */
$dashServerClockMs = (int) round(microtime(true) * 1000);

$iconForPath = static function (string $path): string {
    if (str_contains($path, 'dashboard')) {
        return 'fa-solid fa-house';
    }
    if (str_contains($path, 'santri')) {
        return 'fa-solid fa-user-group';
    }
    if (str_contains($path, 'wali')) {
        return 'fa-solid fa-people-roof';
    }
    if (str_contains($path, 'pembimbing')) {
        return 'fa-solid fa-chalkboard-user';
    }
    if (str_contains($path, 'presensi')) {
        return 'fa-solid fa-qrcode';
    }
    if (str_contains($path, 'jadwal')) {
        return 'fa-solid fa-calendar-days';
    }
    if (str_contains($path, 'akademik')) {
        return 'fa-solid fa-book';
    }
    if (str_contains($path, 'perizinan')) {
        return 'fa-solid fa-person-walking-arrow-right';
    }
    if (str_contains($path, 'admin')) {
        return 'fa-solid fa-file-lines';
    }
    if (str_contains($path, 'poin')) {
        return 'fa-solid fa-star';
    }
    if (str_contains($path, 'keuangan') || str_contains($path, 'pembayaran')) {
        return 'fa-solid fa-wallet';
    }
    if (str_contains($path, 'rekap')) {
        return 'fa-solid fa-chart-column';
    }
    if (str_contains($path, 'settings')) {
        return 'fa-solid fa-gear';
    }
    return 'fa-solid fa-arrow-right';
};

$jamServerLabel = substr($nowTime, 0, 5);
$namaUser = trim((string) ($_SESSION['user']['nama'] ?? ''));
$labelUser = $namaUser !== '' ? $namaUser : 'Bapak/Ibu';
$brandDash = app_header_brand_context($pdo);
$namaPonpes = (string) ($brandDash['title'] ?? 'Pondok Pesantren');
$alamatPonpes = (string) ($brandDash['alamat'] ?? '');
$dashLogo = (string) ($brandDash['logo'] ?? '');
$dashLogoHref = app_pondok_logo_href($pdo);
$dashHeroKicker = (string) ($brandDash['tagline'] ?? '');
$dashLogoInitial = (string) ($brandDash['initials'] ?? 'AP');

$menuPack = app_menu_pack($pdo);
$dashMenuItems = $menuPack['menuItems'];
$dashSearchItems = dashboard_build_search_items($dashMenuItems, $iconForPath);
$sideQuickActions = dashboard_filter_quick_actions($dashMenuItems);
$sideQuickCount = count($sideQuickActions);

$canJadwal = user_can_access_menu_path('/jadwal/index.php', $dashMenuItems);
$canPerizinan = user_can_access_menu_path('/perizinan/index.php', $dashMenuItems);
$pageTitle = 'Dashboard';
$bodyClass = 'dash-page dash-home-mobile-fit';
$loadPushFcm = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="dash-page">
    <div class="dash-hero mb-4">
        <div class="dash-hero-inner">
            <?php
            $brandTitle = $namaPonpes;
            $brandKicker = $dashHeroKicker;
            $brandAlamat = $alamatPonpes;
            $brandLogoHref = $dashLogoHref;
            $brandLogoInitial = $dashLogoInitial;
            require __DIR__ . '/includes/partials/dash_hero_brand.php';
            ?>
            <div class="dash-hero-layout dash-hero-layout--slim">
                <div class="dash-hero-greeting">
                    <div class="dash-hero-kicker text-white-50">Beranda</div>
                    <h1 class="h3 dash-hero-title mb-2"><?= htmlspecialchars($labelUser) ?></h1>
                    <?php if ($dashHijriLabel !== ''): ?>
                        <p class="dash-hero-hijri mb-0 small text-white-50 d-none d-md-block">
                            <i class="fa-solid fa-moon" aria-hidden="true"></i>
                            <strong class="text-white"><?= htmlspecialchars($dashHijriLabel) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="dash-hero-clock" aria-live="polite">
                    <div class="dash-hero-clock__top">
                        <span class="dash-hero-clock__label"><i class="fa-regular fa-clock me-1"></i> Waktu berjalan</span>
                        <span class="dash-hero-clock__live">Live</span>
                    </div>
                    <div class="dash-hero-clock__time" id="dashboard-live-clock">--:--:--</div>
                    <div class="dash-hero-clock__date" id="dashboard-live-date"<?= $dashPasaran !== '' ? ' data-pasaran="' . htmlspecialchars($dashPasaran) . '"' : '' ?>>—</div>
                </div>
            </div>
        </div>
    </div>

    <div class="dash-kpi-grid mb-4" role="list" aria-label="Ringkasan data santri">
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--putra h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-person"></i></div>
                <div class="dash-kpi-box__label">Santri Putra</div>
                <div class="dash-kpi-box__value"><?= (int) $putra ?></div>
                <div class="dash-kpi-box__hint">Jumlah aktif</div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--putri h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-person-dress"></i></div>
                <div class="dash-kpi-box__label">Santri Putri</div>
                <div class="dash-kpi-box__value"><?= (int) $putri ?></div>
                <div class="dash-kpi-box__hint">Jumlah aktif</div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--mukimin h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-book-open-reader"></i></div>
                <div class="dash-kpi-box__label">Data Mukimin</div>
                <div class="dash-kpi-box__value"><?= (int) $mukiminCount ?></div>
                <div class="dash-kpi-box__hint">Santri non aktif</div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--izin h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-person-walking-luggage"></i></div>
                <div class="dash-kpi-box__label">Sedang izin</div>
                <div class="dash-kpi-box__value"><?= (int) $izinAktifCount ?></div>
                <div class="dash-kpi-box__hint">Hari ini</div>
            </div>
        </div>
    </div>

    <div class="dash-layout-grid mb-4">
        <section class="dash-layout-main">
            <div class="card border-0 shadow-sm h-100 dash-panel dash-panel--lift">
                <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-start gap-2 pt-4 px-4 pb-0">
                    <div>
                        <h2 class="h5 mb-1">Kegiatan berlangsung</h2>
                        <p class="small text-muted mb-0">Jadwal pada slot waktu server sekarang (<?= htmlspecialchars($jamServerLabel) ?> WIB)</p>
                    </div>
                    <?php if ($canJadwal): ?>
                    <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="btn btn-sm btn-outline-primary rounded-pill">Jadwal lengkap</a>
                    <?php endif; ?>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <?php if ($kegiatanAktifPresensi !== []): ?>
                        <?php require __DIR__ . '/includes/partials/dashboard_kegiatan_berlangsung_live.php'; ?>
                    <?php elseif ($kegiatanAktifGrouped === []): ?>
                        <div class="dash-empty-chart py-5 text-center text-muted">
                            <div class="dash-empty-chart__inner">
                                <div class="dash-empty-chart__icon display-6 opacity-50" aria-hidden="true"><i class="fa-regular fa-calendar"></i></div>
                                <p class="mb-0 fw-semibold">Belum ada kegiatan di jam <?= htmlspecialchars($jamServerLabel) ?>.</p>
                                <p class="small mb-0 mt-1">Ini normal jika tidak ada jadwal aktif di database untuk slot ini — bukan karena offline. Cek <a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>" class="alert-link">jadwal lengkap</a> atau ubah jam di jadwal kegiatan.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($kegiatanAktifGrouped as $namaKegiatan => $slotRows): ?>
                                <div class="dash-jadwal-row dash-jadwal-row--compact">
                                    <div class="dash-jadwal-row-main">
                                        <span class="dash-jadwal-nama"><?= htmlspecialchars($namaKegiatan) ?></span>
                                        <span class="dash-jadwal-time">
                                            <i class="fa-regular fa-clock me-1 opacity-75"></i>
                                            <?= htmlspecialchars(substr((string) ($slotRows[0]['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($slotRows[0]['jam_selesai'] ?? ''), 0, 5)) ?>
                                        </span>
                                    </div>
                                    <div class="dash-jadwal-tingkatan-wrap">
                                        <?php foreach ($slotRows as $kg): ?>
                                            <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge"><?= htmlspecialchars((string) ($kg['tingkatan'] ?? '—')) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php
                                    $tempatList = array_values(array_unique(array_filter(array_map(
                                        static fn(array $r): string => trim((string) ($r['tempat'] ?? '')),
                                        $slotRows
                                    ))));
                                    ?>
                                    <?php if ($tempatList !== []): ?>
                                        <div class="dash-jadwal-meta dash-jadwal-tempat small">
                                            <i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars(implode(' · ', $tempatList)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <aside class="dash-layout-aside">
            <div class="card border-0 shadow-sm h-100 dash-panel dash-panel-side dash-panel--lift">
                <?php if ($dashSearchItems !== []): ?>
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 <?= $sideQuickCount === 0 ? 'pb-4' : '' ?>">
                    <h2 class="h6 mb-1">Pencarian cepat</h2>
                    <p class="small text-muted mb-2">Cari modul menu</p>
                    <div class="dash-aside-search" id="dash-menu-search-wrap">
                        <label class="visually-hidden" for="dash-menu-search-input">Pencarian cepat modul</label>
                        <span class="dash-aside-search__ico" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input
                            type="search"
                            id="dash-menu-search-input"
                            class="dash-aside-search__input form-control"
                            placeholder="Ketik nama modul…"
                            autocomplete="off"
                            enterkeyhint="search"
                            role="combobox"
                            aria-expanded="false"
                            aria-controls="dash-menu-search-results"
                            aria-autocomplete="list"
                        >
                        <div class="dash-aside-search__results" id="dash-menu-search-results" role="listbox" hidden></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($sideQuickCount > 0): ?>
                <div class="card-header bg-transparent border-0 <?= $dashSearchItems !== [] ? 'pt-3' : 'pt-4' ?> px-4 pb-0 <?= $dashSearchItems !== [] ? 'border-top' : '' ?>">
                    <h2 class="h5 mb-1">Aksi cepat</h2>
                    <p class="small text-muted mb-0">Modul yang sering dipakai</p>
                </div>
                <div class="card-body px-4 pb-4 pt-3 d-flex flex-column gap-2">
                    <?php foreach ($sideQuickActions as $act): ?>
                        <a href="<?= htmlspecialchars(app_rewrite_internal_url($act['path'])) ?>" class="<?= htmlspecialchars($act['class']) ?>">
                            <i class="fa-solid <?= htmlspecialchars($act['icon']) ?> me-2"></i> <?= htmlspecialchars($act['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($dashSearchItems === []): ?>
                <div class="card-body px-4 pb-4 pt-3">
                    <p class="small text-muted mb-0">Gunakan menu modul di samping untuk membuka fitur yang tersedia.</p>
                </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <?php if ($izinAktifRows !== []): ?>
        <div class="card border-0 shadow-sm mb-4 dash-panel dash-panel--lift">
            <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-4 px-4 pb-0">
                <div>
                    <h2 class="h5 mb-1">Santri sedang izin</h2>
                    <p class="small text-muted mb-0">Disetujui · hari ini</p>
                </div>
                <?php if ($canPerizinan): ?>
                <a href="<?= htmlspecialchars(app_href('/perizinan/index.php')) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">Kelola perizinan</a>
                <?php endif; ?>
            </div>
            <div class="card-body px-4 pb-4 pt-2">
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Nama</th>
                                <th>NIS</th>
                                <th>Tingkatan</th>
                                <th class="pe-3">Jenis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($izinAktifRows as $ir): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= htmlspecialchars((string) ($ir['nama_santri'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($ir['nis'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($ir['tingkatan'] ?? '')) ?></td>
                                    <td class="pe-3"><span class="badge text-bg-light border"><?= htmlspecialchars(jenis_izin_label((string) ($ir['jenis_izin'] ?? ''))) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    (function () {
        const searchItems = <?= json_encode(array_map(static function (array $item): array {
            return [
                'path' => app_rewrite_internal_url((string) $item['path']),
                'label' => (string) $item['label'],
                'icon' => (string) $item['icon'],
            ];
        }, $dashSearchItems), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const searchWrap = document.getElementById('dash-menu-search-wrap');
        const searchInput = document.getElementById('dash-menu-search-input');
        const searchResults = document.getElementById('dash-menu-search-results');
        if (searchWrap && searchInput && searchResults && searchItems.length) {
            let activeIdx = -1;
            const norm = (s) => (s || '').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');

            function closeResults() {
                searchResults.hidden = true;
                searchInput.setAttribute('aria-expanded', 'false');
                activeIdx = -1;
            }

            function openResults() {
                searchResults.hidden = false;
                searchInput.setAttribute('aria-expanded', 'true');
            }

            function renderResults(query) {
                const q = norm(query.trim());
                const matches = q === ''
                    ? searchItems.slice(0, 12)
                    : searchItems.filter((item) => norm(item.label).includes(q) || norm(item.path).includes(q)).slice(0, 16);

                searchResults.innerHTML = '';
                if (matches.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'dash-aside-search__empty';
                    empty.textContent = 'Tidak ada modul yang cocok.';
                    searchResults.appendChild(empty);
                    openResults();
                    return;
                }

                matches.forEach((item, idx) => {
                    const row = document.createElement('a');
                    row.className = 'dash-aside-search__item';
                    row.href = item.path;
                    row.setAttribute('role', 'option');
                    row.dataset.idx = String(idx);
                    const ico = document.createElement('i');
                    ico.className = item.icon;
                    ico.setAttribute('aria-hidden', 'true');
                    const lbl = document.createElement('span');
                    lbl.textContent = item.label;
                    row.append(ico, lbl);
                    searchResults.appendChild(row);
                });
                openResults();
            }

            function setActive(idx) {
                const rows = searchResults.querySelectorAll('.dash-aside-search__item');
                rows.forEach((r) => r.classList.remove('is-active'));
                activeIdx = idx;
                if (idx >= 0 && rows[idx]) {
                    rows[idx].classList.add('is-active');
                    rows[idx].scrollIntoView({ block: 'nearest' });
                }
            }

            searchInput.addEventListener('focus', () => renderResults(searchInput.value));
            searchInput.addEventListener('input', () => renderResults(searchInput.value));
            searchInput.addEventListener('keydown', (e) => {
                const rows = searchResults.querySelectorAll('.dash-aside-search__item');
                if (e.key === 'Escape') {
                    closeResults();
                    return;
                }
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setActive(Math.min(activeIdx + 1, rows.length - 1));
                    return;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(Math.max(activeIdx - 1, 0));
                    return;
                }
                if (e.key === 'Enter' && activeIdx >= 0 && rows[activeIdx]) {
                    e.preventDefault();
                    window.location.href = rows[activeIdx].href;
                }
            });
            document.addEventListener('click', (e) => {
                if (!searchWrap.contains(e.target)) {
                    closeResults();
                }
            });
        }

        window.PONDOK_SERVER_CLOCK_MS = <?= (int) $dashServerClockMs ?>;
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
