<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/wa_laporan_alpa.php';

$sampleRows = [
    [
        'nama_santri' => 'Ahmad Santri',
        'nis' => '001',
        'tingkatan' => 'Wustho 1',
        'nama_kegiatan' => 'Akumulasi',
        'total_alpha' => 10,
    ],
    [
        'nama_santri' => 'Budi Santri',
        'nis' => '002',
        'tingkatan' => 'Ulya 2',
        'nama_kegiatan' => 'Akumulasi',
        'total_alpha' => 12,
    ],
];

$userTemplate = <<<'TPL'
*KRITERIA REKAPITULASI:*
* *Batas Minimal:* ALPA ≥ {ambang}
📋 *DAFTAR SANTRI:*
{daftar_santri_alpa}
Mohon tindak lanjut.
TPL;

$matchUser = wa_laporan_alpa_find_daftar_token(trim($userTemplate));
$normalized = str_replace('{daftar_santri_alpa}', '{daftar_santri}', trim($userTemplate));
$matchNorm = wa_laporan_alpa_find_daftar_token($normalized);

$santriList = wa_laporan_alpa_group_by_santri($sampleRows);
$daftar = wa_laporan_alpa_format_daftar_santri($santriList);
$customMsg = '';
if ($matchNorm !== null) {
    $token = (string) $matchNorm['token'];
    $pos = (int) $matchNorm['pos'];
    $customMsg = rtrim(mb_substr($normalized, 0, $pos) . $daftar . mb_substr($normalized, $pos + mb_strlen($token)));
}

$messagesDefault = wa_format_rekap_alpa_per_santri_messages($pdo, 'kumulatif sejak 1 Juni 2026', 10, $sampleRows);
$msgDefault = $messagesDefault[0] ?? '';

$manyRows = [];
for ($i = 1; $i <= 80; $i++) {
    $manyRows[] = [
        'nama_santri' => 'Santri Panjang Nama Contoh ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'nis' => (string) $i,
        'tingkatan' => 'Tingkatan ' . (($i % 5) + 1),
        'nama_kegiatan' => 'Akumulasi',
        'total_alpha' => 5 + ($i % 10),
    ];
}
$messagesMany = wa_format_rekap_alpa_per_santri_messages($pdo, 'bulan Agustus 2026', 5, $manyRows);
$maxLen = wa_laporan_alpa_message_max_len($pdo);
$allPartsWithinLimit = true;
$hasLanjutan = false;
foreach ($messagesMany as $part) {
    if (mb_strlen($part) > $maxLen) {
        $allPartsWithinLimit = false;
    }
    if (str_contains($part, 'lanjutan')) {
        $hasLanjutan = true;
    }
}

$checks = [
    'find_token_user_template' => $matchUser !== null && ($matchUser['token'] ?? '') === '{daftar_santri_alpa}',
    'find_token_after_normalize' => $matchNorm !== null,
    'custom_has_ahmad' => str_contains($customMsg, 'Ahmad Santri'),
    'custom_has_budi' => str_contains($customMsg, 'Budi Santri'),
    'custom_no_placeholder' => !str_contains($customMsg, '{daftar_santri'),
    'default_has_ahmad' => str_contains($msgDefault, 'Ahmad Santri'),
    'default_no_placeholder' => !str_contains($msgDefault, '{daftar_santri'),
    'many_santri_multipart' => count($messagesMany) > 1,
    'many_santri_has_lanjutan' => $hasLanjutan,
    'many_santri_parts_within_limit' => $allPartsWithinLimit,
    'message_max_len_sane' => $maxLen >= 500 && $maxLen <= wa_laporan_alpa_message_hard_max(),
];

$allOk = !in_array(false, $checks, true);
echo ($allOk ? 'PASS' : 'FAIL') . "\n";
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo 'multipart_count=' . count($messagesMany) . ' max_len=' . $maxLen . "\n";
exit($allOk ? 0 : 1);
