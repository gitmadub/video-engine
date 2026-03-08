<?php

declare(strict_types=1);

function ve_remote_dropbox_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['dropbox.com', 'dropboxusercontent.com']);
}

function ve_remote_dropbox_resolve(string $url): array
{
    $parts = parse_url($url);

    if (!is_array($parts)) {
        throw new RuntimeException('Invalid Dropbox URL.');
    }

    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? 'www.dropbox.com';
    $path = $parts['path'] ?? '/';
    $query = [];

    parse_str((string) ($parts['query'] ?? ''), $query);

    if (!str_ends_with(strtolower($host), 'dropboxusercontent.com')) {
        $query['raw'] = '1';
        unset($query['dl']);
    }

    $normalizedUrl = $scheme . '://' . $host . $path;

    if ($query !== []) {
        $normalizedUrl .= '?' . http_build_query($query);
    }

    $filename = ve_remote_filename_from_url($path);

    return [
        'normalized_url' => $normalizedUrl,
        'download_url' => $normalizedUrl,
        'filename' => $filename,
        'referer' => $url,
    ];
}

return [
    'key' => 'dropbox',
    'match' => 've_remote_dropbox_match',
    'resolve' => 've_remote_dropbox_resolve',
];
