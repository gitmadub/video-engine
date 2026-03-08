<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_fembed_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['fembed.com', 'fembed.net', 'femax20.com']);
}

function ve_remote_fembed_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:v|f|e)/([A-Za-z0-9]+)#', $url, $matches) === 1) {
        $extraUrls[] = 'https://fembed.com/v/' . $matches[1];
        $extraUrls[] = 'https://fembed.com/e/' . $matches[1];
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Fembed',
        'extra_urls' => $extraUrls,
    ]);
}

return [
    'key' => 'fembed',
    'match' => 've_remote_fembed_match',
    'resolve' => 've_remote_fembed_resolve',
];
