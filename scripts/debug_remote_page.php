<?php

declare(strict_types=1);

require __DIR__ . '/../app/frontend.php';

$url = trim((string) ($argv[1] ?? ''));

if ($url === '') {
    fwrite(STDERR, "Usage: php scripts/debug_remote_page.php <url>\n");
    exit(1);
}

try {
    $response = ve_remote_http_request($url, [
        'follow_location' => true,
        'timeout' => 30,
    ]);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'ERROR: ' . $throwable->getMessage() . "\n");
    exit(1);
}

echo 'STATUS: ' . (int) ($response['status'] ?? 0) . PHP_EOL;
echo 'URL: ' . (string) ($response['effective_url'] ?? $url) . PHP_EOL;
echo "HEADERS:\n";
echo (string) ($response['headers_raw'] ?? '') . PHP_EOL;
echo "BODY:\n";
echo substr((string) ($response['body'] ?? ''), 0, 12000) . PHP_EOL;
