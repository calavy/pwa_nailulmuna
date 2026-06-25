<?php

declare(strict_types=1);

/**
 * Kuitansi mudah dibaca orang tua / wali.
 *
 * @var array<string,mixed> $kuitansi
 * @var string $sheetId ID elemen untuk print/download
 * @var bool $showQr
 */
$kuitansi = $kuitansi ?? [];
$sheetId = $sheetId ?? 'receipt-orangtua';
$showQr = $showQr ?? true;

$noKw = (string) ($kuitansi['no_kuitansi'] ?? '');
$tanggalFmt = (string) ($kuitansi['tanggal_bayar_fmt'] ?? '');
$namaSantri = (string) ($kuitansi['nama_santri'] ?? '');
$binLabel = (string) ($kuitansi['bin_label'] ?? '');
$nis = (string) ($kuitansi['nis'] ?? '');
$tingkatan = (string) ($kuitansi['tingkatan'] ?? '');
$periodeLabel = (string) ($kuitansi['periode_label'] ?? '');
$jenisLabel = (string) ($kuitansi['jenis_periode_label'] ?? '');
$metodeLabel = (string) ($kuitansi['metode_bayar_label'] ?? '');
$keterangan = (string) ($kuitansi['keterangan'] ?? '');
$namaPetugas = (string) ($kuitansi['nama_petugas'] ?? '');
$details = (array) ($kuitansi['details'] ?? []);
$totalFmt = (string) ($kuitansi['nominal_total_fmt'] ?? '');
$terbilang = (string) ($kuitansi['nominal_terbilang'] ?? '');
$namaPonpes = (string) ($kuitansi['nama_ponpes'] ?? '');
$alamat = (string) ($kuitansi['alamat_ponpes'] ?? '');
$jenisPendidikan = (string) ($kuitansi['jenis_pendidikan'] ?? '');
$logo = (string) ($kuitansi['logo'] ?? '');
$stampel = (string) ($kuitansi['stampel'] ?? '');
$footerNote = (string) ($kuitansi['footer_note'] ?? '');
$verifyUrl = (string) ($kuitansi['verify_url'] ?? '');
?>
<article id="<?= htmlspecialchars($sheetId) ?>" class="kuitansi-ortu card shadow-sm border-0">
    <div class="kuitansi-ortu__inner">
        <header class="kuitansi-ortu__kop text-center">
            <?php if ($logo !== ''): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="" class="kuitansi-ortu__logo" width="72" height="72">
            <?php endif; ?>
            <h2 class="kuitansi-ortu__ponpes mb-1"><?= htmlspecialchars($namaPonpes) ?></h2>
            <?php if ($jenisPendidikan !== ''): ?>
                <p class="kuitansi-ortu__sub mb-1"><?= htmlspecialchars($jenisPendidikan) ?></p>
            <?php endif; ?>
            <?php if ($alamat !== ''): ?>
                <p class="kuitansi-ortu__alamat mb-2"><?= htmlspecialchars($alamat) ?></p>
            <?php endif; ?>
            <p class="kuitansi-ortu__judul mb-0">Bukti Pembayaran / Kuitansi</p>
        </header>

        <div class="kuitansi-ortu__meta row g-2">
            <div class="col-6">
                <div class="kuitansi-ortu__label">Nomor bukti</div>
                <div class="kuitansi-ortu__value font-monospace"><?= htmlspecialchars($noKw) ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="kuitansi-ortu__label">Tanggal bayar</div>
                <div class="kuitansi-ortu__value"><?= htmlspecialchars($tanggalFmt) ?></div>
            </div>
        </div>

        <section class="kuitansi-ortu__santri" aria-label="Data santri">
            <div class="kuitansi-ortu__label">Dibayar untuk santri</div>
            <div class="kuitansi-ortu__nama-santri"><?= htmlspecialchars($namaSantri) ?></div>
            <?php if ($binLabel !== ''): ?>
                <div class="kuitansi-ortu__bin-label"><?= htmlspecialchars($binLabel) ?></div>
            <?php endif; ?>
            <ul class="kuitansi-ortu__info-list list-unstyled mb-0">
                <?php if ($nis !== ''): ?>
                    <li><span>NIS</span><strong class="font-monospace"><?= htmlspecialchars($nis) ?></strong></li>
                <?php endif; ?>
                <?php if ($tingkatan !== ''): ?>
                    <li><span>Tingkatan</span><strong><?= htmlspecialchars($tingkatan) ?></strong></li>
                <?php endif; ?>
                <li><span>Jenis tagihan</span><strong><?= htmlspecialchars($jenisLabel) ?></strong></li>
                <li><span>Periode</span><strong><?= htmlspecialchars($periodeLabel) ?></strong></li>
                <li><span>Cara bayar</span><strong><?= htmlspecialchars($metodeLabel) ?></strong></li>
            </ul>
        </section>

        <?php if ($details !== []): ?>
        <section class="kuitansi-ortu__rincian" aria-label="Rincian pembayaran">
            <div class="kuitansi-ortu__label mb-2">Rincian pembayaran</div>
            <ul class="kuitansi-ortu__rincian-list list-unstyled mb-0">
                <?php foreach ($details as $d): ?>
                    <li class="kuitansi-ortu__rincian-item">
                        <span class="kuitansi-ortu__pos-nama"><?= htmlspecialchars((string) ($d['nama'] ?? '')) ?></span>
                        <span class="kuitansi-ortu__pos-nominal font-monospace"><?= htmlspecialchars((string) ($d['nominal_fmt'] ?? '')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="kuitansi-ortu__total" aria-label="Total pembayaran">
            <div class="kuitansi-ortu__total-label">Jumlah uang yang dibayar</div>
            <div class="kuitansi-ortu__total-angka font-monospace"><?= htmlspecialchars($totalFmt) ?></div>
            <div class="kuitansi-ortu__total-terbilang">(<?= htmlspecialchars($terbilang) ?>)</div>
        </section>

        <?php if ($keterangan !== ''): ?>
            <p class="kuitansi-ortu__catatan"><span class="kuitansi-ortu__label d-inline">Catatan:</span> <?= htmlspecialchars($keterangan) ?></p>
        <?php endif; ?>

        <footer class="kuitansi-ortu__footer">
            <div class="kuitansi-ortu__footer-grid">
                <?php if ($showQr && $verifyUrl !== ''): ?>
                    <div class="kuitansi-ortu__qr-wrap text-center">
                        <div id="qrcode-verify-<?= htmlspecialchars($sheetId) ?>" class="kuitansi-ortu__qr" aria-hidden="true"></div>
                        <p class="kuitansi-ortu__qr-hint mb-0">Scan untuk cek keaslian bukti</p>
                    </div>
                <?php endif; ?>
                <div class="kuitansi-ortu__stempel text-center">
                    <p class="kuitansi-ortu__label mb-1">Stempel resmi pondok</p>
                    <?php if ($stampel !== ''): ?>
                        <img src="<?= htmlspecialchars($stampel) ?>" alt="Stempel resmi" class="kuitansi-ortu__stempel-img" width="120" height="120">
                    <?php endif; ?>
                    <p class="kuitansi-ortu__sah mb-0">SAH · <?= htmlspecialchars($tanggalFmt) ?></p>
                    <?php if ($namaPetugas !== ''): ?>
                        <p class="kuitansi-ortu__petugas mb-0">Petugas: <?= htmlspecialchars($namaPetugas) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <p class="kuitansi-ortu__thanks text-center mb-0"><?= htmlspecialchars($footerNote) ?></p>
        </footer>
    </div>
</article>
