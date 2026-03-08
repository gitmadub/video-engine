<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_netu_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['netu.ac', 'netu.tv', 'waaw.to', 'hqq.tv', 'hqq.to']);
}

function ve_remote_netu_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:e|watch_video\.php\?v=)?([A-Za-z0-9]+)#', $url, $matches) === 1) {
        $extraUrls[] = 'https://netu.ac/e/' . $matches[1];
        $extraUrls[] = 'https://waaw.to/e/' . $matches[1];
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Netu',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'netu',
    'match' => 've_remote_netu_match',
    'resolve' => 've_remote_netu_resolve',
];
