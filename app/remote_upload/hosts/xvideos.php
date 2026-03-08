<?php

declare(strict_types=1);

function ve_remote_xvideos_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['xvideos.com']);
}

function ve_remote_xvideos_resolve(string $url): array
{
    $format = 'best[ext=mp4]/best';
    $info = ve_remote_yt_dlp_extract_info($url, ['format' => $format]);
    $title = trim((string) ($info['title'] ?? 'xvideos-video'));
    $filename = ve_remote_sanitize_filename($title . '.mp4', 'xvideos-video.mp4');

    return [
        'normalized_url' => (string) ($info['webpage_url'] ?? $url),
        'download_url' => $url,
        'download_method' => 'yt_dlp',
        'yt_dlp_format' => $format,
        'merge_output_format' => 'mp4',
        'filename' => $filename,
        'referer' => (string) ($info['webpage_url'] ?? $url),
    ];
}

return [
    'key' => 'xvideos',
    'match' => 've_remote_xvideos_match',
    'resolve' => 've_remote_xvideos_resolve',
];
