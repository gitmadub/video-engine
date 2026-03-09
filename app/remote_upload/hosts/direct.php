<?php

declare(strict_types=1);

function ve_remote_direct_filename_looks_video(string $filename): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return $extension !== '' && in_array($extension, VE_VIDEO_ALLOWED_EXTENSIONS, true);
}

function ve_remote_direct_filename_looks_manifest(string $filename): bool
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'm3u8';
}

function ve_remote_direct_response_is_manifest(array $response): bool
{
    $contentType = strtolower((string) ($response['content_type'] ?? ''));

    if ($contentType !== '') {
        foreach ([
            'application/vnd.apple.mpegurl',
            'application/x-mpegurl',
            'audio/mpegurl',
            'audio/x-mpegurl',
        ] as $needle) {
            if (str_contains($contentType, $needle)) {
                return true;
            }
        }
    }

    $contentDisposition = ve_remote_http_response_header($response, 'content-disposition');

    if ($contentDisposition !== '') {
        return ve_remote_direct_filename_looks_manifest(
            ve_remote_filename_from_content_disposition($contentDisposition)
        );
    }

    return ve_remote_direct_filename_looks_manifest(
        ve_remote_filename_from_url((string) ($response['effective_url'] ?? ''))
    );
}

function ve_remote_direct_is_video_response(array $response): bool
{
    $contentType = strtolower((string) ($response['content_type'] ?? ''));

    if (str_starts_with($contentType, 'video/')) {
        return true;
    }

    $contentDisposition = ve_remote_http_response_header($response, 'content-disposition');

    if ($contentDisposition !== '') {
        return ve_remote_direct_filename_looks_video(
            ve_remote_filename_from_content_disposition($contentDisposition)
        );
    }

    return ve_remote_direct_filename_looks_video(
        ve_remote_filename_from_url((string) ($response['effective_url'] ?? ''))
    );
}

function ve_remote_direct_resolved_source(string $originalUrl, string $effectiveUrl, string $filename, string $downloadMethod = 'curl'): array
{
    return [
        'normalized_url' => $effectiveUrl,
        'download_url' => $effectiveUrl,
        'filename' => $filename,
        'download_method' => $downloadMethod,
    ];
}

function ve_remote_direct_match(string $url): bool
{
    return ve_remote_is_http_url($url);
}

function ve_remote_direct_resolve(string $url): array
{
    $filename = ve_remote_filename_from_url($url);

    if (ve_remote_direct_filename_looks_video($filename)) {
        return ve_remote_direct_resolved_source($url, $url, $filename);
    }

    if (ve_remote_direct_filename_looks_manifest($filename)) {
        return ve_remote_direct_resolved_source($url, $url, $filename, 'ffmpeg');
    }

    $head = ve_remote_http_request($url, [
        'method' => 'HEAD',
        'follow_location' => true,
        'timeout' => 20,
    ]);

    if (($head['status'] ?? 0) < 400 && ve_remote_direct_is_video_response($head)) {
        $contentDisposition = ve_remote_http_response_header($head, 'content-disposition');

        return ve_remote_direct_resolved_source(
            $url,
            (string) ($head['effective_url'] ?? $url),
            $contentDisposition !== ''
                ? ve_remote_filename_from_content_disposition($contentDisposition)
                : ve_remote_filename_from_url((string) ($head['effective_url'] ?? $url))
        );
    }

    if (($head['status'] ?? 0) < 400 && ve_remote_direct_response_is_manifest($head)) {
        $contentDisposition = ve_remote_http_response_header($head, 'content-disposition');

        return ve_remote_direct_resolved_source(
            $url,
            (string) ($head['effective_url'] ?? $url),
            $contentDisposition !== ''
                ? ve_remote_filename_from_content_disposition($contentDisposition)
                : ve_remote_filename_from_url((string) ($head['effective_url'] ?? $url)),
            'ffmpeg'
        );
    }

    $probe = ve_remote_http_request($url, [
        'method' => 'GET',
        'range' => '0-1',
        'follow_location' => true,
        'timeout' => 20,
    ]);

    if (($probe['status'] ?? 0) < 400 && ve_remote_direct_is_video_response($probe)) {
        $contentDisposition = ve_remote_http_response_header($probe, 'content-disposition');

        return ve_remote_direct_resolved_source(
            $url,
            (string) ($probe['effective_url'] ?? $url),
            $contentDisposition !== ''
                ? ve_remote_filename_from_content_disposition($contentDisposition)
                : ve_remote_filename_from_url((string) ($probe['effective_url'] ?? $url))
        );
    }

    if (($probe['status'] ?? 0) < 400 && ve_remote_direct_response_is_manifest($probe)) {
        $contentDisposition = ve_remote_http_response_header($probe, 'content-disposition');

        return ve_remote_direct_resolved_source(
            $url,
            (string) ($probe['effective_url'] ?? $url),
            $contentDisposition !== ''
                ? ve_remote_filename_from_content_disposition($contentDisposition)
                : ve_remote_filename_from_url((string) ($probe['effective_url'] ?? $url)),
            'ffmpeg'
        );
    }

    throw new RuntimeException('The link does not look like a direct downloadable video file.');
}

return [
    'key' => 'direct',
    'match' => 've_remote_direct_match',
    'resolve' => 've_remote_direct_resolve',
];
