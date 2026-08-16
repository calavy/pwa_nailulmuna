<?php
/** Scan presensi + smart route — audit only */
declare(strict_types=1);
$base = 'http://localhost/pwa_nailulmuna';
$jar = sys_get_temp_dir() . '/pwa_audit_scan.txt';
@unlink($jar);

function scan_post_form(string $url, array $fields, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['X-PWA-Offline-Sync: 1'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function scan_post_json(string $url, array $payload, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-PWA-Offline-Sync: 1',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
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
$smartUrl = "$base/api/scan/smart.php";
$loginScanUrl = "$base/login.php?scan=1";

// Warm session
scan_post_form($portalUrl, ['pb_portal_scan' => '1'], $jar);
scan_post_json($smartUrl, ['scan_source' => 'camera', 'qr_code' => '__warm__'], $jar);

$cases = [
    ['label' => 'legacy_invalid_qr', 'url' => $portalUrl, 'mode' => 'form', 'fields' => ['kode_qr' => 'INVALID_QR_XYZ_999', 'scan_source' => 'camera', 'pb_portal_scan' => '1']],
    ['label' => 'legacy_duplicate_pb', 'url' => $portalUrl, 'mode' => 'form', 'fields' => ['kode_qr' => 'PMB001', 'scan_source' => 'camera', 'pb_portal_scan' => '1']],
    ['label' => 'smart_invalid_qr', 'url' => $smartUrl, 'mode' => 'json', 'fields' => ['qr_code' => 'INVALID_QR_XYZ_999', 'scan_source' => 'camera']],
    ['label' => 'smart_empty_qr', 'url' => $smartUrl, 'mode' => 'json', 'fields' => ['qr_code' => '', 'scan_source' => 'camera']],
];

echo "SCAN_FLOW\n";
foreach ($cases as $case) {
    $http = 0;
    if ($case['mode'] === 'json') {
        [$http, $body] = scan_post_json($case['url'], $case['fields'], $jar);
    } else {
        [$http, $body] = scan_post_form($case['url'], $case['fields'], $jar);
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
        $type = (string) ($json['type'] ?? '');
        $msg = substr((string) ($json['message'] ?? ''), 0, 80);
        $redirect = !empty($json['redirect']) ? '1' : '0';
        $fatal = preg_match('/Fatal error|Parse error/i', $body) ? '1' : '0';
        echo "CASE|{$case['label']}|fatal=$fatal|http=$http|type=$type|redirect=$redirect|msg=$msg\n";
        continue;
    }

    [$type, $speak, $banner] = extract_scan_result($body);
    $fatal = preg_match('/Fatal error|Parse error/i', $body) ? '1' : '0';
    echo "CASE|{$case['label']}|fatal=$fatal|type=$type|banner=$banner|speak=" . substr($speak, 0, 80) . "\n";
}

$html = (string) (file_get_contents($loginScanUrl) ?: '');
$assets = [
    'login-scan-kegiatan.js' => str_contains($html, 'login-scan-kegiatan.js'),
    'login-scan-smart-url' => str_contains($html, 'login-scan-smart-url'),
    'login-scan-mode-bar' => !str_contains($html, 'login-scan-mode-bar'),
    'login-scan-munawib-pick' => str_contains($html, 'login-scan-munawib-pick'),
    'hint_otomatis' => str_contains($html, 'absensi') && str_contains($html, 'portal otomatis'),
];
foreach ($assets as $k => $v) {
    echo "ASSET|$k|" . ($v ? 'OK' : 'MISSING') . "\n";
}
