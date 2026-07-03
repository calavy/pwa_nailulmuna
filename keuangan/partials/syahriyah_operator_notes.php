<?php

declare(strict_types=1);

/**
 * Catatan operator untuk alur tagihan & alokasi syahriyah.
 *
 * @var PDO $pdo
 */
if (!function_exists('keuangan_pkpps_alokasi_komponen_nama')) {
    require_once __DIR__ . '/../../helpers/keuangan_pkpps_syahriyah.php';
}
?>
<div class="card shadow-sm mt-4 border-info">
    <div class="card-header fw-semibold text-info-emphasis">Panduan operator — tagihan &amp; alokasi</div>
    <div class="card-body small text-muted">
        <p class="mb-2"><strong>Tagihan masuk santri baru:</strong> isi <em>tanggal masuk</em> di data santri. Santri baru ditagih bulanan mulai bulan masuk pada TA pertama; catatan tersimpan di riwayat dan tetap terlihat di TA berikutnya.</p>
        <p class="mb-2"><strong>Tagihan syahriyah per bulan</strong> = tarif tier kelas keuangan (Muadalah / Wustho / Ulya)
            − potongan % per santri (bulan jeda tidak mengurangi) + <strong>tambahan PKPPS</strong> bila santri aktif di PKPPS.
            Jenis kelas syahriyah di master data <em>tidak</em> menambah nominal tagihan.</p>
        <p class="mb-2"><strong>Kartu syahriyah santri</strong> (<a href="<?= htmlspecialchars(app_href('/pembayaran/kartu_syahriyah_santri.php')) ?>">cari santri</a>): bagian PKPPS masuk
            komponen <strong><?= htmlspecialchars(keuangan_pkpps_alokasi_komponen_nama($pdo)) ?></strong> (gaji guru);
            komponen % (gizi, operasional, KOPSA, …) dihitung hanya dari <em>dasar</em> syahriyah setelah PKPPS.</p>
        <p class="mb-2"><strong>Cicilan kecil:</strong> pembayaran dialokasikan <em>PKPPS dulu</em> (sampai nominal PKPPS lunas),
            lalu masuk ke komponen gaji; sisanya ke komponen % dasar.</p>
        <p class="mb-0"><strong>Cache laporan bulanan:</strong> setelah mengubah pembayaran atau pengaturan,
            buka <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php?refresh=1')) ?>">Laporan syahriyah</a>
            dengan <code>?refresh=1</code> agar agregat bulanan mutakhir.
            Pembagian PKPPS/dasar tidak disimpan di database — mengubah nominal PKPPS dapat mengubah tampilan alokasi pembayaran lama.
            <a href="<?= htmlspecialchars(app_href('/keuangan/panduan.php')) ?>">Panduan alur keuangan lengkap</a>.</p>
    </div>
</div>
