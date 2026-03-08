<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_videobin_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['videobin.co', 'videobin.com']);
}

function ve_remote_videobin_resolve(string $url): array
{
    return ve_remote_generic_html_resolve($url, [
        'label' => 'Videobin',
    ]);
}

return [
    'key' => 'videobin',
    'match' => 've_remote_videobin_match',
    'resolve' => 've_remote_videobin_resolve',
];
