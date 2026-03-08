<?php

declare(strict_types=1);

function ve_remote_direct_filename_looks_video(string $filename): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return $extension !== '' && in_array($extension, VE_VIDEO_ALLOWED_EXTENSIONS, true);
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

function ve_remote_direct_match(string $url): bool
{
    return ve_remote_is_http_url($url);
}

function ve_remote_direct_resolve(string $url): array
{
    $filename = ve_remote_filename_from_url($url);

    if (ve_remote_direct_filename_looks_video($filename)) {
        return [
            'normalized_url' => $url,
            'download_url' => $url,
            'filename' => $filename,
        ];
    }

    $head = ve_remote_http_request($url, [
        'method' => 'HEAD',
        'follow_location' => true,
        'timeout' => 20,
    ]);

    if (($head['status'] ?? 0) < 400 && ve_remote_direct_is_video_response($head)) {
        $contentDisposition = ve_remote_http_response_header($head, 'content-disposition');

        return [
            'normalized_url' => (string) ($head['effective_url'] ?? $url),
            'download_url' => (string) ($head['effective_url'] ?? $url),
            'filename' => $contentDisposition !== ''
                ? ve_remote_filename_from_content_disposition($contentDisposition)
                : ve_remote_filename_from_url((string) ($head['effective_url'] ?? $url)),
        ];
    }

    $probe = ve_remote_http_request($url, [
        'method' => 'GET',
        'range' => '0-1',
        'follow_location' => true,
        'timeout' => 20,
    ]);

    if (($probe['status'] ?? 0) < 400 && ve_remote_direct_is_video_response($probe)) {
        $contentDisposition = ve_remote_http_response_header($probe, 'content-disposition');

        return [
            'normalized_url' => (string) ($probe['effective_url'] ?? $url),
            'download_url' => (string) ($probe['effective_url'] ?? $url),
            'filename' => $contentDisposition !== ''
                ? ve_remote_filename_from_content_disposition($contentDisposition)
                : ve_remote_filename_from_url((string) ($probe['effective_url'] ?? $url)),
        ];
    }

    throw new RuntimeException('The link does not look like a direct downloadable video file.');
}

return [
    'key' => 'direct',
    'match' => 've_remote_direct_match',
    'resolve' => 've_remote_direct_resolve',
];
