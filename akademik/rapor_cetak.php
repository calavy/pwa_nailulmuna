<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_rapor.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/surat_cetak_templates.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_rapor_columns($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Rapor tidak ditemukan.');
}

$st = $pdo->prepare('
    SELECT r.*, s.nis, s.nama_santri, s.tingkatan
    FROM akademik_rapor r
    INNER JOIN santri s ON s.id = r.santri_id
    WHERE r.id = :id LIMIT 1
');
$st->execute(['id' => $id]);
$rapor = $st->fetch(PDO::FETCH_ASSOC);
if (!$rapor) {
    exit('Rapor tidak ditemukan.');
}

$kop = pondok_kop_data($pdo);
$periode = rapor_periode_dari_row($pdo, $rapor);
$santriId = (int) ($rapor['santri_id'] ?? 0);
$tingkatan = trim((string) ($rapor['tingkatan'] ?? ''));
$raporPeriodeLabel = (string) $periode['label'];
$raporPresensi = rapor_presensi_bulan($pdo, $santriId, $periode);
$raporSetoran = rapor_setoran_bulan($pdo, $santriId, $periode);
$raporTugas = rapor_tugas_bulan($pdo, $santriId, $periode);
$raporCompact = false;

$namaPengasuh = (string) $kop['nama_pengasuh'];
$namaKetuaYayasan = (string) $kop['nama_ketua_yayasan'];
$namaWaliKelas = rapor_wali_kelas_santri($pdo, $santriId, $tingkatan);
$kotip = $kop['kota_ponpes'];
$tglTerbit = (string) ($rapor['tanggal_terbit'] ?? date('Y-m-d'));
$tglTerbitTampil = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglTerbit)
    ? date('d-m-Y', strtotime($tglTerbit))
    : $tglTerbit;
$jamCetak = date('d-m-Y H:i');
$raporJudul = surat_cetak_template_render($pdo, 'rapor_judul', [
    'nama_ponpes' => (string) $kop['nama_ponpes'],
    'tahun_ajaran' => $raporPeriodeLabel,
]);
$autoPrint = !isset($_GET['preview']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Rapor — <?= htmlspecialchars((string) ($rapor['nama_santri'] ?? '')) ?></title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            font-size: 10pt;
            color: #0f172a;
            margin: 0;
            background: #f1f5f9;
        }
        .no-print {
            max-width: 210mm;
            margin: 10px auto;
            padding: 0 12px;
            text-align: right;
        }
        .no-print a, .no-print button {
            display: inline-block;
            margin-left: 6px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid #94a3b8;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            cursor: pointer;
        }
        .sheet {
            position: relative;
            max-width: 210mm;
            margin: 0 auto 16px;
            padding: 14mm 16mm 16mm;
            background: #fff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }
        .sheet::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-28deg);
            width: 280px;
            height: 280px;
            background-image: url("<?= htmlspecialchars((string) $kop['logo']) ?>");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.04;
            pointer-events: none;
            z-index: 0;
        }
        .kop {
            display: flex;
            gap: 12px;
            align-items: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }
        .kop img {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
        }
        .kop-mid { flex: 1; text-align: center; }
        .kop-mid .tag {
            margin: 0;
            font-size: 8.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #334155;
        }
        .kop-mid .nama {
            margin: 2px 0;
            font-size: 14pt;
            font-weight: 800;
            color: #065f46;
            text-transform: uppercase;
            line-height: 1.15;
        }
        .kop-mid .addr {
            margin: 0;
            font-size: 8.5pt;
            font-style: italic;
            color: #475569;
        }
        .kop-mid .contact { margin: 2px 0 0; font-size: 7.8pt; color: #64748b; }
        .doc-title {
            text-align: center;
            margin: 10px 0 12px;
            position: relative;
            z-index: 1;
        }
        .doc-title h1 {
            margin: 0;
            font-size: 13pt;
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .doc-title .sub {
            margin-top: 4px;
            font-size: 9pt;
            color: #475569;
        }
        .ident {
            margin-bottom: 12px;
            font-size: 9.5pt;
            line-height: 1.5;
            position: relative;
            z-index: 1;
        }
        .ident table { width: 100%; border-collapse: collapse; }
        .ident td { padding: 1px 0; vertical-align: top; }
        .ident td:first-child { width: 110px; font-weight: 600; color: #334155; }
        .isi-rapor {
            position: relative;
            z-index: 1;
            font-size: 9.5pt;
        }
        .isi-rapor h3 {
            font-size: 10pt;
            color: #0f766e;
            border-bottom: 1px solid #99f6e4;
            padding-bottom: 3px;
            margin: 14px 0 8px;
        }
        .isi-rapor table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.8pt;
            margin-bottom: 8px;
        }
        .isi-rapor th, .isi-rapor td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
        }
        .isi-rapor th { background: #f8fafc; font-weight: 700; }
        .badge-kat {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 8pt;
            font-weight: 600;
        }
        .badge-kat.bagus, .badge-kat.baik { background: #dcfce7; color: #166534; }
        .badge-kat.sedang { background: #fef9c3; color: #854d0e; }
        .badge-kat.buruk { background: #fee2e2; color: #991b1b; }
        .badge-kat.other { background: #f1f5f9; color: #475569; }
        .narasi-box {
            white-space: pre-wrap;
            margin-bottom: 10px;
            line-height: 1.45;
        }
        .ttd-wrap {
            margin-top: 18px;
            position: relative;
            z-index: 1;
        }
        .ttd-meta {
            text-align: right;
            font-size: 9.5pt;
            margin-bottom: 8px;
        }
        .ttd {
            display: flex;
            gap: 12px;
            justify-content: space-between;
        }
        .ttd .box {
            flex: 1;
            text-align: center;
            max-width: 48%;
        }
        .ttd .jab {
            font-size: 9pt;
            color: #475569;
            min-height: 2.2em;
            margin-bottom: 4px;
        }
        .sign-space { height: 18mm; min-height: 52px; }
        .ttd .line {
            border-top: 1px solid #0f172a;
            padding-top: 6px;
            font-weight: 700;
            font-size: 9pt;
            margin: 0 8%;
        }
        .print-time {
            margin-top: 10px;
            text-align: right;
            font-size: 7.5pt;
            color: #94a3b8;
        }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet {
                margin: 0;
                border: none;
                box-shadow: none;
                max-width: none;
            }
            .badge-kat {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body<?= $autoPrint ? ' onload="window.print()"' : '' ?>>
<div class="no-print">
    <a href="<?= htmlspecialchars(app_href('/akademik/rapor_lihat.php?id=' . $id)) ?>">← Kembali</a>
    <button type="button" onclick="window.print()">Cetak</button>
</div>

<div class="sheet">
    <div class="kop">
        <?php if ($kop['logo'] !== ''): ?>
            <img src="<?= htmlspecialchars((string) $kop['logo']) ?>" alt="">
        <?php endif; ?>
        <div class="kop-mid">
            <p class="tag"><?= htmlspecialchars((string) ($kop['jenis_label'] ?? ($kop['jenis_pendidikan'] !== '' ? $kop['jenis_pendidikan'] : 'Lembaga Pondok Pesantren'))) ?></p>
            <p class="nama"><?= htmlspecialchars((string) $kop['nama_ponpes']) ?></p>
            <?php if ($kop['alamat_ponpes'] !== ''): ?>
                <p class="addr"><?= htmlspecialchars((string) $kop['alamat_ponpes']) ?></p>
            <?php endif; ?>
            <?php if ($kop['telp_ponpes'] !== '' || $kop['website_ponpes'] !== ''): ?>
                <p class="contact">
                    <?php if ($kop['telp_ponpes'] !== ''): ?>Telp: <?= htmlspecialchars((string) $kop['telp_ponpes']) ?><?php endif; ?>
                    <?php if ($kop['telp_ponpes'] !== '' && $kop['website_ponpes'] !== ''): ?> · <?php endif; ?>
                    <?php if ($kop['website_ponpes'] !== ''): ?><?= htmlspecialchars((string) $kop['website_ponpes']) ?><?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="doc-title">
        <h1><?= htmlspecialchars($raporJudul) ?></h1>
        <div class="sub"><?= htmlspecialchars((string) ($rapor['judul_periode'] ?? '')) ?></div>
    </div>

    <div class="ident">
        <table>
            <tr><td>Nama Santri</td><td>: <?= htmlspecialchars((string) ($rapor['nama_santri'] ?? '')) ?></td></tr>
            <tr><td>NIS</td><td>: <?= htmlspecialchars((string) ($rapor['nis'] ?? '')) ?></td></tr>
            <?php if ($tingkatan !== ''): ?>
                <tr><td>Tingkatan</td><td>: <?= htmlspecialchars($tingkatan) ?></td></tr>
            <?php endif; ?>
            <tr><td>Periode penilaian</td><td>: <?= htmlspecialchars($raporPeriodeLabel) ?></td></tr>
            <tr><td>Tanggal terbit</td><td>: <?= htmlspecialchars($tglTerbitTampil) ?></td></tr>
            <?php if (trim((string) ($rapor['predikat_akhlak'] ?? '')) !== ''): ?>
                <tr><td>Predikat akhlak</td><td>: <?= htmlspecialchars((string) $rapor['predikat_akhlak']) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="isi-rapor">
        <?php if (trim((string) ($rapor['narasi'] ?? '')) !== ''): ?>
            <h3>Ringkasan / Narasi</h3>
            <div class="narasi-box"><?= htmlspecialchars((string) $rapor['narasi']) ?></div>
        <?php endif; ?>
        <?php if (trim((string) ($rapor['catatan_pondok'] ?? '')) !== ''): ?>
            <h3>Catatan pondok</h3>
            <div class="narasi-box"><?= htmlspecialchars((string) $rapor['catatan_pondok']) ?></div>
        <?php endif; ?>

        <?php
        // Partial rapor_isi memakai badge Bootstrap; untuk cetak kita render manual ringkas
        ?>
        <h3>Presensi bulanan</h3>
        <?php if ($raporPresensi === null): ?>
            <p class="text-muted" style="margin:0 0 8px;color:#64748b;">Tidak ada data presensi pada periode ini.</p>
        <?php else: ?>
            <?php
            $kat = (string) ($raporPresensi['kategori'] ?? '');
            $katClass = match ($kat) {
                'Bagus', 'Baik' => 'baik',
                'Sedang' => 'sedang',
                'Buruk' => 'buruk',
                default => 'other',
            };
            ?>
            <p style="margin:0 0 8px;">
                <span class="badge-kat <?= htmlspecialchars($katClass) ?>"><?= htmlspecialchars($kat !== '' ? $kat : '-') ?></span>
                — Hadir <strong><?= (int) ($raporPresensi['hadir'] ?? 0) ?></strong>/<?= (int) ($raporPresensi['total'] ?? 0) ?>
                (<?= htmlspecialchars((string) ($raporPresensi['persen_hadir'] ?? 0)) ?>%)
                · I: <?= (int) ($raporPresensi['izin'] ?? 0) ?>
                · S: <?= (int) ($raporPresensi['sakit'] ?? 0) ?>
                · A: <?= (int) ($raporPresensi['alpa'] ?? 0) ?>
            </p>
            <?php
            $perKg = $raporPresensi['per_kegiatan'] ?? [];
            if (is_array($perKg) && $perKg !== []):
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>Kegiatan</th>
                            <th style="text-align:center">H</th>
                            <th style="text-align:center">I</th>
                            <th style="text-align:center">S</th>
                            <th style="text-align:center">A</th>
                            <th style="text-align:center">%</th>
                            <th>Kategori</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($perKg as $namaKg => $kg):
                        $kgKat = (string) ($kg['kategori'] ?? '');
                        $kgKatClass = match ($kgKat) {
                            'Bagus', 'Baik' => 'baik',
                            'Sedang' => 'sedang',
                            'Buruk' => 'buruk',
                            default => 'other',
                        };
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $namaKg) ?></td>
                            <td style="text-align:center"><?= (int) ($kg['hadir'] ?? 0) ?></td>
                            <td style="text-align:center"><?= (int) ($kg['izin'] ?? 0) ?></td>
                            <td style="text-align:center"><?= (int) ($kg['sakit'] ?? 0) ?></td>
                            <td style="text-align:center"><?= (int) ($kg['alpa'] ?? 0) ?></td>
                            <td style="text-align:center"><?= htmlspecialchars((string) ($kg['persen_hadir'] ?? 0)) ?></td>
                            <td><span class="badge-kat <?= htmlspecialchars($kgKatClass) ?>"><?= htmlspecialchars($kgKat !== '' ? $kgKat : '-') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <h3>Setoran hafalan</h3>
        <?php if ($raporSetoran === []): ?>
            <p style="margin:0 0 8px;color:#64748b;">Belum ada setoran pada periode ini.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Materi</th>
                        <th style="text-align:right">Nilai</th>
                        <th>Predikat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($raporSetoran as $st): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($st['tanggal_setoran'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(rapor_setoran_kategori_label((string) ($st['kategori_setoran'] ?? 'ALQURAN'))) ?></td>
                        <td><?= htmlspecialchars((string) ($st['target_hafalan'] ?? '')) ?><?= trim((string) ($st['juz_halaman'] ?? '')) !== '' ? ' · ' . htmlspecialchars((string) $st['juz_halaman']) : '' ?></td>
                        <td style="text-align:right"><?= $st['nilai_skor'] !== null && $st['nilai_skor'] !== '' ? htmlspecialchars((string) $st['nilai_skor']) : '—' ?></td>
                        <td><?= htmlspecialchars(trim((string) ($st['predikat'] ?? '')) !== '' ? (string) $st['predikat'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Hasil tugas (Ikhtibar)</h3>
        <?php if ($raporTugas === []): ?>
            <p style="margin:0;color:#64748b;">Belum ada tugas / nilai pada periode ini.</p>
        <?php else: ?>
            <?php foreach ($raporTugas as $grp): ?>
                <p style="margin:10px 0 4px;font-weight:700;">
                    <?= htmlspecialchars((string) ($grp['pembimbing_nama'] ?? 'Pembimbing')) ?>
                    <span style="font-weight:400;color:#475569;"> · <?= htmlspecialchars((string) ($grp['mapel_label'] ?? '')) ?></span>
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>Tugas</th>
                            <th>Tanggal</th>
                            <th>Status</th>
                            <th style="text-align:right">PG</th>
                            <th style="text-align:right">Esai</th>
                            <th style="text-align:right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($grp['tugas'] ?? []) as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($t['judul'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($t['tanggal'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(rapor_sesi_status_label((string) ($t['sesi_status'] ?? ''))) ?></td>
                            <td style="text-align:right"><?= $t['skor_pg'] !== null && $t['skor_pg'] !== '' ? htmlspecialchars((string) $t['skor_pg']) : '—' ?></td>
                            <td style="text-align:right"><?= $t['skor_esai'] !== null && $t['skor_esai'] !== '' ? htmlspecialchars((string) $t['skor_esai']) : '—' ?></td>
                            <td style="text-align:right"><strong><?= $t['nilai_total'] !== null && $t['nilai_total'] !== '' ? htmlspecialchars((string) $t['nilai_total']) : '—' ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="ttd-wrap">
        <div class="ttd-meta"><?= htmlspecialchars($kotip) ?>, <?= htmlspecialchars($tglTerbitTampil) ?></div>
        <div class="ttd">
            <div class="box">
                <div class="jab">Ketua Yayasan,</div>
                <div class="sign-space"></div>
                <div class="line"><?= htmlspecialchars($namaKetuaYayasan !== '' ? $namaKetuaYayasan : '(_______________________)') ?></div>
            </div>
            <div class="box">
                <div class="jab">Pengasuh,</div>
                <div class="sign-space"></div>
                <div class="line"><?= htmlspecialchars($namaPengasuh !== '' ? $namaPengasuh : '(_______________________)') ?></div>
            </div>
            <div class="box">
                <div class="jab">Wali Kelas,</div>
                <div class="sign-space"></div>
                <div class="line"><?= htmlspecialchars($namaWaliKelas !== '' ? $namaWaliKelas : '(_______________________)') ?></div>
            </div>
        </div>
        <?php if ($namaWaliKelas === ''): ?>
            <p style="font-size:7.5pt;color:#94a3b8;margin:6px 0 0;text-align:center;">
                Isi nama wali kelas di menu Santri → Riwayat tingkatan untuk tingkatan ini.
            </p>
        <?php endif; ?>
        <div class="print-time">Waktu cetak: <?= htmlspecialchars($jamCetak) ?></div>
    </div>
</div>
</body>
</html>
