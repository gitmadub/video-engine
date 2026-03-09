<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_streamsb_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['streamsb.com', 'streamsb.net', 'watchsb.com', 'sbembed.com', 'sbbrisk.com', 'sbchill.com']);
}

function ve_remote_streamsb_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:e|d)/([A-Za-z0-9]+)#', $url, $matches) === 1) {
        $extraUrls[] = 'https://streamsb.net/e/' . $matches[1];
        $extraUrls[] = 'https://watchsb.com/e/' . $matches[1];
        $extraUrls[] = 'https://sbbrisk.com/e/' . $matches[1] . '.html';
        $extraUrls[] = 'https://sbchill.com/e/' . $matches[1] . '.html';
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'StreamSB',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'streamsb',
    'match' => 've_remote_streamsb_match',
    'resolve' => 've_remote_streamsb_resolve',
];
