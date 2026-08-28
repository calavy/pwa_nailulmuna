<?php

declare(strict_types=1);

/**
 * Panduan operator: alur presensi santri/SDM hingga rekap keaktifan.
 *
 * @var PDO $pdo
 */
$lateTolerance = (int) app_setting($pdo, 'batas_telat_menit', '15');
require_once __DIR__ . '/../../helpers/penilaian_kehadiran.php';
?>
<div class="rekap-panduan">
    <div class="alert alert-light border mb-4 small">
        <strong>Tiga lapisan:</strong>
        <span class="rekap-panduan-flow ms-1">
            <span class="badge text-bg-primary">Input</span>
            <span class="rekap-panduan-flow__arrow">→</span>
            <span class="badge text-bg-warning text-dark">Sinkronisasi</span>
            <span class="rekap-panduan-flow__arrow">→</span>
            <span class="badge text-bg-success">Rekap</span>
        </span>
        <p class="mb-0 mt-2">Rekap <em>bukan</em> sekadar membaca baris HADIR dari scan. Sistem menjalankan finalisasi yang mengisi IZIN, SAKIT, dan ALPA sebelum data diagregasi.</p>
    </div>

    <div class="accordion rekap-panduan-accordion" id="rekapPanduanAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanMaster" aria-expanded="true">
                    1. Prasyarat: jadwal menentukan slot presensi
                </button>
            </h2>
            <div id="rekapPanduanMaster" class="accordion-collapse collapse show" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">Setiap baris presensi santri terikat ke <strong>kegiatan + jadwal + tanggal</strong>.</p>
                    <table class="table table-sm table-bordered mb-3">
                        <thead class="table-light"><tr><th>Sumber</th><th>Fungsi</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><a href="<?= htmlspecialchars(app_href('/jadwal/index.php')) ?>">Jadwal kegiatan</a></td>
                                <td>Tingkatan, hari (<code>hari_ke</code> 1–7 atau 0 = setiap hari), <code>jam_mulai</code>/<code>jam_selesai</code></td>
                            </tr>
                            <tr>
                                <td><a href="<?= htmlspecialchars(app_href('/jadwal/kegiatan.php')) ?>">Master kegiatan</a></td>
                                <td>Nama, kategori Ta'lim / Jama'ah, status aktif</td>
                            </tr>
                            <tr>
                                <td><a href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktivan.php')) ?>">Jadwal PKPPS</a></td>
                                <td>Jalur alternatif per tingkatan PKPPS (prioritas saat scan)</td>
                            </tr>
                            <tr>
                                <td><a href="<?= htmlspecialchars(app_href('/settings/kalender.php')) ?>">Kalender &amp; libur</a></td>
                                <td>Hari libur akademik — bisa memblokir scan atau sebagian kategori kegiatan</td>
                            </tr>
                            <tr>
                                <td><a href="<?= htmlspecialchars(app_href('/rekap/perizinan.php')) ?>">Perizinan</a></td>
                                <td>Izin/sakit disetujui → status IZIN/SAKIT via sinkronisasi otomatis</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mb-0 text-muted">Tanpa jadwal yang cocok (tingkatan + hari + jam), scan santri ditolak atau tidak ada slot rekap.</p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanScan">
                    2. Input presensi (scan &amp; manual)
                </button>
            </h2>
            <div id="rekapPanduanScan" class="accordion-collapse collapse" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <p class="fw-semibold mb-1">Entry point utama: <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">Scan presensi</a></p>
                    <ol class="mb-3 ps-3">
                        <li>Tentukan tanggal &amp; jam scan (<code>presensi_scan_resolve_clock</code> — mendukung waktu dari perangkat offline)</li>
                        <li>Validasi santri aktif, hari libur, duplikat presensi</li>
                        <li>Cocokkan kegiatan aktif saat scan: PKPPS dulu, fallback jadwal tingkatan (<code>activity_for_tingkatan</code>)</li>
                        <li>Insert ke tabel <code>presensi</code> dengan <code>status_presensi = HADIR</code></li>
                        <li>Catat keterlambatan di kolom <code>catatan</code> jika scan &gt; <?= (int) $lateTolerance ?> menit setelah jam mulai (tetap HADIR, bukan ALPA)</li>
                    </ol>
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Sumber input</th><th>Tabel</th><th>Keterangan</th></tr></thead>
                        <tbody>
                            <tr><td>Scan pembimbing</td><td><code>presensi_pembimbing</code></td><td>SDM hadir pada kegiatan jadwal aktif</td></tr>
                            <tr><td>Scan munawib</td><td><code>presensi_munawib</code></td><td>Pengganti pembimbing (1 munawib/kegiatan/hari)</td></tr>
                            <tr><td>Kegiatan khusus</td><td><code>presensi_kegiatan_khusus</code></td><td>Alur terpisah — <a href="<?= htmlspecialchars(app_href('/rekap/kegiatan_khusus.php')) ?>">rekap khusus</a></td></tr>
                            <tr><td>Manual ALPA</td><td><code>presensi</code></td><td><a href="<?= htmlspecialchars(app_href('/presensi/alpha.php')) ?>">Input ALPA massal</a> per tingkatan+kegiatan+tanggal</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanSync">
                    3. Sinkronisasi otomatis (IZIN / SAKIT / ALPA)
                </button>
            </h2>
            <div id="rekapPanduanSync" class="accordion-collapse collapse" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2"><code>presensi_finalize_date_range()</code> dipanggil otomatis saat membuka rekap (bulanan, harian, dan fetch data rekap lain).</p>
                    <ul class="mb-3 ps-3">
                        <li><strong>Hari ini:</strong> sync jadwal aktif + jadwal yang jam selesainya sudah lewat</li>
                        <li><strong>Hari lampau:</strong> sync jadwal selesai (acuan jam 23:59:59)</li>
                    </ul>
                    <p class="fw-semibold mb-1">Logika per tingkatan (<code>sync_daily_presence_for_tingkatan</code>)</p>
                    <ol class="mb-3 ps-3">
                        <li>Ambil santri aktif yang tingkatannya terjadwal untuk kegiatan+hari itu</li>
                        <li>Tentukan status: perizinan disetujui → IZIN/SAKIT; izin tetap overlap jam kegiatan → IZIN; selain itu → ALPA</li>
                        <li>Selama kegiatan berlangsung: hanya buat IZIN/SAKIT, belum ALPA</li>
                        <li>Setelah jam selesai: buat/update ALPA untuk yang belum HADIR</li>
                        <li>Scan HADIR tidak pernah ditimpa</li>
                    </ol>
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Kondisi (rekap harian real-time)</th><th>Status tampilan</th></tr></thead>
                        <tbody>
                            <tr><td>DB = HADIR / IZIN / SAKIT</td><td>Tetap</td></tr>
                            <tr><td>Belum scan, sebelum jam selesai</td><td>Kosong (belum dihitung)</td></tr>
                            <tr><td>Belum scan, setelah jam selesai</td><td>ALPA</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-2 mb-0 text-muted">Helper: <code>presensi_status_efektif()</code>, <code>presensi_apply_status_efektif_rows()</code> di <code>helpers/presensi_jadwal.php</code>.</p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanFilter">
                    4. Filter sebelum dihitung rekap
                </button>
            </h2>
            <div id="rekapPanduanFilter" class="accordion-collapse collapse" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">Baris <code>presensi</code> difilter via <code>presensi_filter_rows_eligible()</code> agar hanya slot valid yang masuk rekap:</p>
                    <ul class="mb-0 ps-3">
                        <li><code>kegiatan_id &gt; 0</code></li>
                        <li>Tingkatan santri memang ada di jadwal kegiatan pada tanggal tersebut</li>
                        <li>Santri status aktif (<code>santri_sql_aktif_only</code>)</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanRekap">
                    5. Agregasi rekap
                </button>
            </h2>
            <div id="rekapPanduanRekap" class="accordion-collapse collapse" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <p class="fw-semibold mb-1">A. Rekap harian — <a href="<?= htmlspecialchars(app_href('/rekap/keaktifan_hari.php')) ?>">Keaktifan hari ini</a></p>
                    <p class="mb-2">Cross-join jadwal × santri eligible, LEFT JOIN presensi. Setiap kombinasi (kegiatan, santri, hari) punya status. Termasuk ringkasan SDM (pembimbing/munawib) dan kegiatan kosong.</p>

                    <p class="fw-semibold mb-1">B. Rekap bulanan — <a href="<?= htmlspecialchars(app_href('/rekap/index.php')) ?>">Rekap presensi</a></p>
                    <ol class="mb-3 ps-3">
                        <li>Finalisasi seluruh bulan</li>
                        <li>Query tabel <code>presensi</code> (mode Masehi atau Hijriyah via <code>kalender_hijriyah</code>)</li>
                        <li>Filter eligible + agregasi per tingkatan, santri, kegiatan</li>
                        <li>TELAT: HADIR lewat batas telat (catatan &quot;Terlambat N menit&quot; atau jam vs jadwal + <?= (int) $lateTolerance ?> menit)</li>
                        <li><?= htmlspecialchars(penilaian_kehadiran_rumus_absensi($pdo)) ?>; % kehadiran = ABSENSI ÷ N.HARI (N.HARI = slot terhitung)</li>
                        <li>Predikat: Baik 81–100%; Cukup 61–80%; Sedang 41–60%; Kurang 21–40%; Buruk ≤20%</li>
                    </ol>

                    <p class="fw-semibold mb-1">C. Rekap spesialis</p>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <ul class="mb-0 ps-3">
                                <li><a href="<?= htmlspecialchars(app_href('/rekap/pembimbing.php')) ?>">Payroll pembimbing</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/rekap/munawib.php')) ?>">Laporan munawib</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/rekap/pkpps_keaktivan.php')) ?>">Keaktivan PKPPS</a></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="mb-0 ps-3">
                                <li><a href="<?= htmlspecialchars(app_href('/rekap/kegiatan_khusus.php')) ?>">Kegiatan khusus</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/rekap/izin_telat.php')) ?>">Rekap telat</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/rekap/perizinan.php')) ?>">Rekap perizinan</a></li>
                                <li><a href="<?= htmlspecialchars(app_href('/pengasuh/laporan_hari.php')) ?>">Laporan harian pengasuh</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanStatus">
                    6. Ringkasan transisi status santri
                </button>
            </h2>
            <div id="rekapPanduanStatus" class="accordion-collapse collapse" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <table class="table table-sm table-bordered mb-3">
                        <thead class="table-light"><tr><th>Dari</th><th>Ke</th><th>Pemicu</th></tr></thead>
                        <tbody>
                            <tr><td>Belum scan</td><td>HADIR</td><td>Scan QR di <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">presensi/scan</a></td></tr>
                            <tr><td>Belum scan</td><td>IZIN / SAKIT</td><td>Sinkronisasi + perizinan / izin tetap</td></tr>
                            <tr><td>Belum scan</td><td>ALPA</td><td>Sinkronisasi setelah jam kegiatan selesai</td></tr>
                            <tr><td>HADIR</td><td>HADIR</td><td>Scan ulang ditolak (duplikat)</td></tr>
                        </tbody>
                    </table>
                    <p class="mb-0 text-muted"><strong>Catatan operasional:</strong> membuka rekap dapat menulis ALPA/IZIN/SAKIT baru ke database (bukan read-only). Telat ≠ ALPA — telat tetap HADIR dengan catatan keterlambatan.</p>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#rekapPanduanFile">
                    7. File kunci (developer)
                </button>
            </h2>
            <div id="rekapPanduanFile" class="accordion-collapse collapse" data-bs-parent="#rekapPanduanAccordion">
                <div class="accordion-body small">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Peran</th><th>File / fungsi</th></tr></thead>
                        <tbody>
                            <tr><td>Scan santri/SDM</td><td><code>presensi/scan.php</code></td></tr>
                            <tr><td>Cocokkan jadwal saat scan</td><td><code>helpers/app.php</code> → <code>activity_for_tingkatan</code></td></tr>
                            <tr><td>Cocokkan PKPPS</td><td><code>helpers/pkpps.php</code> → <code>activity_for_pkpps_santri</code></td></tr>
                            <tr><td>Finalisasi + status efektif</td><td><code>helpers/presensi_jadwal.php</code></td></tr>
                            <tr><td>Auto IZIN/SAKIT/ALPA</td><td><code>helpers/app.php</code> → <code>sync_daily_presence_for_tingkatan</code></td></tr>
                            <tr><td>Rekap harian</td><td><code>helpers/rekap_keaktifan_hari.php</code></td></tr>
                            <tr><td>Rekap bulanan + ranking</td><td><code>rekap/index.php</code>, <code>helpers/rekap_keaktifan.php</code></td></tr>
                            <tr><td>Keterlambatan scan</td><td><code>helpers/presensi_scan_client.php</code> → <code>presensi_scan_catatan_telat</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
