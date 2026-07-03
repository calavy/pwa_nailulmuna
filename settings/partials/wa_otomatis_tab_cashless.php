<?php

declare(strict_types=1);

/** @var bool $cashlessSaldoRendahWaEnabled */
/** @var int $cashlessSaldoRendahWaAmbang */
/** @var bool $cashlessTransaksiWaEnabled */
/** @var bool $cashlessLaporanHarianWaEnabled */
/** @var string $cashlessLaporanHarianWaJam */
/** @var string $cashlessLaporanHarianWaTargets */
/** @var array<string,mixed> $cashlessLaporanStatus */

$cashlessRingkasanHariIni = $cashlessLaporanStatus['ringkasan'] ?? [];
$cashlessLaporanTanggalLabel = (string) ($cashlessRingkasanHariIni['tanggal_label'] ?? 'hari kemarin');
$cashlessSudahDikirimHariIni = (($cashlessLaporanStatus['last_laporan_tanggal'] ?? '') === ($cashlessLaporanStatus['laporan_tanggal'] ?? ''));
?>
<div class="card shadow-sm border-0 mb-3 border-warning-subtle">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-wallet text-warning me-1"></i> Pengaturan WA cashless</h2>
        <p class="small text-muted mb-3">
            Notifikasi ke wali (saldo rendah &amp; setiap transaksi berhasil) dan laporan harian transaksi ke pengurus
            (<strong>data hari kemarin</strong>, dikirim sesuai jam di bawah). Template pesan di tab <strong>Template</strong>.
        </p>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_cashless_wa_settings">
            <input type="hidden" name="redirect_tab" value="cashless">

            <div class="col-12"><div class="fw-semibold small text-uppercase text-muted">Ke wali santri</div></div>
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="cashless_transaksi_wa_enabled" name="cashless_transaksi_wa_enabled" value="1" <?= $cashlessTransaksiWaEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cashless_transaksi_wa_enabled">WA setiap transaksi berhasil</label>
                </div>
                <div class="form-text">Berisi nominal, saldo keseluruhan, dan sisa jatah belanja hari ini.</div>
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="cashless_saldo_rendah_wa_enabled" name="cashless_saldo_rendah_wa_enabled" value="1" <?= $cashlessSaldoRendahWaEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cashless_saldo_rendah_wa_enabled">WA saldo rendah (sekali per periode)</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="cashless_saldo_rendah_wa_ambang">Ambang saldo rendah (Rp)</label>
                <input type="number" class="form-control" id="cashless_saldo_rendah_wa_ambang" name="cashless_saldo_rendah_wa_ambang" min="0" step="1000" value="<?= (int) $cashlessSaldoRendahWaAmbang ?>">
            </div>

            <div class="col-12 mt-2"><div class="fw-semibold small text-uppercase text-muted">Laporan harian ke pengurus</div></div>
            <div class="col-md-4">
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" id="cashless_laporan_harian_wa_enabled" name="cashless_laporan_harian_wa_enabled" value="1" <?= $cashlessLaporanHarianWaEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cashless_laporan_harian_wa_enabled">Kirim otomatis (cron)</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="cashless_laporan_harian_wa_jam">Jam kirim</label>
                <input type="time" class="form-control" id="cashless_laporan_harian_wa_jam" name="cashless_laporan_harian_wa_jam" value="<?= htmlspecialchars($cashlessLaporanHarianWaJam) ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label" for="cashless_laporan_harian_wa_targets">Nomor penerima</label>
                <input type="text" class="form-control" id="cashless_laporan_harian_wa_targets" name="cashless_laporan_harian_wa_targets" value="<?= htmlspecialchars($cashlessLaporanHarianWaTargets) ?>" placeholder="Kosongkan = nomor pengurus (tab Alpa)">
                <div class="form-text">Pisahkan dengan koma atau baris baru. Jangan kosongkan jika tab Alpa belum diisi nomor pengurus.</div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-success btn-sm">Simpan pengaturan cashless</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Status hari ini</h2>
        <ul class="small text-muted mb-0 ps-3">
            <li>Transaksi ke wali: <strong><?= $cashlessTransaksiWaEnabled ? 'Aktif' : 'Nonaktif' ?></strong>
                · Saldo rendah: <strong><?= $cashlessSaldoRendahWaEnabled ? 'Aktif' : 'Nonaktif' ?></strong>
                (ambang <?= htmlspecialchars(cashless_wa_rp($cashlessSaldoRendahWaAmbang)) ?>)</li>
            <li>Laporan harian (data <strong><?= htmlspecialchars($cashlessLaporanTanggalLabel) ?></strong>): <strong><?= $cashlessLaporanHarianWaEnabled ? 'Aktif' : 'Nonaktif' ?></strong>
                · jam <?= htmlspecialchars($cashlessLaporanHarianWaJam) ?>
                · <?= !empty($cashlessLaporanStatus['send_time_ok']) ? '<span class="text-success">sudah lewat</span>' : '<span class="text-warning">belum</span>' ?></li>
            <li>Data <?= htmlspecialchars($cashlessLaporanTanggalLabel) ?> <?= $cashlessSudahDikirimHariIni ? '<span class="text-muted">sudah dikirim</span>' : '<span class="text-success">belum dikirim</span>' ?>
                · Terakhir sukses: <strong><?= ($cashlessLaporanStatus['last_sent_at'] ?? '') !== '' ? htmlspecialchars((string) $cashlessLaporanStatus['last_sent_at']) : 'Belum pernah' ?></strong>
                · Penerima terdaftar: <strong><?= (int) ($cashlessLaporanStatus['targets_count'] ?? 0) ?></strong></li>
            <?php if (trim((string) ($cashlessLaporanStatus['last_error'] ?? '')) !== ''): ?>
                <li class="text-danger">Error terakhir: <?= htmlspecialchars((string) $cashlessLaporanStatus['last_error']) ?></li>
            <?php endif; ?>
            <li>Transaksi <?= htmlspecialchars($cashlessLaporanTanggalLabel) ?>: <strong><?= (int) ($cashlessRingkasanHariIni['total_transaksi'] ?? 0) ?></strong>
                · <?= htmlspecialchars(cashless_wa_rp((int) ($cashlessRingkasanHariIni['total_nominal'] ?? 0))) ?></li>
            <?php foreach (($cashlessRingkasanHariIni['per_koperasi'] ?? []) as $pk): ?>
                <li><?= htmlspecialchars((string) ($pk['nama'] ?? '')) ?>: <?= (int) ($pk['jumlah'] ?? 0) ?> transaksi · <?= htmlspecialchars(cashless_wa_rp((int) ($pk['nominal'] ?? 0))) ?></li>
            <?php endforeach; ?>
            <?php if (($cashlessRingkasanHariIni['total_transaksi'] ?? 0) === 0): ?>
                <li class="text-muted">Tidak ada transaksi debit pada <?= htmlspecialchars($cashlessLaporanTanggalLabel) ?>.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Kirim laporan manual</h2>
        <p class="small text-muted mb-2">Kirim rekap transaksi debit <strong>hari kemarin</strong> (total + rincian per koperasi) ke nomor penerima laporan.</p>
        <form method="post" class="d-inline" onsubmit="return confirm('Kirim laporan cashless hari kemarin sekarang?');">
            <input type="hidden" name="action" value="jalankan_cashless_laporan_harian">
            <input type="hidden" name="redirect_tab" value="cashless">
            <button type="submit" class="btn btn-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i> Kirim laporan sekarang</button>
        </form>
    </div>
</div>
