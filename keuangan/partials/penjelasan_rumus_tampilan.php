<?php

declare(strict_types=1);

/**
 * Rumus tagihan/kas, KPI dashboard, cache, dan cara membaca laporan (accordion).
 */
?>
<div class="accordion keu-panduan-accordion mt-4" id="keuPenjelasanAccordion">
    <div class="accordion-item border-primary">
        <h2 class="accordion-header">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#keuPenjelasanIntro" aria-expanded="true">
                Rumus &amp; tampilan — ringkasan
            </button>
        </h2>
        <div id="keuPenjelasanIntro" class="accordion-collapse collapse show" data-bs-parent="#keuPenjelasanAccordion">
            <div class="accordion-body small">
                <p class="mb-2">
                    Dokumen lengkap (Markdown):
                    <a href="<?= htmlspecialchars(app_href('/docs/PENJELASAN_KEUANGAN.md')) ?>" target="_blank" rel="noopener"><strong>Penjelasan Keuangan</strong></a>
                    · Pipeline teknis: <a href="<?= htmlspecialchars(app_href('/docs/ALUR_KEUANGAN.md')) ?>" target="_blank" rel="noopener">Alur keuangan</a>.
                </p>
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr><th>Yang dihitung</th><th>Yang disimpan</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>Expected tagihan, sisa, alokasi virtual</td>
                            <td>Pembayaran, pengeluaran, pemasukan, jurnal, akun kas, ledger saku</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPenjelasanRumusTagihan">
                Rumus tagihan (per santri &amp; agregat)
            </button>
        </h2>
        <div id="keuPenjelasanRumusTagihan" class="accordion-collapse collapse" data-bs-parent="#keuPenjelasanAccordion">
            <div class="accordion-body small">
                <table class="table table-sm table-bordered mb-3">
                    <thead class="table-light"><tr><th>Istilah</th><th>Arti</th></tr></thead>
                    <tbody>
                        <tr><td><strong>expected</strong></td><td>Tarif tier ± override ± potongan + PKPPS; pos tidak berlaku = 0</td></tr>
                        <tr><td><strong>paid</strong></td><td>Total nominal detail pembayaran untuk POS + bulan + TA</td></tr>
                        <tr><td><strong>sisa</strong></td><td><code>max(0, expected − paid)</code> per POS (form bayar)</td></tr>
                        <tr><td><strong>paid (rekap)</strong></td><td><code>min(paid, expected)</code>; kelebihan → <code>paid_lebih</code></td></tr>
                    </tbody>
                </table>
                <p class="mb-2"><strong>Periode:</strong> <code>BULANAN</code> (bulan 1–12) dan <code>AWAL_TAHUN</code> (komponen awal TA).</p>
                <p class="mb-2"><strong>Agregat:</strong> <code>keuangan_rekap_pos_with_expected</code> → <code>keuangan_rekap_tagihan_bulanan_ta</code> → kolom tagihan di
                    <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>">rekap kas bulanan</a>.</p>
                <p class="mb-0 text-muted">Helper inti: <code>keuangan_tagihan_breakdown_for_santri</code>, <code>tagihan_santri_masuk.php</code>.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPenjelasanRekapKas">
                Rekap kas bulanan &amp; saldo
            </button>
        </h2>
        <div id="keuPenjelasanRekapKas" class="accordion-collapse collapse" data-bs-parent="#keuPenjelasanAccordion">
            <div class="accordion-body small">
                <p class="mb-2">
                    <a href="<?= htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) ?>">Rekap kas bulanan</a>:
                    per baris bulan tagihan TA — mutasi kas (masuk/keluar/bersih), saldo hitung vs <strong>saldo fisik</strong>, plus kolom tagihan/terbayar/sisa.
                </p>
                <ul class="mb-3 ps-3">
                    <li><strong>Saldo fisik</strong> = <code>keuangan_aruskas_total_kas</code> per akhir periode kalender (kas/bank pondok 1101/1102).</li>
                    <li><strong>Selisih</strong> = fisik − saldo akhir hitung (indikator rekonsiliasi, bukan error tagihan).</li>
                    <li>Baris <strong>Awal Tahun (TA)</strong> — tagihan/terbayar/sisa awal tahun; masuk <strong>total footer TA</strong> (bulanan + awal tahun).</li>
                    <li><code>view=pondok</code> — tanpa saku di total masuk pondok; <code>view=full</code> — sertakan titipan untuk audit.</li>
                </ul>
                <p class="mb-0 fw-semibold">Penting: kolom <em>tagihan</em> = kewajiban agregat; kolom <em>masuk</em> = uang riil. Jangan disamakan.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPenjelasanDashboard">
                Dashboard — KPI tagihan bulan berjalan
            </button>
        </h2>
        <div id="keuPenjelasanDashboard" class="accordion-collapse collapse" data-bs-parent="#keuPenjelasanAccordion">
            <div class="accordion-body small">
                <p class="mb-2">Sumber: <code>tagihan_syahriyah_list_cached</code> di <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">dashboard keuangan</a>.</p>
                <table class="table table-sm table-bordered mb-2">
                    <tbody>
                        <tr><td>Total tagihan</td><td>Jumlah expected syahriyah bulan berjalan (santri aktif)</td></tr>
                        <tr><td>Total terbayar</td><td>Paid valid bulan itu</td></tr>
                        <tr><td>Piutang</td><td><code>max(0, tagihan − terbayar)</code></td></tr>
                        <tr><td>% tertagih</td><td><code>terbayar / tagihan × 100</code> jika tagihan &gt; 0</td></tr>
                    </tbody>
                </table>
                <p class="mb-0">Muat ulang angka cache: tambahkan <code>?refresh=1</code> pada URL dashboard.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPenjelasanCache">
                Kinerja &amp; cache laporan
            </button>
        </h2>
        <div id="keuPenjelasanCache" class="accordion-collapse collapse" data-bs-parent="#keuPenjelasanAccordion">
            <div class="accordion-body small">
                <ul class="mb-2 ps-3">
                    <li>Cache sesi ~600 detik: snapshot dashboard, rekap POS expected/paid, list tagihan, neraca/arus kas cached.</li>
                    <li>Setelah simpan/hapus pembayaran, pemasukan, atau pengeluaran: <code>keuangan_mutasi_cache_invalidate()</code>.</li>
                    <li>Jika angka laporan terasa “macet”: logout/login atau <code>?refresh=1</code> di dashboard.</li>
                </ul>
                <p class="mb-0">Diagnostik read-only (CLI): <code>scripts/_diag_rekap_tagihan_reconcile.php</code>, <code>scripts/_diag_rekap_kas_selisih.php</code>.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPenjelasanBacaAngka">
                Cara membaca angka (anti salah paham)
            </button>
        </h2>
        <div id="keuPenjelasanBacaAngka" class="accordion-collapse collapse" data-bs-parent="#keuPenjelasanAccordion">
            <div class="accordion-body small">
                <ol class="mb-0 ps-3">
                    <li>Tagihan besar di rekap kas <strong>bukan</strong> berarti uang masuk besar di bulan yang sama.</li>
                    <li>Footer total TA harus = jumlah terbayar/sisa <strong>semua baris</strong> (bulanan + baris Awal Tahun).</li>
                    <li>Saku/titipan santri terpisah dari kas operasional pondok (lihat view rekap kas).</li>
                    <li>Selisih saldo fisik vs hitung → <a href="<?= htmlspecialchars(app_href('/keuangan/perbaikan-kas.php')) ?>">Perbaikan kas</a>; jangan menyesuaikan saldo hanya agar cocok total tagihan.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
