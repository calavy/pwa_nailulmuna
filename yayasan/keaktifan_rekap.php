<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = app_href('/yayasan/operasional.php');
$kb = ['kb_open' => '1'];
if ($query !== '') {
    $map = [];
    parse_str($query, $map);
    if (isset($map['mode'])) {
        $kb['kb_mode'] = $map['mode'];
    }
    if (isset($map['month'])) {
        $kb['kb_month'] = $map['month'];
    }
    if (isset($map['year'])) {
        $kb['kb_year'] = $map['year'];
    }
    if (isset($map['tingkatan'])) {
        $kb['kb_tingkatan'] = $map['tingkatan'];
    }
    foreach (['kb_mode', 'kb_month', 'kb_year', 'kb_tingkatan'] as $k) {
        if (isset($map[$k])) {
            $kb[$k] = $map[$k];
        }
    }
}
$target .= '?' . http_build_query($kb);
header('Location: ' . $target . '#yp-keaktifan-bulan', true, 302);
exit;
