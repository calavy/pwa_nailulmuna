<?php

declare(strict_types=1);

/** Bungkus header Location dengan app_href() bila belum. */
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$pattern = '/header\s*\(\s*[\'"]Location:\s*[\'"]\s*\.\s*([^)]+)\)/';
$changed = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/tools/fix_location_headers.php') || str_contains($path, '/vendor/')) {
        continue;
    }

    $code = (string) file_get_contents($path);
    if (!preg_match($pattern, $code)) {
        continue;
    }

    $newCode = preg_replace_callback(
        $pattern,
        static function (array $m): string {
            $expr = trim($m[1]);
            if (
                str_contains($expr, 'app_href(')
                || str_contains($expr, 'app_url(')
                || str_contains($expr, 'app_rewrite_internal_url(')
                || str_contains($expr, 'app_redirect_path(')
                || str_contains($expr, 'app_redirect(')
            ) {
                return $m[0];
            }

            return "header('Location: ' . app_href({$expr}))";
        },
        $code
    );

    if ($newCode !== $code) {
        file_put_contents($path, $newCode);
        $changed++;
        echo basename(dirname($path)) . '/' . basename($path) . "\n";
    }
}

echo "Changed: {$changed}\n";
