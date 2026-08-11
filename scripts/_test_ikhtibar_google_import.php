<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/ikhtibar_google_import.php';

$fail = 0;

function assert_eq(mixed $expected, mixed $actual, string $label): void
{
    global $fail;
    if ($expected !== $actual) {
        echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
        $fail++;
    } else {
        echo "OK: {$label}\n";
    }
}

function assert_true(bool $cond, string $label): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: {$label}\n";
        $fail++;
    } else {
        echo "OK: {$label}\n";
    }
}

// --- ikhtibar_google_sheet_parts ---
$std = ikhtibar_google_sheet_parts('https://docs.google.com/spreadsheets/d/abc123XYZ/edit#gid=987654321');
assert_eq('abc123XYZ', $std['id'] ?? null, 'standard id');
assert_eq('987654321', $std['gid'] ?? null, 'gid from hash');
assert_eq(false, $std['published'] ?? null, 'not published');

$queryGid = ikhtibar_google_sheet_parts('https://docs.google.com/spreadsheets/d/abc123XYZ/edit?gid=555');
assert_eq('555', $queryGid['gid'] ?? null, 'gid from query');

$noGid = ikhtibar_google_sheet_parts('https://docs.google.com/spreadsheets/d/abc123XYZ/edit');
assert_eq('0', $noGid['gid'] ?? null, 'default gid 0');

$pub = ikhtibar_google_sheet_parts('https://docs.google.com/spreadsheets/d/e/pubKey123/pubhtml#gid=42');
assert_eq('pubKey123', $pub['id'] ?? null, 'published id');
assert_eq('42', $pub['gid'] ?? null, 'published gid from hash');
assert_eq(true, $pub['published'] ?? null, 'published flag');

assert_eq(null, ikhtibar_google_sheet_parts('https://example.com/not-sheets'), 'invalid url null');

// --- export URLs ---
$urlsStd = ikhtibar_google_sheet_export_urls(['id' => 'abc', 'gid' => '0', 'published' => false]);
assert_true(count($urlsStd) === 2, 'standard sheet has 2 export urls');
assert_true(str_contains($urlsStd[0], '/export?format=csv'), 'first url is export csv');
assert_true(str_contains($urlsStd[1], '/gviz/tq?tqx=out:csv'), 'second url is gviz csv');

$urlsPub = ikhtibar_google_sheet_export_urls(['id' => 'pub1', 'gid' => '0', 'published' => true]);
assert_true(str_contains($urlsPub[0], '/d/e/pub1/pub?output=csv'), 'published pub csv url');

// --- HTML detection ---
assert_true(ikhtibar_google_is_html_response('<!DOCTYPE html><html>'), 'detect doctype html');
assert_true(ikhtibar_google_is_html_response('  <html lang="en">'), 'detect html tag');
assert_true(!ikhtibar_google_is_html_response("jenis,nomor,teks\nPG,1,Soal"), 'csv not html');

// --- import_dipercoba ---
assert_true(ikhtibar_import_dipercoba_dari_request(['import_google_sheet' => 'https://x'], []), 'sheet url counts as import');
assert_true(!ikhtibar_import_dipercoba_dari_request([], []), 'empty post not import');

// --- optional live fetch (public sample sheet) ---
if (($argv[1] ?? '') === '--live') {
    $liveUrl = 'https://docs.google.com/spreadsheets/d/1CTgM1g_aYoWFFpHU6A_qyqWGH0ulCFhs67uAcRVf1Rw/edit#gid=0';
    $parts = ikhtibar_google_sheet_parts($liveUrl);
    try {
        $csv = ikhtibar_google_fetch_sheet_csv($parts);
        assert_true(strlen($csv) > 10 && !ikhtibar_google_is_html_response($csv), 'live fetch returns csv');
        echo "Live CSV preview: " . substr(str_replace("\n", ' ', $csv), 0, 80) . "...\n";
    } catch (Throwable $e) {
        echo "SKIP live fetch (network): " . $e->getMessage() . "\n";
    }
}

echo $fail === 0 ? "\nALL TESTS PASSED\n" : "\n{$fail} TEST(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
