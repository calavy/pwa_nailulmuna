<?php

declare(strict_types=1);

/**
 * Catatan operator untuk alur tagihan & alokasi syahriyah.
 */
?>
<div class="card shadow-sm mt-4 border-info">
    <div class="card-header fw-semibold text-info-emphasis">Panduan operator — tagihan &amp; alokasi</div>
    <div class="card-body small text-muted">
        <p class="mb-2"><strong>Tagihan syahriyah per bulan</strong> = tarif tier kelas keuangan (Muadalah / Wustho / Ulya)
            − potongan % per santri (bulan jeda tidak mengurangi) + <strong>tambahan PKPPS</strong> bila santri aktif di PKPPS.
            Jenis kelas syahriyah di master data <em>tidak</em> menambah nominal tagihan.</p>
        <p class="mb-2"><strong>Alokasi laporan</strong> (Laporan syahriyah &amp; Alokasi per santri): bagian PKPPS masuk
            <strong><?= htmlspecialchars(keuangan_pkpps_alokasi_umum_label()) ?></strong>;
            komponen % (gizi, operasional, KOPSA, …) dihitung hanya dari <em>dasar</em> syahriyah setelah PKPPS.</p>
        <p class="mb-2"><strong>Cicilan kecil:</strong> pembayaran dialokasikan <em>PKPPS dulu</em> (sampai nominal PKPPS lunas),
            baru sisanya ke komponen %. Cicilan di bawah nominal PKPPS bisa seluruhnya masuk dana umum.</p>
        <p class="mb-0"><strong>Cache laporan bulanan:</strong> setelah mengubah pembayaran atau pengaturan,
            buka <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php?refresh=1')) ?>">Laporan syahriyah</a>
            dengan <code>?refresh=1</code> agar agregat bulanan mutakhir.
            Pembagian PKPPS/dasar tidak disimpan di database — mengubah nominal PKPPS dapat mengubah tampilan alokasi pembayaran lama.
            <a href="<?= htmlspecialchars(app_href('/keuangan/panduan.php')) ?>">Panduan alur keuangan lengkap</a>.</p>
    </div>
</div>
