<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/akademik_hari_khusus.php';
require_once __DIR__ . '/akademik_pasaran.php';

/** @return array<int, string> */
function akademik_kalender_nama_bulan_masehi(): array
{
    return [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
}

/** @return array<int, string> */
function akademik_kalender_hari_minggu(): array
{
    return [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
}

/** Nama hari lengkap untuk header kalender (lebih mudah dibaca). */
function akademik_kalender_hari_minggu_panjang(): array
{
    return [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
}

/**
 * @return list<string>
 */
function akademik_kalender_hari_dalam_bulan_masehi(int $tahun, int $bulan): array
{
    $bulan = max(1, min(12, $bulan));
    $tahun = max(1, min(9999, $tahun));
    $jumlah = (int) date('t', strtotime(sprintf('%04d-%02d-01', $tahun, $bulan)));
    $out = [];
    for ($d = 1; $d <= $jumlah; $d++) {
        $out[] = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function akademik_kalender_libur_overlap(PDO $pdo, string $mulai, string $selesai): array
{
    ensure_akademik_libur_table($pdo);
    $st = $pdo->prepare('SELECT * FROM akademik_libur WHERE tanggal_selesai >= :s AND tanggal_mulai <= :e ORDER BY tanggal_mulai');
    $st->execute(['s' => $mulai, 'e' => $selesai]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string, mixed>> $liburRows
 * @param list<array<string, mixed>> $liburMingguanRows
 * @return array{nama:string,flags:list<string>,tipe?:string}|null
 */
function akademik_kalender_libur_pada_tanggal(array $liburRows, string $ymd, array $liburMingguanRows = []): ?array
{
    foreach ($liburRows as $L) {
        if ($ymd < (string) $L['tanggal_mulai'] || $ymd > (string) $L['tanggal_selesai']) {
            continue;
        }
        $flags = [];
        if (!empty($L['affects_presensi'])) {
            $flags[] = 'P';
        }
        if (!empty($L['affects_setoran'])) {
            $flags[] = 'S';
        }
        if (!empty($L['affects_penilaian'])) {
            $flags[] = 'N';
        }

        return ['nama' => (string) ($L['nama'] ?? 'Libur'), 'flags' => $flags, 'tipe' => 'rentang'];
    }

    $ts = strtotime($ymd);
    if ($ts !== false && $liburMingguanRows !== []) {
        $hariKe = (int) date('N', $ts);
        foreach ($liburMingguanRows as $L) {
            if ((int) ($L['hari_ke'] ?? 0) !== $hariKe) {
                continue;
            }
            $flags = [];
            if (!empty($L['affects_presensi'])) {
                $flags[] = 'P';
            }
            if (!empty($L['affects_setoran'])) {
                $flags[] = 'S';
            }
            if (!empty($L['affects_penilaian'])) {
                $flags[] = 'N';
            }

            return ['nama' => (string) ($L['nama'] ?? 'Libur'), 'flags' => $flags, 'tipe' => 'mingguan'];
        }
    }

    return null;
}

/**
 * @param array<int, string> $hijriBulanNama
 * @return array<string, mixed>
 */
function akademik_kalender_meta_hari(
    PDO $pdo,
    string $ymd,
    array $liburRows,
    array $calMap,
    array $hijriBulanNama,
    string $todayMasehi,
    array $liburMingguanRows = []
): array {
    $ts = strtotime($ymd);
    $hijri = akademik_hijri_tanggal_sistem($pdo, $ymd);
    $hijriK = akademik_hijri_komponen_dari_ymd($hijri, $hijriBulanNama);
    $row = $calMap[$ymd] ?? null;
    $libur = akademik_kalender_libur_pada_tanggal($liburRows, $ymd, $liburMingguanRows);
    $hariKhusus = akademik_hari_khusus_pada_tanggal($pdo, $ymd, $hijriBulanNama);
    $isLibur = $libur !== null || !empty($row['is_libur']);
    $liburNama = $libur['nama'] ?? ($isLibur ? 'Libur' : '');
    if ($liburNama === '' && $hariKhusus !== null) {
        $liburNama = $hariKhusus['nama'];
    }
    $isJumat = $ts !== false && (int) date('N', $ts) === 5;
    $hijriBulanIdx = (int) ($hijriK['b'] ?? 0);
    $pasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($ymd, $pdo) : '';

    return [
        'masehi' => $ymd,
        'masehi_hari' => $ts !== false ? (int) date('j', $ts) : 0,
        'masehi_bulan' => $ts !== false ? (int) date('n', $ts) : 0,
        'masehi_label' => akademik_masehi_label_pendek($ymd),
        'hijri' => $hijri,
        'hijri_short' => $hijriK['h'] ?? 0,
        'hijri_bulan' => $hijriK['b'] ?? 0,
        'hijri_tahun' => $hijriK['t'] ?? 0,
        'hijri_bulan_nama' => $hijriK['bulan_nama'] ?? '',
        'hijri_label' => $hijriK !== null ? sprintf('%d %s %d', $hijriK['h'], $hijriK['bulan_nama'], $hijriK['t']) : '',
        'hijri_ringkas' => $hijriK !== null ? sprintf('%d %s', $hijriK['h'], $hijriK['bulan_nama']) : '',
        'hijri_arab' => akademik_format_hijri_ke_arab_dengan_nama($hijri, $hijriBulanNama),
        'hijri_bulan_idx' => $hijriBulanIdx,
        'hari_khusus' => $hariKhusus,
        'hari_khusus_nama' => $hariKhusus['nama'] ?? '',
        'hari_khusus_jenis' => $hariKhusus['jenis'] ?? '',
        'pasaran' => $pasaran,
        'pasaran_kelas' => $pasaran !== '' ? akademik_pasaran_kelas_css($ymd, $pdo) : '',
        'is_jumat' => $isJumat,
        'is_libur_mingguan' => ($libur['tipe'] ?? '') === 'mingguan',
        'is_today' => $ymd === $todayMasehi,
        'is_libur' => $isLibur,
        'libur_nama' => $liburNama,
        'libur_flags' => $libur['flags'] ?? [],
    ];
}

/** Subjudul hijriyah untuk satu bulan Masehi di tampilan tahun. */
function akademik_kalender_subjudul_hijri_bulan(PDO $pdo, string $gStart, string $gEnd, array $hijriBulanNama): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gEnd)) {
        return '';
    }
    $a = akademik_hijri_komponen_dari_ymd(akademik_hijri_tanggal_sistem($pdo, $gStart), $hijriBulanNama);
    $b = akademik_hijri_komponen_dari_ymd(akademik_hijri_tanggal_sistem($pdo, $gEnd), $hijriBulanNama);
    if ($a === null || $b === null) {
        return '';
    }
    if ($a['label'] === $b['label']) {
        return $a['label'];
    }

    return $a['label'] . ' — ' . $b['label'];
}

/**
 * @param list<array<string, mixed>> $days Meta hari (harus punya kunci masehi Y-m-d)
 * @return list<array<string, mixed>|null>
 */
function akademik_kalender_buat_grid_minggu(array $days): array
{
    if ($days === []) {
        return [];
    }
    $firstYmd = (string) ($days[0]['masehi'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstYmd)) {
        return $days;
    }
    $firstTs = strtotime($firstYmd);
    $pad = $firstTs !== false ? (int) date('N', $firstTs) - 1 : 0;
    $cells = array_fill(0, $pad, null);
    foreach ($days as $day) {
        $cells[] = $day;
    }
    while (count($cells) % 7 !== 0) {
        $cells[] = null;
    }

    return $cells;
}

/**
 * @param array<int, string> $hijriBulanNama
 * @return array{
 *   cells:list<array<string,mixed>|null>,
 *   gStart:string,
 *   gEnd:string,
 *   days:list<array<string,mixed>>
 * }
 */
function akademik_kalender_siapkan_bulan_masehi(
    PDO $pdo,
    int $tahun,
    int $bulan,
    array $hijriBulanNama,
    string $todayMasehi,
    ?array $liburRows = null,
    ?array $calMap = null,
    ?array $liburMingguanRows = null
): array {
    $hariList = akademik_kalender_hari_dalam_bulan_masehi($tahun, $bulan);
    $gStart = $hariList[0] ?? sprintf('%04d-%02d-01', $tahun, $bulan);
    $gEnd = $hariList !== [] ? $hariList[count($hariList) - 1] : $gStart;
    if ($liburRows === null) {
        $liburRows = akademik_kalender_libur_overlap($pdo, $gStart, $gEnd);
    }
    if ($calMap === null) {
        $calMap = akademik_kalender_hari_map_range($pdo, $gStart, $gEnd);
    }
    if ($liburMingguanRows === null) {
        $liburMingguanRows = akademik_libur_mingguan_rows($pdo);
    }
    $days = [];
    foreach ($hariList as $ymd) {
        $days[] = akademik_kalender_meta_hari($pdo, $ymd, $liburRows, $calMap, $hijriBulanNama, $todayMasehi, $liburMingguanRows);
    }
    $cellsRaw = akademik_kalender_buat_grid_minggu($days);

    return [
        'cells' => $cellsRaw,
        'gStart' => $gStart,
        'gEnd' => $gEnd,
        'days' => $days,
    ];
}

/**
 * @param array<int, string> $hijriBulanNama
 * @return array{
 *   cells:list<array<string,mixed>|null>,
 *   gStart:string,
 *   gEnd:string,
 *   days:list<array<string,mixed>>
 * }
 */
function akademik_kalender_siapkan_bulan_hijri(
    PDO $pdo,
    int $tahunH,
    int $bulanH,
    array $hijriBulanNama,
    string $todayMasehi
): array {
    $hariList = akademik_masehi_days_in_hijri_month($pdo, $tahunH, $bulanH);
    if ($hariList === []) {
        [$gStart, $gEnd] = akademik_gregorian_range_from_hijri_month($pdo, $tahunH, $bulanH);
        $hariList = masehi_linear_days_between($gStart, $gEnd);
    }
    $gStart = $hariList[0] ?? date('Y-m-d');
    $gEnd = $hariList !== [] ? $hariList[count($hariList) - 1] : $gStart;
    $liburRows = akademik_kalender_libur_overlap($pdo, $gStart, $gEnd);
    $liburMingguanRows = akademik_libur_mingguan_rows($pdo);
    $calMap = akademik_kalender_hari_map_range($pdo, $gStart, $gEnd);
    $days = [];
    foreach ($hariList as $ymd) {
        $days[] = akademik_kalender_meta_hari($pdo, $ymd, $liburRows, $calMap, $hijriBulanNama, $todayMasehi, $liburMingguanRows);
    }
    $cellsRaw = akademik_kalender_buat_grid_minggu($days);

    return [
        'cells' => $cellsRaw,
        'gStart' => $gStart,
        'gEnd' => $gEnd,
        'days' => $days,
    ];
}

/**
 * Input tanggal Masehi format H/B/T (Hari · Bulan · Tahun).
 *
 * @param int|string $indeks Kunci array POST (1–12) atau '' untuk form tunggal
 */
function hijri_render_input_hbt(
    string $fieldPrefix,
    int|string $indeks,
    string $ymdValue = '',
    string $inputSize = 'sm'
): void {
    $hbt = $ymdValue !== '' ? hijri_masehi_ke_hbt($ymdValue) : ['h' => 0, 'b' => 0, 't' => 0];
    $suffix = $indeks === '' ? '' : '[' . $indeks . ']';
    $sizeClass = $inputSize === 'sm' ? ' form-control-sm' : '';
    ?>
    <div class="hijri-hbt-group d-flex flex-wrap align-items-center gap-1">
        <label class="hijri-hbt-field mb-0">
            <span class="hijri-hbt-label">H</span>
            <input type="number" class="form-control<?= $sizeClass ?> hijri-hbt-input" name="<?= htmlspecialchars($fieldPrefix) ?>_h<?= $suffix ?>"
                   value="<?= $hbt['h'] > 0 ? (int) $hbt['h'] : '' ?>" min="1" max="31" placeholder="1" title="Hari" inputmode="numeric">
        </label>
        <span class="hijri-hbt-sep">/</span>
        <label class="hijri-hbt-field mb-0">
            <span class="hijri-hbt-label">B</span>
            <input type="number" class="form-control<?= $sizeClass ?> hijri-hbt-input" name="<?= htmlspecialchars($fieldPrefix) ?>_b<?= $suffix ?>"
                   value="<?= $hbt['b'] > 0 ? (int) $hbt['b'] : '' ?>" min="1" max="12" placeholder="1" title="Bulan" inputmode="numeric">
        </label>
        <span class="hijri-hbt-sep">/</span>
        <label class="hijri-hbt-field mb-0">
            <span class="hijri-hbt-label">T</span>
            <input type="number" class="form-control<?= $sizeClass ?> hijri-hbt-input" name="<?= htmlspecialchars($fieldPrefix) ?>_t<?= $suffix ?>"
                   value="<?= $hbt['t'] > 0 ? (int) $hbt['t'] : '' ?>" min="1970" max="2100" placeholder="<?= (int) date('Y') ?>" title="Tahun Masehi" inputmode="numeric">
        </label>
    </div>
    <?php
}

/** CSS class untuk sel hari. */
function akademik_kalender_cell_classes(array $day): string
{
    $c = ['akad-cal-day'];
    $ts = strtotime((string) ($day['masehi'] ?? ''));
    $dow = $ts !== false ? (int) date('N', $ts) : 0;
    if ($dow === 6 || $dow === 7) {
        $c[] = 'akad-cal-day--weekend';
    }
    if (!empty($day['is_today'])) {
        $c[] = 'akad-cal-day--today';
    }
    if (!empty($day['is_jumat']) && empty($day['is_libur'])) {
        $c[] = 'akad-cal-day--jumat';
    }
    if (!empty($day['is_libur_mingguan'])) {
        $c[] = 'akad-cal-day--libur-minggu';
    }
    if (!empty($day['is_libur'])) {
        $c[] = 'akad-cal-day--libur';
    }
    $hb = (int) ($day['hijri_bulan_idx'] ?? 0);
    if ($hb >= 1 && $hb <= 12) {
        $c[] = 'akad-cal-hijri-bulan--' . $hb;
    }
    $jenisKhusus = (string) ($day['hari_khusus_jenis'] ?? '');
    if ($jenisKhusus === 'islam') {
        $c[] = 'akad-cal-day--hari-islam';
    } elseif ($jenisKhusus === 'nasional') {
        $c[] = 'akad-cal-day--hari-nasional';
    }
    if (!empty($day['agenda_items'])) {
        $c[] = 'akad-cal-day--has-agenda';
    }

    return implode(' ', $c);
}

/**
 * @param list<array<string,mixed>|null> $cells
 * @param 'masehi'|'hijri' $datePrimary Tanggal utama yang ditampilkan besar di sel
 */
function akademik_kalender_render_month(
    array $cells,
    bool $compact = false,
    string $monthTitle = '',
    string $monthSubtitle = '',
    bool $agendaKlik = false,
    string $datePrimary = 'masehi'
): void {
    $tableClass = 'akad-cal-table' . ($compact ? ' akad-cal-table--compact' : '');
    $hari = $compact ? akademik_kalender_hari_minggu() : akademik_kalender_hari_minggu_panjang();
    $hijriPrimary = $datePrimary === 'hijri';
    ?>
    <div class="akad-cal-month-block<?= $compact ? ' akad-cal-month-block--compact' : '' ?><?= $hijriPrimary ? ' akad-cal-month-block--hijri-primary' : '' ?>">
        <?php if ($monthTitle !== ''): ?>
            <div class="akad-cal-month-head">
                <h3 class="akad-cal-month-title"><?= htmlspecialchars($monthTitle) ?></h3>
                <?php if ($monthSubtitle !== ''): ?>
                    <p class="akad-cal-month-subtitle"><?= htmlspecialchars($monthSubtitle) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="akad-cal-table-shell">
        <table class="<?= htmlspecialchars($tableClass) ?>" role="grid">
            <thead>
                <tr>
                    <?php foreach ($hari as $idx => $label): ?>
                        <?php
                        $thExtra = $idx === 5 ? 'akad-cal-th--jumat' : ($idx >= 6 ? 'akad-cal-th--weekend' : '');
                        ?>
                        <th scope="col" class="<?= htmlspecialchars($thExtra) ?>"><?= htmlspecialchars($label) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $weeks = (int) ceil(count($cells) / 7);
            for ($w = 0; $w < $weeks; $w++):
                ?>
                <tr>
                <?php
                for ($c = 0; $c < 7; $c++):
                    $cell = $cells[$w * 7 + $c] ?? null;
                    if ($cell === null):
                        ?>
                        <td class="akad-cal-day akad-cal-day--empty" aria-hidden="true"></td>
                    <?php
                    else:
                        $colClass = $c === 4 ? ' akad-cal-col--jumat' : ($c >= 5 ? ' akad-cal-col--weekend' : '');
                        $agendaItems = is_array($cell['agenda_items'] ?? null) ? $cell['agenda_items'] : [];
                        $tipParts = array_filter([
                            (string) ($cell['masehi_label'] ?? ''),
                            (string) ($cell['hijri_label'] ?? ''),
                            (string) ($cell['pasaran'] ?? '') !== '' ? 'Pasaran ' . $cell['pasaran'] : '',
                            (string) ($cell['hari_khusus_nama'] ?? ''),
                            (string) ($cell['libur_nama'] ?? ''),
                        ]);
                        foreach ($agendaItems as $agTip) {
                            $tipParts[] = (string) ($agTip['judul'] ?? '');
                        }
                        $tip = implode(' · ', array_unique($tipParts));
                        $eventLabel = (string) ($cell['hari_khusus_nama'] ?? '');
                        $eventKind = (string) ($cell['hari_khusus_jenis'] ?? 'lain');
                        if ($eventLabel === '' && !empty($cell['is_libur']) && (string) ($cell['libur_nama'] ?? '') !== '') {
                            $eventLabel = (string) $cell['libur_nama'];
                            $eventKind = 'libur';
                        }
                        $masehiYmd = (string) ($cell['masehi'] ?? '');
                        $pickClass = $agendaKlik && $masehiYmd !== '' ? ' akad-cal-day--pick' : '';
                        $primaryNum = $hijriPrimary
                            ? (int) ($cell['hijri_short'] ?? 0)
                            : (int) ($cell['masehi_hari'] ?? 0);
                        if ($hijriPrimary) {
                            $altLabel = $compact && $masehiYmd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $masehiYmd)
                                ? date('d/m', strtotime($masehiYmd))
                                : (string) ($cell['masehi_label'] ?? '');
                        } else {
                            $altLabel = (string) ($cell['hijri_ringkas'] ?? '');
                        }
                        $ariaLabel = trim(
                            (string) ($cell['masehi_label'] ?? '')
                            . '. '
                            . (string) ($cell['hijri_label'] ?? '')
                            . ($tip !== '' ? '. ' . $tip : '')
                        );
                        ?>
                        <td class="<?= htmlspecialchars(akademik_kalender_cell_classes($cell) . $colClass . $pickClass) ?>"
                            <?= $masehiYmd !== '' ? ' data-masehi="' . htmlspecialchars($masehiYmd) . '"' : '' ?>
                            <?= $agendaKlik && $masehiYmd !== '' ? ' role="button" tabindex="0"' : ' tabindex="0"' ?>
                            <?= $ariaLabel !== '' ? ' aria-label="' . htmlspecialchars($ariaLabel) . '"' : '' ?>
                            <?= $tip !== '' ? ' title="' . htmlspecialchars($tip) . '"' : '' ?>>
                            <div class="akad-cal-day-inner">
                                <div class="akad-cal-day-top">
                                    <span class="akad-cal-day-num"><?= $primaryNum ?></span>
                                    <?php if (!empty($cell['is_today'])): ?>
                                        <span class="akad-cal-day-today-badge">Hari ini</span>
                                    <?php elseif (!empty($cell['is_libur'])): ?>
                                        <span class="akad-cal-day-libur-dot" aria-hidden="true"></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($altLabel !== ''): ?>
                                    <span class="akad-cal-day-alt<?= $compact ? ' akad-cal-day-alt--compact' : '' ?>">
                                        <?= htmlspecialchars($altLabel) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ((string) ($cell['pasaran'] ?? '') !== ''): ?>
                                    <span class="akad-cal-day-pasaran <?= htmlspecialchars((string) ($cell['pasaran_kelas'] ?? '')) ?><?= $compact ? ' akad-cal-day-pasaran--compact' : '' ?>">
                                        <?= htmlspecialchars((string) $cell['pasaran']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($eventLabel !== ''): ?>
                                    <span class="akad-cal-day-event akad-cal-day-event--<?= htmlspecialchars($eventKind) ?>">
                                        <?= htmlspecialchars($eventLabel) ?>
                                    </span>
                                <?php endif; ?>
                                <?php
                                $agendaShown = 0;
                                foreach ($agendaItems as $agCell):
                                    if ($agendaShown >= ($compact ? 1 : 2)) {
                                        break;
                                    }
                                    $agendaShown++;
                                    $agJudul = (string) ($agCell['judul'] ?? '');
                                    if ($agJudul === '') {
                                        continue;
                                    }
                                    ?>
                                    <span class="akad-cal-day-event akad-cal-day-event--agenda" title="<?= htmlspecialchars($agJudul) ?>">
                                        <?= htmlspecialchars(mb_strlen($agJudul) > ($compact ? 10 : 16) ? mb_substr($agJudul, 0, $compact ? 8 : 14) . '…' : $agJudul) ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($agendaItems) > ($compact ? 1 : 2)): ?>
                                    <span class="akad-cal-day-agenda-more">+<?= count($agendaItems) - ($compact ? 1 : 2) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php
                    endif;
                endfor;
                ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}
