<?php
/** HTTP login smoke via curl — audit only */
$base = 'http://localhost/pwa_nailulmuna';
$jar = sys_get_temp_dir() . '/pwa_audit_cookies.txt';
@unlink($jar);

function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$code, $final, $body];
}

function http_post(string $url, array $fields, string $jar): array {
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
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$code, $final, $body];
}

function page_status(string $path, string $body, string $final): string {
    if (preg_match('/Fatal error|Parse error|Uncaught (?:Exception|Error)/i', $body)) return 'FATAL';
    if (str_contains($final, 'login.php') && !str_contains($path, 'login')) return 'REDIRECT_LOGIN';
    if (strlen($body) < 300) return 'WARN_EMPTY';
    return 'OK';
}

function title_of(string $body): string {
    return preg_match('/<title>([^<]+)<\/title>/i', $body, $m) ? trim($m[1]) : '';
}

$logins = [
    ['label' => 'pembimbing', 'url' => "$base/login.php?peran=pembimbing", 'fields' => ['peran'=>'pembimbing','username'=>'PMB001','password'=>'abc123','login_method'=>'password']],
    ['label' => 'pengurus', 'url' => "$base/login.php?peran=pengurus", 'fields' => ['peran'=>'pengurus','username'=>'slamet','password'=>'abc123','login_method'=>'password']],
];

$pages = [
    '/pembimbing/dashboard.php','/yayasan/keaktifan.php','/yayasan/operasional.php',
    '/presensi/scan.php','/presensi/scan.php?portal=1','/rekap/keaktifan_hari.php','/dashboard.php',
];

echo "HTTP_LOGIN_SMOKE\n";
foreach ($logins as $login) {
    @unlink($jar);
    http_get($login['url'], $jar);
    [$c,$f,$b] = http_post($login['url'], $login['fields'], $jar);
    $loginOk = !str_contains($f, 'login.php') || str_contains($b, 'Dashboard') || str_contains($f, 'dashboard');
    echo "LOGIN|{$login['label']}|code=$c|final=$f|ok=" . ($loginOk ? '1' : '0') . "\n";
    foreach ($pages as $p) {
        [$c2,$f2,$b2] = http_get($base.$p, $jar);
        $st = page_status($p, $b2, $f2);
        echo "PAGE|{$login['label']}|$p|$st|code=$c2|final=$f2|title=" . title_of($b2) . "\n";
    }
}

// Portal scan public
[$c,$f,$b] = http_get("$base/presensi/scan.php?portal=1", $jar);
echo "PUBLIC|portal_scan|" . page_status('portal', $b, $f) . "|title=" . title_of($b) . "\n";
if (!preg_match('/presensi-scan-feedback|presensi-scan-timer|presensi-scan-banner/', $b)) {
    echo "PUBLIC|portal_scan|WARN|missing scan assets\n";
}
if (!preg_match('/data-type=/', $b) && !preg_match('/presensi-scan-result/', $b)) {
    echo "PUBLIC|portal_scan|INFO|no result overlay element on GET (expected)\n";
}
