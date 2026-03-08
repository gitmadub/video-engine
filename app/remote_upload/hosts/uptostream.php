<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_uptostream_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['uptostream.com', 'uptostream.co']);
}

function ve_remote_uptostream_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:iframe/|embed-|e/)?([A-Za-z0-9]+)(?:\.html)?#', $url, $matches) === 1) {
        $extraUrls[] = 'https://uptostream.com/iframe/' . $matches[1];
        $extraUrls[] = 'https://uptostream.com/e/' . $matches[1];
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Uptostream',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'uptostream',
    'match' => 've_remote_uptostream_match',
    'resolve' => 've_remote_uptostream_resolve',
];
