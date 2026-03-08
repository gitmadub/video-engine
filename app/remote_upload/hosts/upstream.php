<?php

declare(strict_types=1);

require_once __DIR__ . '/_generic_html_host.php';

function ve_remote_upstream_match(string $url): bool
{
    return ve_remote_url_matches_host($url, ['upstream.to']);
}

function ve_remote_upstream_resolve(string $url): array
{
    $extraUrls = [];

    if (preg_match('#/(?:embed-|e/)?([A-Za-z0-9]+)(?:\.html)?#', $url, $matches) === 1) {
        $extraUrls[] = 'https://upstream.to/embed-' . $matches[1] . '.html';
        $extraUrls[] = 'https://upstream.to/e/' . $matches[1];
    }

    return ve_remote_generic_html_resolve($url, [
        'label' => 'Upstream',
        'extra_urls' => $extraUrls,
        'verify_ssl' => false,
    ]);
}

return [
    'key' => 'upstream',
    'match' => 've_remote_upstream_match',
    'resolve' => 've_remote_upstream_resolve',
];
