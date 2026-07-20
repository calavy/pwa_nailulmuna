<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_ta_context.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_kartu_syahriyah.php';
require_once __DIR__ . '/../helpers/keuangan_cek_pembayaran.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';

require_roles(['admin', 'pengurus']);
keuangan_ensure_schema_deferred($pdo);

$keuanganTa = keuangan_ta_resolve($pdo);
$taMulai = (int) $keuanganTa['mulai'];
$taSelesai = (int) $keuanganTa['selesai'];
$berjalan = keuangan_periode_berjalan($pdo);
$bulanBerjalan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));

$q = trim((string) ($_GET['q'] ?? ''));
$santriId = (int) ($_GET['santri_id'] ?? 0);
$jenis = strtoupper(trim((string) ($_GET['jenis'] ?? 'BULANAN')));
if (!in_array($jenis, ['BULANAN', 'AWAL_TAHUN'], true)) {
    $jenis = 'BULANAN';
}
$namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

$santriPick = null;
$bulanRows = [];
$awalRows = [];
$totalBayar = 0;
$totalHarus = 0;

if ($santriId > 0 && table_exists($pdo, 'santri')) {
    $st = $pdo->prepare('SELECT id, ' . $namaCol . ' AS nama_santri, nis, tingkatan, kategori_kelas FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $santriPick = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($santriPick) {
        if ($jenis === 'AWAL_TAHUN') {
            $awalRows = keuangan_kartu_pembayaran_awal_tahun_rows($pdo, $santriId, $taMulai, $taSelesai);
            foreach ($awalRows as $ar) {
                $totalHarus += (int) ($ar['expected'] ?? 0);
                $totalBayar += min((int) ($ar['paid'] ?? 0), (int) ($ar['expected'] ?? 0));
            }
        } else {
            $bulanRows = keuangan_kartu_pembayaran_bulan_rows($pdo, $santriId, $taMulai, $taSelesai, $bulanBerjalan);
            foreach ($bulanRows as $br) {
                if (($br['status'] ?? '') === 'belum') {
                    continue;
                }
                $totalHarus += (int) ($br['harus'] ?? 0);
                $totalBayar += (int) ($br['bayar'] ?? 0);
            }
        }
    }
}

$kartuBaseUrl = static function (int $sid, string $j) use ($q): string {
    $params = ['santri_id' => $sid, 'jenis' => $j];
    if ($q !== '') {
        $params['q'] = $q;
    }

    return app_href('/pembayaran/kartu_syahriyah_santri.php?' . http_build_query($params));
};

$pageTitle = 'Kartu Pembayaran Santri';
$pageStylesheets = [app_asset_href('/assets/css/kartu-syahriyah.css')];
require_once __DIR__ . '/../includes/header.php';

$statusRowClass = static function (string $st): string {
    return match ($st) {
        'lunas', 'Lunas' => 'kp-row--lunas',
        'sebagian', 'Sebagian' => 'kp-row--sebagian',
        'belum_bayar', 'Belum' => 'kp-row--nunggak',
        'belum' => 'kp-row--future',
        default => '',
    };
};

$cellClass = static function (array $cell): string {
    $st = (string) ($cell['status'] ?? '—');
    if ($st === 'Lunas') {
        return 'kp-cell--lunas';
    }
    if ($st === 'Sebagian') {
        return 'kp-cell--sebagian';
    }
    if ($st === 'Belum') {
        return 'kp-cell--nunggak';
    }

    return 'kp-cell--empty';
};
?>

<div class="page-intro mb-3">
    <h1 class="h4 mb-1">Kartu pembayaran santri</h1>
    <p class="text-muted small mb-0">
        Ringkasan pembayaran per santri — tahun ajaran <?= (int) $taMulai ?>/<?= (int) $taSelesai ?>.
        Mode <strong>bulanan</strong>: saku, makan, dan syahriyah per bulan (hingga bulan berjalan).
        Mode <strong>awal tahun</strong>: komponen pembayaran awal tahun.
    </p>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <label class="form-label small mb-0" for="kartu-santri-q">Cari santri (nama / NIS)</label>
        <p class="small text-muted mb-2">Ketik beberapa huruf — daftar santri muncul otomatis.</p>
        <div class="kartu-santri-search-wrap position-relative">
            <input type="search" id="kartu-santri-q" class="form-control"
                   value="<?= $santriPick ? '' : htmlspecialchars($q) ?>"
                   placeholder="<?= $santriPick ? 'Ketik untuk ganti santri…' : 'Ketik nama atau NIS…' ?>"
                   autocomplete="off" autofocus
                   aria-controls="kartu-santri-results" aria-expanded="false">
            <div id="kartu-santri-results" class="list-group list-group-flush border rounded mt-2 shadow-sm kartu-santri-results d-none" role="listbox"></div>
        </div>
        <?php if ($santriPick): ?>
            <div class="mt-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/pembayaran/kartu_syahriyah_santri.php?jenis=' . urlencode($jenis))) ?>">
                    <i class="fa-solid fa-arrows-rotate me-1"></i>Ganti santri
                </a>
            </div>
        <?php endif; ?>
        <noscript>
            <form method="get" class="row g-2 align-items-end mt-2">
                <input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis) ?>">
                <div class="col-8">
                    <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Nama / NIS">
                </div>
                <div class="col-4">
                    <button type="submit" class="btn btn-primary w-100">Cari</button>
                </div>
            </form>
        </noscript>
    </div>
</div>

<?php if ($santriPick): ?>
    <div class="card shadow-sm border-primary mb-3 ks-santri-head">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h2 class="h5 mb-1"><?= htmlspecialchars((string) ($santriPick['nama_santri'] ?? '-')) ?></h2>
                    <div class="text-muted small">
                        NIS <?= htmlspecialchars((string) ($santriPick['nis'] ?? '-')) ?>
                        · <?= htmlspecialchars((string) ($santriPick['tingkatan'] ?? '-')) ?>
                        · <?= htmlspecialchars((string) ($santriPick['kategori_kelas'] ?? '-')) ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="small text-muted"><?= $jenis === 'AWAL_TAHUN' ? 'Total awal tahun' : 'Total terbayar (s/d bulan ini)' ?></div>
                    <div class="fs-5 fw-bold text-success">Rp <?= number_format($totalBayar, 0, ',', '.') ?></div>
                    <?php if ($totalHarus > $totalBayar): ?>
                        <div class="small text-danger">Kurang Rp <?= number_format($totalHarus - $totalBayar, 0, ',', '.') ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm" role="group" aria-label="Jenis kartu">
                    <a class="btn <?= $jenis === 'BULANAN' ? 'btn-primary' : 'btn-outline-primary' ?>"
                       href="<?= htmlspecialchars($kartuBaseUrl($santriId, 'BULANAN')) ?>">Bulanan</a>
                    <a class="btn <?= $jenis === 'AWAL_TAHUN' ? 'btn-primary' : 'btn-outline-primary' ?>"
                       href="<?= htmlspecialchars($kartuBaseUrl($santriId, 'AWAL_TAHUN')) ?>">Awal tahun</a>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php?santri_id=' . $santriId . '&jenis_periode=' . urlencode($jenis === 'AWAL_TAHUN' ? 'AWAL_TAHUN' : 'BULANAN'))) ?>">Input pembayaran</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(keuangan_riwayat_pembayaran_url_santri($santriId)) ?>">Riwayat</a>
            </div>
        </div>
    </div>

    <?php if ($jenis === 'AWAL_TAHUN'): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold">Pembayaran awal tahun</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 kp-table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Komponen</th>
                            <th class="text-end">Terbayar</th>
                            <th class="text-end">Tagihan</th>
                            <th class="text-end">Sisa</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($awalRows === []): ?>
                            <tr>
                                <td colspan="5" class="text-muted text-center py-4">Tidak ada komponen awal tahun untuk santri ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($awalRows as $ar): ?>
                                <?php $st = (string) ($ar['status'] ?? '—'); ?>
                                <tr class="<?= htmlspecialchars($statusRowClass($st)) ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars((string) ($ar['nama'] ?? '-')) ?></td>
                                    <td class="text-end">Rp <?= number_format((int) ($ar['paid'] ?? 0), 0, ',', '.') ?></td>
                                    <td class="text-end text-muted">Rp <?= number_format((int) ($ar['expected'] ?? 0), 0, ',', '.') ?></td>
                                    <td class="text-end"><?= ((int) ($ar['sisa'] ?? 0)) > 0 ? 'Rp ' . number_format((int) $ar['sisa'], 0, ',', '.') : '—' ?></td>
                                    <td><span class="badge kp-badge"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($awalRows !== []): ?>
                        <tfoot class="table-light">
                            <tr>
                                <th>Total</th>
                                <th class="text-end">Rp <?= number_format($totalBayar, 0, ',', '.') ?></th>
                                <th class="text-end">Rp <?= number_format($totalHarus, 0, ',', '.') ?></th>
                                <th class="text-end"><?= $totalHarus > $totalBayar ? 'Rp ' . number_format($totalHarus - $totalBayar, 0, ',', '.') : '—' ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold">Pembayaran bulanan</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 kp-table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:8rem">Bulan</th>
                            <th class="text-end">Saku</th>
                            <th class="text-end">Makan</th>
                            <th class="text-end">Syahriyah</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bulanRows as $br): ?>
                            <?php
                            $st = (string) ($br['status'] ?? 'belum');
                            $future = $st === 'belum';
                            ?>
                            <tr class="<?= htmlspecialchars($statusRowClass($st)) ?>">
                                <td class="fw-semibold"><?= htmlspecialchars((string) ($br['label'] ?? '')) ?></td>
                                <td class="text-end <?= htmlspecialchars($cellClass($future ? [] : (array) ($br['saku'] ?? []))) ?>">
                                    <?= htmlspecialchars(keuangan_kartu_pembayaran_format_cell((array) ($br['saku'] ?? []), $future)) ?>
                                </td>
                                <td class="text-end <?= htmlspecialchars($cellClass($future ? [] : (array) ($br['makan'] ?? []))) ?>">
                                    <?= htmlspecialchars(keuangan_kartu_pembayaran_format_cell((array) ($br['makan'] ?? []), $future)) ?>
                                </td>
                                <td class="text-end <?= htmlspecialchars($cellClass($future ? [] : (array) ($br['syahriyah'] ?? []))) ?>">
                                    <?= htmlspecialchars(keuangan_kartu_pembayaran_format_cell((array) ($br['syahriyah'] ?? []), $future)) ?>
                                    <?php if (!$future && (int) ($br['pkpps'] ?? 0) > 0): ?>
                                        <div class="small text-teal">+PKPPS <?= number_format((int) $br['pkpps'], 0, ',', '.') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($future): ?>
                                        <span class="badge text-bg-light text-muted">Belum</span>
                                    <?php else: ?>
                                        <span class="badge kp-badge"><?= htmlspecialchars((string) ($br['keterangan'] ?? '')) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Total (s/d bulan ini)</th>
                            <th colspan="3" class="text-end">
                                Terbayar Rp <?= number_format($totalBayar, 0, ',', '.') ?>
                                <?php if ($totalHarus > 0): ?>
                                    <span class="text-muted"> / tagihan Rp <?= number_format($totalHarus, 0, ',', '.') ?></span>
                                <?php endif; ?>
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer small text-muted">
                Angka sel = terbayar / tagihan (jika belum lunas). Bulan mendatang bertanda —.
            </div>
        </div>
    <?php endif; ?>
<?php elseif ($santriId > 0): ?>
    <div class="alert alert-warning">Santri tidak ditemukan.</div>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fa-solid fa-id-card fa-2x mb-2 opacity-50"></i>
        <p class="mb-0 small">Cari dan pilih santri untuk menampilkan kartu pembayaran.</p>
    </div>
<?php endif; ?>

<script>
(function () {
    var input = document.getElementById('kartu-santri-q');
    var results = document.getElementById('kartu-santri-results');
    if (!input || !results) {
        return;
    }
    var searchUrl = <?= json_encode(app_href('/api/keuangan/santri_search.php'), JSON_UNESCAPED_UNICODE) ?>;
    var kartuBase = <?= json_encode(app_href('/pembayaran/kartu_syahriyah_santri.php'), JSON_UNESCAPED_UNICODE) ?>;
    var jenis = <?= json_encode($jenis, JSON_UNESCAPED_UNICODE) ?>;
    var timer = null;
    var seq = 0;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function kartuUrl(id) {
        return kartuBase + '?santri_id=' + encodeURIComponent(String(id)) + '&jenis=' + encodeURIComponent(jenis);
    }

    function setOpen(open) {
        results.classList.toggle('d-none', !open);
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function renderItems(items) {
        results.innerHTML = '';
        if (!items.length) {
            if (input.value.trim().length >= 1) {
                results.innerHTML = '<div class="list-group-item small text-muted">Santri tidak ditemukan.</div>';
                setOpen(true);
            } else {
                setOpen(false);
            }
            return;
        }
        items.forEach(function (item) {
            var a = document.createElement('a');
            a.href = kartuUrl(item.id);
            a.className = 'list-group-item list-group-item-action';
            a.setAttribute('role', 'option');
            a.innerHTML = '<div class="fw-semibold">' + esc(item.nama || item.label || '-') + '</div>'
                + '<div class="small text-muted">NIS ' + esc(item.nis || '-') + '</div>';
            results.appendChild(a);
        });
        setOpen(true);
    }

    function fetchSantri(q) {
        var s = ++seq;
        fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&limit=15', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (s !== seq) {
                    return;
                }
                renderItems((data && data.ok) ? (data.items || []) : []);
            })
            .catch(function () {
                if (s === seq) {
                    setOpen(false);
                }
            });
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 1) {
            seq++;
            results.innerHTML = '';
            setOpen(false);
            return;
        }
        timer = setTimeout(function () {
            fetchSantri(q);
        }, 220);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var first = results.querySelector('a.list-group-item-action');
            if (first) {
                window.location.href = first.href;
            }
        }
        if (e.key === 'Escape') {
            results.innerHTML = '';
            setOpen(false);
        }
    });

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== input) {
            setOpen(false);
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
