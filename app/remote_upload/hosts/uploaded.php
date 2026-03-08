<?php

declare(strict_types=1);

require_once __DIR__ . '/_direct_fallback_host.php';

function ve_remote_uploaded_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['uploaded.net', 'uploaded.to']);
}

function ve_remote_uploaded_resolve(string $url): array
{
    return ve_remote_try_direct_or_fail(
        $url,
        'Uploaded.net is no longer active and no direct downloadable video file could be extracted from the supplied URL.'
    );
}

return [
    'key' => 'uploaded',
    'match' => 've_remote_uploaded_match',
    'resolve' => 've_remote_uploaded_resolve',
];
