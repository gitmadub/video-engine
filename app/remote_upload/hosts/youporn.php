<?php

declare(strict_types=1);

function ve_remote_youporn_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['youporn.com']);
}

function ve_remote_youporn_resolve(string $url): array
{
    $format = ve_remote_yt_dlp_mp4_format();
    $info = ve_remote_yt_dlp_extract_info($url, ['format' => $format]);
    $title = trim((string) ($info['title'] ?? 'youporn-video'));
    $filename = ve_remote_sanitize_filename($title . '.mp4', 'youporn-video.mp4');

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
    'key' => 'youporn',
    'match' => 've_remote_youporn_match',
    'resolve' => 've_remote_youporn_resolve',
];
