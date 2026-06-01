<?php
/** Scan presensi flow simulation — audit only */
declare(strict_types=1);
$base = 'http://localhost/pwa_nailulmuna';
$jar = sys_get_temp_dir() . '/pwa_audit_scan.txt';
@unlink($jar);

function scan_post(string $url, array $fields, string $jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

function extract_scan_result(string $html): array {
    $type = '';
    $speak = '';
    if (preg_match('/id="presensi-scan-result"[^>]*data-type="([^"]*)"/', $html, $m)) {
        $type = $m[1];
    }
    if (preg_match('/data-speak="([^"]*)"/', $html, $m2)) {
        $speak = html_entity_decode($m2[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $banner = '';
    if (preg_match('/presensi-scan-banner[^>]*>([\s\S]*?)<\/div>/', $html, $m3)) {
        $banner = trim(strip_tags($m3[1]));
    }
    return [$type, $speak, $banner];
}

$portalUrl = "$base/presensi/scan.php?portal=1";

// Warm session
scan_post($portalUrl, ['pb_portal_scan' => '1'], $jar);

$cases = [
    ['label' => 'invalid_qr', 'fields' => ['kode_qr' => 'INVALID_QR_XYZ_999', 'scan_source' => 'camera', 'pb_portal_scan' => '1']],
    ['label' => 'duplicate_pb', 'fields' => ['kode_qr' => 'PMB001', 'scan_source' => 'camera', 'pb_portal_scan' => '1']],
];

echo "SCAN_FLOW\n";
foreach ($cases as $case) {
    $html = scan_post($portalUrl, $case['fields'], $jar);
    [$type, $speak, $banner] = extract_scan_result($html);
    $fatal = preg_match('/Fatal error|Parse error/i', $html) ? '1' : '0';
    echo "CASE|{$case['label']}|fatal=$fatal|type=$type|banner=$banner|speak=" . substr($speak, 0, 80) . "\n";
}

// Assets present
$html = file_get_contents($portalUrl) ?: '';
$assets = [
    'presensi-scan-feedback.js' => str_contains($html, 'presensi-scan-feedback'),
    'presensi-scan-timer.js' => str_contains($html, 'presensi-scan-timer'),
    'timer_hint' => str_contains($html, 'Waktu scan kurang') || str_contains($html, 'scan-timer-hint'),
];
foreach ($assets as $k => $v) {
    echo "ASSET|$k|" . ($v ? 'OK' : 'MISSING') . "\n";
}
