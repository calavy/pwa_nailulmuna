<?php

declare(strict_types=1);

/**
 * @var PDO $pdo
 * @var array<string, string> $tiers
 * @var list<array<string, mixed>> $kelasMakanRows
 * @var string $makanPosNama
 * @var int $taMulaiTarifBulan
 * @var int $taSelesaiTarifBulan
 * @var array<int, string> $bulanLabelsShort
 */
$makanPosNama = keuangan_makan_pos_nama($pdo);
$kelasMakanRows = kelas_keuangan_list_active($pdo);
?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Nama &amp; tarif default per kelas keuangan</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Atur <strong>nama tampilan</strong> komponen makan di kuitansi/tagihan, dan <strong>nominal khusus per kelas keuangan</strong>
            (nama/kode di <a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">Kelas keuangan</a>).
            Kosongkan nominal kelas = pakai tarif tier (Muadalah/Wustho/Ulya) di tab
            <a href="?bagian=syahriyah_makan">Syahriyah &amp; makan</a>.
        </p>
        <form method="post">
            <input type="hidden" name="action" value="save_makan_pengaturan">
            <div class="mb-3">
                <label class="form-label small mb-1">Nama tampilan komponen</label>
                <input type="text" name="keuangan_pos_nama_makan" class="form-control form-control-sm" maxlength="120"
                       value="<?= htmlspecialchars($makanPosNama) ?>" required placeholder="Makan">
                <div class="form-text">Contoh: Makan, Uang Makan, Konsumsi — muncul di kuitansi, portal wali, dan form pembayaran.</div>
            </div>
            <?php if ($kelasMakanRows === []): ?>
                <div class="alert alert-warning small mb-0">
                    Belum ada kelas keuangan. <a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">Tambahkan kelas keuangan</a> terlebih dahulu.
                </div>
            <?php else: ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kelas keuangan</th>
                                <th>Tier tarif</th>
                                <th class="text-end" style="min-width:8rem">Fallback tier</th>
                                <th class="text-end" style="min-width:9rem">Nominal khusus (default)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($kelasMakanRows as $row):
                            $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
                            if ($kode === '') {
                                continue;
                            }
                            $nama = trim((string) ($row['nama_tampilan'] ?? $kode));
                            $tier = strtolower(trim((string) ($row['tarif_keuangan_tier'] ?? 'wustho')));
                            $fallback = keuangan_makan_tier_fallback_nominal($pdo, $tier);
                            $override = keuangan_makan_kelas_override_nominal($pdo, $kode, 0);
                            $val = $override !== null ? (string) $override : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($nama) ?></div>
                                    <div class="small text-muted font-monospace"><?= htmlspecialchars($kode) ?></div>
                                </td>
                                <td class="small"><?= htmlspecialchars($tiers[$tier] ?? ucfirst($tier)) ?></td>
                                <td class="text-end font-monospace small text-muted">Rp <?= number_format($fallback, 0, ',', '.') ?></td>
                                <td>
                                    <input type="text" class="form-control form-control-sm text-end"
                                           name="makan_kelas[<?= htmlspecialchars($kode) ?>][default]"
                                           value="<?= htmlspecialchars($val) ?>"
                                           placeholder="<?= htmlspecialchars(number_format($fallback, 0, ',', '.')) ?>"
                                           inputmode="numeric"
                                           title="Kosongkan untuk pakai fallback tier">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="btn-toggle-makan-bulan" aria-expanded="false">
                    Ubah per bulan tagihan
                </button>
                <div id="makan-bulan-panel" class="d-none">
                    <p class="small text-muted">Isi hanya bulan yang nominal berbeda dari default kelas di atas. Kosongkan = pakai default kelas atau fallback tier.</p>
                    <?php foreach ($kelasMakanRows as $row):
                        $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
                        if ($kode === '') {
                            continue;
                        }
                        $nama = trim((string) ($row['nama_tampilan'] ?? $kode));
                        ?>
                        <h3 class="h6 mt-3 mb-2 text-secondary"><?= htmlspecialchars($nama) ?> <span class="text-muted fw-normal">(<?= htmlspecialchars($kode) ?>)</span></h3>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width:9rem">Bulan</th>
                                        <th class="text-end" style="min-width:8rem">Nominal khusus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php for ($b = 1; $b <= 12; $b++):
                                    $label = (string) ($bulanLabelsShort[$b] ?? ('Bulan ' . $b));
                                    $ovBulan = keuangan_makan_kelas_override_nominal($pdo, $kode, $b);
                                    $valB = $ovBulan !== null ? (string) $ovBulan : '';
                                    ?>
                                    <tr>
                                        <td class="small fw-semibold"><?= htmlspecialchars($label) ?></td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm text-end"
                                                   name="makan_kelas[<?= htmlspecialchars($kode) ?>][bulan][<?= $b ?>]"
                                                   value="<?= htmlspecialchars($valB) ?>"
                                                   inputmode="numeric"
                                                   placeholder="—">
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary">Simpan pengaturan makan</button>
            <?php endif; ?>
        </form>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('btn-toggle-makan-bulan');
    var panel = document.getElementById('makan-bulan-panel');
    if (!btn || !panel) return;
    btn.addEventListener('click', function () {
        var show = panel.classList.contains('d-none');
        panel.classList.toggle('d-none', !show);
        btn.setAttribute('aria-expanded', show ? 'true' : 'false');
        btn.textContent = show ? 'Sembunyikan tarif per bulan' : 'Ubah per bulan tagihan';
    });
})();
</script>
