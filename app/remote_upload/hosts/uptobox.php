<?php

declare(strict_types=1);

require_once __DIR__ . '/_direct_fallback_host.php';

function ve_remote_uptobox_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['uptobox.com', 'uptobox.fr']);
}

function ve_remote_uptobox_resolve(string $url): array
{
    return ve_remote_try_direct_or_fail(
        $url,
        'Uptobox is no longer active and no direct downloadable video file could be extracted from the supplied URL.'
    );
}

return [
    'key' => 'uptobox',
    'match' => 've_remote_uptobox_match',
    'resolve' => 've_remote_uptobox_resolve',
];
