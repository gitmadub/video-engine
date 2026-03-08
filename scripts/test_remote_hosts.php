<?php

declare(strict_types=1);

require __DIR__ . '/../app/frontend.php';

$urls = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $value): bool => trim($value) !== ''
));

if ($urls === []) {
    fwrite(STDERR, "Usage: php scripts/test_remote_hosts.php <url> [url...]\n");
    exit(1);
}

foreach ($urls as $url) {
    echo "URL: {$url}\n";

    try {
        $resolved = ve_remote_resolve_source(['source_url' => $url]);
        echo json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    } catch (Throwable $throwable) {
        echo 'ERROR: ' . $throwable->getMessage() . "\n\n";
    }
}
