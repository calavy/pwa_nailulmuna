<?php

declare(strict_types=1);

/**
 * Panduan operator: alur modul keuangan (tagihan on-the-fly, pembayaran, alokasi, akuntansi).
 *
 * @var PDO $pdo
 */

if (!function_exists('keuangan_pkpps_alokasi_umum_label')) {
    require_once __DIR__ . '/../../helpers/keuangan_pkpps_syahriyah.php';
}
$umumLabel = keuangan_pkpps_alokasi_komponen_nama($pdo);
?>
<div class="keu-panduan">
    <div class="alert alert-light border mb-4 small">
        <strong>Model data:</strong> tagihan bulanan <em>tidak</em> disimpan sebagai invoice tetap — dihitung dari pengaturan + data santri.
        Yang tercatat di database: <strong>pembayaran</strong>, <strong>pengeluaran</strong>, <strong>pemasukan</strong>, dan <strong>jurnal akuntansi</strong>.
    </div>

    <div class="accordion keu-panduan-accordion" id="keuPanduanAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanModul" aria-expanded="true">
                    1. Peta modul &amp; pintu masuk
                </button>
            </h2>
            <div id="keuPanduanModul" class="accordion-collapse collapse show" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <p class="fw-semibold mb-1">Pengaturan</p>
                            <ul class="mb-0 ps-3">
                                <li><a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php')) ?>">Pengaturan keuangan</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/settings/kelas_keuangan.php')) ?>">Kelas keuangan</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/settings/kalender.php')) ?>">Kalender &amp; bulan tagihan</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/keuangan/potongan_syahriyah.php')) ?>">Potongan syahriyah</a></li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <p class="fw-semibold mb-1">Operasional</p>
                            <ul class="mb-0 ps-3">
                                <li><a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Dashboard keuangan</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">Tagihan syahriyah</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">Input pembayaran</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/keuangan/pengeluaran.php')) ?>">Pengeluaran</a> · <a href="<?= htmlspecialchars(app_href('/keuangan/pemasukan.php')) ?>">Pemasukan</a></li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <p class="fw-semibold mb-1">Laporan &amp; portal wali</p>
                            <ul class="mb-0 ps-3">
                                <li><a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php')) ?>">Laporan syahriyah</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php')) ?>">Laporan PKPPS</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/keuangan/neraca.php')) ?>">Neraca</a> · <a href="<?= htmlspecialchars(app_href('/keuangan/arus-kas.php')) ?>">Arus kas</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/wali/tagihan.php')) ?>">Portal wali — tagihan</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/wali/pembayaran.php')) ?>">Portal wali — pembayaran</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanFondasi">
                    2. Fondasi nominal (sebelum tagihan)
                </button>
            </h2>
            <div id="keuPanduanFondasi" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Sumber</th><th>Fungsi</th></tr></thead>
                        <tbody>
                            <tr><td>Tahun ajaran aktif</td><td>Semua transaksi bulanan terikat TA (<code>keuangan_periode_*</code>)</td></tr>
                            <tr><td>Bulan tagihan</td><td>Slot Hijriyah/Masehi via kalender pondok</td></tr>
                            <tr><td>Kelas santri → tier</td><td>Muadalah / Wustho / Ulya</td></tr>
                            <tr><td>Tarif global per pos</td><td>Syahriyah, makan, saku, awal tahun, dll.</td></tr>
                            <tr><td>Override per bulan</td><td>Tabel <code>keuangan_tarif_bulanan</code></td></tr>
                            <tr><td>Kelas keuangan</td><td>Mapping kode kelas santri → tier tarif</td></tr>
                            <tr><td>Alokasi dana</td><td>Persen pembagian syahriyah &amp; awal tahun</td></tr>
                            <tr><td>Akun kas/bank</td><td>Rekening saat bayar / terima</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-2 mb-0 text-muted">POS wajib bulanan: <strong>syahriyah</strong> saja. Makan &amp; saku opsional — tidak menentukan status lunas wajib.</p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanTagihan">
                    3. Alur tagihan syahriyah bulanan
                </button>
            </h2>
            <div id="keuPanduanTagihan" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <ol class="mb-2 ps-3">
                        <li>Kelas santri → tier tarif dasar syahriyah</li>
                        <li>Potongan % per santri (bulan jeda tidak mengurangi)</li>
                        <li><strong>Tambahan PKPPS</strong> bila santri aktif di <code>pkpps_santri</code></li>
                        <li>Override tarif per bulan (jika diatur)</li>
                        <li>Kurangi total yang sudah dibayar → sisa &amp; status (lunas / sebagian / belum)</li>
                    </ol>
                    <p class="mb-2"><strong>Rumus:</strong> expected = (tarif tier − potongan%) + tambahan PKPPS.</p>
                    <p class="mb-2">Modifier: potongan % (<a href="<?= htmlspecialchars(app_href('/keuangan/potongan_syahriyah.php')) ?>">pengaturan</a>),
                        PKPPS (<a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-pkpps')) ?>">nominal PKPPS</a>).</p>
                    <p class="mb-2">Jenis syahriyah di <a href="<?= htmlspecialchars(app_href('/settings/kelas_syahriyah.php')) ?>">master data</a> <em>tidak</em> menambah nominal tagihan.</p>
                    <p class="mb-2">Makan &amp; saku opsional — tarif tier + override per santri; tidak menentukan status lunas wajib.</p>
                    <p class="mb-0">Helper: <code>tagihan_bulanan_page_context()</code>, <code>tagihan_wajib_status_for_month_bulk()</code>, <code>tagihan_syahriyah_list_cached()</code>.</p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanBayar">
                    4. Input pembayaran
                </button>
            </h2>
            <div id="keuPanduanBayar" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <ol class="mb-2 ps-3">
                        <li>Petugas pilih santri, pos, nominal di <a href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">input pembayaran</a></li>
                        <li>Validasi: tidak melebihi sisa tagihan per pos</li>
                        <li>Simpan header <code>keuangan_pembayaran</code> + detail per pos</li>
                        <li>Pos <strong>saku</strong> → otomatis top-up cashless</li>
                        <li>Jurnal otomatis: debit kas/bank, kredit pendapatan atau titipan saku (2101)</li>
                        <li>Redirect ke <a href="<?= htmlspecialchars(app_href('/keuangan/kuitansi.php')) ?>">kuitansi</a></li>
                    </ol>
                    <p class="mb-0">
                        Riwayat: <a href="<?= htmlspecialchars(app_href('/pembayaran/riwayat.php')) ?>">pembayaran/riwayat</a>.
                        Koreksi: edit/hapus via modul admin pembayaran + balik jurnal.
                    </p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanKeluar">
                    5. Pengeluaran, pemasukan, talangan
                </button>
            </h2>
            <div id="keuPanduanKeluar" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <ul class="mb-0 ps-3">
                        <li><strong>Pengeluaran</strong> — <a href="<?= htmlspecialchars(app_href('/keuangan/pengeluaran.php')) ?>">input pengeluaran</a>; bisa ditandai <code>alokasi_nama</code></li>
                        <li><strong>Pemasukan</strong> — <a href="<?= htmlspecialchars(app_href('/keuangan/pemasukan.php')) ?>">pemasukan lain</a> (donasi/hibah)</li>
                        <li><strong>Talangan</strong> — <a href="<?= htmlspecialchars(app_href('/keuangan/talangan.php')) ?>">dana talangan</a> internal</li>
                        <li><strong>Inventaris</strong> — <a href="<?= htmlspecialchars(app_href('/keuangan/inventaris.php')) ?>">aset tetap</a> + penyusutan</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanAlokasi">
                    6. Alokasi dana syahriyah
                </button>
            </h2>
            <div id="keuPanduanAlokasi" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">Alokasi = pembagian <strong>persentase</strong> (bukan transfer otomatis antar rekening).</p>
                    <ul class="mb-2 ps-3">
                        <li>Bagian PKPPS → <strong><?= htmlspecialchars($umumLabel) ?></strong> (gaji guru)</li>
                        <li>Komponen % (gizi, operasional, KOPSA, …) dihitung dari <em>dasar</em> syahriyah setelah PKPPS</li>
                        <li>Cicilan kecil: PKPPS diambil dulu, sisanya ke % dasar</li>
                        <li>Pengeluaran dengan <code>alokasi_nama</code> mengurangi saldo virtual komponen</li>
                    </ul>
                    <p class="mb-0">
                        Pengaturan: <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=alokasi')) ?>">alokasi syahriyah</a>
                        · Laporan: <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan.php')) ?>">laporan syahriyah</a>,
                        <a href="<?= htmlspecialchars(app_href('/pembayaran/kartu_syahriyah_santri.php')) ?>">kartu syahriyah santri</a>,
                        <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_kopsa_per_santri.php')) ?>">KOPSA</a>.
                        Setelah ubah pembayaran/pengaturan, muat ulang laporan dengan <code>?refresh=1</code>.
                    </p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanPkpps">
                    7. PKPPS dalam keuangan
                </button>
            </h2>
            <div id="keuPanduanPkpps" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">PKPPS bukan tagihan terpisah — nominal ditambahkan ke syahriyah dasar untuk santri aktif PKPPS.</p>
                    <ol class="mb-2 ps-3">
                        <li>Cek <code>pkpps_santri.is_aktif</code></li>
                        <li>Nominal dari pengaturan per kelas keuangan × bulan</li>
                        <li>Masuk <code>expected</code> saat simulasi tagihan</li>
                        <li>Saat laporan: <code>keuangan_syahriyah_split_pembayaran_tambahan()</code> pisahkan PKPPS vs dasar</li>
                    </ol>
                    <p class="mb-0">
                        <a href="<?= htmlspecialchars(app_href('/keuangan/pengaturan.php?bagian=syahriyah_makan#tambahan-pkpps')) ?>">Pengaturan nominal PKPPS</a>
                        · <a href="<?= htmlspecialchars(app_href('/pembayaran/laporan_pkpps_syahriyah.php')) ?>">Laporan PKPPS</a>
                    </p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanCashless">
                    8. Cashless &amp; saku
                </button>
            </h2>
            <div id="keuPanduanCashless" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">Pembayaran pos saku → top-up <code>cashless_accounts</code> (titipan COA 2101, bukan pendapatan).</p>
                    <p class="mb-0">
                        <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_scan.php')) ?>">Cashless scan</a>
                        · <a href="<?= htmlspecialchars(app_href('/keuangan/cashless_laporan.php')) ?>">Laporan cashless</a>
                        · Wali: <a href="<?= htmlspecialchars(app_href('/wali/keuangan.php')) ?>">saldo saku</a>
                    </p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanWa">
                    9. WA tagihan otomatis
                </button>
            </h2>
            <div id="keuPanduanWa" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">Cron <code>cron/wa_auto.php</code> kirim ke wali santri syahriyah belum lunas (jadwal kalender + pengaturan WA).</p>
                    <p class="mb-0">
                        Manual: tombol WA di <a href="<?= htmlspecialchars(app_href('/pembayaran/tagihan_syahriyah.php')) ?>">tagihan syahriyah</a>
                        · Template: <a href="<?= htmlspecialchars(app_href('/settings/wa_pesan.php')) ?>">pengaturan pesan WA</a>
                    </p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#keuPanduanAkuntansi">
                    10. Akuntansi &amp; ringkasan
                </button>
            </h2>
            <div id="keuPanduanAkuntansi" class="accordion-collapse collapse" data-bs-parent="#keuPanduanAccordion">
                <div class="accordion-body small">
                    <table class="table table-sm table-bordered mb-3">
                        <thead class="table-light"><tr><th>Transaksi</th><th>Debit</th><th>Kredit</th></tr></thead>
                        <tbody>
                            <tr><td>Pembayaran syahriyah</td><td>Kas/Bank</td><td>Pendapatan syahriyah</td></tr>
                            <tr><td>Pembayaran saku</td><td>Kas/Bank</td><td>Titipan saku (2101)</td></tr>
                            <tr><td>Pengeluaran</td><td>Beban</td><td>Kas/Bank</td></tr>
                            <tr><td>Pemasukan</td><td>Kas/Bank</td><td>Pendapatan lain</td></tr>
                        </tbody>
                    </table>
                    <p class="fw-semibold mb-1">Ringkasan mental model</p>
                    <ol class="mb-3 ps-3">
                        <li>Tagihan = kalkulasi; pembayaran = record di DB</li>
                        <li>Syahriyah satu-satunya pos wajib bulanan</li>
                        <li>Hanya PKPPS menambah nominal syahriyah (bukan invoice terpisah)</li>
                        <li>Alokasi = % dari koleksi + cocokkan pengeluaran bertag alokasi</li>
                        <li>Saku = titipan → cashless → belanja koperasi</li>
                        <li>Jurnal otomatis menghubungkan operasional dengan neraca</li>
                    </ol>
                    <p class="fw-semibold mb-1">File penting (quick reference)</p>
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Peran</th><th>File helper</th></tr></thead>
                        <tbody>
                            <tr><td>Simpan pembayaran</td><td><code>helpers/keuangan_transaksi.php</code></td></tr>
                            <tr><td>Hitung tagihan</td><td><code>helpers/tagihan_bulanan.php</code></td></tr>
                            <tr><td>Potongan syahriyah</td><td><code>helpers/keuangan_syahriyah_potongan.php</code></td></tr>
                            <tr><td>PKPPS tambahan</td><td><code>helpers/keuangan_pkpps_syahriyah.php</code></td></tr>
                            <tr><td>Jurnal</td><td><code>helpers/keuangan_jurnal.php</code></td></tr>
                            <tr><td>Alokasi &amp; rekap</td><td><code>helpers/keuangan_rekap.php</code></td></tr>
                            <tr><td>WA tagihan</td><td><code>helpers/wa_tagihan.php</code></td></tr>
                            <tr><td>Cashless</td><td><code>helpers/cashless_koperasi.php</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
