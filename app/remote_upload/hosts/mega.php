<?php

declare(strict_types=1);

function ve_remote_mega_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['mega.nz', 'mega.io']);
}

function ve_remote_mega_resolve(string $url): array
{
    $info = ve_remote_mega_info($url);
    $filename = trim((string) ($info['name'] ?? ''));

    if ($filename === '') {
        $filename = 'mega-download.bin';
    }

    return [
        'normalized_url' => $url,
        'download_url' => $url,
        'download_method' => 'mega_py',
        'filename' => $filename,
        'referer' => '',
        'headers' => [],
    ];
}

return [
    'key' => 'mega',
    'match' => 've_remote_mega_match',
    'resolve' => 've_remote_mega_resolve',
];
